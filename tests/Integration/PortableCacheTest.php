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
use lindemannrock\base\cache\CacheBackendStatus;
use lindemannrock\base\cache\ScopedCache;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\caching\ArrayCache;
use yii\caching\Cache;
use yii\caching\CacheInterface;
use yii\caching\DbCache;

require_once dirname(__DIR__) . '/Fixtures/CascadeCache.php';

/**
 * @since 5.55.0
 */
#[CoversClass(CacheStorageService::class)]
final class PortableCacheTest extends TestCase
{
    private CacheInterface $originalCache;
    private string $originalStorageMethod;
    private string $originalIndexPrefix;
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
        $this->originalIndexPrefix = $settings->indexPrefix;
        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = false;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('cache', $this->originalCache);
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = $this->originalStorageMethod;
        $settings->indexPrefix = $this->originalIndexPrefix;
        if ($this->hadEphemeralSetting) {
            $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
        } else {
            unset($_SERVER['CRAFT_EPHEMERAL']);
        }

        parent::tearDown();
    }

    public function testLegacyRedisAndForwardCompatibleCraftTokensUseApplicationCache(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        $service = new CacheStorageService();

        foreach (['redis', 'craft'] as $storageMethod) {
            SearchManager::$plugin->getSettings()->cacheStorageMethod = $storageMethod;
            self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
            self::assertTrue($service->write('search', 'prefix_content', $storageMethod, ['hits' => []], 73));
            $result = $service->read('search', 'prefix_content', $storageMethod, 73);
            self::assertTrue($result->isHit());
            self::assertSame(['hits' => []], $result->value);
        }

        self::assertContains(73, $cache->setDurations);
        self::assertSame('unknown', $service->getDriverLabel());
    }

    public function testSettingsModelAcceptsCraftWithoutExposingANewPublicChoice(): void
    {
        $settings = new Settings(['cacheStorageMethod' => 'craft']);
        self::assertTrue($settings->validate(['cacheStorageMethod']));

        $template = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/cache.twig');
        self::assertIsString($template);
        self::assertStringNotContainsString("value: 'craft'", $template);
    }

    public function testCascadeCacheIsAcceptedForRedisAndEphemeralFileWithoutInspectingItsPrimary(): void
    {
        $cache = new CascadeCache();
        Craft::$app->set('cache', $cache);
        $service = new CacheStorageService();

        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
        self::assertSame('managed', $service->getDriverLabel());
        self::assertTrue($service->write('autocomplete', 'prefix_news', 'identity', ['news'], 61));
        self::assertSame(['news'], $service->read('autocomplete', 'prefix_news', 'identity', 61)->value);

        $_SERVER['CRAFT_EPHEMERAL'] = true;
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'file';
        self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
        self::assertTrue($service->write('search', 'prefix_news', 'ephemeral', [], 47));
        self::assertTrue($service->read('search', 'prefix_news', 'ephemeral', 47)->isHit());
        if (property_exists($cache, 'setDurations')) {
            self::assertContains(47, $cache->setDurations);
        }
    }

    public function testDatabaseCompatibleCacheUsesApplicationCache(): void
    {
        $cache = new PortableDatabaseCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();

        self::assertSame(CacheBackendStatus::BACKEND_DATABASE, CacheBackendStatus::fromCache($cache)->backend);
        self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
        self::assertSame('database', $service->getDriverLabel());
        self::assertTrue($service->write('autocomplete', 'prefix_content', 'db-item', ['database'], 89));
        self::assertSame(['database'], $service->read('autocomplete', 'prefix_content', 'db-item', 89)->value);
    }

    public function testScopedFamiliesPreserveFalseyValuesAndInvalidateWithoutFlushing(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'craft';
        $service = new CacheStorageService();
        $sentinel = new ScopedCache($cache, 'unrelated-plugin', 'sentinel');
        self::assertTrue($sentinel->set('keep', 'safe', 300));

        self::assertTrue($service->write('search', 'prefix_a', 'empty', [], 37));
        self::assertTrue($service->write('search', 'prefix_b', 'empty', ['b'], 37));
        self::assertTrue($service->write('autocomplete', 'prefix_a', 'empty', ['a'], 41));
        self::assertTrue($service->read('search', 'prefix_a', 'empty', 37)->isHit());
        self::assertSame([], $service->read('search', 'prefix_a', 'empty', 37)->value);

        self::assertTrue($service->invalidateScope('search', 'prefix_a'));
        self::assertTrue($service->read('search', 'prefix_a', 'empty', 37)->isMiss());
        self::assertSame(['b'], $service->read('search', 'prefix_b', 'empty', 37)->value);
        self::assertSame(['a'], $service->read('autocomplete', 'prefix_a', 'empty', 41)->value);

        self::assertTrue($service->invalidateFamily('search'));
        self::assertTrue($service->read('search', 'prefix_b', 'empty', 37)->isMiss());
        self::assertSame(['a'], $service->read('autocomplete', 'prefix_a', 'empty', 41)->value);
        self::assertSame('safe', $sentinel->get('keep')->value);
        self::assertSame(0, $cache->flushCalls);
    }

    public function testNonPositiveDurationSkipsApplicationWrite(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();

        self::assertFalse($service->write('search', 'prefix_content', 'zero', [], 0));
        self::assertFalse($service->write('autocomplete', 'prefix_content', 'negative', [], -1));
        self::assertSame([], $cache->setDurations);
    }

    public function testApplicationCacheFailuresReturnFailureOrMissWithoutEscaping(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();
        $cache->throwGet = true;

        self::assertTrue($service->read('search', 'prefix_content', 'failure', 60)->isFailure());
        self::assertFalse($service->write('search', 'prefix_content', 'failure', [], 60));

        Craft::$app->set('cache', new ArrayCache());
        self::assertSame(CacheStorageService::STORAGE_DISABLED, $service->getEffectiveStorage());
        self::assertTrue($service->read('search', 'prefix_content', 'miss', 60)->isMiss());
        self::assertFalse($service->write('search', 'prefix_content', 'miss', [], 60));
    }

    public function testEphemeralFileWithUnsuitableCacheNeverChangesRuntimeCacheFiles(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = 'file';
        $_SERVER['CRAFT_EPHEMERAL'] = true;
        Craft::$app->set('cache', new ArrayCache());
        $service = new CacheStorageService();
        $scope = 'portable-ephemeral-file';
        $path = PluginHelper::getCachePath(SearchManager::$plugin, 'search') . $scope . DIRECTORY_SEPARATOR;
        FileHelper::createDirectory($path);
        $sentinel = $path . 'owned-sentinel.cache';
        file_put_contents($sentinel, 'owned');
        $mtime = filemtime($sentinel);

        try {
            self::assertSame(CacheStorageService::STORAGE_DISABLED, $service->getEffectiveStorage());
            self::assertTrue($service->read('search', $scope, 'owned-sentinel', 60)->isMiss());
            self::assertFalse($service->write('search', $scope, 'new-item', [], 60));
            self::assertTrue($service->invalidateScope('search', $scope));
            self::assertTrue($service->invalidateFamily('search'));
            self::assertSame(0, $service->countFiles('search'));
            self::assertSame('owned', file_get_contents($sentinel));
            self::assertSame($mtime, filemtime($sentinel));
            self::assertFileDoesNotExist($path . 'new-item.cache');
        } finally {
            FileHelper::removeDirectory($path);
        }
    }

    public function testNonEphemeralFileCachePreservesPathsJsonTtlAndClearBoundaries(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = 'file';
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $service = new CacheStorageService();
        $effectivePrefix = SearchManager::$plugin->getSettings()->indexPrefix ?? '';
        $firstScope = $service->getFullIndexName('alpha');
        $secondScope = $service->getFullIndexName($effectivePrefix . 'beta');
        self::assertSame($effectivePrefix . 'alpha', $firstScope);
        self::assertSame($effectivePrefix . 'beta', $secondScope);
        $root = PluginHelper::getCachePath(SearchManager::$plugin, 'search');

        try {
            self::assertSame(CacheStorageService::STORAGE_FILE, $service->getEffectiveStorage());
            self::assertTrue($service->write('search', $firstScope, 'first', ['hits' => []], 30));
            self::assertTrue($service->write('search', $secondScope, 'second', ['hits' => [2]], 30));
            self::assertSame(['hits' => []], $service->read('search', $firstScope, 'first', 30)->value);
            self::assertJsonStringEqualsJsonString(
                json_encode(['hits' => []], JSON_THROW_ON_ERROR),
                (string)file_get_contents($root . $firstScope . '/first.cache'),
            );
            self::assertSame(2, $service->countFiles('search'));

            touch($root . $firstScope . '/first.cache', time() - 31);
            self::assertTrue($service->read('search', $firstScope, 'first', 30)->isMiss());
            self::assertFileDoesNotExist($root . $firstScope . '/first.cache');
            self::assertSame(['hits' => [2]], $service->read('search', $secondScope, 'second', 30)->value);

            self::assertTrue($service->invalidateScope('search', $firstScope));
            self::assertSame(['hits' => [2]], $service->read('search', $secondScope, 'second', 30)->value);
            self::assertTrue($service->invalidateFamily('search'));
            self::assertTrue($service->read('search', $secondScope, 'second', 30)->isMiss());
        } finally {
            FileHelper::removeDirectory($root . $firstScope);
            FileHelper::removeDirectory($root . $secondScope);
        }
    }
}

