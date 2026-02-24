<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services\analytics;

use craft\db\Query;
use craft\helpers\App;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\base\traits\GeoLookupTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Analytics Breakdown Service
 *
 * Device, browser, OS, source, geographic, and time breakdowns.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
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
        $query = (new Query())
            ->select(['deviceType', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['deviceType' => null]])
            ->groupBy('deviceType')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
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
        $query = (new Query())
            ->select(['browser', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['browser' => null]])
            ->groupBy('browser')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
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
        $query = (new Query())
            ->select(['osName', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['osName' => null]])
            ->groupBy('osName')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->all();
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
        $query = (new Query())
            ->select(['COUNT(*) as total', 'SUM(CASE WHEN isRobot = 1 THEN 1 ELSE 0 END) as bots'])
            ->from('{{%searchmanager_analytics}}');

        $this->applyDateRangeFilter($query, $dateRange);

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
            ->groupBy('botName')
            ->orderBy(['count' => SORT_DESC])
            ->limit(10);

        $this->applyDateRangeFilter($topBotsQuery, $dateRange);

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
     * Get source breakdown (frontend, cp, api, custom sources)
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getSourceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['source', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('source')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            // Format source label
            $sourceLabel = match ($row['source']) {
                'frontend' => 'Frontend',
                'cp' => 'Control Panel',
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
     * Get peak usage hours
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getPeakUsageHours(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $hourExpr = DateFormatHelper::localHourExpression('dateCreated');

        $query = (new Query())
            ->select([
                'hour' => $hourExpr,
                'COUNT(*) as count',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy($hourExpr)
            ->orderBy(['hour' => SORT_ASC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

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
        $query = (new Query())
            ->select(['country', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['country' => null]])
            ->andWhere(['!=', 'country', ''])
            ->groupBy('country')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
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
        $query = (new Query())
            ->select(['city', 'country', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['city' => null]])
            ->andWhere(['!=', 'city', ''])
            ->groupBy(['city', 'country'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
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
        } catch (\Exception $e) {
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
        $settings = SearchManager::$plugin->getSettings();

        return [
            'provider' => $settings->geoProvider ?? 'ip-api.com',
            'apiKey' => $settings->geoApiKey ?? null,
        ];
    }

    /**
     * Get default location for local/private IPs
     *
     * @return array
     */
    private function getDefaultLocation(): array
    {
        $settings = SearchManager::$plugin->getSettings();
        $defaultCountry = $settings->defaultCountry ?: (App::env('SEARCH_MANAGER_DEFAULT_COUNTRY') ?: 'AE');
        $defaultCity = $settings->defaultCity ?: (App::env('SEARCH_MANAGER_DEFAULT_CITY') ?: 'Dubai');

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
}
