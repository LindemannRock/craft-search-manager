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

    /** @inheritdoc */
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
     */
    public function trackSearch(
        string $indexHandle,
        string $query,
        int $resultsCount,
        ?float $executionTime,
        string $backend,
        ?int $siteId = null,
        array $analyticsOptions = [],
        ?string $sessionId = null,
    ): void {
        $this->_tracking->trackSearch($indexHandle, $query, $resultsCount, $executionTime, $backend, $siteId, $analyticsOptions, $sessionId);
    }

    /**
     */
    public function classifyIntent(string $query): ?string
    {
        return $this->_tracking->classifyIntent($query);
    }

    // =========================================================================
    // QUERY INSIGHTS
    // =========================================================================

    /**
     */
    public function getMostCommonSearches(int|array|null $siteId, int $limit = 10, ?string $dateRange = null): array
    {
        return $this->_queryInsights->getMostCommonSearches($siteId, $limit, $dateRange);
    }

    /**
     */
    public function getRecentSearches(int|array|null $siteId, int $limit = 5, ?bool $hasResults = null, ?string $dateRange = null): array
    {
        return $this->_queryInsights->getRecentSearches($siteId, $limit, $hasResults, $dateRange);
    }

    /**
     */
    public function getAnalyticsCount(int|array|null $siteId = null, ?bool $hasResults = null, ?string $dateRange = null): int
    {
        return $this->_queryInsights->getAnalyticsCount($siteId, $hasResults, $dateRange);
    }

    /**
     */
    public function getQueryLengthDistribution(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_queryInsights->getQueryLengthDistribution($siteId, $dateRange);
    }

    /**
     */
    public function getWordCloudData(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 50): array
    {
        return $this->_queryInsights->getWordCloudData($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getZeroResultClusters(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 20): array
    {
        return $this->_queryInsights->getZeroResultClusters($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getIntentBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_queryInsights->getIntentBreakdown($siteId, $dateRange);
    }

    /**
     */
    public function getTrendingQueries(int|array|null $siteId, string $dateRange = 'last7days', int $limit = 10): array
    {
        return $this->_queryInsights->getTrendingQueries($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getUniqueQueriesCount(int|array|null $siteId, int $days = 30): int
    {
        return $this->_queryInsights->getUniqueQueriesCount($siteId, $days);
    }

    // =========================================================================
    // BREAKDOWNS
    // =========================================================================

    /**
     */
    public function getDeviceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getDeviceBreakdown($siteId, $dateRange);
    }

    /**
     */
    public function getBrowserBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getBrowserBreakdown($siteId, $dateRange);
    }

    /**
     */
    public function getOsBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getOsBreakdown($siteId, $dateRange);
    }

    /**
     */
    public function getBotStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getBotStats($siteId, $dateRange);
    }

    /**
     */
    public function getSourceBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getSourceBreakdown($siteId, $dateRange);
    }

    /**
     */
    public function getPeakUsageHours(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_breakdown->getPeakUsageHours($siteId, $dateRange);
    }

    /**
     */
    public function getCountryBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_breakdown->getCountryBreakdown($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getCityBreakdown(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_breakdown->getCityBreakdown($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getLocationFromIp(string $ip): ?array
    {
        return $this->_breakdown->getLocationFromIp($ip);
    }

    // =========================================================================
    // PERFORMANCE
    // =========================================================================

    /**
     */
    public function getPerformanceData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_performance->getPerformanceData($siteId, $dateRange);
    }

    /**
     */
    public function getCacheStats(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_performance->getCacheStats($siteId, $dateRange);
    }

    /**
     */
    public function getTopPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_performance->getTopPerformingQueries($siteId, $dateRange, $limit);
    }

    /**
     */
    public function getWorstPerformingQueries(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        return $this->_performance->getWorstPerformingQueries($siteId, $dateRange, $limit);
    }

    /**
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
     */
    public function getAnalyticsSummary(int|array|null $siteId = null, string $dateRange = 'last7days'): array
    {
        return $this->_export->getAnalyticsSummary($siteId, $dateRange);
    }

    /**
     */
    public function getChartData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        return $this->_export->getChartData($siteId, $dateRange);
    }

    /**
     */
    public function exportAnalytics(int|array|null $siteId, string $dateRange): array
    {
        return $this->_export->exportAnalytics($siteId, $dateRange);
    }

    /**
     */
    public function deleteAnalytic(int $id): bool
    {
        return $this->_export->deleteAnalytic($id);
    }

    /**
     */
    public function clearAnalytics(int|array|null $siteId = null): int
    {
        return $this->_export->clearAnalytics($siteId);
    }

    /**
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
