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
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\web\Request;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\tests\Support\OwnedAnalyticsTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\caching\ArrayCache;

/**
 * Local regression coverage for the backend search cache as used by public
 * API / widget searches (`/actions/search-manager/api/search` → BackendService).
 *
 * Anonymous API responses don't expose cache meta, so this asserts cache
 * create/reuse directly at the BackendService layer — exactly what
 * ApiController::actionSearch() calls. The widget and the direct API hit the
 * same method with the same cache-affecting options, so proving it here proves
 * it for both.
 *
 * Each test uses a nonsense marker query (matches no content, no query rule /
 * promotion / synonym). Caching is forced on for the duration and restored in
 * tearDown.
 *
 * @since 5.47.0
 */
final class SearchCacheReuseTest extends TestCase
{
    private bool $originalEnableCache = true;
    private ?string $indexHandle = null;
    private ?object $originalRequest = null;
    private ?OwnedAnalyticsTracker $analyticsTracker = null;

    /** @var list<string> */
    private array $testQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = Craft::$app->getRequest();
        $this->analyticsTracker = OwnedAnalyticsTracker::forQueryPrefix('__smcachetest_');

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = $settings->enableCache;
        $settings->enableCache = true;

        // A real enabled index with a working backend, via the shared helper.
        $pair = $this->findWorkingIndexAndElement();
        $this->indexHandle = $pair !== null ? $pair[0]->handle : $this->firstEnabledIndexHandle();

        if ($this->indexHandle !== null) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = $this->originalEnableCache;

