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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\base\traits\GeoLookupTrait;
use lindemannrock\searchmanager\helpers\AnalyticsGeoConfigHelper;
use lindemannrock\searchmanager\SearchManager;
use yii\db\Expression;

/**
 * Analytics Breakdown Service
 *
 * Device, browser, OS, source, geographic, and time breakdowns.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since 5.39.0
 */
class AnalyticsBreakdownService
{
    use AnalyticsQueryTrait;
    use GeoLookupTrait;

    /**
     * Get device breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getDeviceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        // Dimensions like deviceType are derived from the request once at write
        // time, so they're identical across all rows in an action. Group inner
        // by (dimension, action); outer counts actions per dimension.
        $perAction = (new Query())
            ->select(['deviceType'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['deviceType' => null]])
            ->groupBy(['deviceType', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        return (new Query())
            ->select(['deviceType', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('deviceType')
            ->orderBy(['count' => SORT_DESC])
            ->all();
    }

    /**
     * Get browser breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getBrowserBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $perAction = (new Query())
            ->select(['browser'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['browser' => null]])
            ->groupBy(['browser', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        return (new Query())
            ->select(['browser', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('browser')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10)
            ->all();
    }

    /**
     * Get OS breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getOsBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $perAction = (new Query())
            ->select(['osName'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['osName' => null]])
            ->groupBy(['osName', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        return (new Query())
            ->select(['osName', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('osName')
            ->orderBy(['count' => SORT_DESC])
            ->all();
    }

    /**
     * Get bot statistics
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getBotStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $hasTrafficType = $this->hasAnalyticsColumn('trafficType');
        $hasBotCategory = $this->hasAnalyticsColumn('botCategory');
        $hasBotProducerName = $this->hasAnalyticsColumn('botProducerName');

        // Inner: collapse to one row per action, carrying a traffic
        // classification derived once from the request user agent.
        $perAction = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->groupBy(new Expression($identityExpr));

        if ($hasTrafficType) {
            $perAction->select([
                'MAX(isRobot) AS actionIsRobot',
                "MAX(CASE WHEN trafficType = 'system' THEN 1 ELSE 0 END) AS actionIsSystem",
                "MAX(CASE WHEN trafficType = 'bot' THEN 1 ELSE 0 END) AS actionIsBot",
            ]);
        } else {
            $perAction->select(['MAX(isRobot) AS actionIsRobot']);
        }

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        // Outer: total = action count. Bot/system counts are deduped to search
        // actions, not raw index fan-out rows.
        $summaryQuery = (new Query())
            ->from(['t' => $perAction]);

        if ($hasTrafficType) {
            $summaryQuery->select([
                'COUNT(*) as total',
                'SUM(CASE WHEN actionIsBot = 1 THEN 1 ELSE 0 END) as bots',
                'SUM(CASE WHEN actionIsSystem = 1 THEN 1 ELSE 0 END) as systems',
            ]);
        } else {
            $summaryQuery->select([
                'COUNT(*) as total',
                'SUM(CASE WHEN actionIsRobot = 1 THEN 1 ELSE 0 END) as bots',
                new Expression('0 AS systems'),
            ]);
        }

        $result = $summaryQuery->one();

        $total = (int)($result['total'] ?? 0);
        $bots = (int)($result['bots'] ?? 0);
        $systems = (int)($result['systems'] ?? 0);
        $humans = max(0, $total - $bots - $systems);
        $nonHuman = $bots + $systems;

        // Top agents stays per-row (operational signal: "which agents are
        // hitting search how often"). A bot firing 3 index searches IS three
        // backend calls — the operational concern is request volume.
        $topAgentsSelect = ['botName', 'COUNT(*) as count'];
        $topAgentsGroup = ['botName'];
        if ($hasTrafficType) {
            $topAgentsSelect[] = 'trafficType';
            $topAgentsGroup[] = 'trafficType';
        }
        if ($hasBotCategory) {
            $topAgentsSelect[] = 'botCategory';
            $topAgentsGroup[] = 'botCategory';
        }
        if ($hasBotProducerName) {
            $topAgentsSelect[] = 'botProducerName';
            $topAgentsGroup[] = 'botProducerName';
        }

        $topAgentsQuery = (new Query())
            ->select($topAgentsSelect)
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['not', ['botName' => null]])
            ->groupBy($topAgentsGroup)
            ->orderBy(['count' => SORT_DESC])
            ->limit(10);

        if ($hasTrafficType) {
            $topAgentsQuery->andWhere(['trafficType' => ['bot', 'system']]);
        } else {
            $topAgentsQuery->andWhere(['isRobot' => 1]);
        }

        $this->applyDateRangeFilter($topAgentsQuery, $dateRange);

        if ($siteId) {
            $topAgentsQuery->andWhere(['siteId' => $siteId]);
        }

        $topAgents = array_map(static function(array $row) use ($hasTrafficType, $hasBotCategory, $hasBotProducerName): array {
            return [
                'botName' => $row['botName'] ?? null,
                'trafficType' => $hasTrafficType ? ($row['trafficType'] ?? 'bot') : 'bot',
                'botCategory' => $hasBotCategory ? ($row['botCategory'] ?? null) : null,
                'botProducerName' => $hasBotProducerName ? ($row['botProducerName'] ?? null) : null,
                'count' => (int)($row['count'] ?? 0),
            ];
        }, $topAgentsQuery->all());

        return [
            'total' => $total,
            'bots' => $bots,
            'systems' => $systems,
            'humans' => $humans,
            'botPercentage' => $total > 0 ? round(($bots / $total) * 100, 1) : 0,
            'nonHumanPercentage' => $total > 0 ? round(($nonHuman / $total) * 100, 1) : 0,
            'topBots' => $topAgents,
            'topAgents' => $topAgents,
            'chart' => [
                'labels' => ['Human', 'Bot', 'System'],
                'types' => ['human', 'bot', 'system'],
                'values' => [$humans, $bots, $systems],
            ],
        ];
    }

    /**
     * Get source breakdown (frontend, cp, api, custom sources)
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getSourceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $perAction = (new Query())
            ->select(['source'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy(['source', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        $results = (new Query())
            ->select(['source', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('source')
            ->orderBy(['count' => SORT_DESC])
            ->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            // Format source label
            $sourceLabel = match ($row['source']) {
                'frontend' => Craft::t('search-manager', 'Frontend'),
                'cp' => Craft::t('search-manager', 'Control Panel'),
                'api' => 'API',
                default => ucfirst($row['source']),
            };

            $data[] = [
                'source' => $row['source'],
                'label' => $sourceLabel,
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return [
            'data' => $data,
            'labels' => array_column($data, 'label'),
            'values' => array_column($data, 'count'),
            'percentages' => array_column($data, 'percentage'),
        ];
    }

    /**
     * Get API key attribution breakdown — searches grouped by the API key that
     * made them (slice 5). Only keyed rows are counted (`apiKeyId IS NOT NULL`),
     * so this returns an empty data set on installs that never enable
     * `requireApiKey`. The label is the key's prefix snapshot, so rows stay
     * readable after a key is revoked or deleted.
     *
     * @since 5.47.0
     */
    public function getApiKeyBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $perAction = (new Query())
            ->select(['apiKeyPrefix', 'apiKeyType'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['apiKeyId' => null]])
            ->groupBy(['apiKeyPrefix', 'apiKeyType', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        $results = (new Query())
            ->select(['apiKeyPrefix', 'apiKeyType', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy(['apiKeyPrefix', 'apiKeyType'])
            ->orderBy(['count' => SORT_DESC])
            ->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'apiKeyPrefix' => $row['apiKeyPrefix'],
                'apiKeyType' => $row['apiKeyType'],
                'label' => $row['apiKeyPrefix'] ?: '—',
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return [
            'data' => $data,
            'labels' => array_column($data, 'label'),
            'values' => array_column($data, 'count'),
            'percentages' => array_column($data, 'percentage'),
        ];
    }

    /**
     * Get peak usage hours
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getPeakUsageHours(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $hourExpr = DateFormatHelper::localHourExpression('dateCreated');
        $identityExpr = $this->actionIdentityExpression();

        // dateCreated is per-row; all rows in a multi-index action share the
        // same write timestamp (same search action) so the local-hour bucket is
        // identical across them — bucket-per-action via GROUP BY (hour, action).
        $perAction = (new Query())
            ->select(['hour' => $hourExpr])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy([$hourExpr, new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        $results = (new Query())
            ->select(['hour', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('hour')
            ->orderBy(['hour' => SORT_ASC])
            ->all();

        // Initialize all 24 hours with 0
        $hourlyData = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $hourlyData[(int)$row['hour']] = (int)$row['count'];
        }

        // Find peak hour
        $peakHour = array_search(max($hourlyData), $hourlyData);
        $peakHourFormatted = sprintf('%02d:00', $peakHour);

        return [
            'data' => array_values($hourlyData),
            'labels' => array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23)),
            'peakHour' => $peakHour,
            'peakHourFormatted' => $peakHourFormatted,
        ];
    }

    /**
     * Get country breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getCountryBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $identityExpr = $this->actionIdentityExpression();

        $perAction = (new Query())
            ->select(['country'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['country' => null]])
            ->andWhere(['!=', 'country', ''])
            ->groupBy(['country', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        $results = (new Query())
            ->select(['country', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy('country')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit)
            ->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            $code = $row['country'];
            $data[] = [
                'code' => $code,
                'name' => GeoHelper::getCountryName($code),
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return $data;
    }

    /**
     * Get city breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getCityBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $identityExpr = $this->actionIdentityExpression();

        // Two-dimensional dedup: group inner by (city, country, action) so each
        // action contributes once to its (city, country) bucket.
        $perAction = (new Query())
            ->select(['city', 'country'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['city' => null]])
            ->andWhere(['!=', 'city', ''])
            ->groupBy(['city', 'country', new Expression($identityExpr)]);

        $this->applyDateRangeFilter($perAction, $dateRange);

        if ($siteId) {
            $perAction->andWhere(['siteId' => $siteId]);
        }

        $results = (new Query())
            ->select(['city', 'country', 'COUNT(*) as count'])
            ->from(['t' => $perAction])
            ->groupBy(['city', 'country'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit)
            ->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'city' => $row['city'],
                'country' => $row['country'],
                'countryName' => GeoHelper::getCountryName($row['country'] ?? ''),
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return $data;
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
                return $this->getDefaultLocation();
            }

            // Use centralized geo lookup from base plugin
            $geoData = $this->lookupGeoIp($ip, $this->getGeoConfig());

            if ($geoData === null) {
                return null;
            }

            // Map to expected format (lat/lon instead of latitude/longitude)
            return [
                'countryCode' => $geoData['countryCode'] ?? null,
                'country' => $geoData['country'] ?? null,
                'city' => $geoData['city'] ?? null,
                'region' => $geoData['region'] ?? null,
                'lat' => $geoData['latitude'] ?? null,
                'lon' => $geoData['longitude'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get geo lookup configuration from plugin settings
     *
     * @return array<string, mixed>
     */
    protected function getGeoConfig(): array
    {
        return AnalyticsGeoConfigHelper::config();
    }

    /**
     * Get default location for local/private IPs
     *
     * @return array|null
     */
    private function getDefaultLocation(): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $defaultCountry = $settings->defaultCountry;
        $defaultCity = $settings->defaultCity;

        if (!$defaultCountry || !$defaultCity) {
            return null;
        }

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
            'NL' => [
                'Amsterdam' => ['countryCode' => 'NL', 'country' => 'Netherlands', 'city' => 'Amsterdam', 'region' => 'North Holland', 'lat' => 52.3676, 'lon' => 4.9041],
            ],
            'SE' => [
                'Stockholm' => ['countryCode' => 'SE', 'country' => 'Sweden', 'city' => 'Stockholm', 'region' => 'Stockholm County', 'lat' => 59.3293, 'lon' => 18.0686],
            ],
            'DK' => [
                'Copenhagen' => ['countryCode' => 'DK', 'country' => 'Denmark', 'city' => 'Copenhagen', 'region' => 'Capital Region of Denmark', 'lat' => 55.6761, 'lon' => 12.5683],
            ],
            'NO' => [
                'Oslo' => ['countryCode' => 'NO', 'country' => 'Norway', 'city' => 'Oslo', 'region' => 'Oslo', 'lat' => 59.9139, 'lon' => 10.7522],
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

        Craft::warning('Configured default analytics location was not found; leaving local/private IP geo fields empty. | ' . json_encode([
            'configuredCountry' => $defaultCountry,
            'configuredCity' => $defaultCity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), SearchManager::$plugin->id);

        return null;
    }
}
