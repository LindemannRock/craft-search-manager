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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\SearchManager;
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

    private function seedRow(string $query, \DateTimeInterface $dateCreated): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => $query,
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