/**
 * @since 5.55.0
 */
class PortablePersistentCache extends Cache
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<int> */
    public array $setDurations = [];

    public int $flushCalls = 0;
    public bool $throwGet = false;

    public function set($key, $value, $duration = null, $dependency = null)
    {
        $this->setDurations[] = (int)$duration;

        return parent::set($key, $value, $duration, $dependency);
    }

    protected function getValue($key)
    {
        if ($this->throwGet) {
            throw new \RuntimeException('Injected application-cache read failure.');
        }

        return $this->values[$key] ?? false;
    }

    protected function getValues($keys)
    {
        return array_map(fn(string $key): mixed => $this->getValue($key), $keys);
    }

    protected function setValue($key, $value, $duration)
    {
        if ($this->throwGet) {
            throw new \RuntimeException('Injected application-cache write failure.');
        }
        $this->values[$key] = $value;

        return true;
    }

    protected function setValues($data, $duration)
    {
        foreach ($data as $key => $value) {
            $this->setValue($key, $value, $duration);
        }

        return [];
    }

    protected function addValue($key, $value, $duration)
    {
        if ($this->throwGet) {
            throw new \RuntimeException('Injected application-cache add failure.');
        }
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    protected function deleteValue($key)
    {
        unset($this->values[$key]);

        return true;
    }

    protected function flushValues()
    {
        $this->flushCalls++;
        $this->values = [];

        return true;
    }
}

/**
 * @since 5.55.0
 */
final class PortableDatabaseCache extends DbCache
{
    /** @var array<string, mixed> */
    private array $values = [];

    protected function getValue($key)
    {
        return $this->values[$key] ?? false;
    }

    protected function getValues($keys)
    {
        return array_map(fn(string $key): mixed => $this->getValue($key), $keys);
    }

    protected function setValue($key, $value, $duration)
    {
        $this->values[$key] = $value;

        return true;
    }

    protected function setValues($data, $duration)
    {
        foreach ($data as $key => $value) {
            $this->values[$key] = $value;
        }

        return [];
    }

    protected function addValue($key, $value, $duration)
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    protected function deleteValue($key)
    {
        unset($this->values[$key]);

        return true;
    }

    protected function flushValues()
    {
        $this->values = [];

        return true;
    }
}
