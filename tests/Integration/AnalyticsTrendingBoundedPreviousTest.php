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
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\analytics\AnalyticsQueryInsightsService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for bounded previous-period trending analytics.
 */
final class AnalyticsTrendingBoundedPreviousTest extends TestCase
{
    private const TEST_SITE_ID = 999998;

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

    public function testTrendingQueriesLoadsPreviousCountsOnlyForCurrentQueries(): void
    {
        $now = new \DateTime();
        $previous = (clone $now)->modify('-8 days');

        $this->seedRow('current trend', $now);
        $this->seedRow('current trend', $previous);
        $this->seedRow('current trend', $previous);

        for ($i = 0; $i < 40; $i++) {
            $this->seedRow('previous only ' . $i, $previous);
        }

        $trending = SearchManager::$plugin->analytics->getTrendingQueries(self::TEST_SITE_ID, 'last7days', 10);

        self::assertCount(1, $trending);
        self::assertSame('current trend', $trending[0]['query']);
        self::assertSame(1, (int)$trending[0]['count']);
        self::assertSame(2, (int)$trending[0]['previousCount']);
        self::assertSame('down', $trending[0]['trend']);
    }

    public function testTrendingQueriesMergeCurrentAndPreviousCaseVariants(): void
    {
        $now = new \DateTime();
        $previous = (clone $now)->modify('-8 days');

        foreach (['Test', 'Test', 'test', 'test', 'test'] as $query) {
            $this->seedRow($query, $now);
        }
        foreach (['TEST', 'test', 'test'] as $query) {
            $this->seedRow($query, $previous);
        }

        $trending = SearchManager::$plugin->analytics->getTrendingQueries(self::TEST_SITE_ID, 'last7days', 10);

        self::assertCount(1, $trending);
        self::assertSame('test', $trending[0]['query']);
        self::assertSame(5, $trending[0]['count']);
        self::assertSame(3, $trending[0]['previousCount']);
        self::assertSame('up', $trending[0]['trend']);
        self::assertSame(67.0, $trending[0]['changePercent']);
    }

    public function testTrendingFoldUsesDeterministicRepresentativeOnCountTie(): void
    {
        $method = new \ReflectionMethod(AnalyticsQueryInsightsService::class, 'foldNormalizedQueryRows');
        $method->setAccessible(true);

        $expected = [[
            'query' => 'Case Variant',
            'normalizedQuery' => 'case variant',
            'count' => 4,
        ]];
        $rows = [
            ['query' => 'case Variant', 'normalizedQuery' => 'case variant', 'count' => 2],
            ['query' => 'Case Variant', 'normalizedQuery' => 'case variant', 'count' => 2],
        ];

        self::assertSame($expected, $method->invoke(null, $rows));
        self::assertSame($expected, $method->invoke(null, array_reverse($rows)));
    }

    public function testTrendingFoldPreservesTotalWhenRepresentativeChanges(): void
    {
        $method = new \ReflectionMethod(AnalyticsQueryInsightsService::class, 'foldNormalizedQueryRows');
        $method->setAccessible(true);

        self::assertSame([[
            'query' => 'test',
            'normalizedQuery' => 'test',
            'count' => 5,
        ]], $method->invoke(null, [
            ['query' => 'Test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'Test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'test', 'normalizedQuery' => 'test', 'count' => 1],
        ]));
    }

    public function testTrendingFoldIsIndependentOfInputOrder(): void
    {
        $method = new \ReflectionMethod(AnalyticsQueryInsightsService::class, 'foldNormalizedQueryRows');
        $method->setAccessible(true);

        $rows = [
            ['query' => 'test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'Test', 'normalizedQuery' => 'test', 'count' => 1],
            ['query' => 'test', 'normalizedQuery' => 'test', 'count' => 2],
            ['query' => 'Test', 'normalizedQuery' => 'test', 'count' => 1],
        ];
        $expected = [[
            'query' => 'test',
            'normalizedQuery' => 'test',
            'count' => 5,
        ]];

        self::assertSame($expected, $method->invoke(null, $rows));
        self::assertSame($expected, $method->invoke(null, array_reverse($rows)));
    }

    public function testGroupedQueryDisplaySurfacesDoNotGroupRawQueryText(): void
    {
        foreach ([
            'AnalyticsQueryInsightsService.php',
            'AnalyticsPerformanceService.php',
            'AnalyticsRulesService.php',
        ] as $filename) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/' . $filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/->groupBy\\(\\s*(?:[\'"]query[\'"]|\\[\\s*[\'"]query[\'"])/',
                $source,
                $filename,
            );
        }

        $queryInsights = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/AnalyticsQueryInsightsService.php');
        $performance = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/AnalyticsPerformanceService.php');
        $rules = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/AnalyticsRulesService.php');
        self::assertIsString($queryInsights);
        self::assertIsString($performance);
        self::assertIsString($rules);
        self::assertStringContainsString("->groupBy(['normalizedQuery', 'siteId'])", $queryInsights);
        self::assertStringContainsString("->groupBy(['normalizedQuery', 'siteId'])", $performance);
        self::assertStringContainsString("new Expression('LOWER([[query]])')", $rules);
    }

    private function seedRow(string $query, \DateTimeInterface $dateCreated): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => $query,
            'normalizedQuery' => QueryNormalizer::forCacheIdentity($query),
            'resultsCount' => 1,
            'executionTime' => 1.0,
            'backend' => 'test-trending-bounded',
            'siteId' => self::TEST_SITE_ID,
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

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete(
                '{{%searchmanager_analytics}}',
                ['siteId' => self::TEST_SITE_ID, 'backend' => 'test-trending-bounded'],
            )
            ->execute();
    }
}
