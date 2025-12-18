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

        // Determine days based on date range
        $days = match ($dateRange) {
            'today' => 1,
            'yesterday' => 2,
            'last7days' => 7,
            'last30days' => 30,
            'last90days' => 90,
            'all' => 365,
            default => 7,
        };

        // Get chart data
        $chartData = SearchManager::$plugin->analytics->getChartData($siteId, $days);

        // Get most common 404s
        $mostCommon = SearchManager::$plugin->analytics->getMostCommon404s($siteId, 15);

        // Get recent searches
        $recentSearches = SearchManager::$plugin->analytics->getRecent404s($siteId, 100, null, $days);
        $recentUnhandled = SearchManager::$plugin->analytics->getRecent404s($siteId, 15, false);

        // Get counts
        $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, null, $days);
        $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true, $days);
        $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false, $days);

        // Get device analytics
        $deviceData = SearchManager::$plugin->analytics->getDeviceBreakdown($siteId, $days);
        $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $days);
        $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $days);
        $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $days);

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
        $this->requirePermission('searchManager:manageSettings');

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

            $filename = $filenamePart . '-analytics-' . $sitePart . '-' . $dateRange . '-' . date('Y-m-d') . '.' . $format;

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
            // Determine days based on date range
            $days = match ($dateRange) {
                'today' => 1,
                'yesterday' => 2,
                'last7days' => 7,
                'last30days' => 30,
                'last90days' => 90,
                'all' => 365,
                default => 7,
            };

            $data = [];

            // Summary Stats (Header)
            if ($type === 'all' || $type === 'summary') {
                $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, null, $days);
                $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true, $days);
                $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false, $days);
                $mostCommon = SearchManager::$plugin->analytics->getMostCommon404s($siteId, 15);
                $recentUnhandled = SearchManager::$plugin->analytics->getRecent404s($siteId, 15, false);

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
                $data['chartData'] = SearchManager::$plugin->analytics->getChartData($siteId, $days);
            }

            // Query Analysis Tab
            if ($type === 'all' || $type === 'query-analysis') {
                $data['queryAnalysis'] = [
                    'lengthDistribution' => SearchManager::$plugin->analytics->getQueryLengthDistribution($siteId, $days),
                    'wordCloud' => SearchManager::$plugin->analytics->getWordCloudData($siteId, $days),
                ];
            }

            // Content Gaps Tab
            if ($type === 'all' || $type === 'content-gaps') {
                $data['contentGaps'] = [
                    'clusters' => SearchManager::$plugin->analytics->getZeroResultClusters($siteId, $days, 15),
                ];
            }

            // Audience/Device Stats
            if ($type === 'all' || $type === 'device-stats' || $type === 'devices') {
                $deviceData = SearchManager::$plugin->analytics->getDeviceBreakdown($siteId, $days);
                $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $days);
                $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $days);
                $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $days);

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
                $browserData = SearchManager::$plugin->analytics->getBrowserBreakdown($siteId, $days);
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
                $osData = SearchManager::$plugin->analytics->getOsBreakdown($siteId, $days);
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
                $botStats = SearchManager::$plugin->analytics->getBotStats($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $botStats,
                ]);
            }

            // Geographic data - countries
            if ($type === 'countries') {
                $countryData = SearchManager::$plugin->analytics->getCountryBreakdown($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $countryData,
                ]);
            }

            // Geographic data - cities
            if ($type === 'cities') {
                $cityData = SearchManager::$plugin->analytics->getCityBreakdown($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $cityData,
                ]);
            }

            // Peak usage hours
            if ($type === 'hourly') {
                $hourlyData = SearchManager::$plugin->analytics->getPeakUsageHours($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $hourlyData,
                ]);
            }

            // Intent breakdown
            if ($type === 'intent') {
                $intentData = SearchManager::$plugin->analytics->getIntentBreakdown($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $intentData,
                ]);
            }

            // Source breakdown
            if ($type === 'source') {
                $sourceData = SearchManager::$plugin->analytics->getSourceBreakdown($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $sourceData,
                ]);
            }

            // Performance data
            if ($type === 'performance') {
                $performanceData = SearchManager::$plugin->analytics->getPerformanceData($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $performanceData,
                ]);
            }

            // Cache stats
            if ($type === 'cache-stats') {
                $cacheStats = SearchManager::$plugin->analytics->getCacheStats($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $cacheStats,
                ]);
            }

            // Top performing queries
            if ($type === 'top-queries') {
                $topQueries = SearchManager::$plugin->analytics->getTopPerformingQueries($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $topQueries,
                ]);
            }

            // Worst performing queries
            if ($type === 'worst-queries') {
                $worstQueries = SearchManager::$plugin->analytics->getWorstPerformingQueries($siteId, $days);
                return $this->asJson([
                    'success' => true,
                    'data' => $worstQueries,
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
        $days = (int)Craft::$app->getRequest()->getQueryParam('days', 30);

        $chartData = SearchManager::$plugin->analytics->getChartData($siteId, $days);

        return $this->asJson([
            'success' => true,
            'data' => $chartData,
        ]);
    }
}
