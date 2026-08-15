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

    public function getEffectiveStorage(): string
    {
        $configured = SearchManager::$plugin->getSettings()->cacheStorageMethod;
        $ephemeral = App::isEphemeral();

        if ($configured === 'file' && !$ephemeral) {
            return self::STORAGE_FILE;
        }

        if (!in_array($configured, ['file', 'redis', 'craft'], true)) {
            return self::STORAGE_DISABLED;
        }

        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':disposable-cache');
        $status = CacheBackendStatus::fromCache($cache);

        return $status->supportsCrossRequest($ephemeral)
            ? self::STORAGE_APPLICATION
            : self::STORAGE_DISABLED;
    }

    public function read(string $family, string $scope, string $itemIdentity, int $ttl): ScopedCacheResult
    {
        $this->assertOwnedFamily($family);
        $this->assertItemIdentity($itemIdentity);

        return match ($this->getEffectiveStorage()) {
            self::STORAGE_APPLICATION => $this->readApplicationCache($family, $scope, $itemIdentity),
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

        return match ($this->getEffectiveStorage()) {
            self::STORAGE_APPLICATION => $this->writeApplicationCache($family, $scope, $itemIdentity, $value, $ttl),
            self::STORAGE_FILE => $this->writeFileCache($family, $scope, $itemIdentity, $value),
            default => false,
        };
    }

    public function invalidateScope(string $family, string $scope): bool
    {
        $this->assertOwnedFamily($family);

        return match ($this->getEffectiveStorage()) {
            self::STORAGE_APPLICATION => $this->invalidateApplicationScope($family, $scope),
            self::STORAGE_FILE => $this->clearFileScope($family, $scope),
            default => true,
        };
    }

    public function invalidateFamily(string $family): bool
    {
        $this->assertOwnedFamily($family);

        return match ($this->getEffectiveStorage()) {
            self::STORAGE_APPLICATION => $this->invalidateApplicationFamily($family),
            self::STORAGE_FILE => $this->clearFileFamily($family),
            default => true,
        };
    }

    public function countFiles(string $family): int
    {
        $this->assertOwnedFamily($family);

        if ($this->getEffectiveStorage() !== self::STORAGE_FILE) {
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
        $effective = $this->getEffectiveStorage();
        if ($effective === self::STORAGE_FILE) {
            return 'file';
        }
        if ($effective === self::STORAGE_DISABLED) {
            return 'none';
        }

        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':cache-driver');

        return match (CacheBackendStatus::fromCache($cache)->backend) {
            CacheBackendStatus::BACKEND_REDIS => 'redis',
            CacheBackendStatus::BACKEND_MANAGED => 'managed',
            CacheBackendStatus::BACKEND_DATABASE => 'database',
            CacheBackendStatus::BACKEND_FILESYSTEM => 'file',
            CacheBackendStatus::BACKEND_MEMORY => 'none',
            default => 'unknown',
        };
    }

    private function readApplicationCache(string $family, string $scope, string $itemIdentity): ScopedCacheResult
    {
        $cache = $this->getScopedCache($family);
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
        string $family,
        string $scope,
        string $itemIdentity,
        array $value,
        int $ttl,
    ): bool {
        $cache = $this->getScopedCache($family);
        $written = $cache?->set($itemIdentity, $value, $ttl, $scope) === true;
        if (!$written) {
            $this->logFailure($family, 'write');
        }

        return $written;
    }

    private function invalidateApplicationScope(string $family, string $scope): bool
    {
        $invalidated = $this->getScopedCache($family)?->invalidateScope($scope) === true;
        if (!$invalidated) {
            $this->logFailure($family, 'invalidate-scope');
        }

        return $invalidated;
    }

    private function invalidateApplicationFamily(string $family): bool
    {
        $invalidated = $this->getScopedCache($family)?->invalidateFamily() === true;
        if (!$invalidated) {
            $this->logFailure($family, 'invalidate-family');
        }

        return $invalidated;
    }

    private function getScopedCache(string $family): ?ScopedCache
    {
        $this->assertOwnedFamily($family);

        $cache = PluginHelper::getApplicationCacheOrLog(SearchManager::$plugin->id . ':' . $family);
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
