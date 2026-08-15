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
            $identity = self::identity($storageMethod);
            SearchManager::$plugin->getSettings()->cacheStorageMethod = $storageMethod;
            self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
            self::assertTrue($service->write('search', 'prefix_content', $identity, ['hits' => []], 73));
            $result = $service->read('search', 'prefix_content', $identity, 73);
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
        $autocompleteIdentity = self::identity('identity');
        self::assertTrue($service->write('autocomplete', 'prefix_news', $autocompleteIdentity, ['news'], 61));
        self::assertSame(['news'], $service->read('autocomplete', 'prefix_news', $autocompleteIdentity, 61)->value);

        $_SERVER['CRAFT_EPHEMERAL'] = true;
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'file';
        self::assertSame(CacheStorageService::STORAGE_APPLICATION, $service->getEffectiveStorage());
        $searchIdentity = self::identity('ephemeral');
        self::assertTrue($service->write('search', 'prefix_news', $searchIdentity, [], 47));
        self::assertTrue($service->read('search', 'prefix_news', $searchIdentity, 47)->isHit());
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
        $identity = self::identity('db-item');
        self::assertTrue($service->write('autocomplete', 'prefix_content', $identity, ['database'], 89));
        self::assertSame(['database'], $service->read('autocomplete', 'prefix_content', $identity, 89)->value);
    }

    public function testScopedFamiliesPreserveFalseyValuesAndInvalidateWithoutFlushing(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'craft';
        $service = new CacheStorageService();
        $sentinel = new ScopedCache($cache, 'unrelated-plugin', 'sentinel');
        $identity = self::identity('empty');
        self::assertTrue($sentinel->set('keep', 'safe', 300));

        self::assertTrue($service->write('search', 'prefix_a', $identity, [], 37));
        self::assertTrue($service->write('search', 'prefix_b', $identity, ['b'], 37));
        self::assertTrue($service->write('autocomplete', 'prefix_a', $identity, ['a'], 41));
        self::assertTrue($service->read('search', 'prefix_a', $identity, 37)->isHit());
        self::assertSame([], $service->read('search', 'prefix_a', $identity, 37)->value);

        self::assertTrue($service->invalidateScope('search', 'prefix_a'));
        self::assertTrue($service->read('search', 'prefix_a', $identity, 37)->isMiss());
        self::assertSame(['b'], $service->read('search', 'prefix_b', $identity, 37)->value);
        self::assertSame(['a'], $service->read('autocomplete', 'prefix_a', $identity, 41)->value);

        self::assertTrue($service->invalidateFamily('search'));
        self::assertTrue($service->read('search', 'prefix_b', $identity, 37)->isMiss());
        self::assertSame(['a'], $service->read('autocomplete', 'prefix_a', $identity, 41)->value);
        self::assertSame('safe', $sentinel->get('keep')->value);
        self::assertSame(0, $cache->flushCalls);
    }

    public function testNonPositiveDurationSkipsApplicationWrite(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();

        self::assertFalse($service->write('search', 'prefix_content', self::identity('zero'), [], 0));
        self::assertFalse($service->write('autocomplete', 'prefix_content', self::identity('negative'), [], -1));
        self::assertSame([], $cache->setDurations);
    }

    public function testApplicationCacheFailuresReturnFailureOrMissWithoutEscaping(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();
        $cache->throwGet = true;
        $failureIdentity = self::identity('failure');

        self::assertTrue($service->read('search', 'prefix_content', $failureIdentity, 60)->isFailure());
        self::assertFalse($service->write('search', 'prefix_content', $failureIdentity, [], 60));

        Craft::$app->set('cache', new ArrayCache());
        self::assertSame(CacheStorageService::STORAGE_DISABLED, $service->getEffectiveStorage());
        $missIdentity = self::identity('miss');
        self::assertTrue($service->read('search', 'prefix_content', $missIdentity, 60)->isMiss());
        self::assertFalse($service->write('search', 'prefix_content', $missIdentity, [], 60));
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
        $sentinelIdentity = self::identity('owned-sentinel');
        $newIdentity = self::identity('new-item');
        FileHelper::createDirectory($path);
        $sentinel = $path . $sentinelIdentity . '.cache';
        file_put_contents($sentinel, 'owned');
        $mtime = filemtime($sentinel);

        try {
            self::assertSame(CacheStorageService::STORAGE_DISABLED, $service->getEffectiveStorage());
            self::assertTrue($service->read('search', $scope, $sentinelIdentity, 60)->isMiss());
            self::assertFalse($service->write('search', $scope, $newIdentity, [], 60));
            self::assertTrue($service->invalidateScope('search', $scope));
            self::assertTrue($service->invalidateFamily('search'));
            self::assertSame(0, $service->countFiles('search'));
            self::assertSame('owned', file_get_contents($sentinel));
            self::assertSame($mtime, filemtime($sentinel));
            self::assertFileDoesNotExist($path . $newIdentity . '.cache');
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
        $effectivePrefix = $settings->indexPrefix ?? '';
        $firstScope = $settings->getFullIndexName('alpha');
        $secondScope = $settings->getFullIndexName($effectivePrefix . 'beta');
        $firstIdentity = self::identity('first');
        $secondIdentity = self::identity('second');
        self::assertSame($effectivePrefix . 'alpha', $firstScope);
        self::assertSame($effectivePrefix . $effectivePrefix . 'beta', $secondScope);
        $root = PluginHelper::getCachePath(SearchManager::$plugin, 'search');

        try {
            self::assertSame(CacheStorageService::STORAGE_FILE, $service->getEffectiveStorage());
            self::assertTrue($service->write('search', $firstScope, $firstIdentity, ['hits' => []], 30));
            self::assertTrue($service->write('search', $secondScope, $secondIdentity, ['hits' => [2]], 30));
            self::assertSame(['hits' => []], $service->read('search', $firstScope, $firstIdentity, 30)->value);
            self::assertJsonStringEqualsJsonString(
                json_encode(['hits' => []], JSON_THROW_ON_ERROR),
                (string)file_get_contents($root . $firstScope . '/' . $firstIdentity . '.cache'),
            );
            self::assertSame(2, $service->countFiles('search'));

            touch($root . $firstScope . '/' . $firstIdentity . '.cache', time() - 31);
            self::assertTrue($service->read('search', $firstScope, $firstIdentity, 30)->isMiss());
            self::assertFileDoesNotExist($root . $firstScope . '/' . $firstIdentity . '.cache');
            self::assertSame(['hits' => [2]], $service->read('search', $secondScope, $secondIdentity, 30)->value);

            self::assertTrue($service->invalidateScope('search', $firstScope));
            self::assertSame(['hits' => [2]], $service->read('search', $secondScope, $secondIdentity, 30)->value);
            self::assertTrue($service->invalidateFamily('search'));
            self::assertTrue($service->read('search', $secondScope, $secondIdentity, 30)->isMiss());
        } finally {
            FileHelper::removeDirectory($root . $firstScope);
            FileHelper::removeDirectory($root . $secondScope);
        }
    }

    public function testFileBoundaryRejectsTraversalAndMalformedOwnershipWithoutChangingASentinel(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = 'file';
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $service = new CacheStorageService();
        $searchRoot = PluginHelper::getCachePath(SearchManager::$plugin, 'search');
        $cacheRoot = dirname(rtrim($searchRoot, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR;
        FileHelper::createDirectory($cacheRoot);
        $sentinel = $cacheRoot . 'portable-boundary-sentinel.cache';
        file_put_contents($sentinel, 'owned');
        $mtime = filemtime($sentinel);
        $validIdentity = self::identity('valid');

        try {
            $this->assertInvalidArgument(
                static fn() => $service->write('../outside', 'scope', $validIdentity, [], 60),
            );
            $this->assertInvalidArgument(
                static fn() => $service->write('search', 'scope', '../../sentinel', [], 60),
            );
            $this->assertInvalidArgument(
                static fn() => $service->countFiles('search/../../outside'),
            );
            self::assertTrue($service->read('search', '../outside', $validIdentity, 60)->isFailure());
            self::assertFalse($service->write('search', '../outside', $validIdentity, [], 60));
            self::assertFalse($service->invalidateScope('search', '../outside'));
            self::assertSame('owned', file_get_contents($sentinel));
            self::assertSame($mtime, filemtime($sentinel));
        } finally {
            @unlink($sentinel);
        }
    }

    public function testApplicationBoundaryRejectsUnsupportedFamiliesBeforeCacheOperations(): void
    {
        $cache = new PortablePersistentCache();
        Craft::$app->set('cache', $cache);
        SearchManager::$plugin->getSettings()->cacheStorageMethod = 'redis';
        $service = new CacheStorageService();
        $identity = self::identity('valid');

        $this->assertInvalidArgument(
            static fn() => $service->read('outside', 'scope', $identity, 60),
        );
        $this->assertInvalidArgument(
            static fn() => $service->write('search', 'scope', strtoupper($identity), [], 60),
        );
        $this->assertInvalidArgument(
            static fn() => $service->invalidateFamily('search/../outside'),
        );
        self::assertSame([], $cache->setDurations);
        self::assertSame(0, $cache->flushCalls);
    }

    private static function identity(string $seed): string
    {
        return md5($seed);
    }

    private function assertInvalidArgument(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected invalid cache ownership input to be rejected.');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
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
