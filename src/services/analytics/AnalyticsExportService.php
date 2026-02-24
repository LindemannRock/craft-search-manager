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
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Analytics Export Service
 *
 * Summary, charts, export, and data maintenance.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
class AnalyticsExportService
{
    use AnalyticsQueryTrait;
    use LoggingTrait;

    /**
     */
    public function __construct()
    {
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Get analytics summary
     *
     * @param int|array|null $siteId Site ID(s) to filter by
     * @param string $dateRange Date range filter
     * @return array Analytics summary data
     */
    public function getAnalyticsSummary(int|array|null $siteId = null, string $dateRange = 'last7days'): array
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');
        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $totalSearches = (int)$query->count();
        $uniqueVisitors = (int)(clone $query)->select('COUNT(DISTINCT ip)')->scalar();
        // Zero results excludes handled searches (redirected or showed promotions)
        $zeroResults = (int)(clone $query)->andWhere(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0])->count();
        $zeroResultsRate = $totalSearches > 0 ? round(($zeroResults / $totalSearches) * 100, 1) : 0;

        return [
            'totalSearches' => $totalSearches,
            'uniqueVisitors' => $uniqueVisitors,
            'zeroResults' => $zeroResults,
            'zeroResultsRate' => $zeroResultsRate,
        ];
    }

    /**
     * Get chart data for visualization
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getChartData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $localDateExpr = DateFormatHelper::localDateExpression('dateCreated');

        $query = (new Query())
            ->select([
                'date' => $localDateExpr,
                'COUNT(*) as total',
                // Count searches with results OR redirected OR showed promotions as successful
                'SUM(CASE WHEN isHit = 1 OR wasRedirected = 1 OR promotionsShown > 0 THEN 1 ELSE 0 END) as withResults',
                // Only count as zero results if no hit AND not redirected AND no promotions (true content gaps)
                'SUM(CASE WHEN isHit = 0 AND wasRedirected = 0 AND promotionsShown = 0 THEN 1 ELSE 0 END) as zeroResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy($localDateExpr)
            ->orderBy(['date' => SORT_ASC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        return $this->normalizeDailyCounts($results, $dateRange, ['total', 'withResults', 'zeroResults'], true);
    }

    /**
     * Export analytics data
     *
     * @param int|array|null $siteId Site ID(s) to filter by
     * @param string $dateRange Date range to filter
     * @return array Export data (rows, headers, jsonData)
     */
    public function exportAnalytics(int|array|null $siteId, string $dateRange): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->select([
                'dateCreated',
                'indexHandle',
                'query',
                'resultsCount',
                'synonymsExpanded',
                'rulesMatched',
                'promotionsShown',
                'wasRedirected',
                'executionTime',
                'backend',
                'siteId',
                'intent',
                'source',
                'platform',
                'appVersion',
                'deviceType',
                'deviceBrand',
                'deviceModel',
                'osName',
                'osVersion',
                'browser',
                'browserVersion',
                'country',
                'city',
                'language',
                'region',
                'referer as referrer',
                'isRobot',
                'botName',
                'userAgent',
            ])
            ->orderBy(['dateCreated' => SORT_DESC]);

        // Apply date range filter
        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        ExportHelper::assertNotEmpty($results);

        // Check if geo detection is enabled
        $settings = SearchManager::$plugin->getSettings();
        $geoEnabled = $settings->enableGeoDetection ?? false;

        $headers = [
            Craft::t('search-manager', 'Date'),
            Craft::t('search-manager', 'Time'),
            Craft::t('search-manager', 'Query'),
            Craft::t('search-manager', 'Hits'),
            Craft::t('search-manager', 'Synonyms'),
            Craft::t('search-manager', 'Rules'),
            Craft::t('search-manager', 'Promotions'),
            Craft::t('search-manager', 'Redirected'),
            Craft::t('search-manager', 'Execution Time (ms)'),
            Craft::t('search-manager', 'Backend'),
            Craft::t('search-manager', 'Index'),
            Craft::t('search-manager', 'Site'),
            Craft::t('search-manager', 'Intent'),
            Craft::t('search-manager', 'Source'),
            Craft::t('search-manager', 'Platform'),
            Craft::t('search-manager', 'App Version'),
            Craft::t('search-manager', 'Referrer'),
            Craft::t('search-manager', 'Device Type'),
            Craft::t('search-manager', 'Device Brand'),
            Craft::t('search-manager', 'Device Model'),
            Craft::t('search-manager', 'OS'),
            Craft::t('search-manager', 'OS Version'),
            Craft::t('search-manager', 'Browser'),
            Craft::t('search-manager', 'Browser Version'),
        ];

        if ($geoEnabled) {
            $headers[] = Craft::t('search-manager', 'Country');
            $headers[] = Craft::t('search-manager', 'City');
            $headers[] = Craft::t('search-manager', 'Region');
        }

        $headers[] = Craft::t('search-manager', 'Language');
        $headers[] = Craft::t('search-manager', 'Is Bot');
        $headers[] = Craft::t('search-manager', 'Bot Name');
        $headers[] = Craft::t('search-manager', 'User Agent');

        $rows = [];

        foreach ($results as $row) {
            $date = \craft\helpers\DateTimeHelper::toDateTime($row['dateCreated']);
            $dateStr = $date ? $date->format('Y-m-d') : '';
            $timeStr = $date ? $date->format('H:i:s') : '';

            $siteName = '';
            if (!empty($row['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($row['siteId']);
                $siteName = $site ? $site->name : '';
            }

            $rowData = [
                'date' => $dateStr,
                'time' => $timeStr,
                'query' => $row['query'],
                'hits' => $row['resultsCount'],
                'synonyms' => ($row['synonymsExpanded'] ?? false) ? 1 : 0,
                'rules' => $row['rulesMatched'] ?? 0,
                'promotions' => $row['promotionsShown'] ?? 0,
                'redirected' => ($row['wasRedirected'] ?? false) ? 1 : 0,
                'execution_time_ms' => $row['executionTime'] ?? 0,
                'backend' => $row['backend'],
                'index' => $row['indexHandle'],
                'site' => $siteName,
                'intent' => $row['intent'] ?? '',
                'source' => $row['source'] ?? 'frontend',
                'platform' => $row['platform'] ?? '',
                'app_version' => $row['appVersion'] ?? '',
                'referrer' => $row['referrer'] ?? '',
                'device_type' => $row['deviceType'] ?? '',
                'device_brand' => $row['deviceBrand'] ?? '',
                'device_model' => $row['deviceModel'] ?? '',
                'os' => $row['osName'] ?? '',
                'os_version' => $row['osVersion'] ?? '',
                'browser' => $row['browser'] ?? '',
                'browser_version' => $row['browserVersion'] ?? '',
            ];

            if ($geoEnabled) {
                $rowData['country'] = $row['country'] ?? '';
                $rowData['city'] = $row['city'] ?? '';
                $rowData['region'] = $row['region'] ?? '';
            }

            $rowData['language'] = $row['language'] ?? '';
            $rowData['is_bot'] = $row['isRobot'] ? 1 : 0;
            $rowData['bot_name'] = $row['botName'] ?? '';
            $rowData['user_agent'] = $row['userAgent'] ?? '';

            $rows[] = $rowData;
        }

        return [
            'rows' => $rows,
            'headers' => $headers,
            'jsonData' => $this->_exportAsJson($results, $geoEnabled),
        ];
    }

    /**
     * Delete an analytic record
     *
     * @param int $id
     * @return bool
     */
    public function deleteAnalytic(int $id): bool
    {
        try {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_analytics}}', ['id' => $id])
                ->execute();
            return true;
        } catch (\Exception $e) {
            $this->logError('Failed to delete analytic', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Clear all analytics
     *
     * @param int|array|null $siteId
     * @return int
     */
    public function clearAnalytics(int|array|null $siteId = null): int
    {
        $condition = [];
        if ($siteId) {
            $condition = ['siteId' => $siteId];
        }

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_analytics}}', $condition)
            ->execute();
    }

    /**
     * Clean up old analytics based on retention setting
     *
     * @return int Number of records deleted
     */
    public function cleanupOldAnalytics(): int
    {
        $settings = SearchManager::$plugin->getSettings();
        $retention = $settings->analyticsRetention;

        if ($retention <= 0) {
            return 0;
        }

        $date = (new \DateTime())->modify("-{$retention} days");

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(
                '{{%searchmanager_analytics}}',
                ['<', 'dateCreated', Db::prepareDateForDb($date)]
            )
            ->execute();

        if ($deleted > 0) {
            $this->logInfo('Cleaned up old analytics', ['deleted' => $deleted, 'retention' => $retention]);
        }

        return $deleted;
    }

    /**
     * Export analytics data as JSON
     *
     * @param array $results Raw query results
     * @param bool $geoEnabled Whether geo detection is enabled
     * @return array JSON export data
     */
    private function _exportAsJson(array $results, bool $geoEnabled): array
    {
        $data = [];

        foreach ($results as $row) {
            $date = \craft\helpers\DateTimeHelper::toDateTime($row['dateCreated']);

            // Get site name
            $siteName = null;
            if (!empty($row['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($row['siteId']);
                $siteName = $site ? $site->name : null;
            }

            $item = [
                'date' => $date ? $date->format('Y-m-d') : null,
                'time' => $date ? $date->format('H:i:s') : null,
                'datetime' => $date ? $date->format('c') : null,
                'query' => $row['query'],
                'hits' => (int)$row['resultsCount'],
                'synonyms' => (bool)($row['synonymsExpanded'] ?? false),
                'rules' => (int)($row['rulesMatched'] ?? 0),
                'promotions' => (int)($row['promotionsShown'] ?? 0),
                'redirected' => (bool)($row['wasRedirected'] ?? false),
                'executionTime' => $row['executionTime'] ? (float)$row['executionTime'] : null,
                'backend' => $row['backend'],
                'indexHandle' => $row['indexHandle'],
                'siteId' => $row['siteId'] ? (int)$row['siteId'] : null,
                'siteName' => $siteName,
                'intent' => $row['intent'],
                'source' => $row['source'] ?? 'frontend',
                'platform' => $row['platform'],
                'appVersion' => $row['appVersion'],
                'referrer' => $row['referrer'],
                'device' => [
                    'type' => $row['deviceType'],
                    'brand' => $row['deviceBrand'],
                    'model' => $row['deviceModel'],
                ],
                'os' => [
                    'name' => $row['osName'],
                    'version' => $row['osVersion'],
                ],
                'browser' => [
                    'name' => $row['browser'],
                    'version' => $row['browserVersion'],
                ],
                'language' => $row['language'],
                'isBot' => (bool)$row['isRobot'],
                'botName' => $row['botName'],
                'userAgent' => $row['userAgent'],
            ];

            // Add geo data if enabled
            if ($geoEnabled) {
                $item['location'] = [
                    'country' => $row['country'],
                    'city' => $row['city'],
                    'region' => $row['region'],
                ];
            }

            $data[] = $item;
        }

        return [
            'exported' => date('c'),
            'count' => count($data),
            'data' => $data,
        ];
    }
}
