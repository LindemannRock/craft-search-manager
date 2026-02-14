<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use craft\base\Component;
use craft\db\Query;
use lindemannrock\base\traits\GeoLookupTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\services\analytics\AnalyticsBreakdownService;
use lindemannrock\searchmanager\services\analytics\AnalyticsExportService;
use lindemannrock\searchmanager\services\analytics\AnalyticsPerformanceService;
use lindemannrock\searchmanager\services\analytics\AnalyticsQueryInsightsService;
use lindemannrock\searchmanager\services\analytics\AnalyticsRulesService;
use lindemannrock\searchmanager\services\analytics\AnalyticsTrackingService;

/**
 * Analytics Service
 *
 * Facade that delegates to focused sub-services for analytics functionality.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
class AnalyticsService extends Component
{
    use LoggingTrait;
    use GeoLookupTrait;

    private AnalyticsTrackingService $_tracking;
    private AnalyticsQueryInsightsService $_queryInsights;
    private AnalyticsBreakdownService $_breakdown;
    private AnalyticsPerformanceService $_performance;
    private AnalyticsRulesService $_rules;
    private AnalyticsExportService $_export;

    /**
     * Initialize the service
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');

        $this->_tracking = new AnalyticsTrackingService();
        $this->_queryInsights = new AnalyticsQueryInsightsService();
        $this->_breakdown = new AnalyticsBreakdownService();
        $this->_performance = new AnalyticsPerformanceService();
        $this->_rules = new AnalyticsRulesService();
        $this->_export = new AnalyticsExportService();
    }

    // =========================================================================
    // TRACKING
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public function trackSearch(
        string $indexHandle,
        string $query,
        int $resultsCount,
        ?float $executionTime,
        string $backend,
        ?int $siteId = null,
        array $analyticsOptions = [],
    ): void {
        $this->_tracking->trackSearch($indexHandle, $query, $resultsCount, $executionTime, $backend, $siteId, $analyticsOptions);
    }

    /**
     * @since 5.0.0
     */
    public function classifyIntent(string $query): ?string
    {
        return $this->_tracking->classifyIntent($query);
    }

    // =========================================================================
    // QUERY INSIGHTS
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public function getMostCommonSearches(int|array|null $siteId, int $limit = 10, ?string $dateRange = null): array
    {
        return $this->_queryInsights->getMostCommonSearches($siteId, $limit, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getRecentSearches(int|array|null $siteId, int $limit = 5, ?bool $hasResults = null, ?string $dateRange = null): array
    {
        return $this->_queryInsights->getRecentSearches($siteId, $limit, $hasResults, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getAnalyticsCount(int|array|null $siteId = null, ?bool $hasResults = null, ?string $dateRange = null): int
    {
        return $this->_queryInsights->getAnalyticsCount($siteId, $hasResults, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getQueryLengthDistribution(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_queryInsights->getQueryLengthDistribution($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getWordCloudData(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 50): array
    {
        return $this->_queryInsights->getWordCloudData($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getZeroResultClusters(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 20): array
    {
        return $this->_queryInsights->getZeroResultClusters($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getIntentBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_queryInsights->getIntentBreakdown($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getTrendingQueries(int|array|null $siteId, string $dateRange = 'last7days', int $limit = 10): array
    {
        return $this->_queryInsights->getTrendingQueries($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getUniqueQueriesCount(int|array|null $siteId, int $days = 30): int
    {
        return $this->_queryInsights->getUniqueQueriesCount($siteId, $days);
    }

    // =========================================================================
    // BREAKDOWNS
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public function getDeviceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getDeviceBreakdown($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getBrowserBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getBrowserBreakdown($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getOsBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getOsBreakdown($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getBotStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getBotStats($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getSourceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getSourceBreakdown($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getPeakUsageHours(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getPeakUsageHours($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getCountryBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_breakdown->getCountryBreakdown($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getCityBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_breakdown->getCityBreakdown($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getLocationFromIp(string $ip): ?array
    {
        return $this->_breakdown->getLocationFromIp($ip);
    }

    // =========================================================================
    // PERFORMANCE
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public function getPerformanceData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_performance->getPerformanceData($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getCacheStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_performance->getCacheStats($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getTopPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_performance->getTopPerformingQueries($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getWorstPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_performance->getWorstPerformingQueries($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.0.0
     */
    public function getAverageExecutionTime(int|array|null $siteId, int $days = 30): float
    {
        return $this->_performance->getAverageExecutionTime($siteId, $days);
    }

    // =========================================================================
    // RULES & PROMOTIONS
    // =========================================================================

    /**
     * @since 5.10.0
     */
    public function getTopTriggeredRules(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_rules->getTopTriggeredRules($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.10.0
     */
    public function getRulesByActionType(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_rules->getRulesByActionType($siteId, $dateRange);
    }

    /**
     * @since 5.10.0
     */
    public function getQueriesTriggeringRules(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 15): array
    {
        return $this->_rules->getQueriesTriggeringRules($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.10.0
     */
    public function getRuleAnalytics(int $ruleId, string $dateRange = 'last7days'): array
    {
        return $this->_rules->getRuleAnalytics($ruleId, $dateRange);
    }

    /**
     * @since 5.10.0
     */
    public function getTopPromotions(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_rules->getTopPromotions($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.10.0
     */
    public function getPromotionsByPosition(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_rules->getPromotionsByPosition($siteId, $dateRange);
    }

    /**
     * @since 5.10.0
     */
    public function getQueriesTriggeringPromotions(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 15): array
    {
        return $this->_rules->getQueriesTriggeringPromotions($siteId, $dateRange, $limit);
    }

    /**
     * @since 5.10.0
     */
    public function getPromotionAnalytics(int $promotionId, string $dateRange = 'last7days'): array
    {
        return $this->_rules->getPromotionAnalytics($promotionId, $dateRange);
    }

    // =========================================================================
    // EXPORT & MAINTENANCE
    // =========================================================================

    /**
     * @since 5.0.0
     */
    public function getAnalyticsSummary(int|array|null $siteId = null, string $dateRange = 'last7days'): array
    {
        return $this->_export->getAnalyticsSummary($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function getChartData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_export->getChartData($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function exportAnalytics(int|array|null $siteId, string $dateRange): array
    {
        return $this->_export->exportAnalytics($siteId, $dateRange);
    }

    /**
     * @since 5.0.0
     */
    public function deleteAnalytic(int $id): bool
    {
        return $this->_export->deleteAnalytic($id);
    }

    /**
     * @since 5.0.0
     */
    public function clearAnalytics(int|array|null $siteId = null): int
    {
        return $this->_export->clearAnalytics($siteId);
    }

    /**
     * @since 5.0.0
     */
    public function cleanupOldAnalytics(): int
    {
        return $this->_export->cleanupOldAnalytics();
    }

    /**
     * Apply date range filter to query
     *
     * @param Query $query
     * @param string $dateRange
     * @param string|null $column
     * @since 5.0.0
     */
    public function applyDateRangeFilter(Query $query, string $dateRange, ?string $column = null): void
    {
        $column = $column ?: 'dateCreated';
        \lindemannrock\base\helpers\DateRangeHelper::applyToQuery($query, $dateRange, $column);
    }

    /**
     * Get geo lookup configuration from plugin settings
     *
     * @return array<string, mixed>
     */
    protected function getGeoConfig(): array
    {
        $settings = \lindemannrock\searchmanager\SearchManager::$plugin->getSettings();

        return [
            'provider' => $settings->geoProvider ?? 'ip-api.com',
            'apiKey' => $settings->geoApiKey ?? null,
        ];
    }
}
