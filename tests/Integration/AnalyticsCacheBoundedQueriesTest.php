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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\jobs\CacheWarmJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for analytics queries that feed search caching and cache warming.
 */
final class AnalyticsCacheBoundedQueriesTest extends TestCase
{
    private const TEST_SITE_ID = 999997;
    private const TEST_BACKEND = 'test-analytics-cache-bounded';
    private const TEST_INDEX = 'test-cache-warm-bounded';

    private bool $originalEnableCache = true;
    private bool $originalPopularOnly = false;
    private int $originalPopularThreshold = 5;
    private int $originalAnalyticsRetention = 90;
    private ?string $indexHandle = null;
    private ?object $originalRequest = null;

    /** @var list<string> */
    private array $testQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = Craft::$app->getRequest();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = $settings->enableCache;
        $this->originalPopularOnly = $settings->cachePopularQueriesOnly;
        $this->originalPopularThreshold = $settings->popularQueryThreshold;
        $this->originalAnalyticsRetention = $settings->analyticsRetention;

        $settings->enableCache = true;
        $settings->cachePopularQueriesOnly = true;
        $settings->popularQueryThreshold = 3;
        $settings->analyticsRetention = 30;

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
        $settings->cachePopularQueriesOnly = $this->originalPopularOnly;
        $settings->popularQueryThreshold = $this->originalPopularThreshold;
        $settings->analyticsRetention = $this->originalAnalyticsRetention;

