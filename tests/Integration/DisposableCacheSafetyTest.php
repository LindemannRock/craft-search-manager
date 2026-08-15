<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\cache\ScopedCacheResult;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\services\CacheStorageService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.55.0
 */
#[CoversClass(CacheStorageService::class)]
final class DisposableCacheSafetyTest extends TestCase
{
    public function testApprovedBaseCacheContractLoadsFromTheWorkspacePackage(): void
    {
        $baseRoot = realpath(dirname(__DIR__, 3) . '/base');
        self::assertIsString($baseRoot);

        foreach ([PluginHelper::class, CacheBackendStatus::class, ScopedCache::class, ScopedCacheResult::class] as $class) {
            $source = realpath((new \ReflectionClass($class))->getFileName());
            self::assertIsString($source);
            self::assertStringStartsWith($baseRoot . DIRECTORY_SEPARATOR, $source);
        }
    }

    public function testDisposableCacheOwnersUseOnlyTheBackendNeutralService(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $owners = [
            $pluginRoot . '/src/controllers/UtilitiesController.php',
            $pluginRoot . '/src/services/AutocompleteService.php',
            $pluginRoot . '/src/services/BackendService.php',
            $pluginRoot . '/src/services/DeviceDetectionService.php',
            $pluginRoot . '/src/utilities/ClearSearchCache.php',
        ];

        foreach ($owners as $owner) {
            $source = file_get_contents($owner);
            self::assertIsString($source);
            self::assertStringNotContainsString('executeCommand(', $source, $owner);
            self::assertStringNotContainsString('getRedisCacheOrLog', $source, $owner);
            self::assertStringNotContainsString('searchmanager-search-keys', $source, $owner);
            self::assertStringNotContainsString('searchmanager-autocomplete-keys', $source, $owner);
            self::assertStringNotContainsString('searchmanager-device-keys', $source, $owner);
        }
    }

    public function testDisposableCacheImplementationDoesNotEnumerateOrFlushSharedStorage(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/CacheStorageService.php');
        self::assertIsString($source);

        foreach (['SMEMBERS', 'SADD', 'SREM', 'KEYS', 'SCAN', 'flush('] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $source);
        }

        self::assertStringContainsString('new ScopedCache(', $source);
        self::assertStringContainsString('invalidateScope(', $source);
        self::assertStringContainsString('invalidateFamily(', $source);
    }
}
