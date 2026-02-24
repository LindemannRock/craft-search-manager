<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services\analytics;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use lindemannrock\base\helpers\DateFormatHelper;

/**
 * Analytics Performance Service
 *
 * Execution time and cache metrics.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
class AnalyticsPerformanceService
{
    use AnalyticsQueryTrait;

    /**
     * Get average execution time over time for performance chart
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getPerformanceData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $localDate = DateFormatHelper::localDateExpression('dateCreated');

        $query = (new Query())
            ->select([
                'date' => $localDate,
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy($localDate)
            ->orderBy(['date' => SORT_ASC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return [
            'labels' => array_column($results, 'date'),
            'avgTime' => array_map(fn($r) => round((float)$r['avgTime'], 2), $results),
            'minTime' => array_map(fn($r) => round((float)$r['minTime'], 2), $results),
            'maxTime' => array_map(fn($r) => round((float)$r['maxTime'], 2), $results),
            'searches' => array_map(fn($r) => (int)$r['searches'], $results),
        ];
    }

    /**
     * Get cache hit statistics
     * Note: Cache hits are identified by executionTime = 0
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getCacheStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        // Total searches
        $totalQuery = (new Query())
            ->from('{{%searchmanager_analytics}}');

        $this->applyDateRangeFilter($totalQuery, $dateRange);

        if ($siteId) {
            $totalQuery->andWhere(['siteId' => $siteId]);
        }

        $total = (int)$totalQuery->count();

        // Cache hits (executionTime = 0)
        $cacheHitQuery = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['executionTime' => 0]);

        $this->applyDateRangeFilter($cacheHitQuery, $dateRange);

        if ($siteId) {
            $cacheHitQuery->andWhere(['siteId' => $siteId]);
        }

        $cacheHits = (int)$cacheHitQuery->count();
        $cacheMisses = $total - $cacheHits;

        return [
            'total' => $total,
            'cacheHits' => $cacheHits,
            'cacheMisses' => $cacheMisses,
            'hitRate' => $total > 0 ? round(($cacheHits / $total) * 100, 1) : 0,
            'missRate' => $total > 0 ? round(($cacheMisses / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get top performing queries (fastest response time)
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getTopPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'query',
                'siteId',
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
                'AVG(resultsCount) as avgResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['>', 'executionTime', 0]) // Exclude cache hits
            ->andWhere(['>', 'resultsCount', 0]) // Only queries with results
            ->groupBy(['query', 'siteId'])
            ->having(['>=', 'COUNT(*)', 3]) // At least 3 searches for reliable avg
            ->orderBy(['avgTime' => SORT_ASC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($r) {
            $siteName = null;
            if (!empty($r['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($r['siteId']);
                $siteName = $site ? $site->name : null;
            }
            return [
                'query' => $r['query'],
                'siteId' => $r['siteId'],
                'siteName' => $siteName,
                'avgTime' => round((float)$r['avgTime'], 2),
                'minTime' => round((float)$r['minTime'], 2),
                'maxTime' => round((float)$r['maxTime'], 2),
                'searches' => (int)$r['searches'],
                'avgResults' => round((float)$r['avgResults'], 1),
            ];
        }, $results);
    }

    /**
     * Get worst performing queries (slowest response time)
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getWorstPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'query',
                'siteId',
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
                'AVG(resultsCount) as avgResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['>', 'executionTime', 0]) // Exclude cache hits
            ->groupBy(['query', 'siteId'])
            ->having(['>=', 'COUNT(*)', 3]) // At least 3 searches for reliable avg
            ->orderBy(['avgTime' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($r) {
            $siteName = null;
            if (!empty($r['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($r['siteId']);
                $siteName = $site ? $site->name : null;
            }
            return [
                'query' => $r['query'],
                'siteId' => $r['siteId'],
                'siteName' => $siteName,
                'avgTime' => round((float)$r['avgTime'], 2),
                'minTime' => round((float)$r['minTime'], 2),
                'maxTime' => round((float)$r['maxTime'], 2),
                'searches' => (int)$r['searches'],
                'avgResults' => round((float)$r['avgResults'], 1),
            ];
        }, $results);
    }

    /**
     * Get average execution time
     *
     * @param int|array|null $siteId
     * @param int $days
     * @return float
     */
    public function getAverageExecutionTime(int|array|null $siteId, int $days = 30): float
    {
        $query = (new Query())
            ->select(['AVG(executionTime) as avgTime'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['executionTime' => null]])
            ->andWhere(['source' => 'frontend'])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $result = $query->scalar();
        return round((float)$result, 2);
    }
}