        if ($this->indexHandle !== null) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        $this->deleteTestAnalyticsRows();

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }

        parent::tearDown();
    }

    public function testPopularQueryProbeUsesBoundedThresholdLogic(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/BackendService.php');

        self::assertIsString($source);
        self::assertStringContainsString('private function _isQueryPopularForCache', $source);
        self::assertStringContainsString('->limit($rowsNeededBeforeCurrentSearch)', $source);
        self::assertStringContainsString("'normalizedQuery' => \$normalizedQuery", $source);
        self::assertStringContainsString("'indexHandle' => \$indexName", $source);
        self::assertStringContainsString("->andFilterWhere(['siteId' => \$siteId])", $source);
        self::assertStringContainsString('QueryNormalizer::forCacheIdentity($query)', $source);
        self::assertStringNotContainsString('private function _getQuerySearchCount', $source);
        self::assertStringNotContainsString('private function _normalizeQueryForCache', $source);

        $methodStart = strpos($source, 'private function _isQueryPopularForCache');
        self::assertIsInt($methodStart);
        $methodBody = substr($source, $methodStart, 1600);

        self::assertStringNotContainsString('->count(', $methodBody);
    }

    public function testPopularOnlyCacheWritesWhenPendingSearchMeetsThreshold(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery('popular');

        $this->seedAnalyticsRows($query, 2, $handle);

        $first = SearchManager::$plugin->backend->search($handle, $query, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertFalse($first['meta']['cached'], 'first threshold-crossing search still executes before writing cache');

        $second = SearchManager::$plugin->backend->search($handle, $query, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertTrue($second['meta']['cached'], 'query should be cached once stored rows plus pending search meet threshold');
    }

    public function testPopularOnlyCacheCountsMixedCaseRowsByNormalizedQuery(): void
    {
        $handle = $this->requireIndex();
        $baseQuery = $this->markerQuery('redirect twig');
        $displayQuery = str_replace('redirect twig', 'Redirect Twig', $baseQuery);
        $spacedQuery = str_replace('redirect twig', 'REDIRECT   TWIG', $baseQuery);
        $normalizedQuery = QueryNormalizer::forCacheIdentity($baseQuery);

        $this->seedAnalyticsRows($displayQuery, 1, $handle, null, $normalizedQuery);
        $this->seedAnalyticsRows($spacedQuery, 1, $handle, null, $normalizedQuery);

        $first = SearchManager::$plugin->backend->search($handle, $displayQuery, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertFalse($first['meta']['cached'], 'threshold-crossing search executes before the cache write');

        $second = SearchManager::$plugin->backend->search($handle, $spacedQuery, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertTrue($second['meta']['cached'], 'mixed-case historical rows should count through normalizedQuery');
    }

    public function testPopularOnlyCacheEligibilityIsScopedByIndexAndConcreteSite(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery('scoped');
        $normalizedQuery = QueryNormalizer::forCacheIdentity($query);

        $this->seedAnalyticsRows($query, 2, $handle . '-other', null, $normalizedQuery, self::TEST_SITE_ID);
        $this->seedAnalyticsRows($query, 2, $handle, null, $normalizedQuery, self::TEST_SITE_ID + 1);

        self::assertFalse(
            $this->isQueryPopularForCache($query, 3, $handle, self::TEST_SITE_ID),
            'rows from another index or another concrete site must not make this search popular',
        );

        $this->seedAnalyticsRows($query, 2, $handle, null, $normalizedQuery, self::TEST_SITE_ID);

        self::assertTrue(
            $this->isQueryPopularForCache($query, 3, $handle, self::TEST_SITE_ID),
            'rows from the same index and same concrete site should count with the pending search',
        );
    }

    public function testPopularOnlyCacheEligibilityForAllSitesIsScopedByIndexOnly(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery('all-sites');
        $normalizedQuery = QueryNormalizer::forCacheIdentity($query);

        $this->seedAnalyticsRows($query, 2, $handle . '-other', null, $normalizedQuery, self::TEST_SITE_ID);

        self::assertFalse($this->isQueryPopularForCache($query, 3, $handle, null));

        $this->seedAnalyticsRows($query, 1, $handle, null, $normalizedQuery, self::TEST_SITE_ID);
        $this->seedAnalyticsRows($query, 1, $handle, null, $normalizedQuery, self::TEST_SITE_ID + 1);

        self::assertTrue(
            $this->isQueryPopularForCache($query, 3, $handle, null),
            'all-sites searches should count same-index rows across sites',
        );
    }

    public function testAnalyticsTrackingPreservesDisplayQueryAndStoresNormalizedQuery(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery('Display   Case');

        Craft::$app->set('request', new \craft\web\Request());

        SearchManager::$plugin->analytics->trackSearch(
            $handle,
            $query,
            1,
            1.0,
            self::TEST_BACKEND,
            self::TEST_SITE_ID,
            ['source' => 'test'],
            null,
        );

        $row = (new \craft\db\Query())
            ->select(['query', 'normalizedQuery'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['backend' => self::TEST_BACKEND, 'siteId' => self::TEST_SITE_ID])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        self::assertIsArray($row);
        self::assertSame($query, $row['query']);
        self::assertSame(QueryNormalizer::forCacheIdentity($query), $row['normalizedQuery']);
    }

    public function testCaseAndWhitespaceVariantsShareNormalizedQuery(): void
    {
        self::assertSame(
            'redirect twig',
            QueryNormalizer::forCacheIdentity("  Redirect \n\t Twig  "),
        );
        self::assertSame(
            QueryNormalizer::forCacheIdentity('Redirect Twig'),
            QueryNormalizer::forCacheIdentity('redirect   twig'),
        );
    }

    public function testUnicodeSpaceVariantsShareNormalizedQuery(): void
    {
        $base = 'unicode space';

        self::assertSame($base, QueryNormalizer::forCacheIdentity("  Unicode\u{00A0}Space  "));
        self::assertSame($base, QueryNormalizer::forCacheIdentity("Unicode\u{3000}Space"));
        self::assertSame($base, QueryNormalizer::forCacheIdentity("Unicode\u{2003}\tSpace"));
    }

    public function testPopularOnlyCacheDoesNotWriteBelowThreshold(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery('unpopular');

        $this->seedAnalyticsRows($query, 1, $handle);

        $first = SearchManager::$plugin->backend->search($handle, $query, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertFalse($first['meta']['cached']);

        $second = SearchManager::$plugin->backend->search($handle, $query, [
            'limit' => 10,
            'skipAnalytics' => true,
        ]);
        self::assertFalse($second['meta']['cached'], 'below-threshold queries must not get a cache entry');
    }

    public function testCacheWarmPopularQueriesAreDateBounded(): void
    {
        $recentQuery = $this->markerQuery('recent-warm');
        $oldQuery = $this->markerQuery('old-warm');

        $this->seedAnalyticsRows($recentQuery, 1, self::TEST_INDEX, new \DateTime('-1 day'));
        $this->seedAnalyticsRows($oldQuery, 20, self::TEST_INDEX, new \DateTime('-60 days'));

        $method = new \ReflectionMethod(CacheWarmJob::class, 'getPopularQueries');
        $method->setAccessible(true);

        $results = $method->invoke(new CacheWarmJob(), self::TEST_INDEX, 1);

        self::assertCount(1, $results);
        self::assertSame($recentQuery, $results[0]['query']);
        self::assertSame(self::TEST_SITE_ID, (int)$results[0]['siteId']);
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

        if (!SearchManager::$plugin->getSettings()->enableCache) {
            $this->markTestSkipped('enableCache is overridden off (config), cannot test cache behaviour.');
        }

        if (!SearchManager::$plugin->getSettings()->cachePopularQueriesOnly) {
            $this->markTestSkipped('cachePopularQueriesOnly is overridden off (config), cannot test popular-only cache behaviour.');
        }

        return $this->indexHandle;
    }

    private function markerQuery(string $label): string
    {
        $query = '__sm_analytics_cache_' . $label . '_' . StringHelper::UUID();
        $this->testQueries[] = $query;

        return $query;
    }

    private function seedAnalyticsRows(
        string $query,
        int $count,
        string $indexHandle = self::TEST_INDEX,
        ?\DateTimeInterface $dateCreated = null,
        ?string $normalizedQuery = null,
        int $siteId = self::TEST_SITE_ID,
    ): void {
        $dateCreated ??= new \DateTime();
        $normalizedQuery ??= QueryNormalizer::forCacheIdentity($query);

        for ($i = 0; $i < $count; $i++) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
                'indexHandle' => $indexHandle,
                'query' => $query,
                'normalizedQuery' => $normalizedQuery,
                'resultsCount' => 1,
                'executionTime' => 1.0,
                'backend' => self::TEST_BACKEND,
                'siteId' => $siteId,
                'sessionId' => null,
                'isHit' => 1,
                'wasRedirected' => 0,
                'promotionsShown' => 0,
                'synonymsExpanded' => 0,
                'rulesMatched' => 0,
                'isRobot' => 0,
                'isMobileApp' => 0,
                'dateCreated' => Db::prepareDateForDb($dateCreated),
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    private function isQueryPopularForCache(string $query, int $threshold, string $indexHandle, ?int $siteId): bool
    {
        $method = new \ReflectionMethod(SearchManager::$plugin->backend, '_isQueryPopularForCache');
        $method->setAccessible(true);

        return (bool)$method->invoke(SearchManager::$plugin->backend, $query, $threshold, $indexHandle, $siteId);
    }

    private function deleteTestAnalyticsRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['backend' => self::TEST_BACKEND])
            ->execute();

        if ($this->testQueries === []) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['query' => $this->testQueries])
            ->execute();
    }
}
