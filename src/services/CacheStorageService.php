<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\cache\ScopedCacheResult;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\cache\CacheStorageDecision;
use lindemannrock\searchmanager\SearchManager;

/**
 * Resolves and operates Search Manager's disposable cache storage.
 *
 * Index scopes are canonical full index names. Logical index handles are
 * normalized by their owning caller before crossing this boundary.
 *
 * @since 5.55.0
 */
final class CacheStorageService
{
    public const STORAGE_APPLICATION = 'application';
    public const STORAGE_FILE = 'file';
    public const STORAGE_DISABLED = 'disabled';

    /** @var array<string, true> */
    private const OWNED_FAMILIES = [
        'search' => true,
        'autocomplete' => true,
        'device' => true,
    ];

    private const FILE_SCOPE_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/D';
    private const ITEM_IDENTITY_PATTERN = '/\A[a-f0-9]{32}\z/D';

    /** @var array<string, true> */
    private static array $loggedFailures = [];

    public function getStorageDecision(?string $configuredStorage = null): CacheStorageDecision
    {
        $configuredStorage ??= SearchManager::$plugin->getSettings()->cacheStorageMethod;
        $ephemeral = App::isEphemeral();

        if ($configuredStorage === 'file' && !$ephemeral) {
            return new CacheStorageDecision(
                $configuredStorage,
                CacheStorageDecision::EFFECTIVE_FILE,
                false,
                CacheBackendStatus::fromCache(null),
                null,
                CacheStorageDecision::PERSISTENCE_CONFIRMED,
                false,
                true,
                CacheStorageDecision::REASON_DURABLE_FILE,
            );
        }

        if (!in_array($configuredStorage, ['file', 'redis', 'craft'], true)) {
            return new CacheStorageDecision(
                $configuredStorage,
                CacheStorageDecision::EFFECTIVE_DISABLED,
                $ephemeral,
                CacheBackendStatus::fromCache(null),
                null,
                CacheStorageDecision::PERSISTENCE_UNSUITABLE,
                false,
                false,
                CacheStorageDecision::REASON_UNKNOWN_TOKEN,
            );
        }

        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':disposable-cache');
        $status = CacheBackendStatus::fromCache($cache);
        $fileStorageBypassed = $configuredStorage === 'file';

        if ($status->supportsCrossRequest($ephemeral)) {
            return new CacheStorageDecision(
                $configuredStorage,
                CacheStorageDecision::EFFECTIVE_APPLICATION,
                $ephemeral,
                $status,
                $cache,
                $status->crossRequestPersistent === true
                    ? CacheStorageDecision::PERSISTENCE_CONFIRMED
                    : CacheStorageDecision::PERSISTENCE_UNKNOWN,
                $fileStorageBypassed,
                false,
                $fileStorageBypassed
                    ? CacheStorageDecision::REASON_EPHEMERAL_FILE_APPLICATION
                    : CacheStorageDecision::REASON_APPLICATION,
            );
        }

