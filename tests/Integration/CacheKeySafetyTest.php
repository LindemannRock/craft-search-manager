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
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\helpers\CacheKeyHelper;
use lindemannrock\searchmanager\interfaces\AutocompleteBackendInterface;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\caching\ArrayCache;

/**
 * Regression coverage for checked search/autocomplete cache identities.
 *
 * @since 5.54.0
 */
final class CacheKeySafetyTest extends TestCase
{
    private bool $originalEnableCache;
    private bool $originalEnableAutocompleteCache;
    private string $originalCacheStorageMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = $settings->enableCache;
        $this->originalEnableAutocompleteCache = $settings->enableAutocompleteCache;
        $this->originalCacheStorageMethod = $settings->cacheStorageMethod;
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = $this->originalEnableCache;
        $settings->enableAutocompleteCache = $this->originalEnableAutocompleteCache;
        $settings->cacheStorageMethod = $this->originalCacheStorageMethod;

        parent::tearDown();
    }

    public function testCheckedEncoderRejectsInvalidAndUnsupportedValuesWithoutCollisions(): void
    {
        $invalidUtf8 = "\xB1\x31";
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        $cyclic = [];
        $cyclic['self'] = &$cyclic;

        try {
            self::assertNull(CacheKeyHelper::generate(['value' => $invalidUtf8]));
            self::assertNull(CacheKeyHelper::generate(['value' => $resource]));
            self::assertNull(CacheKeyHelper::generate(['value' => $cyclic]));
            self::assertNull(CacheKeyHelper::generate(['value' => NAN]));
            self::assertNull(CacheKeyHelper::generate(['value' => new \stdClass()]));
        } finally {
            fclose($resource);
        }
    }

    public function testCheckedEncoderSortsMapsRecursivelyAndPreservesListOrder(): void
    {
        $first = CacheKeyHelper::generate([
            'options' => [
                'filters' => [
                    'section' => 'news',
                    'status' => 'live',
                ],
                'facets' => ['section', 'status'],
            ],
        ]);
        $equivalent = CacheKeyHelper::generate([
            'options' => [
                'facets' => ['section', 'status'],
                'filters' => [
                    'status' => 'live',
                    'section' => 'news',
                ],
            ],
        ]);
        $differentListOrder = CacheKeyHelper::generate([
            'options' => [
                'filters' => [
                    'status' => 'live',
                    'section' => 'news',
                ],
                'facets' => ['status', 'section'],
            ],
        ]);

        self::assertNotNull($first);
        self::assertSame($first, $equivalent);
        self::assertNotSame($first, $differentListOrder);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cacheStorageProvider(): array
    {
        return [
            'file' => ['file'],
            'redis' => ['redis'],
        ];
    }

    #[DataProvider('cacheStorageProvider')]
    public function testMissingKeysBypassEveryReadAndWriteConsumerForEachStorageMethod(string $storageMethod): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->cacheStorageMethod = $storageMethod;
        $invalidUtf8 = "\xB1\x31";
        $marker = str_replace('-', '', $this->nextTestMarker('cache-key-bypass', 'index'));
        $fullIndexName = $settings->getFullIndexName($marker);
        $searchPath = PluginHelper::getCachePath(SearchManager::$plugin, 'search') . $fullIndexName . '/';
        $autocompletePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete') . $fullIndexName . '/';
        $this->trackTempPath($searchPath);
        $this->trackTempPath($autocompletePath);

        $originalCache = Craft::$app->getCache();
        $cache = new CacheKeyCountingCache();
        Craft::$app->set('cache', $cache);

        try {
            $backend = new BackendService();
            $getSearch = new \ReflectionMethod($backend, '_getFromCache');
            $getSearch->setAccessible(true);
            $saveSearch = new \ReflectionMethod($backend, '_saveToCache');
            $saveSearch->setAccessible(true);

            self::assertNull($getSearch->invoke(
                $backend,
                $marker,
                'coffee',
                ['nested' => ['invalid' => $invalidUtf8]],
            ));
            $saveSearch->invoke(
                $backend,
                $marker,
                'coffee',
                ['nested' => ['invalid' => $invalidUtf8]],
                ['hits' => [], 'total' => 0],
            );

            $autocomplete = new AutocompleteService();
            $generateAutocomplete = new \ReflectionMethod($autocomplete, 'generateCacheKey');
            $generateAutocomplete->setAccessible(true);
            $autocompleteKey = $generateAutocomplete->invoke(
                $autocomplete,
                'suggest',
                $fullIndexName,
                $invalidUtf8,
                1,
                'en',
                10,
                true,
            );
            self::assertNull($autocompleteKey);

            $getAutocomplete = new \ReflectionMethod($autocomplete, 'getFromCache');
            $getAutocomplete->setAccessible(true);
            $saveAutocomplete = new \ReflectionMethod($autocomplete, 'saveToCache');
            $saveAutocomplete->setAccessible(true);
            self::assertNull($getAutocomplete->invoke($autocomplete, $autocompleteKey, $fullIndexName));
            $saveAutocomplete->invoke($autocomplete, $autocompleteKey, ['coffee'], $fullIndexName);
        } finally {
            Craft::$app->set('cache', $originalCache);
        }

        self::assertSame(0, $cache->getCalls);
        self::assertSame(0, $cache->setCalls);
        self::assertDirectoryDoesNotExist($searchPath);
        self::assertDirectoryDoesNotExist($autocompletePath);
    }

    public function testSearchExecutesWithoutReadingOrWritingCacheWhenIdentityIsUnsupported(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = true;
        $settings->cacheStorageMethod = 'file';
        $marker = str_replace('-', '', $this->nextTestMarker('cache-key-search', 'index'));
        $fullIndexName = $settings->getFullIndexName($marker);
        $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'search') . $fullIndexName . '/';
        $this->trackTempPath($cachePath);

        $backend = $this->createMock(BackendInterface::class);
        $backend->method('getName')->willReturn('cache-key-test');
        $backend->expects(self::exactly(2))
            ->method('search')
            ->willReturn(['hits' => [], 'total' => 0]);
        $service = new CacheKeyBackendService($backend);
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $options = [
                'skipAnalytics' => true,
                'nested' => ['resource' => $resource],
            ];
            $first = $service->search($marker, 'cache key safety', $options);
            $second = $service->search($marker, 'cache key safety', $options);
        } finally {
            fclose($resource);
        }

        self::assertFalse($first['meta']['cached']);
        self::assertFalse($second['meta']['cached']);
        self::assertDirectoryDoesNotExist($cachePath);
    }

    public function testAutocompleteExecutesWhenItsCacheIdentityCannotBeEncoded(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAutocompleteCache = true;
        $settings->cacheStorageMethod = 'file';
        $marker = str_replace('-', '', $this->nextTestMarker('cache-key-autocomplete', 'index'));
        $invalidIndexHandle = $marker . "\xB1";
        $fullIndexName = $settings->getFullIndexName($invalidIndexHandle);
        $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete') . $fullIndexName . '/';
        $this->trackTempPath($cachePath);

        $backend = $this->createMockForIntersectionOfInterfaces([
            BackendInterface::class,
            AutocompleteBackendInterface::class,
        ]);
        $backend->method('getName')->willReturn('cache-key-test');
        $backend->method('supportsAutocomplete')->willReturn(true);
        $backend->expects(self::once())
            ->method('autocomplete')
            ->willReturn(['safe-suggestion']);
        $this->swapPluginComponent(
            'search-manager',
            'backend',
            new CacheKeyBackendService($backend),
        );

        $result = SearchManager::$plugin->autocomplete->suggest('pro', $invalidIndexHandle, [
            'minLength' => 1,
            'includeMeta' => true,
        ]);

        self::assertSame(['safe-suggestion'], $result['suggestions'] ?? null);
        self::assertFalse($result['meta']['cached'] ?? true);
        self::assertDirectoryDoesNotExist($cachePath);
    }

    public function testNormalAutocompleteRequestsStillReuseFileCache(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAutocompleteCache = true;
        $settings->cacheStorageMethod = 'file';
        $marker = str_replace('-', '', $this->nextTestMarker('cache-key-reuse', 'index'));
        $fullIndexName = $settings->getFullIndexName($marker);
        $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete') . $fullIndexName . '/';
        $this->trackTempPath($cachePath);

        $backend = $this->createMockForIntersectionOfInterfaces([
            BackendInterface::class,
            AutocompleteBackendInterface::class,
        ]);
        $backend->method('getName')->willReturn('cache-key-test');
        $backend->method('supportsAutocomplete')->willReturn(true);
        $backend->expects(self::once())
            ->method('autocomplete')
            ->willReturn(['product']);
        $this->swapPluginComponent(
            'search-manager',
            'backend',
            new CacheKeyBackendService($backend),
        );

        $options = [
            'minLength' => 1,
            'includeMeta' => true,
            'siteId' => 1,
            'language' => 'en',
            'limit' => 5,
            'fuzzy' => false,
        ];
        $first = SearchManager::$plugin->autocomplete->suggest('pro', $marker, $options);
        $second = SearchManager::$plugin->autocomplete->suggest('pro', $marker, $options);

        self::assertSame(['product'], $first['suggestions'] ?? null);
        self::assertFalse($first['meta']['cached'] ?? true);
        self::assertSame(['product'], $second['suggestions'] ?? null);
        self::assertTrue($second['meta']['cached'] ?? false);
    }
}

/**
 * @since 5.54.0
 */
final class CacheKeyBackendService extends BackendService
{
    public function __construct(private readonly BackendInterface $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }
}

/**
 * @since 5.54.0
 */
final class CacheKeyCountingCache extends ArrayCache
{
    public int $getCalls = 0;
    public int $setCalls = 0;

    public function get($key): mixed
    {
        $this->getCalls++;

        return parent::get($key);
    }

    public function set($key, $value, $duration = null, $dependency = null): bool
    {
        $this->setCalls++;

        return parent::set($key, $value, $duration, $dependency);
    }
}
