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
        $dateRange = Craft::$app->getRequest()->getQueryParam('dateRange', 'last7days');

        // Determine days based on date range
        $days = match ($dateRange) {
            'today' => 1,
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

        // Get recent 404s
        $recentHandled = SearchManager::$plugin->analytics->getRecent404s($siteId, 5, true);
        $recentUnhandled = SearchManager::$plugin->analytics->getRecent404s($siteId, 5, false);

        // Get counts
        $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId);
        $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true);
        $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false);

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

        return $this->renderTemplate('search-manager/analytics/index', [
            'chartData' => $chartData,
            'mostCommon' => $mostCommon,
            'recentHandled' => $recentHandled,
            'recentUnhandled' => $recentUnhandled,
            'totalCount' => $totalCount,
            'handledCount' => $handledCount,
            'unhandledCount' => $unhandledCount,
            'deviceBreakdown' => $deviceBreakdown,
            'browserBreakdown' => $browserBreakdown,
            'osBreakdown' => $osBreakdown,
            'botStats' => $botStats,
            'dateRange' => $dateRange,
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
     * Export analytics to CSV
     *
     * @return Response
     */
    public function actionExportCsv(): Response
    {
        $this->requirePermission('searchManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getQueryParam('dateRange', 'last7days');
        $format = $request->getQueryParam('format', 'csv');

        try {
            $csv = SearchManager::$plugin->analytics->exportToCsv(null, null);

            // Build filename following shortlink pattern
            $settings = SearchManager::$plugin->getSettings();
            $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
            $filename = $filenamePart . '-analytics-' . $dateRange . '-' . date('Y-m-d') . '.' . $format;

            return Craft::$app->getResponse()
                ->sendContentAsFile($csv, $filename, [
                    'mimeType' => $format === 'csv' ? 'text/csv' : 'application/json',
                ]);
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
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $siteId = $request->getParam('siteId');
        $dateRange = $request->getParam('dateRange', 'last7days');
        $type = $request->getParam('type', 'all'); // 'all', 'summary', 'chart', 'query-analysis', 'content-gaps', 'device-stats'

        try {
            // Determine days based on date range
            $days = match ($dateRange) {
                'today' => 1,
                'last7days' => 7,
                'last30days' => 30,
                'last90days' => 90,
                'all' => 365,
                default => 7,
            };

            $data = [];

            // Summary Stats (Header)
            if ($type === 'all' || $type === 'summary') {
                $totalCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId);
                $handledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, true);
                $unhandledCount = SearchManager::$plugin->analytics->getAnalyticsCount($siteId, false);
                $mostCommon = SearchManager::$plugin->analytics->getMostCommon404s($siteId, 15);
                $recentUnhandled = SearchManager::$plugin->analytics->getRecent404s($siteId, 5, false);

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
                    'clusters' => SearchManager::$plugin->analytics->getZeroResultClusters($siteId, $days),
                ];
            }

            // Audience/Device Stats
            if ($type === 'all' || $type === 'device-stats') {
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
        $days = (int)Craft::$app->getRequest()->getQueryParam('days', 30);

        $chartData = SearchManager::$plugin->analytics->getChartData($siteId, $days);

        return $this->asJson([
            'success' => true,
            'data' => $chartData,
        ]);
    }
}