        return new CacheStorageDecision(
            $configuredStorage,
            CacheStorageDecision::EFFECTIVE_DISABLED,
            $ephemeral,
            $status,
            $cache,
            CacheStorageDecision::PERSISTENCE_UNSUITABLE,
            $fileStorageBypassed,
            false,
            $fileStorageBypassed
                ? CacheStorageDecision::REASON_EPHEMERAL_FILE_UNSUITABLE
                : CacheStorageDecision::REASON_APPLICATION_UNSUITABLE,
        );
    }

    public function getEffectiveStorage(): string
    {
        return $this->getStorageDecision()->effectiveStorage;
    }

    public function read(string $family, string $scope, string $itemIdentity, int $ttl): ScopedCacheResult
    {
        $this->assertOwnedFamily($family);
        $this->assertItemIdentity($itemIdentity);

        $decision = $this->getStorageDecision();

        return match ($decision->effectiveStorage) {
            self::STORAGE_APPLICATION => $this->readApplicationCache($decision, $family, $scope, $itemIdentity),
            self::STORAGE_FILE => $this->readFileCache($family, $scope, $itemIdentity, $ttl),
            default => ScopedCacheResult::miss(),
        };
    }

    public function write(string $family, string $scope, string $itemIdentity, array $value, int $ttl): bool
    {
        $this->assertOwnedFamily($family);
        $this->assertItemIdentity($itemIdentity);

        if ($ttl <= 0) {
            return false;
        }

        $decision = $this->getStorageDecision();

        return match ($decision->effectiveStorage) {
            self::STORAGE_APPLICATION => $this->writeApplicationCache($decision, $family, $scope, $itemIdentity, $value, $ttl),
            self::STORAGE_FILE => $this->writeFileCache($family, $scope, $itemIdentity, $value),
            default => false,
        };
    }

    public function invalidateScope(string $family, string $scope): bool
    {
        $this->assertOwnedFamily($family);

        $decision = $this->getStorageDecision();

        return match ($decision->effectiveStorage) {
            self::STORAGE_APPLICATION => $this->invalidateApplicationScope($decision, $family, $scope),
            self::STORAGE_FILE => $this->clearFileScope($family, $scope),
            default => true,
        };
    }

    public function invalidateFamily(string $family): bool
    {
        $this->assertOwnedFamily($family);

        $decision = $this->getStorageDecision();

        return match ($decision->effectiveStorage) {
            self::STORAGE_APPLICATION => $this->invalidateApplicationFamily($decision, $family),
            self::STORAGE_FILE => $this->clearFileFamily($family),
            default => true,
        };
    }

    public function countFiles(string $family): int
    {
        $this->assertOwnedFamily($family);

        if (!$this->getStorageDecision()->usesFileCache()) {
            return 0;
        }

        $path = $this->getFilePath($family);
        try {
            if (!@is_dir($path)) {
                return 0;
            }

            $count = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.cache')) {
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            $this->logFailure($family, 'count-files', $e);
            return 0;
        }
    }

    public function getDriverLabel(): string
    {
        $decision = $this->getStorageDecision();
        if ($decision->usesFileCache()) {
            return 'file';
        }
        if ($decision->isDisabled()) {
            return 'none';
        }

        return match ($decision->backendStatus->backend) {
            CacheBackendStatus::BACKEND_REDIS => 'redis',
            CacheBackendStatus::BACKEND_MANAGED => 'managed',
            CacheBackendStatus::BACKEND_DATABASE => 'database',
            CacheBackendStatus::BACKEND_FILESYSTEM => 'file',
            CacheBackendStatus::BACKEND_MEMORY => 'none',
            default => 'unknown',
        };
    }

    public function getDisplayFilePath(?CacheStorageDecision $decision = null): ?string
    {
        $decision ??= $this->getStorageDecision();
        if (!$decision->canResolveFilePath || !$decision->usesFileCache()) {
            return null;
        }

        return PluginHelper::getCacheBasePath(SearchManager::$plugin);
    }

    private function readApplicationCache(
        CacheStorageDecision $decision,
        string $family,
        string $scope,
        string $itemIdentity,
    ): ScopedCacheResult {
        $cache = $this->getScopedCache($decision, $family);
        if ($cache === null) {
            return ScopedCacheResult::failure();
        }

        $result = $cache->get($itemIdentity, $scope);
        if ($result->isFailure()) {
            $this->logFailure($family, 'read');
        }

        return $result;
    }

    private function writeApplicationCache(
        CacheStorageDecision $decision,
        string $family,
        string $scope,
        string $itemIdentity,
        array $value,
        int $ttl,
    ): bool {
        $cache = $this->getScopedCache($decision, $family);
        $written = $cache?->set($itemIdentity, $value, $ttl, $scope) === true;
        if (!$written) {
            $this->logFailure($family, 'write');
        }

        return $written;
    }

    private function invalidateApplicationScope(CacheStorageDecision $decision, string $family, string $scope): bool
    {
        $invalidated = $this->getScopedCache($decision, $family)?->invalidateScope($scope) === true;
        if (!$invalidated) {
            $this->logFailure($family, 'invalidate-scope');
        }

        return $invalidated;
    }

    private function invalidateApplicationFamily(CacheStorageDecision $decision, string $family): bool
    {
        $invalidated = $this->getScopedCache($decision, $family)?->invalidateFamily() === true;
        if (!$invalidated) {
            $this->logFailure($family, 'invalidate-family');
        }

        return $invalidated;
    }

    private function getScopedCache(CacheStorageDecision $decision, string $family): ?ScopedCache
    {
        $this->assertOwnedFamily($family);

        $cache = $decision->applicationCache;
        if ($cache === null) {
            return null;
        }

        try {
            return new ScopedCache($cache, SearchManager::$plugin->id, $family);
        } catch (\Throwable $e) {
            $this->logFailure($family, 'initialize', $e);
            return null;
        }
    }

    private function readFileCache(string $family, string $scope, string $itemIdentity, int $ttl): ScopedCacheResult
    {
        try {
            $cacheFile = $this->getFilePath($family, $scope) . $itemIdentity . '.cache';
            if (!@is_file($cacheFile)) {
                return ScopedCacheResult::miss();
            }

            $mtime = @filemtime($cacheFile);
            if ($mtime === false) {
                $this->logFailure($family, 'file-timestamp');
                return ScopedCacheResult::failure();
            }
            if ($ttl > 0 && time() - $mtime > $ttl) {
                @unlink($cacheFile);
                return ScopedCacheResult::miss();
            }

            $content = @file_get_contents($cacheFile);
            if (!is_string($content)) {
                $this->logFailure($family, 'file-read');
                return ScopedCacheResult::failure();
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                @unlink($cacheFile);
                return ScopedCacheResult::failure();
            }

            return ScopedCacheResult::hit($decoded);
        } catch (\Throwable $e) {
            $this->logFailure($family, 'file-read', $e);
            return ScopedCacheResult::failure();
        }
    }

    private function writeFileCache(string $family, string $scope, string $itemIdentity, array $value): bool
    {
        try {
            $cachePath = $this->getFilePath($family, $scope);
            if (!@is_dir($cachePath)) {
                FileHelper::createDirectory($cachePath);
            }

            $cacheFile = $cachePath . $itemIdentity . '.cache';
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            if (@file_put_contents($cacheFile, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException('Cache file could not be written.');
            }

            return true;
        } catch (\Throwable $e) {
            $this->logFailure($family, 'file-write', $e);
            return false;
        }
    }

    private function clearFilePath(string $path): bool
    {
        try {
            if (!@is_dir($path)) {
                return true;
            }

            FileHelper::clearDirectory($path);
            return true;
        } catch (\Throwable $e) {
            $this->logFailure('file', 'clear', $e);
            return false;
        }
    }

    private function clearFileScope(string $family, string $scope): bool
    {
        try {
            return $this->clearFilePath($this->getFilePath($family, $scope));
        } catch (\Throwable $e) {
            $this->logFailure($family, 'clear-scope', $e);
            return false;
        }
    }

    private function clearFileFamily(string $family): bool
    {
        try {
            return $this->clearFilePath($this->getFilePath($family));
        } catch (\Throwable $e) {
            $this->logFailure($family, 'clear-family', $e);
            return false;
        }
    }

    private function getFilePath(string $family, ?string $scope = null): string
    {
        $this->assertOwnedFamily($family);

        $path = PluginHelper::getCachePath(SearchManager::$plugin, $family);
        if ($scope === null) {
            return $path;
        }
        if (preg_match(self::FILE_SCOPE_PATTERN, $scope) !== 1) {
            throw new \InvalidArgumentException('Cache scope is not a safe file path segment.');
        }

        return $path . $scope . DIRECTORY_SEPARATOR;
    }

    private function assertOwnedFamily(string $family): void
    {
        if (!isset(self::OWNED_FAMILIES[$family])) {
            throw new \InvalidArgumentException('Unsupported Search Manager cache family.');
        }
    }

    private function assertItemIdentity(string $itemIdentity): void
    {
        if (preg_match(self::ITEM_IDENTITY_PATTERN, $itemIdentity) !== 1) {
            throw new \InvalidArgumentException('Search Manager cache item identity must be a lowercase MD5 hash.');
        }
    }

    private function logFailure(string $family, string $operation, ?\Throwable $exception = null): void
    {
        $key = $family . ':' . $operation;
        if (isset(self::$loggedFailures[$key])) {
            return;
        }
        self::$loggedFailures[$key] = true;

        Craft::warning(sprintf(
            'Search Manager %s cache %s failed%s; the value will be recomputed.',
            $family,
            $operation,
            $exception === null ? '' : ' (' . $exception::class . ')',
        ), 'search-manager');
    }
}
