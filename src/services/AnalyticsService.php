<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Analytics Service
 *
 * Tracks search queries and provides analytics data
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     1.0.0
 */
class AnalyticsService extends Component
{
    use LoggingTrait;

    /**
     * Initialize the service
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Track a search query
     *
     * @param string $indexHandle The index handle that was searched
     * @param string $query The search query
     * @param int $resultsCount Number of results returned
     * @param float|null $executionTime Query execution time in milliseconds
     * @param string $backend The search backend used (algolia, mysql, etc.)
     * @param int|null $siteId The site ID
     * @return void
     */
    public function trackSearch(
        string $indexHandle,
        string $query,
        int $resultsCount,
        ?float $executionTime,
        string $backend,
        ?int $siteId = null,
    ): void {
        $settings = SearchManager::$plugin->getSettings();

        // Check if analytics is enabled
        if (!$settings->enableAnalytics) {
            return;
        }

        // Get site ID if not provided
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        // Get referrer, IP, and user agent
        $request = Craft::$app->getRequest();
        $referer = $request->getReferrer();
        $userAgent = $request->getUserAgent();

        // Detect device information using Matomo DeviceDetector
        $deviceInfo = SearchManager::$plugin->deviceDetection->detectDevice($userAgent);

        // Multi-step IP processing for privacy and geo detection
        $ip = null;
        $geoData = null;
        $rawIp = $request->getUserIP();

        // Step 1: Subnet masking (if anonymizeIpAddress enabled)
        if ($settings->anonymizeIpAddress && $rawIp) {
            $rawIp = $this->_anonymizeIp($rawIp);
        }

        // Step 2: Get geo location (BEFORE hashing, using anonymized or full IP)
        if ($settings->enableGeoDetection && $rawIp) {
            $geoData = $this->getLocationFromIp($rawIp);
        }

        // Step 3: Hash with salt for storage
        if ($rawIp) {
            try {
                $ip = $this->_hashIpWithSalt($rawIp);
            } catch (\Exception $e) {
                $this->logError('Failed to hash IP address', ['error' => $e->getMessage()]);
                $ip = null;
            }
        }

        // Determine if this is a hit (results found)
        $isHit = $resultsCount > 0;

        // Insert analytics record directly
        try {
            Craft::$app->getDb()->createCommand()
                ->insert('{{%searchmanager_analytics}}', [
                    'indexHandle' => $indexHandle,
                    'query' => $query,
                    'resultsCount' => $resultsCount,
                    'executionTime' => $executionTime,
                    'backend' => $backend,
                    'siteId' => $siteId,
                    'ip' => $ip,
                    'userAgent' => $userAgent,
                    'referer' => $referer,
                    'isHit' => $isHit,
                    // Device detection fields
                    'deviceType' => $deviceInfo['deviceType'],
                    'deviceBrand' => $deviceInfo['deviceBrand'],
                    'deviceModel' => $deviceInfo['deviceModel'],
                    'browser' => $deviceInfo['browser'],
                    'browserVersion' => $deviceInfo['browserVersion'],
                    'browserEngine' => $deviceInfo['browserEngine'],
                    'osName' => $deviceInfo['osName'],
                    'osVersion' => $deviceInfo['osVersion'],
                    'clientType' => $deviceInfo['clientType'],
                    'isRobot' => $deviceInfo['isRobot'],
                    'isMobileApp' => $deviceInfo['isMobileApp'],
                    'botName' => $deviceInfo['botName'],
                    // Geographic data
                    'country' => $geoData['countryCode'] ?? null,
                    'city' => $geoData['city'] ?? null,
                    'region' => $geoData['region'] ?? null,
                    'latitude' => $geoData['lat'] ?? null,
                    'longitude' => $geoData['lon'] ?? null,
                    'language' => null, // Could be extracted from Accept-Language header if needed
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();

            $this->logDebug('Tracked search query', [
                'indexHandle' => $indexHandle,
                'query' => $query,
                'resultsCount' => $resultsCount,
                'backend' => $backend,
                'isHit' => $isHit,
            ]);
        } catch (\Exception $e) {
            $this->logError('Failed to track search query', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get analytics summary
     *
     * @param string $dateRange Date range filter
     * @param int|null $linkId Optional filter (not used for search, kept for compatibility)
     * @return array Analytics summary data
     */
    public function getAnalyticsSummary(string $dateRange = 'last7days', ?int $linkId = null): array
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');
        $this->applyDateRangeFilter($query, $dateRange);

        $totalSearches = (int)$query->count();
        $uniqueVisitors = (int)$query->select('COUNT(DISTINCT ip)')->scalar();
        $zeroResults = (int)(clone $query)->andWhere(['isHit' => 0])->count();
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
     */
    public function getChartData(?int $siteId, int $days = 30): array
    {
        $query = (new Query())
            ->select([
                'DATE(dateCreated) as date',
                'COUNT(*) as total',
                'SUM(CASE WHEN isHit = 1 THEN 1 ELSE 0 END) as withResults',
                'SUM(CASE WHEN isHit = 0 THEN 1 ELSE 0 END) as zeroResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))])
            ->groupBy('DATE(dateCreated)')
            ->orderBy(['date' => SORT_ASC]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
    }

    /**
     * Get most common search queries
     */
    public function getMostCommon404s(?int $siteId, int $limit = 10): array
    {
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count', 'SUM(resultsCount) as totalResults', 'MAX(dateCreated) as lastSearched'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        // Convert lastSearched dates from UTC to user's timezone
        foreach ($results as &$result) {
            if (!empty($result['lastSearched'])) {
                $utcDate = new \DateTime($result['lastSearched'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
                $result['lastSearched'] = $utcDate;
            }
        }

        return $results;
    }

    /**
     * Get recent searches
     */
    public function getRecent404s(?int $siteId, int $limit = 5, ?bool $hasResults = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            $query->andWhere(['isHit' => $hasResults ? 1 : 0]);
        }

        $results = $query->all();

        // Convert dateCreated from UTC to user's timezone
        foreach ($results as &$result) {
            if (!empty($result['dateCreated'])) {
                $utcDate = new \DateTime($result['dateCreated'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
                $result['dateCreated'] = $utcDate;
            }
        }

        return $results;
    }

    /**
     * Get analytics count
     */
    public function getAnalyticsCount(?int $siteId = null, ?bool $hasResults = null): int
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            $query->andWhere(['isHit' => $hasResults ? 1 : 0]);
        }

        return (int)$query->count();
    }

    /**
     * Get device breakdown
     */
    public function getDeviceBreakdown(?int $siteId, int $days = 30): array
    {
        $query = (new Query())
            ->select(['deviceType', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['deviceType' => null]])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))])
            ->groupBy('deviceType')
            ->orderBy(['count' => SORT_DESC]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
    }

    /**
     * Get browser breakdown
     */
    public function getBrowserBreakdown(?int $siteId, int $days = 30): array
    {
        $query = (new Query())
            ->select(['browser', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['browser' => null]])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))])
            ->groupBy('browser')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
    }

    /**
     * Get OS breakdown
     */
    public function getOsBreakdown(?int $siteId, int $days = 30): array
    {
        $query = (new Query())
            ->select(['osName', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['osName' => null]])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))])
            ->groupBy('osName')
            ->orderBy(['count' => SORT_DESC]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
    }

    /**
     * Get bot statistics
     */
    public function getBotStats(?int $siteId, int $days = 30): array
    {
        $query = (new Query())
            ->select(['COUNT(*) as total', 'SUM(CASE WHEN isRobot = 1 THEN 1 ELSE 0 END) as bots'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $result = $query->one();

        $total = (int)($result['total'] ?? 0);
        $bots = (int)($result['bots'] ?? 0);
        $humans = $total - $bots;

        // Get top bots
        $topBotsQuery = (new Query())
            ->select(['botName', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['isRobot' => 1])
            ->andWhere(['not', ['botName' => null]])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))])
            ->groupBy('botName')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10);

        if ($siteId) {
            $topBotsQuery->andWhere(['siteId' => $siteId]);
        }

        return [
            'total' => $total,
            'bots' => $bots,
            'humans' => $humans,
            'botPercentage' => $total > 0 ? round(($bots / $total) * 100, 1) : 0,
            'topBots' => $topBotsQuery->all(),
            'chart' => [
                'labels' => ['Humans', 'Bots'],
                'values' => [$humans, $bots],
            ],
        ];
    }

    /**
     * Export to CSV
     */
    public function exportToCsv(?int $siteId, ?array $analyticsIds = null): string
    {
        $query = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->select([
                'dateCreated',
                'indexHandle',
                'query',
                'resultsCount',
                'executionTime',
                'backend',
                'siteId',
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

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($analyticsIds) {
            $query->andWhere(['id' => $analyticsIds]);
        }

        $results = $query->all();

        // Check if there's any data to export
        if (empty($results)) {
            throw new \Exception('No data to export for the selected period.');
        }

        // Check if geo detection is enabled
        $settings = SearchManager::$plugin->getSettings();
        $geoEnabled = $settings->enableGeoDetection ?? false;

        // CSV headers - conditionally include geo columns
        if ($geoEnabled) {
            $csv = "Date,Time,Query,Results,Execution Time (ms),Backend,Index,Site,Referrer,Device Type,Device Brand,Device Model,OS,OS Version,Browser,Browser Version,Country,City,Region,Language,Is Bot,Bot Name,User Agent\n";
        } else {
            $csv = "Date,Time,Query,Results,Execution Time (ms),Backend,Index,Site,Referrer,Device Type,Device Brand,Device Model,OS,OS Version,Browser,Browser Version,Language,Is Bot,Bot Name,User Agent\n";
        }

        foreach ($results as $row) {
            $date = \craft\helpers\DateTimeHelper::toDateTime($row['dateCreated']);
            $dateStr = $date ? $date->format('Y-m-d') : '';
            $timeStr = $date ? $date->format('H:i:s') : '';

            // Get site name
            $siteName = '';
            if (!empty($row['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($row['siteId']);
                $siteName = $site ? $site->name : '';
            }

            if ($geoEnabled) {
                $csv .= sprintf(
                    '"%s","%s","%s",%d,%.2f,"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s",%d,"%s","%s"' . "\n",
                    $dateStr,
                    $timeStr,
                    $row['query'],
                    $row['resultsCount'],
                    $row['executionTime'] ?? 0,
                    $row['backend'],
                    $row['indexHandle'],
                    $siteName,
                    $row['referrer'] ?? '',
                    $row['deviceType'] ?? '',
                    $row['deviceBrand'] ?? '',
                    $row['deviceModel'] ?? '',
                    $row['osName'] ?? '',
                    $row['osVersion'] ?? '',
                    $row['browser'] ?? '',
                    $row['browserVersion'] ?? '',
                    $row['country'] ?? '',
                    $row['city'] ?? '',
                    $row['region'] ?? '',
                    $row['language'] ?? '',
                    $row['isRobot'] ? 1 : 0,
                    $row['botName'] ?? '',
                    $row['userAgent'] ?? ''
                );
            } else {
                $csv .= sprintf(
                    '"%s","%s","%s",%d,%.2f,"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s",%d,"%s","%s"' . "\n",
                    $dateStr,
                    $timeStr,
                    $row['query'],
                    $row['resultsCount'],
                    $row['executionTime'] ?? 0,
                    $row['backend'],
                    $row['indexHandle'],
                    $siteName,
                    $row['referrer'] ?? '',
                    $row['deviceType'] ?? '',
                    $row['deviceBrand'] ?? '',
                    $row['deviceModel'] ?? '',
                    $row['osName'] ?? '',
                    $row['osVersion'] ?? '',
                    $row['browser'] ?? '',
                    $row['browserVersion'] ?? '',
                    $row['language'] ?? '',
                    $row['isRobot'] ? 1 : 0,
                    $row['botName'] ?? '',
                    $row['userAgent'] ?? ''
                );
            }
        }

        return $csv;
    }

    /**
     * Delete an analytic record
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
     */
    public function clearAnalytics(?int $siteId = null): int
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
     * Apply date range filter to query
     */
    private function applyDateRangeFilter(Query $query, string $dateRange, ?string $column = null): void
    {
        $column = $column ?: 'dateCreated';

        switch ($dateRange) {
            case 'today':
                $query->andWhere(['>=', $column, Db::prepareDateForDb(new \DateTime('today'))]);
                break;
            case 'last7days':
                $query->andWhere(['>=', $column, Db::prepareDateForDb((new \DateTime())->modify('-7 days'))]);
                break;
            case 'last30days':
                $query->andWhere(['>=', $column, Db::prepareDateForDb((new \DateTime())->modify('-30 days'))]);
                break;
            case 'last90days':
                $query->andWhere(['>=', $column, Db::prepareDateForDb((new \DateTime())->modify('-90 days'))]);
                break;
            case 'alltime':
                // No filter
                break;
        }
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
     * Get location data from IP address
     *
     * @param string $ip
     * @return array|null
     */
    public function getLocationFromIp(string $ip): ?array
    {
        try {
            // Skip local/private IPs - return default location data for local development
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                // Get default location from settings or env
                $settings = SearchManager::$plugin->getSettings();
                $defaultCountry = $settings->defaultCountry ?: (getenv('SEARCH_MANAGER_DEFAULT_COUNTRY') ?: 'AE');
                $defaultCity = $settings->defaultCity ?: (getenv('SEARCH_MANAGER_DEFAULT_CITY') ?: 'Dubai');

                // Predefined locations for common cities worldwide
                $locations = [
                    'US' => [
                        'New York' => ['countryCode' => 'US', 'country' => 'United States', 'city' => 'New York', 'region' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060],
                        'Los Angeles' => ['countryCode' => 'US', 'country' => 'United States', 'city' => 'Los Angeles', 'region' => 'California', 'lat' => 34.0522, 'lon' => -118.2437],
                        'Chicago' => ['countryCode' => 'US', 'country' => 'United States', 'city' => 'Chicago', 'region' => 'Illinois', 'lat' => 41.8781, 'lon' => -87.6298],
                        'San Francisco' => ['countryCode' => 'US', 'country' => 'United States', 'city' => 'San Francisco', 'region' => 'California', 'lat' => 37.7749, 'lon' => -122.4194],
                    ],
                    'GB' => [
                        'London' => ['countryCode' => 'GB', 'country' => 'United Kingdom', 'city' => 'London', 'region' => 'England', 'lat' => 51.5074, 'lon' => -0.1278],
                        'Manchester' => ['countryCode' => 'GB', 'country' => 'United Kingdom', 'city' => 'Manchester', 'region' => 'England', 'lat' => 53.4808, 'lon' => -2.2426],
                    ],
                    'AE' => [
                        'Dubai' => ['countryCode' => 'AE', 'country' => 'United Arab Emirates', 'city' => 'Dubai', 'region' => 'Dubai', 'lat' => 25.2048, 'lon' => 55.2708],
                        'Abu Dhabi' => ['countryCode' => 'AE', 'country' => 'United Arab Emirates', 'city' => 'Abu Dhabi', 'region' => 'Abu Dhabi', 'lat' => 24.4539, 'lon' => 54.3773],
                    ],
                    'SA' => [
                        'Riyadh' => ['countryCode' => 'SA', 'country' => 'Saudi Arabia', 'city' => 'Riyadh', 'region' => 'Riyadh Province', 'lat' => 24.7136, 'lon' => 46.6753],
                        'Jeddah' => ['countryCode' => 'SA', 'country' => 'Saudi Arabia', 'city' => 'Jeddah', 'region' => 'Makkah Province', 'lat' => 21.5433, 'lon' => 39.1728],
                    ],
                    'DE' => [
                        'Berlin' => ['countryCode' => 'DE', 'country' => 'Germany', 'city' => 'Berlin', 'region' => 'Berlin', 'lat' => 52.5200, 'lon' => 13.4050],
                        'Munich' => ['countryCode' => 'DE', 'country' => 'Germany', 'city' => 'Munich', 'region' => 'Bavaria', 'lat' => 48.1351, 'lon' => 11.5820],
                    ],
                    'FR' => [
                        'Paris' => ['countryCode' => 'FR', 'country' => 'France', 'city' => 'Paris', 'region' => 'Île-de-France', 'lat' => 48.8566, 'lon' => 2.3522],
                    ],
                    'CA' => [
                        'Toronto' => ['countryCode' => 'CA', 'country' => 'Canada', 'city' => 'Toronto', 'region' => 'Ontario', 'lat' => 43.6532, 'lon' => -79.3832],
                        'Vancouver' => ['countryCode' => 'CA', 'country' => 'Canada', 'city' => 'Vancouver', 'region' => 'British Columbia', 'lat' => 49.2827, 'lon' => -123.1207],
                    ],
                    'AU' => [
                        'Sydney' => ['countryCode' => 'AU', 'country' => 'Australia', 'city' => 'Sydney', 'region' => 'New South Wales', 'lat' => -33.8688, 'lon' => 151.2093],
                        'Melbourne' => ['countryCode' => 'AU', 'country' => 'Australia', 'city' => 'Melbourne', 'region' => 'Victoria', 'lat' => -37.8136, 'lon' => 144.9631],
                    ],
                    'JP' => [
                        'Tokyo' => ['countryCode' => 'JP', 'country' => 'Japan', 'city' => 'Tokyo', 'region' => 'Tokyo', 'lat' => 35.6762, 'lon' => 139.6503],
                    ],
                    'SG' => [
                        'Singapore' => ['countryCode' => 'SG', 'country' => 'Singapore', 'city' => 'Singapore', 'region' => 'Singapore', 'lat' => 1.3521, 'lon' => 103.8198],
                    ],
                    'IN' => [
                        'Mumbai' => ['countryCode' => 'IN', 'country' => 'India', 'city' => 'Mumbai', 'region' => 'Maharashtra', 'lat' => 19.0760, 'lon' => 72.8777],
                        'Delhi' => ['countryCode' => 'IN', 'country' => 'India', 'city' => 'Delhi', 'region' => 'Delhi', 'lat' => 28.7041, 'lon' => 77.1025],
                    ],
                ];

                // Return the configured location if it exists
                if (isset($locations[$defaultCountry][$defaultCity])) {
                    return $locations[$defaultCountry][$defaultCity];
                }

                // Fallback to Dubai if configuration not found
                return $locations['AE']['Dubai'];
            }

            // Use ip-api.com (free, no API key required, 45 requests per minute)
            $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,country,city,regionName,region,lat,lon";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    return [
                        'countryCode' => $data['countryCode'] ?? null,
                        'country' => $data['country'] ?? null,
                        'city' => $data['city'] ?? null,
                        'region' => $data['regionName'] ?? null,
                        'lat' => $data['lat'] ?? null,
                        'lon' => $data['lon'] ?? null,
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logWarning('Failed to get location from IP', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Anonymize IP address (keep first 3 octets for IPv4, first 4 segments for IPv6)
     *
     * @param string|null $ip
     * @return string|null
     */
    private function _anonymizeIp(?string $ip): ?string
    {
        if (empty($ip)) {
            return null;
        }

        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            // Keep first 4 segments, anonymize the rest
            $parts = array_slice($parts, 0, 4);
            return implode(':', $parts) . '::';
        }

        return null;
    }

    /**
     * Hash IP address with salt for privacy
     *
     * Uses SHA256 with a secret salt to hash IPs. This prevents rainbow table attacks
     * while still allowing unique visitor tracking (same IP = same hash).
     *
     * @param string $ip The IP address to hash
     * @return string Hashed IP address (64 characters)
     * @throws \Exception If salt is not configured
     */
    private function _hashIpWithSalt(string $ip): string
    {
        $settings = SearchManager::$plugin->getSettings();
        $salt = $settings->ipHashSalt;

        if (!$salt || $salt === '$SEARCH_MANAGER_IP_SALT' || trim($salt) === '') {
            $this->logError('IP hash salt not configured - analytics tracking disabled', [
                'ip' => 'hidden',
                'saltValue' => $salt ?? 'NULL',
            ]);
            throw new \Exception('IP hash salt not configured. Run: php craft search-manager/security/generate-salt');
        }

        return hash('sha256', $ip . $salt);
    }
}
