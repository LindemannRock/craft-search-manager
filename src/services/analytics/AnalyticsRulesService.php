<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services\analytics;

use craft\db\Query;
use lindemannrock\base\helpers\DateFormatHelper;

/**
 * Analytics Rules Service
 *
 * Query rules and promotion analytics.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since 5.39.0
 */
class AnalyticsRulesService
{
    use AnalyticsQueryTrait;

    /**
     * Get top triggered query rules
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getTopTriggeredRules(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'queryRuleId',
                'ruleName',
                'actionType',
                'COUNT(*) as hits',
                'AVG(resultsCount) as avgResults',
            ])
            ->from('{{%searchmanager_rule_analytics}}')
            ->groupBy(['queryRuleId', 'ruleName', 'actionType'])
            ->orderBy(['hits' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($row) {
            return [
                'queryRuleId' => (int)$row['queryRuleId'],
                'ruleName' => $row['ruleName'],
                'actionType' => $row['actionType'],
                'hits' => (int)$row['hits'],
                'avgResults' => round((float)$row['avgResults'], 1),
            ];
        }, $results);
    }

    /**
     * Get rules breakdown by action type
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getRulesByActionType(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['actionType', 'COUNT(*) as count'])
            ->from('{{%searchmanager_rule_analytics}}')
            ->groupBy('actionType')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return [
            'labels' => array_column($results, 'actionType'),
            'values' => array_map(fn($r) => (int)$r['count'], $results),
        ];
    }

    /**
     * Get top queries that trigger rules
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getQueriesTriggeringRules(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 15): array
    {
        $query = (new Query())
            ->select([
                'query',
                'COUNT(*) as count',
                'COUNT(DISTINCT queryRuleId) as rulesTriggered',
            ])
            ->from('{{%searchmanager_rule_analytics}}')
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($row) {
            return [
                'query' => $row['query'],
                'count' => (int)$row['count'],
                'rulesTriggered' => (int)$row['rulesTriggered'],
            ];
        }, $results);
    }

    /**
     * Get analytics for a specific query rule
     *
     * @param int $ruleId The query rule ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     */
    public function getRuleAnalytics(int $ruleId, string $dateRange = 'last7days'): array
    {
        $localDateExpr = DateFormatHelper::localDateExpression('dateCreated');

        $query = (new Query())
            ->from('{{%searchmanager_rule_analytics}}')
            ->where(['queryRuleId' => $ruleId]);

        $this->applyDateRangeFilter($query, $dateRange);

        // Get summary stats
        $totalTriggers = (int)(clone $query)->count();
        $uniqueQueries = (int)(clone $query)->select('COUNT(DISTINCT query)')->scalar();
        $avgResultsAfter = (float)(clone $query)->select('AVG(resultsCount)')->scalar();

        // Get top queries
        $topQueries = (clone $query)
            ->select(['query', 'COUNT(*) as count', 'AVG(resultsCount) as avgResults', 'MAX(dateCreated) as lastTriggered'])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10)
            ->all();

