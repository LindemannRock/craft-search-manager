<?php
/**
 * Redirect Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Analytics Controller
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     1.0.0
 */
class AnalyticsController extends Controller
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Analytics index - Charts and analytics
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewAnalytics');

        $siteId = Craft::$app->getRequest()->getQueryParam('siteId');
        $siteId = $siteId ? (int)$siteId : null; // Convert empty string to null
        $dateRange = Craft::$app->getRequest()->getQueryParam('dateRange', 'last7days');

        // Get chart data
        $chartData = SearchManager::$plugin->analytics->getChartData($siteId, $dateRange);

        // Get most common searches
        $mostCommon = SearchManager::$plugin->analytics->getMostCommonSearches($siteId, 15, $dateRange);

        // Get recent searches
        $recentSearches = SearchManager::$plugin->analytics->getRecentSearches($siteId, 100, null, $dateRange);
        $recentUnhandled = SearchManager::$plugin->analytics->getRecentSearches($siteId, 15, false, $dateRange);

        // Get counts
        $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, null, $dateRange);
        $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true, $dateRange);
        $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false, $dateRange);

        // Get device analytics
        $deviceData = SearchManager::$plugin->analytics->getDeviceBreakdown($siteId, $dateRange);
        $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $dateRange);
        $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $dateRange);
        $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $dateRange);

        // Transform data for charts
        $deviceBreakdown = [
            'labels' => array_column($deviceData, 'deviceType'),
            'values' => array_column($deviceData, 'count'),
        ];
        $browserBreakdown = [
            'labels' => array_column($browserData, 'browser'),
            'values' => array_column($browserData, 'count'),
        ];
        $osBreakdown = [
            'labels' => array_column($osData, 'osName'),
            'values' => array_column($osData, 'count'),
        ];

        // Get all sites for dropdown
        $sites = Craft::$app->getSites()->getAllSites();
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/analytics/index', [
            'chartData' => $chartData,
            'mostCommon' => $mostCommon,
            'recentSearches' => $recentSearches,
            'recentUnhandled' => $recentUnhandled,
            'totalCount' => $totalCount,
            'handledCount' => $handledCount,
            'unhandledCount' => $unhandledCount,
            'deviceBreakdown' => $deviceBreakdown,
            'browserBreakdown' => $browserBreakdown,
            'osBreakdown' => $osBreakdown,
            'botStats' => $botStats,
            'dateRange' => $dateRange,
            'siteId' => $siteId,
            'sites' => $sites,
            'settings' => $settings,
        ]);
    }

    /**
     * Delete an analytic
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:viewAnalytics');

        $analyticId = Craft::$app->getRequest()->getRequiredBodyParam('analyticId');

        if (SearchManager::$plugin->analytics->deleteAnalytic($analyticId)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not delete analytic']);
    }

    /**
     * Clear all analytics
     *
     * @return Response
     */
    public function actionClearAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:clearAnalytics');

        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $siteId = $siteId ? (int)$siteId : null; // Convert empty string to null

        $deleted = SearchManager::$plugin->analytics->clearAnalytics($siteId);

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', '{count} analytics cleared', ['count' => $deleted])
        );

        return $this->asJson([
            'success' => true,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Export analytics data
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $format = $request->getQueryParam('format', 'csv');
        $siteId = $request->getQueryParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;

        try {
            $csvData = SearchManager::$plugin->analytics->exportAnalytics(
                $siteId,
                $dateRange,
                $format
            );

            // Generate filename
            $settings = SearchManager::$plugin->getSettings();
            $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));

            // Get site name for filename
            $sitePart = 'all';
            if ($siteId) {
                $site = Craft::$app->getSites()->getSiteById($siteId);
                if ($site) {
                    $sitePart = strtolower(preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '-', $site->name)));
                }
            }

            // Use "alltime" instead of "all" for clearer filename
            $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
            $filename = $filenamePart . '-analytics-' . $sitePart . '-' . $dateRangeLabel . '-' . date('Y-m-d') . '.' . $format;

            return Craft::$app->getResponse()->sendContentAsFile(
                $csvData,
                $filename,
                [
                    'mimeType' => $format === 'csv' ? 'text/csv' : 'application/json',
                ]
            );
        } catch (\Exception $e) {
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('search-manager/analytics?dateRange=' . $dateRange);
        }
    }

    /**
     * Get analytics data via AJAX
     *
     * @return Response
     */
    public function actionGetData(): Response
    {
        $this->requirePermission('searchManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int)$siteId : null; // Convert empty string to null
        $dateRange = $request->getParam('dateRange', 'last7days');
        $type = $request->getParam('type', 'all'); // 'all', 'summary', 'chart', 'query-analysis', 'content-gaps', 'device-stats'

        try {
            $data = [];

            // Summary Stats (Header)
            if ($type === 'all' || $type === 'summary') {
                $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, null, $dateRange);
                $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true, $dateRange);
                $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false, $dateRange);
                $mostCommon = SearchManager::$plugin->analytics->getMostCommonSearches($siteId, 15, $dateRange);
                $recentUnhandled = SearchManager::$plugin->analytics->getRecentSearches($siteId, 15, false, $dateRange);

                $data['summary'] = [
                    'totalCount' => $totalCount,
                    'handledCount' => $handledCount,
                    'unhandledCount' => $unhandledCount,
                    'mostCommon' => $mostCommon,
                    'recentUnhandled' => $recentUnhandled,
                ];
            }

            // Main Chart
            if ($type === 'all' || $type === 'chart') {
                $data['chartData'] = SearchManager::$plugin->analytics->getChartData($siteId, $dateRange);
            }

            // Query Analysis Tab
            if ($type === 'all' || $type === 'query-analysis') {
                $data['queryAnalysis'] = [
                    'lengthDistribution' => SearchManager::$plugin->analytics->getQueryLengthDistribution($siteId, $dateRange),
                    'wordCloud' => SearchManager::$plugin->analytics->getWordCloudData($siteId, $dateRange),
                ];
            }

            // Content Gaps Tab
            if ($type === 'all' || $type === 'content-gaps') {
                $data['contentGaps'] = [
                    'clusters' => SearchManager::$plugin->analytics->getZeroResultClusters($siteId, $dateRange, 15),
                ];
            }

            // Audience/Device Stats
            if ($type === 'all' || $type === 'device-stats' || $type === 'devices') {
                $deviceData = SearchManager::$plugin->analytics->getDeviceBreakdown($siteId, $dateRange);
                $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $dateRange);
                $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $dateRange);
                $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $dateRange);

                $data['deviceStats'] = [
                    'deviceBreakdown' => [
                        'labels' => array_column($deviceData, 'deviceType'),
                        'values' => array_column($deviceData, 'count'),
                    ],
                    'browserBreakdown' => [
                        'labels' => array_column($browserData, 'browser'),
                        'values' => array_column($browserData, 'count'),
                    ],
                    'osBreakdown' => [
                        'labels' => array_column($osData, 'osName'),
                        'values' => array_column($osData, 'count'),
                    ],
                    'botStats' => $botStats,
                ];

                // For backward compatibility with smart-links style requests
                if ($type === 'devices') {
                    return $this->asJson([
                        'success' => true,
                        'data' => [
                            'labels' => array_column($deviceData, 'deviceType'),
                            'values' => array_column($deviceData, 'count'),
                        ],
                    ]);
                }
            }

            // Browsers only
            if ($type === 'browsers') {
                $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => [
                        'labels' => array_column($browserData, 'browser'),
                        'values' => array_column($browserData, 'count'),
                    ],
                ]);
            }

            // OS only
            if ($type === 'os') {
                $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => [
                        'labels' => array_column($osData, 'osName'),
                        'values' => array_column($osData, 'count'),
                    ],
                ]);
            }

            // Bots only
            if ($type === 'bots') {
                $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $botStats,
                ]);
            }

            // Geographic data - countries
            if ($type === 'countries') {
                $countryData = SearchManager::$plugin->analytics->getCountryBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $countryData,
                ]);
            }

            // Geographic data - cities
            if ($type === 'cities') {
                $cityData = SearchManager::$plugin->analytics->getCityBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $cityData,
                ]);
            }

            // Peak usage hours
            if ($type === 'hourly') {
                $hourlyData = SearchManager::$plugin->analytics->getPeakUsageHours($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $hourlyData,
                ]);
            }

            // Trending queries
            if ($type === 'trending') {
                $trendingData = SearchManager::$plugin->analytics->getTrendingQueries($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $trendingData,
                ]);
            }

            // Intent breakdown
            if ($type === 'intent') {
                $intentData = SearchManager::$plugin->analytics->getIntentBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $intentData,
                ]);
            }

            // Source breakdown
            if ($type === 'source') {
                $sourceData = SearchManager::$plugin->analytics->getSourceBreakdown($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $sourceData,
                ]);
            }

            // Performance data
            if ($type === 'performance') {
                $performanceData = SearchManager::$plugin->analytics->getPerformanceData($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $performanceData,
                ]);
            }

            // Cache stats
            if ($type === 'cache-stats') {
                $cacheStats = SearchManager::$plugin->analytics->getCacheStats($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $cacheStats,
                ]);
            }

            // Top performing queries
            if ($type === 'top-queries') {
                $topQueries = SearchManager::$plugin->analytics->getTopPerformingQueries($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $topQueries,
                ]);
            }

            // Worst performing queries
            if ($type === 'worst-queries') {
                $worstQueries = SearchManager::$plugin->analytics->getWorstPerformingQueries($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $worstQueries,
                ]);
            }

            // =====================================================================
            // QUERY RULES ANALYTICS
            // =====================================================================

            // Top triggered rules
            if ($type === 'query-rules-top') {
                $topRules = SearchManager::$plugin->analytics->getTopTriggeredRules($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $topRules,
                ]);
            }

            // Rules by action type
            if ($type === 'query-rules-by-type') {
                $rulesByType = SearchManager::$plugin->analytics->getRulesByActionType($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $rulesByType,
                ]);
            }

            // Top queries triggering rules
            if ($type === 'query-rules-queries') {
                $ruleQueries = SearchManager::$plugin->analytics->getQueriesTriggeringRules($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $ruleQueries,
                ]);
            }

            // =====================================================================
            // PROMOTIONS ANALYTICS
            // =====================================================================

            // Top promotions
            if ($type === 'promotions-top') {
                $topPromos = SearchManager::$plugin->analytics->getTopPromotions($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $topPromos,
                ]);
            }

            // Promotions by position
            if ($type === 'promotions-by-position') {
                $promosByPosition = SearchManager::$plugin->analytics->getPromotionsByPosition($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $promosByPosition,
                ]);
            }

            // Top queries triggering promotions
            if ($type === 'promotions-queries') {
                $promoQueries = SearchManager::$plugin->analytics->getQueriesTriggeringPromotions($siteId, $dateRange);
                return $this->asJson([
                    'success' => true,
                    'data' => $promoQueries,
                ]);
            }

            // Flatten structure for backward compatibility if 'all' is requested
            if ($type === 'all') {
                return $this->asJson([
                    'success' => true,
                    'data' => array_merge(
                        $data['summary'],
                        ['chartData' => $data['chartData']],
                        $data['deviceStats']
                    ),
                ]);
            }

            return $this->asJson([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get chart data (AJAX)
     *
     * @return Response
     */
    public function actionGetChartData(): Response
    {
        $this->requirePermission('searchManager:viewAnalytics');

        $siteId = Craft::$app->getRequest()->getQueryParam('siteId');
        $siteId = $siteId ? (int)$siteId : null; // Convert empty string to null
        $dateRange = Craft::$app->getRequest()->getQueryParam('dateRange', 'last30days');

        $chartData = SearchManager::$plugin->analytics->getChartData($siteId, $dateRange);

        return $this->asJson([
            'success' => true,
            'data' => $chartData,
        ]);
    }

    /**
     * Get analytics for a specific query rule (AJAX)
     *
     * @return Response
     */
    public function actionGetRuleAnalytics(): Response
    {
        $this->requirePermission('searchManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $ruleId = (int)$request->getParam('ruleId');
        $dateRange = $request->getParam('range', 'last7days');

        if (!$ruleId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Rule ID is required',
            ]);
        }

        try {
            // Get the rule
            $rule = \lindemannrock\searchmanager\models\QueryRule::findById($ruleId);
            if (!$rule) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Rule not found',
                ]);
            }

            // Get analytics data
            $analytics = SearchManager::$plugin->analytics->getRuleAnalytics($ruleId, $dateRange);

            // Render the partial template
            $html = Craft::$app->getView()->renderTemplate(
                'search-manager/query-rules/_partials/analytics-content',
                [
                    'rule' => $rule,
                    'analytics' => $analytics,
                    'dateRange' => $dateRange,
                ]
            );

            return $this->asJson([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get analytics for a specific promotion (AJAX)
     *
     * @return Response
     */
    public function actionGetPromotionAnalytics(): Response
    {
        $this->requirePermission('searchManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $promotionId = (int)$request->getParam('promotionId');
        $dateRange = $request->getParam('range', 'last7days');

        if (!$promotionId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Promotion ID is required',
            ]);
        }

        try {
            // Get the promotion
            $promotion = \lindemannrock\searchmanager\models\Promotion::findById($promotionId);
            if (!$promotion) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Promotion not found',
                ]);
            }

            // Get analytics data
            $analytics = SearchManager::$plugin->analytics->getPromotionAnalytics($promotionId, $dateRange);

            // Render the partial template
            $html = Craft::$app->getView()->renderTemplate(
                'search-manager/promotions/_partials/analytics-content',
                [
                    'promotion' => $promotion,
                    'analytics' => $analytics,
                    'dateRange' => $dateRange,
                ]
            );

            return $this->asJson([
                'success' => true,
                'html' => $html,
            ]);
        } catch (\Exception $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Export analytics for a specific query rule
     *
     * @return Response
     */
    public function actionExportRuleAnalytics(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $ruleId = (int)$request->getQueryParam('ruleId');
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $format = $request->getQueryParam('format', 'csv');

        if (!$ruleId) {
            throw new \yii\web\BadRequestHttpException('Rule ID is required');
        }

        // Get the rule
        $rule = \lindemannrock\searchmanager\models\QueryRule::findById($ruleId);
        if (!$rule) {
            throw new \yii\web\NotFoundHttpException('Rule not found');
        }

        // Get raw analytics data for export
        $query = (new \craft\db\Query())
            ->from('{{%searchmanager_rule_analytics}}')
            ->where(['queryRuleId' => $ruleId]);

        SearchManager::$plugin->analytics->applyDateRangeFilter($query, $dateRange);

        $data = $query->orderBy(['dateCreated' => SORT_DESC])->all();

        // Generate filename
        $settings = SearchManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $ruleName = preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '-', $rule->name));
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = $filenamePart . '-query-rule-' . $ruleName . '-' . $dateRangeLabel . '-' . date('Y-m-d') . '.' . $format;

        if ($format === 'json') {
            $content = json_encode($data, JSON_PRETTY_PRINT);
            $mimeType = 'application/json';
        } else {
            // CSV format
            $lines = [];
            if (!empty($data)) {
                $lines[] = implode(',', array_keys($data[0]));
                foreach ($data as $row) {
                    $lines[] = implode(',', array_map(function($val) {
                        return '"' . str_replace('"', '""', $val ?? '') . '"';
                    }, $row));
                }
            }
            $content = implode("\n", $lines);
            $mimeType = 'text/csv';
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $content,
            $filename,
            ['mimeType' => $mimeType]
        );
    }

    /**
     * Export analytics for a specific promotion
     *
     * @return Response
     */
    public function actionExportPromotionAnalytics(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $promotionId = (int)$request->getQueryParam('promotionId');
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $format = $request->getQueryParam('format', 'csv');

        if (!$promotionId) {
            throw new \yii\web\BadRequestHttpException('Promotion ID is required');
        }

        // Get the promotion
        $promotion = \lindemannrock\searchmanager\models\Promotion::findById($promotionId);
        if (!$promotion) {
            throw new \yii\web\NotFoundHttpException('Promotion not found');
        }

        // Get raw analytics data for export
        $query = (new \craft\db\Query())
            ->from('{{%searchmanager_promotion_analytics}}')
            ->where(['promotionId' => $promotionId]);

        SearchManager::$plugin->analytics->applyDateRangeFilter($query, $dateRange);

        $data = $query->orderBy(['dateCreated' => SORT_DESC])->all();

        // Generate filename
        $settings = SearchManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $promotionTitle = preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '-', $promotion->title));
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = $filenamePart . '-promotion-' . $promotionTitle . '-' . $dateRangeLabel . '-' . date('Y-m-d') . '.' . $format;

        if ($format === 'json') {
            $content = json_encode($data, JSON_PRETTY_PRINT);
            $mimeType = 'application/json';
        } else {
            // CSV format
            $lines = [];
            if (!empty($data)) {
                $lines[] = implode(',', array_keys($data[0]));
                foreach ($data as $row) {
                    $lines[] = implode(',', array_map(function($val) {
                        return '"' . str_replace('"', '""', $val ?? '') . '"';
                    }, $row));
                }
            }
            $content = implode("\n", $lines);
            $mimeType = 'text/csv';
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $content,
            $filename,
            ['mimeType' => $mimeType]
        );
    }

    /**
     * Export tab-specific data
     *
     * @return Response
     */
    public function actionExportTab(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $tab = $request->getQueryParam('tab', 'trending');
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $siteId = $request->getQueryParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;
        $format = $request->getQueryParam('format', 'csv');

        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $settings = SearchManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $data = [];
        $filename = '';

        switch ($tab) {
            case 'trending':
                $trending = SearchManager::$plugin->analytics->getTrendingQueries($siteId, $dateRange, 50);
                foreach ($trending as $item) {
                    $data[] = [
                        'query' => $item['query'],
                        'searches' => $item['count'],
                        'previous_period' => $item['previousCount'],
                        'trend' => $item['trend'],
                        'change_percent' => $item['changePercent'],
                    ];
                }
                $filename = $filenamePart . '-trending-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            case 'query-rules':
                $rulesData = SearchManager::$plugin->analytics->getTopTriggeredRules($siteId, $dateRange);
                foreach ($rulesData as $item) {
                    $data[] = [
                        'rule_name' => $item['ruleName'],
                        'action_type' => $item['actionType'],
                        'hits' => $item['hits'],
                        'avg_results' => $item['avgResults'],
                    ];
                }
                $filename = $filenamePart . '-query-rules-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            case 'promotions':
                $promosData = SearchManager::$plugin->analytics->getTopPromotions($siteId, $dateRange);
                foreach ($promosData as $item) {
                    $data[] = [
                        'element_title' => $item['elementTitle'] ?? 'Element #' . $item['elementId'],
                        'position' => $item['position'],
                        'impressions' => $item['impressions'],
                        'unique_queries' => $item['uniqueQueries'],
                    ];
                }
                $filename = $filenamePart . '-promotions-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            case 'performance':
                $fastQueries = SearchManager::$plugin->analytics->getTopPerformingQueries($siteId, $dateRange);
                $slowQueries = SearchManager::$plugin->analytics->getWorstPerformingQueries($siteId, $dateRange);
                foreach ($fastQueries as $item) {
                    $data[] = [
                        'query' => $item['query'],
                        'type' => 'fast',
                        'avg_time_ms' => $item['avgTime'],
                        'searches' => $item['searches'],
                    ];
                }
                foreach ($slowQueries as $item) {
                    $data[] = [
                        'query' => $item['query'],
                        'type' => 'slow',
                        'avg_time_ms' => $item['avgTime'],
                        'searches' => $item['searches'],
                    ];
                }
                $filename = $filenamePart . '-performance-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            case 'traffic-devices':
                $deviceData = SearchManager::$plugin->analytics->getDeviceBreakdown($siteId, $dateRange);
                $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $dateRange);
                $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $dateRange);

                foreach ($deviceData as $item) {
                    $data[] = ['category' => 'device', 'name' => $item['deviceType'] ?? '', 'count' => $item['count']];
                }
                foreach ($browserData as $item) {
                    $data[] = ['category' => 'browser', 'name' => $item['browser'] ?? '', 'count' => $item['count']];
                }
                foreach ($osData as $item) {
                    $data[] = ['category' => 'os', 'name' => $item['osName'] ?? '', 'count' => $item['count']];
                }
                $filename = $filenamePart . '-traffic-devices-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            case 'geographic':
                $countries = SearchManager::$plugin->analytics->getCountryBreakdown($siteId, $dateRange);
                $cities = SearchManager::$plugin->analytics->getCityBreakdown($siteId, $dateRange);

                foreach ($countries as $item) {
                    $data[] = ['type' => 'country', 'name' => $item['name'] ?? '', 'count' => $item['count']];
                }
                foreach ($cities as $item) {
                    $countryName = $item['country'] ?? '';
                    $data[] = ['type' => 'city', 'name' => ($item['city'] ?? '') . ', ' . $countryName, 'count' => $item['count']];
                }
                $filename = $filenamePart . '-geographic-' . $dateRangeLabel . '-' . date('Y-m-d');
                break;

            default:
                throw new \yii\web\BadRequestHttpException('Invalid tab specified');
        }

        $filename .= '.' . $format;

        if ($format === 'json') {
            $content = json_encode($data, JSON_PRETTY_PRINT);
            $mimeType = 'application/json';
        } else {
            $lines = [];
            if (!empty($data)) {
                $lines[] = implode(',', array_keys($data[0]));
                foreach ($data as $row) {
                    $lines[] = implode(',', array_map(function($val) {
                        return '"' . str_replace('"', '""', $val ?? '') . '"';
                    }, $row));
                }
            } else {
                $lines[] = 'No data available';
            }
            $content = implode("\n", $lines);
            $mimeType = 'text/csv';
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $content,
            $filename,
            ['mimeType' => $mimeType]
        );
    }

    /**
     * Export content gaps data (zero-hit queries)
     *
     * @return Response
     */
    public function actionExportContentGaps(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $siteId = $request->getQueryParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;
        $format = $request->getQueryParam('format', 'csv');
        $type = $request->getQueryParam('type', 'clusters'); // 'clusters' or 'recent'

        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $settings = SearchManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));

        if ($type === 'clusters') {
            // Get content gaps clusters
            $clusters = SearchManager::$plugin->analytics->getZeroResultClusters($siteId, $dateRange);
            $data = [];

            foreach ($clusters as $cluster) {
                $data[] = [
                    'main_term' => $cluster['representative'],
                    'total_searches' => $cluster['count'],
                    'related_queries' => implode('; ', $cluster['queries']),
                    'last_searched' => $cluster['lastSearched'],
                ];
            }

            $filename = $filenamePart . '-content-gaps-clusters-' . $dateRangeLabel . '-' . date('Y-m-d') . '.' . $format;
        } else {
            // Get recent zero-hit queries
            $query = (new \craft\db\Query())
                ->select(['query', 'siteId', 'indexHandle', 'backend', 'dateCreated'])
                ->from('{{%searchmanager_analytics}}')
                ->where(['isHit' => 0])
                ->andWhere(['wasRedirected' => 0])
                ->andWhere(['or', ['promotionsShown' => null], ['promotionsShown' => 0]])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(1000);

            SearchManager::$plugin->analytics->applyDateRangeFilter($query, $dateRange);

            if ($siteId) {
                $query->andWhere(['siteId' => $siteId]);
            }

            $results = $query->all();
            $data = [];

            foreach ($results as $row) {
                $siteName = '—';
                if ($row['siteId']) {
                    $site = Craft::$app->getSites()->getSiteById($row['siteId']);
                    $siteName = $site ? $site->name : '—';
                }

                $data[] = [
                    'query' => $row['query'],
                    'site' => $siteName,
                    'index' => $row['indexHandle'],
                    'backend' => $row['backend'],
                    'date' => $row['dateCreated'],
                ];
            }

            $filename = $filenamePart . '-content-gaps-recent-' . $dateRangeLabel . '-' . date('Y-m-d') . '.' . $format;
        }

        if ($format === 'json') {
            $content = json_encode($data, JSON_PRETTY_PRINT);
            $mimeType = 'application/json';
        } else {
            // CSV format
            $lines = [];
            if (!empty($data)) {
                $lines[] = implode(',', array_keys($data[0]));
                foreach ($data as $row) {
                    $lines[] = implode(',', array_map(function($val) {
                        return '"' . str_replace('"', '""', $val ?? '') . '"';
                    }, $row));
                }
            } else {
                $lines[] = 'No content gaps found';
            }
            $content = implode("\n", $lines);
            $mimeType = 'text/csv';
        }

        return Craft::$app->getResponse()->sendContentAsFile(
            $content,
            $filename,
            ['mimeType' => $mimeType]
        );
    }
}