        if ($this->indexHandle !== null) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }

        parent::tearDown();
    }

    protected function cleanupExternalState(): void
    {
        $this->analyticsTracker?->cleanupOwnedRows();
    }

    private function firstEnabledIndexHandle(): ?string
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled && SearchManager::$plugin->backend->getBackendForIndex($index->handle) !== null) {
                return $index->handle;
            }
        }

        return null;
    }

    private function requireIndex(): string
    {
        if ($this->indexHandle === null) {
            $this->markTestSkipped('No enabled index with a working backend available.');
        }

        // Sanity: caching must actually be enabled (no config override forcing it off).
        if (!SearchManager::$plugin->getSettings()->enableCache) {
            $this->markTestSkipped('enableCache is overridden off (config), cannot test cache behaviour.');
        }

        return $this->indexHandle;
    }

    private function markerQuery(): string
    {
        // Deterministic within a test, unique across runs; matches no real
        // content/rule/promotion so only cache behaviour is exercised.
        $query = '__smcachetest_' . StringHelper::UUID();
        $this->testQueries[] = $query;

        return $query;
    }

    private function search(string $handle, string $query, array $options): array
    {
        return SearchManager::$plugin->backend->search($handle, $query, $options + ['skipAnalytics' => true]);
    }

    // 1. Cache is written on first search and reused on the identical repeat.
    public function testIdenticalSearchCreatesThenReusesCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();
        $options = ['limit' => 10];

        $first = $this->search($handle, $query, $options);
        $this->assertFalse($first['meta']['cached'], 'first search must be a cache miss (and write the cache)');

        $second = $this->search($handle, $query, $options);
        $this->assertTrue($second['meta']['cached'], 'identical repeat search must hit the cache');
        $this->assertSame(0, $second['meta']['took'], 'cache hit reports took=0');
        $this->assertSame($first['total'], $second['total'], 'total unchanged on cache hit');
        $this->assertEquals($first['hits'], $second['hits'], 'hits unchanged on cache hit');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function skipAnalyticsProvider(): array
    {
        return [
            'records analytics' => [false],
            'skips analytics' => [true],
        ];
    }

    // 1b. Cache writes are independent of analytics recording.
    #[DataProvider('skipAnalyticsProvider')]
    public function testBrandNewQueryCachesOnFirstSearchRegardlessOfAnalyticsOptOut(bool $skipAnalytics): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();
        $options = [
            'limit' => 10,
            'skipAnalytics' => $skipAnalytics,
        ];

        if (!$skipAnalytics) {
            Craft::$app->set('request', new \craft\web\Request());
        }

        $first = SearchManager::$plugin->backend->search($handle, $query, $options);
        $this->assertFalse($first['meta']['cached'], 'first search must be a cache miss and write the cache');

        $second = SearchManager::$plugin->backend->search($handle, $query, $options);
        $this->assertTrue($second['meta']['cached'], 'second identical search must hit cache regardless of skipAnalytics');
        $this->assertSame(0, $second['meta']['took'], 'cache hit reports took=0');
    }

    public function testBackendServiceNoLongerContainsPopularCacheGate(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/BackendService.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('cachePopularQueriesOnly', $source);
        self::assertStringNotContainsString('popularQueryThreshold', $source);
        self::assertStringNotContainsString('_isQueryPopularForCache', $source);
        self::assertStringNotContainsString('Query not popular enough to cache', $source);
        self::assertStringContainsString('if ($settings->enableCache && !$backendFailed && !$includeQueryRuleDebug) {', $source);
        self::assertStringContainsString('$this->_saveToCache($indexName, $query, $options, $results);', $source);
    }

    public function testSettingsModelNoLongerExposesPopularCacheSettings(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/models/Settings.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('public bool $cachePopularQueriesOnly', $source);
        self::assertStringNotContainsString('public int $popularQueryThreshold', $source);
        self::assertStringNotContainsString("'cachePopularQueriesOnly'", $source);
        self::assertStringNotContainsString("'popularQueryThreshold'", $source);
        self::assertArrayNotHasKey('cachePopularQueriesOnly', SearchManager::$plugin->getSettings()->attributeLabels());
        self::assertArrayNotHasKey('popularQueryThreshold', SearchManager::$plugin->getSettings()->attributeLabels());
    }

    public function testInstallSchemaNoLongerCreatesPopularCacheColumns(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');

        self::assertIsString($source);
        self::assertStringNotContainsString("'cachePopularQueriesOnly'", $source);
        self::assertStringNotContainsString("'popularQueryThreshold'", $source);
        self::assertStringContainsString("'enableCache' => \$this->boolean()->notNull()->defaultValue(true)", $source);
        self::assertStringContainsString("'cacheDuration' => \$this->integer()->notNull()->defaultValue(3600)", $source);
        self::assertStringContainsString("'clearCacheOnSave' => \$this->boolean()->notNull()->defaultValue(true)", $source);
    }

    public function testCacheSettingsTemplateNoLongerRendersPopularCacheFields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/cache.twig');

        self::assertIsString($source);
        self::assertStringNotContainsString('cachePopularQueriesOnly', $source);
        self::assertStringNotContainsString('popularQueryThreshold', $source);
        self::assertStringNotContainsString('popular-query-threshold-settings', $source);
        self::assertStringNotContainsString('Cache Popular Queries Only', $source);
        self::assertStringContainsString("id: 'enableCache'", $source);
        self::assertStringContainsString("id: 'cacheDuration'", $source);
    }

    // 2a. Analytics / attribution-only options must NOT fragment the cache.
    public function testAnalyticsOnlyOptionsDoNotFragmentCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        $first = $this->search($handle, $query, [
            'limit' => 10,
            'source' => 'widget',
            'sessionId' => 'session-A',
        ]);
        $this->assertFalse($first['meta']['cached']);

        // Same result-affecting option (limit), different analytics/attribution
        // options → must reuse the same cache entry.
        $second = $this->search($handle, $query, [
            'limit' => 10,
            'source' => 'api',
            'sessionId' => 'session-B',
            'platform' => 'iOS 17.2',
            'appVersion' => '2.1.0',
            'apiKeyId' => 7,
            'apiKeyPrefix' => 'sm_pub_abcd1234',
            'apiKeyType' => 'public',
        ]);
        $this->assertTrue($second['meta']['cached'], 'analytics/attribution-only options must not fragment the cache');
    }

    // 2b. Result-affecting options MUST fragment the cache.
    public function testResultAffectingOptionsFragmentCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        $first = $this->search($handle, $query, ['limit' => 10]);
        $this->assertFalse($first['meta']['cached']);

        $differentLimit = $this->search($handle, $query, ['limit' => 25]);
        $this->assertFalse($differentLimit['meta']['cached'], 'changing limit must fragment the cache');

        $differentType = $this->search($handle, $query, ['limit' => 10, 'type' => 'entry']);
        $this->assertFalse($differentType['meta']['cached'], 'changing type must fragment the cache');
    }

    // 3. Widget-style option shape (resultsLimit=100 canonical search) creates + reuses cache.
    public function testWidgetStyleSearchCreatesAndReusesCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        // The backend option shape ApiController::actionSearch() builds for a
        // widget-style search. Snippet options (snippetMode, snippetMaxLength,
        // snippetCleanMarkdown, ...) are consumed by the canonical hit pipeline and
        // never reach backend->search(), so they cannot affect the cache key.
        $widgetOptions = [
            'limit' => 100,
            'offset' => 0,
            'page' => 0,
            'type' => null,
            'source' => 'header-search',
        ];

        $first = $this->search($handle, $query, $widgetOptions);
        $this->assertFalse($first['meta']['cached'], 'first widget-style search must be a cache miss');

        $second = $this->search($handle, $query, $widgetOptions);
        $this->assertTrue($second['meta']['cached'], 'repeated widget-style search must hit the cache');
        $this->assertSame(0, $second['meta']['took']);
    }

    // 4. Widget equivalence: the full attribution/analytics set the widget +
    //    ApiController attach must not fragment the cache.
    public function testWidgetAttributionOptionsDoNotFragmentCache(): void
    {
        // SearchService.js::performSearch() always sends skipAnalytics=1 and an
        // optional X-Search-Manager-Key; ApiController::actionSearch() folds those
        // plus source/platform/appVersion/sessionId/key attribution into the
        // analytics options, which BackendService::_generateCacheKey() excludes
        // (source, platform, appVersion, skipAnalytics, sessionId, apiKeyId,
        // apiKeyPrefix, apiKeyType). Result-affecting options (limit) held equal.
        $handle = $this->requireIndex();
        $query = $this->markerQuery();
        $base = ['limit' => 100];

        $first = $this->search($handle, $query, $base);
        $this->assertFalse($first['meta']['cached']);

        $withAttribution = $this->search($handle, $query, $base + [
            'source' => 'header-search',
            'platform' => 'Android 14',
            'appVersion' => '3.0.1',
            'sessionId' => StringHelper::UUID(),
            'apiKeyId' => 42,
            'apiKeyPrefix' => 'sm_pub_ffff0000',
            'apiKeyType' => 'public',
        ]);
        $this->assertTrue($withAttribution['meta']['cached'], 'widget attribution/analytics options must not fragment the cache');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localStorageFamilyProvider(): iterable
    {
        yield 'MySQL' => ['mysql'];
        yield 'PostgreSQL' => ['pgsql'];
        yield 'Redis' => ['redis'];
        yield 'File' => ['file'];
    }

    #[DataProvider('localStorageFamilyProvider')]
    public function testFailedLocalSearchIsNotCachedAndSuccessfulEmptyRetryIsReusable(string $family): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache) use ($family): void {
            $storage = $this->emptyFailureStorage();
            $storage->failNextTotal(1);
            $backend = new CacheFailureBackend($storage, $family);
            $service = $this->installCacheFailureBackend($backend);
            $handle = 'pr160-' . $family . '-empty';
            $query = 'pr160empty';
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->search($handle, $query, $options);

            self::assertSame([], $first['hits']);
            self::assertSame(0, $first['total']);
            self::assertFalse($first['meta']['cached']);
            self::assertNoPrivateFailureState($first);
            self::assertSame(0, $cache->searchCacheWriteCount());
            self::assertSame(1, $storage->totalCallsForSite(1));

            $second = $service->search($handle, $query, $options);

            self::assertSame([], $second['hits']);
            self::assertFalse($second['meta']['cached']);
            self::assertSame(1, $cache->searchCacheWriteCount());
            self::assertSame(2, $storage->totalCallsForSite(1));

            $third = $service->search($handle, $query, $options);

            self::assertTrue($third['meta']['cached']);
            self::assertSame(2, $storage->totalCallsForSite(1));
            self::assertSame(2, $backend->searchCalls);
        });
    }

    public function testAdvancedLocalSearchFailureIsNotCachedAndRemainsNondisclosed(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $storage = $this->emptyFailureStorage();
            $storage->failNextTotal(1);
            $backend = new CacheFailureBackend($storage, 'mysql');
            $service = $this->installCacheFailureBackend($backend);
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->search('pr160-advanced', 'pr160alpha OR pr160beta', $options);
            self::assertSame([], $first['hits']);
            self::assertNoPrivateFailureState($first);
            self::assertSame(0, $cache->searchCacheWriteCount());

            $second = $service->search('pr160-advanced', 'pr160alpha OR pr160beta', $options);
            self::assertFalse($second['meta']['cached']);
            self::assertSame(2, $storage->totalCallsForSite(1));
        });
    }

    public function testLocalBackendSetupFailureIsNotCachedAndNextRequestRetriesSetup(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $backend = new CacheFailureBackend($this->emptyFailureStorage(), 'file');
            $backend->setupFailuresRemaining = 1;
            $service = $this->installCacheFailureBackend($backend);
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->search('pr160-setup', 'pr160setup', $options);
            self::assertSame([], $first['hits']);
            self::assertNoPrivateFailureState($first);
            self::assertSame(0, $cache->searchCacheWriteCount());
            self::assertSame(1, $backend->storageCreateCalls);

            $second = $service->search('pr160-setup', 'pr160setup', $options);
            self::assertFalse($second['meta']['cached']);
            self::assertSame(2, $backend->storageCreateCalls);
        });
    }

    public function testSynonymFailureKeepsSuccessfulSiblingButPreventsAggregateCaching(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $storage = $this->hitFailureStorage('pr160success', 1, 101);
            $backend = new CacheFailureBackend($storage, 'pgsql');
            $backend->failQueries['pr160broken'] = true;
            $service = $this->installCacheFailureBackend($backend);
            $this->swapPluginComponent('search-manager', 'queryRules', new CacheFailureSynonymService());
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->search('pr160-synonym', 'pr160broken', $options);
            self::assertSame([101], array_column($first['hits'], 'elementId'));
            self::assertNoPrivateFailureState($first);
            self::assertSame(0, $cache->searchCacheWriteCount());

            $second = $service->search('pr160-synonym', 'pr160broken', $options);
            self::assertSame([101], array_column($second['hits'], 'elementId'));
            self::assertSame(4, $backend->searchCalls);
            self::assertSame(0, $cache->searchCacheWriteCount());
        });
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function hostedSynonymFailureOrderProvider(): iterable
    {
        yield 'failed child first' => [
            'pr160-hosted-failed-first',
            ['pr160-hosted-failed-first', 'pr160-hosted-success-first'],
        ];
        yield 'failed child last' => [
            'pr160-hosted-success-last',
            ['pr160-hosted-success-last', 'pr160-hosted-failed-last'],
        ];
    }

    /**
     * Hosted adapters log backend details and return only `_failed` privately.
     *
     * @param list<string> $expandedQueries
     */
    #[DataProvider('hostedSynonymFailureOrderProvider')]
    public function testHostedSynonymFailureIsStickyAcrossAllChildren(
        string $query,
        array $expandedQueries,
    ): void {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache) use ($query, $expandedQueries): void {
            $handle = $this->requireAnalyticsIndexHandle();
            $failedQuery = current(array_filter(
                $expandedQueries,
                static fn(string $expandedQuery): bool => str_contains($expandedQuery, '-failed-'),
            ));
            $successfulQuery = current(array_filter(
                $expandedQueries,
                static fn(string $expandedQuery): bool => str_contains($expandedQuery, '-success-'),
            ));
            self::assertIsString($failedQuery);
            self::assertIsString($successfulQuery);

            $backend = new HostedSynonymFailureBackend($failedQuery, $successfulQuery, $this->testSiteId());
            $service = new HostedSynonymFailureBackendService($backend);
            $this->swapPluginComponent('search-manager', 'backend', $service);
            $this->swapPluginComponent(
                'search-manager',
                'queryRules',
                new HostedSynonymFailureQueryRuleService($expandedQueries),
            );
            $settings = SearchManager::$plugin->getSettings();
            $originalEnableAnalytics = $settings->enableAnalytics;
            $originalEnableGeoDetection = $settings->enableGeoDetection;
            $settings->enableAnalytics = true;
            $settings->enableGeoDetection = false;
            $this->forcePluginEdition(SearchManager::EDITION_PRO);
            Craft::$app->set('request', new Request());
            $this->testQueries[] = $query;
            $sessionId = $query . '-session';
            $options = [
                'siteId' => $this->testSiteId(),
                'source' => 'pr160',
                'sessionId' => $sessionId,
            ];

            try {
                $first = $service->search($handle, $query, $options);
                $second = $service->search($handle, $query, $options);

                self::assertSame([606], array_column($first['hits'], 'elementId'));
                self::assertSame([606], array_column($second['hits'], 'elementId'));
                self::assertSame(1, $first['total']);
                self::assertSame(1, $second['total']);
                self::assertFalse($first['meta']['cached']);
                self::assertFalse($second['meta']['cached']);
                self::assertNoPrivateFailureState($first);
                self::assertNoPrivateFailureState($second);
                self::assertStringNotContainsString(
                    HostedSynonymFailureBackend::FAILURE_DETAIL,
                    json_encode([$first, $second], JSON_THROW_ON_ERROR),
                );
                self::assertSame(0, $cache->searchCacheWriteCount());
                self::assertSame(2, $backend->searchCallsByQuery[$failedQuery] ?? 0);
                self::assertSame(2, $backend->searchCallsByQuery[$successfulQuery] ?? 0);
                self::assertSame(
                    [HostedSynonymFailureBackend::FAILURE_DETAIL, HostedSynonymFailureBackend::FAILURE_DETAIL],
                    $backend->failureDetails,
                );

                $rows = (new Query())
                    ->from('{{%searchmanager_analytics}}')
                    ->where(['query' => $query])
                    ->orderBy(['id' => SORT_ASC])
                    ->all();
                self::assertCount(2, $rows);
                self::assertSame([1, 1], array_map(
                    static fn(array $row): int => (int)$row['resultsCount'],
                    $rows,
                ));
                self::assertSame([1, 1], array_map(
                    static fn(array $row): int => (int)$row['isHit'],
                    $rows,
                ));
                self::assertSame(['pr160', 'pr160'], array_column($rows, 'source'));
                self::assertSame([$sessionId, $sessionId], array_column($rows, 'sessionId'));
            } finally {
                $settings->enableAnalytics = $originalEnableAnalytics;
                $settings->enableGeoDetection = $originalEnableGeoDetection;
            }
        });
    }

    public function testMultiSiteFailureKeepsSuccessfulSiteAndRetriesFailedSite(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $storage = $this->hitFailureStorage('pr160site', 2, 202);
            $storage->failNextTotal(1, 2);
            $backend = new CacheFailureBackend($storage, 'redis');
            $service = $this->installCacheFailureBackend($backend);
            $options = ['siteId' => [1, 2], 'skipAnalytics' => true];

            $first = $service->search('pr160-sites', 'pr160site', $options);
            self::assertSame([202], array_column($first['hits'], 'elementId'));
            self::assertSame([2], array_column($first['hits'], 'siteId'));
            self::assertNoPrivateFailureState($first);
            self::assertSame(0, $cache->searchCacheWriteCount());

            $second = $service->search('pr160-sites', 'pr160site', $options);
            self::assertSame([202], array_column($second['hits'], 'elementId'));
            self::assertSame(2, $storage->totalCallsForSite(1));
            self::assertSame(2, $storage->totalCallsForSite(2));
            self::assertSame(0, $cache->searchCacheWriteCount());
        });
    }

    public function testMultiIndexFailureRetriesOnlyFailedChildAndDoesNotLeakState(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $failedStorage = $this->emptyFailureStorage();
            $failedStorage->failNextTotal(1, 2);
            $successfulStorage = $this->hitFailureStorage('pr160multi', 1, 303);
            $failedHandle = 'pr160-failed-index';
            $successfulHandle = 'pr160-success-index';
            $settings = SearchManager::$plugin->getSettings();
            $backend = new CacheFailureBackend($failedStorage, 'file', [
                $settings->getFullIndexName($failedHandle) => $failedStorage,
                $settings->getFullIndexName($successfulHandle) => $successfulStorage,
            ]);
            $service = $this->installCacheFailureBackend($backend);
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->searchMultiple([$failedHandle, $successfulHandle], 'pr160multi', $options);
            self::assertSame([303], array_column($first['hits'], 'elementId'));
            self::assertSame([$successfulHandle], array_column($first['hits'], '_index'));
            self::assertNoPrivateFailureState($first);
            self::assertSame(1, $cache->searchCacheWriteCount());

            $second = $service->searchMultiple([$failedHandle, $successfulHandle], 'pr160multi', $options);
            self::assertSame([303], array_column($second['hits'], 'elementId'));
            self::assertSame(2, $backend->searchCallsByIndex[$failedHandle] ?? 0);
            self::assertSame(1, $backend->searchCallsByIndex[$successfulHandle] ?? 0);
            self::assertSame(2, $failedStorage->totalCallsForSite(1));
            self::assertSame(1, $successfulStorage->totalCallsForSite(1));
            self::assertSame(1, $cache->searchCacheWriteCount());
        });
    }

    public function testFailedLocalSearchStillRecordsAnalyticsUnlessExplicitlySkipped(): void
    {
        $this->withIsolatedSearchCache(function(CacheWriteRecordingArrayCache $cache): void {
            $handle = $this->requireAnalyticsIndexHandle();
            $query = $this->markerQuery();
            $storage = $this->emptyFailureStorage();
            $storage->failNextTotal($this->testSiteId());
            $backend = new CacheFailureBackend($storage, 'mysql');
            $service = $this->installCacheFailureBackend($backend);
            $settings = SearchManager::$plugin->getSettings();
            $originalEnableAnalytics = $settings->enableAnalytics;
            $originalEnableGeoDetection = $settings->enableGeoDetection;
            $settings->enableAnalytics = true;
            $settings->enableGeoDetection = false;
            $this->forcePluginEdition(SearchManager::EDITION_PRO);
            Craft::$app->set('request', new Request());

            try {
                $results = $service->search($handle, $query, [
                    'siteId' => $this->testSiteId(),
                    'source' => 'pr160',
                    'sessionId' => 'pr160-session',
                ]);

                self::assertSame([], $results['hits']);
                self::assertSame(0, $cache->searchCacheWriteCount());
                $rows = (new Query())
                    ->from('{{%searchmanager_analytics}}')
                    ->where(['query' => $query])
                    ->all();
                self::assertCount(1, $rows);
                self::assertSame(0, (int)$rows[0]['resultsCount']);
                self::assertSame('pr160', $rows[0]['source']);
                self::assertSame('pr160-session', $rows[0]['sessionId']);
                self::assertSame(0, (int)$rows[0]['isHit']);
                self::assertSame(0, (int)$rows[0]['wasRedirected']);

                $service->search($handle, $query . '-skip', [
                    'siteId' => $this->testSiteId(),
                    'skipAnalytics' => true,
                ]);
                self::assertSame(
                    0,
                    (int)(new Query())
                        ->from('{{%searchmanager_analytics}}')
                        ->where(['query' => $query . '-skip'])
                        ->count(),
                );
            } finally {
                $settings->enableAnalytics = $originalEnableAnalytics;
                $settings->enableGeoDetection = $originalEnableGeoDetection;
            }
        });
    }

    public function testDirectLocalBackendFailureKeepsExactPublicEmptyShape(): void
    {
        $storage = $this->emptyFailureStorage();
        $storage->failNextTotal(1);
        $backend = new CacheFailureBackend($storage, 'mysql');

        self::assertSame(
            ['hits' => [], 'total' => 0, 'searchDebug' => ['relaxedMatching' => false, 'resolvedTerms' => []]],
            $backend->search('pr160-direct', 'pr160direct', ['siteId' => 1]),
        );
    }

    private function emptyFailureStorage(): CacheFailureRecordingStorage
    {
        return new CacheFailureRecordingStorage([], [], [], 0, 0.0);
    }

    private function hitFailureStorage(string $term, int $siteId, int $elementId): CacheFailureRecordingStorage
    {
        return new CacheFailureRecordingStorage(
            termDocs: [$term => [$siteId . ':' . $elementId => 1]],
            titleByElement: [$elementId => [$term]],
            docLengths: [$siteId . ':' . $elementId => 1],
            totalDocs: 1,
            avgDocLength: 1.0,
            elementsById: [
                $elementId => [
                    'title' => 'PR1.60 hit ' . $elementId,
                    'elementType' => 'entry',
                    'documentData' => [
                        'title' => 'PR1.60 hit ' . $elementId,
                        'url' => '/pr160/' . $elementId,
                    ],
                ],
            ],
        );
    }

    private function installCacheFailureBackend(CacheFailureBackend $backend): CacheFailureBackendService
    {
        $service = new CacheFailureBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);

        return $service;
    }

    /**
     * @param callable(CacheWriteRecordingArrayCache): void $callback
     */
    private function withIsolatedSearchCache(callable $callback): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalStorageMethod = $settings->cacheStorageMethod;
        $originalIndexPrefix = $settings->indexPrefix;
        $originalCache = Craft::$app->getCache();
        $cache = new CacheWriteRecordingArrayCache();
        $settings->enableCache = true;
        $settings->cacheStorageMethod = 'redis';
        $settings->indexPrefix = 'fs3_';
        Craft::$app->set('cache', $cache);

        try {
            $callback($cache);
        } finally {
            Craft::$app->set('cache', $originalCache);
            $settings->cacheStorageMethod = $originalStorageMethod;
            $settings->indexPrefix = $originalIndexPrefix;
        }
    }

    private function requireAnalyticsIndexHandle(): string
    {
        foreach (SearchIndex::findAll() as $index) {
            if (
                $index->enabled
                && $index->enableAnalytics
                && SearchManager::$plugin->dependencies->isIndexAvailable($index->handle)
            ) {
                return $index->handle;
            }
        }

        self::markTestSkipped('No enabled analytics index is available for PR1.60 coverage.');
    }

    private function testSiteId(): int
    {
        return (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function assertNoPrivateFailureState(array $results): void
    {
        self::assertStringNotContainsString('_failed', json_encode($results, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('failureReporter', json_encode($results, JSON_THROW_ON_ERROR));
    }
}

/**
 * @since 5.54.0
 */
final class CacheWriteRecordingArrayCache extends ArrayCache
{
    /** @var list<string> */
    public array $setKeys = [];

    public function set($key, $value, $duration = null, $dependency = null)
    {
        $this->setKeys[] = (string)$key;

        return parent::set($key, $value, $duration, $dependency);
    }

    public function searchCacheWriteCount(): int
    {
        $prefix = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'search');

        return count(array_filter(
            $this->setKeys,
            static fn(string $key): bool => str_starts_with($key, $prefix),
        ));
    }
}

/**
 * @since 5.54.0
 */
final class CacheFailureRecordingStorage extends RecordingStorage
{
    /** @var array<int, int> */
    private array $totalCallsBySite = [];

    /** @var array<int, int> */
    private array $totalFailuresBySite = [];

    public function failNextTotal(int $siteId, int $times = 1): void
    {
        $this->totalFailuresBySite[$siteId] = ($this->totalFailuresBySite[$siteId] ?? 0) + $times;
    }

    public function totalCallsForSite(int $siteId): int
    {
        return $this->totalCallsBySite[$siteId] ?? 0;
    }

    public function getTotalDocCount(int $siteId): int
    {
        $this->totalCallsBySite[$siteId] = ($this->totalCallsBySite[$siteId] ?? 0) + 1;
        if (($this->totalFailuresBySite[$siteId] ?? 0) > 0) {
            $this->totalFailuresBySite[$siteId]--;
            throw new \RuntimeException('Deterministic local storage read failure.');
        }

        return parent::getTotalDocCount($siteId);
    }
}

/**
 * @since 5.54.0
 */
final class CacheFailureBackend extends AbstractSearchEngineBackend
{
    public int $searchCalls = 0;
    public int $storageCreateCalls = 0;
    public int $setupFailuresRemaining = 0;

    /** @var array<string, int> */
    public array $searchCallsByIndex = [];

    /** @var array<string, bool> */
    public array $failQueries = [];

    /**
     * @param array<string, StorageInterface> $storagesByFullIndex
     */
    public function __construct(
        private readonly CacheFailureRecordingStorage $defaultStorage,
        private readonly string $family,
        private readonly array $storagesByFullIndex = [],
    ) {
        parent::__construct();
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;
        $this->searchCallsByIndex[$indexName] = ($this->searchCallsByIndex[$indexName] ?? 0) + 1;
        if (!empty($this->failQueries[$query])) {
            $siteId = is_int($options['siteId'] ?? null) ? $options['siteId'] : 1;
            $this->defaultStorage->failNextTotal($siteId);
        }

        return parent::search($indexName, $query, $options);
    }

    protected function createStorage(string $fullIndexName): StorageInterface
    {
        $this->storageCreateCalls++;
        if ($this->setupFailuresRemaining > 0) {
            $this->setupFailuresRemaining--;
            throw new \RuntimeException('Deterministic local backend setup failure.');
        }

        return $this->storagesByFullIndex[$fullIndexName] ?? $this->defaultStorage;
    }

    protected function getBackendLabel(): string
    {
        return strtoupper($this->family);
    }

    public function getName(): string
    {
        return $this->family;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }
}

/**
 * @since 5.54.0
 */
final class CacheFailureBackendService extends BackendService
{
    public function __construct(private readonly CacheFailureBackend $backend)
    {
        parent::__construct();
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->backend;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }
}

/**
 * @since 5.54.0
 */
final class CacheFailureSynonymService extends QueryRuleService
{
    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return [];
    }

    public function expandWithSynonyms(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): array {
        return [$query, 'pr160success'];
    }
}

/**
 * Deterministic hosted-style backend: failure details stay private while the
 * supported hosted `_failed` result marker reaches BackendService.
 *
 * @since 5.54.0
 */
final class HostedSynonymFailureBackend extends BaseBackend
{
    public const FAILURE_DETAIL = 'Deterministic hosted synonym failure detail.';

    /** @var array<string, int> */
    public array $searchCallsByQuery = [];

    /** @var list<string> */
    public array $failureDetails = [];

    public function __construct(
        private readonly string $failedQuery,
        private readonly string $successfulQuery,
        private readonly int $siteId,
    ) {
        parent::__construct();
    }

    public function index(string $indexName, array $data): bool
    {
        return false;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return false;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCallsByQuery[$query] = ($this->searchCallsByQuery[$query] ?? 0) + 1;

        if ($query === $this->failedQuery) {
            $this->failureDetails[] = self::FAILURE_DETAIL;

            return ['hits' => [], 'total' => 0, '_failed' => true];
        }

        if ($query === $this->successfulQuery) {
            return [
                'hits' => [[
                    'elementId' => 606,
                    'siteId' => $this->siteId,
                    'title' => 'Hosted synonym sibling',
                    'score' => 6.0,
                ]],
                'total' => 1,
            ];
        }

        return ['hits' => [], 'total' => 0];
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }

    public function getName(): string
    {
        return 'hosted-synonym-test';
    }
}

/**
 * @since 5.54.0
 */
final class HostedSynonymFailureBackendService extends BackendService
{
    public function __construct(private readonly HostedSynonymFailureBackend $backend)
    {
        parent::__construct();
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->backend;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }
}

/**
 * @since 5.54.0
 */
final class HostedSynonymFailureQueryRuleService extends QueryRuleService
{
    /**
     * @param list<string> $expandedQueries
     */
    public function __construct(private readonly array $expandedQueries)
    {
        parent::__construct();
    }

    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return [];
    }

    public function expandWithSynonyms(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): array {
        return $this->expandedQueries;
    }
}
