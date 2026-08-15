<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\cachecascade\CascadeCache;
use craft\helpers\FileHelper;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\device\DeviceDetection;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\caching\CacheInterface;

require_once dirname(__DIR__) . '/Fixtures/CascadeCache.php';

/**
 * @since 5.55.0
 */
#[CoversClass(DeviceDetectionService::class)]
final class DeviceCachePortabilityTest extends TestCase
{
    private CacheInterface $originalCache;
    private string $originalStorageMethod;
    private bool $originalCacheEnabled;
    private bool $hadEphemeralSetting;
    private mixed $originalEphemeralSetting;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = Craft::$app->getCache();
        self::assertInstanceOf(CacheInterface::class, $cache);
        $this->originalCache = $cache;
        $settings = SearchManager::$plugin->getSettings();
        $this->originalStorageMethod = $settings->cacheStorageMethod;
        $this->originalCacheEnabled = (bool)$settings->cacheDeviceDetection;
        $settings->cacheDeviceDetection = true;
        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = false;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('cache', $this->originalCache);
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = $this->originalStorageMethod;
        $settings->cacheDeviceDetection = $this->originalCacheEnabled;
        if ($this->hadEphemeralSetting) {
            $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
        } else {
            unset($_SERVER['CRAFT_EPHEMERAL']);
        }

        parent::tearDown();
    }

    public function testApplicationCacheReuseAndClearResetTheRequestLocalDetector(): void
    {
        $cache = new CascadeCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new DeviceDetectionService();
        $userAgent = 'CacheManager/1.0';

        $first = $service->detectDevice($userAgent);
        self::assertTrue($first['isSystemAgent']);
        $scoped = new ScopedCache($cache, SearchManager::$plugin->id, 'device');
        $identity = [
            'legacyPrefix' => PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'device'),
            'device' => $userAgent,
        ];
        self::assertTrue($scoped->set($identity, ['cacheMarker' => 'reused'], 300));
        self::assertSame(['cacheMarker' => 'reused'], (new DeviceDetectionService())->detectDevice($userAgent));

        $property = new \ReflectionProperty(DeviceDetectionService::class, 'deviceDetection');
        self::assertInstanceOf(DeviceDetection::class, $property->getValue($service));
        $service->clearCache();
        self::assertNull($property->getValue($service));
        self::assertTrue($scoped->get($identity)->isMiss());

        $afterClear = $this->detectWithNewService($userAgent);
        self::assertTrue($afterClear['isSystemAgent']);
        self::assertArrayNotHasKey('cacheMarker', $afterClear);
    }

    public function testEphemeralFileModeUsesApplicationCacheWithoutChangingDeviceFiles(): void
    {
        $cache = new CascadeCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'file';
        $_SERVER['CRAFT_EPHEMERAL'] = true;
        $path = PluginHelper::getCachePath(SearchManager::$plugin, 'device');
        FileHelper::createDirectory($path);
        $sentinel = $path . 'portable-owned-sentinel.cache';
        file_put_contents($sentinel, 'owned');
        $mtime = filemtime($sentinel);

        try {
            $service = new DeviceDetectionService();
            $result = $service->detectDevice('CacheManager/1.0');
            self::assertTrue($result['isSystemAgent']);
            $service->clearCache();
            self::assertSame('owned', file_get_contents($sentinel));
            self::assertSame($mtime, filemtime($sentinel));
            self::assertCount(1, glob($path . '*.cache') ?: []);
        } finally {
            @unlink($sentinel);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function detectWithNewService(string $userAgent): array
    {
        return (new DeviceDetectionService())->detectDevice($userAgent);
    }
}
