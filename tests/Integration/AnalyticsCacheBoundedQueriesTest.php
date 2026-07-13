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
        $this->originalAnalyticsRetention = $settings->analyticsRetention;

        $settings->enableCache = true;
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
