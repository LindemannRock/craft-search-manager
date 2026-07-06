<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Read-side semantics for sessionId-based action dedup.
 *
 * Multi-index searches write one row per (search, index) into
 * `searchmanager_analytics`, all sharing a generated sessionId UUID.
 * Single-index searches write one row with sessionId NULL. Dashboards
 * that count "search actions" must collapse the multi-index fan-out
 * back to one action per sessionId while leaving NULL-session rows
 * intact.
 *
 * `AnalyticsExportService::getAnalyticsSummary()` is the first call
 * site converted to the deduped formula. This test pins both the
 * happy path (total + raw rows differ correctly) and the "all rows
 * in the action must be zero-result" edge case for the zero-result
 * action count.
 *
 * @since 5.46.0
 */
final class AnalyticsSessionDedupTest extends TestCase
{
    /**
     * Synthetic site ID for full test isolation. `searchmanager_analytics.siteId`
     * has no foreign key, so a value outside real Craft site IDs (1..N) lets the
     * test class assert absolute counts regardless of what real analytics rows
     * exist in the table. All seedRow() inserts use this; all service-method
     * calls in tests pass it as the siteId filter so only test rows match.
     */
    private const TEST_SITE_ID = 999999;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateAnalytics();
    }

    protected function tearDown(): void
    {
        $this->truncateAnalytics();
        parent::tearDown();
    }

    public function testTotalSearchesDedupesMultiIndexFanout(): void
    {
        $multiSession = StringHelper::UUID();
        $this->seedRow(null, isHit: 0); // Single-index, zero result.
        $this->seedRow($multiSession, isHit: 1); // Multi-index row 1.
        $this->seedRow($multiSession, isHit: 1); // Multi-index row 2.
        $this->seedRow($multiSession, isHit: 0); // Multi-index row 3.

        $rawRowCount = (int)(new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['siteId' => self::TEST_SITE_ID])
            ->count();
        $this->assertSame(4, $rawRowCount, 'Sanity: four rows seeded.');

        $summary = SearchManager::$plugin->analytics->getAnalyticsSummary(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(
            2,
            $summary['totalSearches'],
            'A 1-row single-index search plus a 3-row multi-index search is 2 actions, not 4 rows.',
        );
    }

    public function testZeroResultsCountsOnlyActionsWhereAllRowsAreZero(): void
    {
        $allZeroSession = StringHelper::UUID();
        $mixedSession = StringHelper::UUID();

        // Single-index zero-result action.
        $this->seedRow(null, isHit: 0);
        // Multi-index action with every row zero-result — counts as zero-result.
        $this->seedRow($allZeroSession, isHit: 0);
        $this->seedRow($allZeroSession, isHit: 0);
        $this->seedRow($allZeroSession, isHit: 0);
        // Multi-index action with one row hit — does NOT count as zero-result.
        $this->seedRow($mixedSession, isHit: 0);
        $this->seedRow($mixedSession, isHit: 1);
        $this->seedRow($mixedSession, isHit: 0);

        $summary = SearchManager::$plugin->analytics->getAnalyticsSummary(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(
            3,
            $summary['totalSearches'],
            'Three actions: single-index + two multi-index sessions.',
        );
        $this->assertSame(
            2,
            $summary['zeroResults'],
            'Mixed multi-index action is not zero-result; only the all-zero ones count.',
        );
    }

    public function testRedirectAndPromotionSuccessOutcomesExcludeFromZeroResults(): void
    {
        $redirectSession = StringHelper::UUID();
        $promotionSession = StringHelper::UUID();

        // Multi-index action that resolved via a redirect — not zero-result.
        $this->seedRow($redirectSession, isHit: 0, wasRedirected: 1);
        $this->seedRow($redirectSession, isHit: 0, wasRedirected: 1);
        // Multi-index action that showed a promotion — not zero-result.
        $this->seedRow($promotionSession, isHit: 0, promotionsShown: 1);
        $this->seedRow($promotionSession, isHit: 0, promotionsShown: 0);

        $summary = SearchManager::$plugin->analytics->getAnalyticsSummary(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(2, $summary['totalSearches']);
        $this->assertSame(
            0,
            $summary['zeroResults'],
            'Neither action is zero-result: one carries a redirect, the other a promotion impression.',
        );
        $this->assertSame(0.0, $summary['zeroResultsRate']);
    }

    public function testGetChartDataAggregatesActionsPerDayNotRows(): void
    {
        $allZero = StringHelper::UUID();
        $mixedHit = StringHelper::UUID();

        // 1 single-index zero-result action.
        $this->seedRow(null, isHit: 0);
        // 1 multi-index all-zero action (3 rows → 1 action).
        $this->seedRow($allZero, isHit: 0);
        $this->seedRow($allZero, isHit: 0);
        $this->seedRow($allZero, isHit: 0);
        // 1 multi-index with-results action (3 rows, one carrying a hit → 1 action).
        $this->seedRow($mixedHit, isHit: 0);
        $this->seedRow($mixedHit, isHit: 1);
        $this->seedRow($mixedHit, isHit: 0);

        $chart = SearchManager::$plugin->analytics->getChartData(self::TEST_SITE_ID, 'last7days');

        // normalizeDailyCounts fills the range with zero rows; find today's bucket.
        $today = (new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())))->format('Y-m-d');
        $todayBucket = null;
        foreach ($chart as $row) {
            if (($row['date'] ?? null) === $today) {
                $todayBucket = $row;
                break;
            }
        }

        $this->assertNotNull($todayBucket, 'normalizeDailyCounts should emit a bucket for today.');
        $this->assertSame(
            3,
            (int) $todayBucket['total'],
            'Three actions today, not seven raw rows.',
        );
        $this->assertSame(
            1,
            (int) $todayBucket['withResults'],
            'Only the mixed-hit multi-index action counts as with-results.',
        );
        $this->assertSame(
            2,
            (int) $todayBucket['zeroResults'],
            'Single-index zero + all-zero multi-index = 2 zero-result actions.',
        );
    }

    public function testMultiIndexSameQueryCountsAsOneActionInTopSearches(): void
    {
        $multi = StringHelper::UUID();
        $this->seedRow(null, isHit: 1, query: 'alpha');            // single-index action
        $this->seedRow($multi, isHit: 1, query: 'alpha');           // multi-index row 1
        $this->seedRow($multi, isHit: 1, query: 'alpha');           // multi-index row 2
        $this->seedRow($multi, isHit: 1, query: 'alpha');           // multi-index row 3

        $top = SearchManager::$plugin->analytics->getMostCommonSearches(self::TEST_SITE_ID, 10, 'last30days');

        $this->assertCount(1, $top, 'Single distinct query string.');
        $this->assertSame('alpha', $top[0]['query']);
        $this->assertSame(
            2,
            (int) $top[0]['count'],
            'Two actions: one single-index + one multi-index (collapsing 3 rows).',
        );
    }

    public function testQueryLengthBucketCountsActionOnceNotRows(): void
    {
        $oneWord = StringHelper::UUID();
        $multi = StringHelper::UUID();
        // Single-word query, multi-index — 3 rows = 1 action in "1 word" bucket.
        $this->seedRow($oneWord, query: 'apple');
        $this->seedRow($oneWord, query: 'apple');
        $this->seedRow($oneWord, query: 'apple');
        // Three-word query, multi-index — 3 rows = 1 action in "2-3 words" bucket.
        $this->seedRow($multi, query: 'red ripe apple');
        $this->seedRow($multi, query: 'red ripe apple');
        $this->seedRow($multi, query: 'red ripe apple');

        $dist = SearchManager::$plugin->analytics->getQueryLengthDistribution(self::TEST_SITE_ID, 'last30days');
        $buckets = array_combine($dist['labels'], $dist['values']);

        $this->assertSame([
            Craft::t('search-manager', '1 word'),
            Craft::t('search-manager', '2-3 words'),
            Craft::t('search-manager', '4+ words'),
        ], $dist['labels']);
        $this->assertSame(
            1,
            (int) $buckets[Craft::t('search-manager', '1 word')],
            'Multi-index single-word search = 1 action, not 3 rows.',
        );
        $this->assertSame(
            1,
            (int) $buckets[Craft::t('search-manager', '2-3 words')],
            'Multi-index 3-word search = 1 action, not 3 rows.',
        );
        $this->assertSame(0, (int) $buckets[Craft::t('search-manager', '4+ words')]);
    }

    public function testIntentBucketCountsActionOnceNotRows(): void
    {
        $questionSession = StringHelper::UUID();
        // Multi-index action whose query classifies as a question, 3 rows shared.
        $this->seedRow($questionSession, query: 'how to install', intent: 'question');
        $this->seedRow($questionSession, query: 'how to install', intent: 'question');
        $this->seedRow($questionSession, query: 'how to install', intent: 'question');

        $intent = SearchManager::$plugin->analytics->getIntentBreakdown(self::TEST_SITE_ID, 'last30days');
        $byIntent = array_combine($intent['labels'], $intent['values']);

        $this->assertSame(
            1,
            (int) $byIntent['question'],
            'Multi-index question search = 1 action in the question bucket, not 3 rows.',
        );
    }

    public function testDeviceBreakdownCountsActionOnceNotRows(): void
    {
        $multi = StringHelper::UUID();
        // Single-index mobile action.
        $this->seedRow(null, deviceType: 'smartphone');
        // Multi-index mobile action (3 rows → 1 action).
        $this->seedRow($multi, deviceType: 'smartphone');
        $this->seedRow($multi, deviceType: 'smartphone');
        $this->seedRow($multi, deviceType: 'smartphone');

        $devices = SearchManager::$plugin->analytics->getDeviceBreakdown(self::TEST_SITE_ID, 'last30days');
        $byDevice = [];
        foreach ($devices as $row) {
            $byDevice[$row['deviceType']] = (int) $row['count'];
        }

        $this->assertSame(
            2,
            $byDevice['smartphone'] ?? 0,
            'Two smartphone actions, not four raw rows. Dimension shared across action collapses correctly.',
        );
    }

    public function testBotStatsDedupesMultiIndexBotSessionToOneAction(): void
    {
        $botSession = StringHelper::UUID();
        $humanSession = StringHelper::UUID();
        // Multi-index bot session (3 rows, all isRobot=1) → 1 bot action.
        $this->seedRow($botSession, isRobot: 1);
        $this->seedRow($botSession, isRobot: 1);
        $this->seedRow($botSession, isRobot: 1);
        // Single-index human action.
        $this->seedRow(null, isRobot: 0);
        // Multi-index human action (2 rows, isRobot=0).
        $this->seedRow($humanSession, isRobot: 0);
        $this->seedRow($humanSession, isRobot: 0);

        $stats = SearchManager::$plugin->analytics->getBotStats(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(3, $stats['total'], 'Three actions total (1 bot + 2 human), not six raw rows.');
        $this->assertSame(1, $stats['bots'], 'One bot action, not three raw bot rows.');
        $this->assertSame(2, $stats['humans']);
        // 1/3 = 33.3%
        $this->assertEqualsWithDelta(33.3, $stats['botPercentage'], 0.1);
    }

    public function testCacheStatsExcludeIntentTrackingPings(): void
    {
        // One real backend execution that hit the cache.
        $this->seedRow(null, executionTime: 0.0);
        // One real backend execution that missed the cache.
        $this->seedRow(null, executionTime: 5.5);
        // Widget intent ping — Enter/click/idle tracker writes NULL executionTime
        // because no backend call happened at that moment. Must NOT be counted
        // as a cache miss; the user already saw the result.
        $this->seedRow(null, executionTime: null);

        $stats = SearchManager::$plugin->analytics->getCacheStats(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(2, $stats['total'], 'Total counts backend executions only — intent pings excluded.');
        $this->assertSame(1, $stats['cacheHits']);
        $this->assertSame(1, $stats['cacheMisses']);
        $this->assertSame(50.0, $stats['hitRate']);
        $this->assertSame(50.0, $stats['missRate']);
    }

    public function testZeroResultClustersExcludeMixedMultiIndexActions(): void
    {
        $allZero = StringHelper::UUID();
        $mixed = StringHelper::UUID();
        // Single-index zero-result query → included.
        $this->seedRow(null, isHit: 0, query: 'orphan term');
        // All-zero multi-index action → included.
        $this->seedRow($allZero, isHit: 0, query: 'lonely token');
        $this->seedRow($allZero, isHit: 0, query: 'lonely token');
        // Mixed-result multi-index action (one row had a hit) → excluded.
        $this->seedRow($mixed, isHit: 0, query: 'partial match');
        $this->seedRow($mixed, isHit: 1, query: 'partial match');
        $this->seedRow($mixed, isHit: 0, query: 'partial match');

        $clusters = SearchManager::$plugin->analytics->getZeroResultClusters(self::TEST_SITE_ID, 'last30days', 20);
        $queries = array_column($clusters, 'representative');

        $this->assertContains('orphan term', $queries, 'Single-index zero-result action is a content gap.');
        $this->assertContains('lonely token', $queries, 'All-zero multi-index action is a content gap.');
        $this->assertNotContains(
            'partial match',
            $queries,
            'Multi-index action where any row carried a hit is NOT a content gap.',
        );
    }

    /**
     * Insert one row into searchmanager_analytics with sensible defaults.
     * Tests override only the columns they care about.
     */
    private function seedRow(
        ?string $sessionId,
        int $isHit = 0,
        int $wasRedirected = 0,
        int $promotionsShown = 0,
        string $query = 'dedup-test',
        ?string $intent = null,
        ?string $deviceType = null,
        int $isRobot = 0,
        ?string $trafficType = null,
        ?float $executionTime = 1.0,
    ): void {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => $query,
            'normalizedQuery' => QueryNormalizer::forCacheIdentity($query),
            'resultsCount' => $isHit > 0 ? 1 : 0,
            'executionTime' => $executionTime,
            'backend' => 'test',
            'siteId' => self::TEST_SITE_ID,
            'sessionId' => $sessionId,
            'intent' => $intent,
            'deviceType' => $deviceType,
            'isHit' => $isHit,
            'wasRedirected' => $wasRedirected,
            'promotionsShown' => $promotionsShown,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => $isRobot,
            // Real detection always sets trafficType alongside isRobot (both come
            // from the same DeviceDetection pass), so getBotStats() classifies by
            // trafficType. Keep the seeded pair consistent; a bare isRobot=1 with
            // the column's 'human' default is a state production never writes.
            'trafficType' => $trafficType ?? ($isRobot === 1 ? 'bot' : 'human'),
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * Delete only rows this test class seeded — never touch real analytics data.
     *
     * Scoping by siteId = TEST_SITE_ID (a synthetic value outside the range of
     * real Craft site IDs) gives bulletproof isolation: no real search anywhere
     * can ever write to siteId 999999, so this delete only touches our seeds.
     * The `backend = 'test'` marker is belt-and-suspenders defence.
     */
    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete(
                '{{%searchmanager_analytics}}',
                ['siteId' => self::TEST_SITE_ID, 'backend' => 'test'],
            )
            ->execute();
    }
}