        // Get daily triggers
        $dailyTriggers = (clone $query)
            ->select([
                'date' => $localDateExpr,
                'COUNT(*) as count',
            ])
            ->groupBy($localDateExpr)
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Get recent triggers (only columns needed for CP display)
        $recentTriggers = (clone $query)
            ->select(['dateCreated', 'query', 'indexHandle', 'siteId'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(20)
            ->all();

        return [
            'totalTriggers' => $totalTriggers,
            'uniqueQueries' => $uniqueQueries,
            'avgResultsAfter' => $avgResultsAfter,
            'topQueries' => array_map(function($row) {
                return [
                    'query' => $row['query'],
                    'count' => (int)$row['count'],
                    'avgResults' => (float)$row['avgResults'],
                    'lastTriggered' => $row['lastTriggered'],
                ];
            }, $topQueries),
            'dailyTriggers' => $this->normalizeDailyCounts($dailyTriggers, $dateRange, ['count'], true),
            'recentTriggers' => $recentTriggers,
        ];
    }

    /**
     * Get top promotions by impressions
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getTopPromotions(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'promotionId',
                'elementId',
                'elementTitle',
                'position',
                'COUNT(*) as impressions',
                'COUNT(DISTINCT query) as uniqueQueries',
            ])
            ->from('{{%searchmanager_promotion_analytics}}')
            ->groupBy(['promotionId', 'elementId', 'elementTitle', 'position'])
            ->orderBy(['impressions' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($row) {
            return [
                'promotionId' => (int)$row['promotionId'],
                'elementId' => (int)$row['elementId'],
                'elementTitle' => $row['elementTitle'],
                'position' => (int)$row['position'],
                'impressions' => (int)$row['impressions'],
                'uniqueQueries' => (int)$row['uniqueQueries'],
            ];
        }, $results);
    }

    /**
     * Get promotions breakdown by position
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getPromotionsByPosition(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['position', 'COUNT(*) as count'])
            ->from('{{%searchmanager_promotion_analytics}}')
            ->groupBy('position')
            ->orderBy(['position' => SORT_ASC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return [
            'labels' => array_column($results, 'position'),
            'values' => array_map(fn($r) => (int)$r['count'], $results),
        ];
    }

    /**
     * Get top queries that trigger promotions
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getQueriesTriggeringPromotions(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 15): array
    {
        $query = (new Query())
            ->select([
                'query',
                'COUNT(*) as count',
                'COUNT(DISTINCT promotionId) as promotionsShown',
            ])
            ->from('{{%searchmanager_promotion_analytics}}')
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($row) {
            return [
                'query' => $row['query'],
                'count' => (int)$row['count'],
                'promotionsShown' => (int)$row['promotionsShown'],
            ];
        }, $results);
    }

    /**
     * Get analytics for a specific promotion
     *
     * @param int $promotionId The promotion ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     */
    public function getPromotionAnalytics(int $promotionId, string $dateRange = 'last7days'): array
    {
        $localDateExpr = DateFormatHelper::localDateExpression('dateCreated');

        $query = (new Query())
            ->from('{{%searchmanager_promotion_analytics}}')
            ->where(['promotionId' => $promotionId]);

        $this->applyDateRangeFilter($query, $dateRange);

        // Get summary stats
        $totalImpressions = (int)(clone $query)->count();
        $uniqueQueries = (int)(clone $query)->select('COUNT(DISTINCT query)')->scalar();
        $avgPosition = (float)(clone $query)->select('AVG(position)')->scalar();

        // Get top queries
        $topQueries = (clone $query)
            ->select(['query', 'COUNT(*) as count', 'AVG(position) as avgPosition', 'MAX(dateCreated) as lastShown'])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10)
            ->all();

        // Get daily impressions
        $dailyImpressions = (clone $query)
            ->select([
                'date' => $localDateExpr,
                'COUNT(*) as count',
            ])
            ->groupBy($localDateExpr)
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Get recent impressions (only columns needed for CP display)
        $recentImpressions = (clone $query)
            ->select(['dateCreated', 'query', 'indexHandle', 'siteId', 'position'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(20)
            ->all();

        return [
            'totalImpressions' => $totalImpressions,
            'uniqueQueries' => $uniqueQueries,
            'avgPosition' => $avgPosition,
            'topQueries' => array_map(function($row) {
                return [
                    'query' => $row['query'],
                    'count' => (int)$row['count'],
                    'avgPosition' => (float)$row['avgPosition'],
                    'lastShown' => $row['lastShown'],
                ];
            }, $topQueries),
            'dailyImpressions' => $this->normalizeDailyCounts($dailyImpressions, $dateRange, ['count'], true),
            'recentImpressions' => $recentImpressions,
        ];
    }
}
