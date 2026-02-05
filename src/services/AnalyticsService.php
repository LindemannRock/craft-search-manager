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
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\base\traits\GeoLookupTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Analytics Service
 *
 * Tracks search queries and provides analytics data
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
class AnalyticsService extends Component
{
    use LoggingTrait;
    use GeoLookupTrait;

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
     * @param array $analyticsOptions Optional analytics options:
     *   - source: The source of the search (frontend, cp, api, ios-app, android-app, etc.)
     *   - trigger: What triggered the tracking (click, enter, idle, unknown)
     *   - platform: The platform info (iOS 17, Android 14, Windows 11, etc.)
     *   - appVersion: The app version (1.0.0, 2.3.1, etc.)
     *   - synonymsExpanded: Whether query was expanded with synonyms
     *   - rulesMatched: Number of query rules that matched
     *   - promotionsShown: Number of promotions shown
     *   - wasRedirected: Whether a redirect rule matched
     *   - matchedRules: Array of matched QueryRule objects (for detailed tracking)
     *   - matchedPromotions: Array of matched Promotion objects (for detailed tracking)
     * @return void
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
        $settings = SearchManager::$plugin->getSettings();

        // Check if global analytics is enabled
        if (!$settings->enableAnalytics) {
            return;
        }

        // Check if index-level analytics is enabled
        // Handle 'all', comma-joined indices, and single index handles
        // IMPORTANT: Resolve to only indices with enableAnalytics=true to avoid recording disabled indices
        if ($indexHandle === 'all') {
            // For 'all': resolve enabled indices, filter to those with analytics enabled
            $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
            $analyticsEnabledHandles = [];
            foreach ($allIndices as $idx) {
                if ($idx->enabled && $idx->enableAnalytics) {
                    $analyticsEnabledHandles[] = $idx->handle;
                }
            }
            if (empty($analyticsEnabledHandles)) {
                $this->logDebug('Analytics disabled for all indices', ['indexHandle' => $indexHandle]);
                return;
            }
            // Use resolved handles so record doesn't implicitly include disabled indices
            $indexHandle = implode(',', $analyticsEnabledHandles);
        } elseif (str_contains($indexHandle, ',')) {
            // Comma-joined indices: filter to only those that are enabled AND have analytics enabled
            $handles = array_map('trim', explode(',', $indexHandle));
            $analyticsEnabledHandles = [];
            foreach ($handles as $handle) {
                $idx = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($handle);
                if ($idx && $idx->enabled && $idx->enableAnalytics) {
                    $analyticsEnabledHandles[] = $handle;
                }
            }
            if (empty($analyticsEnabledHandles)) {
                $this->logDebug('Analytics disabled for all specified indices', ['indexHandle' => $indexHandle]);
                return;
            }
            // Use filtered handles so disabled indices aren't represented
            $indexHandle = implode(',', $analyticsEnabledHandles);
        } else {
            // Single index handle - require both enabled and enableAnalytics
            $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
            if ($index && (!$index->enabled || !$index->enableAnalytics)) {
                $this->logDebug('Analytics disabled for index', ['indexHandle' => $indexHandle, 'enabled' => $index->enabled, 'enableAnalytics' => $index->enableAnalytics]);
                return;
            }
        }

        // Get site ID if not provided
        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        // Extract analytics options
        $source = $analyticsOptions['source'] ?? null;
        $trigger = $analyticsOptions['trigger'] ?? null;
        $platform = $analyticsOptions['platform'] ?? null;
        $appVersion = $analyticsOptions['appVersion'] ?? null;

        // Extract query rules & promotions tracking options
        $synonymsExpanded = $analyticsOptions['synonymsExpanded'] ?? false;
        $rulesMatched = $analyticsOptions['rulesMatched'] ?? 0;
        $promotionsShown = $analyticsOptions['promotionsShown'] ?? 0;
        $wasRedirected = $analyticsOptions['wasRedirected'] ?? false;
        $matchedRules = $analyticsOptions['matchedRules'] ?? [];
        $matchedPromotions = $analyticsOptions['matchedPromotions'] ?? [];

        // Get referrer, IP, and user agent
        $request = Craft::$app->getRequest();
        $referer = $request->getReferrer();
        $userAgent = $request->getUserAgent();

        // Auto-detect source if not provided
        if ($source === null) {
            $source = $this->_detectSource($request, $referer);
        }

        // Detect device information using Matomo DeviceDetector
        $deviceInfo = SearchManager::$plugin->deviceDetection->detectDevice($userAgent);

        // Multi-step IP processing for privacy
        $ip = null;
        $rawIp = $request->getUserIP();
        $ipForGeoLookup = null;

        // Step 1: Subnet masking (if anonymizeIpAddress enabled)
        if ($settings->anonymizeIpAddress && $rawIp) {
            $rawIp = $this->_anonymizeIp($rawIp);
        }

        // Step 2: Store IP for async geo-lookup (BEFORE hashing)
        if ($settings->enableGeoDetection && $rawIp) {
            $ipForGeoLookup = $rawIp;
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

        // Classify search intent
        $intent = $this->classifyIntent($query);

        // Insert analytics record directly (geo data will be populated async)
        try {
            $db = Craft::$app->getDb();
            $db->createCommand()
                ->insert('{{%searchmanager_analytics}}', [
                    'indexHandle' => $indexHandle,
                    'query' => $query,
                    'resultsCount' => $resultsCount,
                    'executionTime' => $executionTime,
                    'backend' => $backend,
                    'siteId' => $siteId,
                    'intent' => $intent,
                    'source' => $source,
                    'trigger' => $trigger,
                    'platform' => $platform,
                    'appVersion' => $appVersion,
                    'ip' => $ip,
                    'userAgent' => $userAgent,
                    'referer' => $referer,
                    'isHit' => $isHit,
                    // Query rules & promotions tracking
                    'synonymsExpanded' => $synonymsExpanded,
                    'rulesMatched' => $rulesMatched,
                    'promotionsShown' => $promotionsShown,
                    'wasRedirected' => $wasRedirected,
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
                    // Geographic data - populated async via GeoLookupJob
                    'country' => null,
                    'city' => null,
                    'region' => null,
                    'latitude' => null,
                    'longitude' => null,
                    'language' => null, // Could be extracted from Accept-Language header if needed
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();

            // Get the inserted record ID for async geo-lookup
            $analyticsId = (int) $db->getLastInsertID();

            $this->logDebug('Tracked search query', [
                'indexHandle' => $indexHandle,
                'query' => $query,
                'resultsCount' => $resultsCount,
                'backend' => $backend,
                'isHit' => $isHit,
                'analyticsId' => $analyticsId,
            ]);

            // Queue async geo-lookup if enabled and we have an IP
            if ($ipForGeoLookup && $analyticsId) {
                Craft::$app->getQueue()->push(new \lindemannrock\searchmanager\jobs\GeoLookupJob([
                    'analyticsId' => $analyticsId,
                    'ip' => $ipForGeoLookup,
                ]));
            }

            // Track detailed rule analytics
            if (!empty($matchedRules)) {
                $this->trackRuleAnalytics($matchedRules, $query, $indexHandle, $siteId, $resultsCount);
            }

            // Track detailed promotion analytics
            if (!empty($matchedPromotions)) {
                $this->trackPromotionAnalytics($matchedPromotions, $query, $indexHandle, $siteId);
            }
        } catch (\Exception $e) {
            $this->logError('Failed to track search query', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Track detailed analytics for matched query rules
     *
     * @param array $matchedRules Array of QueryRule objects
     * @param string $query The search query
     * @param string $indexHandle The index handle
     * @param int|null $siteId The site ID
     * @param int $resultsCount Results count after rules applied
     */
    private function trackRuleAnalytics(array $matchedRules, string $query, string $indexHandle, ?int $siteId, int $resultsCount): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($matchedRules as $rule) {
            try {
                Craft::$app->getDb()->createCommand()
                    ->insert('{{%searchmanager_rule_analytics}}', [
                        'queryRuleId' => $rule->id,
                        'ruleName' => $rule->name,
                        'actionType' => $rule->actionType,
                        'query' => $query,
                        'indexHandle' => $indexHandle,
                        'siteId' => $siteId,
                        'resultsCount' => $resultsCount,
                        'dateCreated' => $now,
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])
                    ->execute();
            } catch (\Exception $e) {
                $this->logError('Failed to track rule analytics', [
                    'ruleId' => $rule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Track detailed analytics for matched promotions
     *
     * @param array $matchedPromotions Array of Promotion objects with position info
     * @param string $query The search query
     * @param string $indexHandle The index handle
     * @param int|null $siteId The site ID
     */
    private function trackPromotionAnalytics(array $matchedPromotions, string $query, string $indexHandle, ?int $siteId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($matchedPromotions as $promo) {
            try {
                // Get element title for denormalized storage
                $elementTitle = null;
                $element = Craft::$app->getElements()->getElementById($promo->elementId);
                if ($element) {
                    $elementTitle = $element->title ?? (string)$element;
                }

                Craft::$app->getDb()->createCommand()
                    ->insert('{{%searchmanager_promotion_analytics}}', [
                        'promotionId' => $promo->id,
                        'elementId' => $promo->elementId,
                        'elementTitle' => $elementTitle,
                        'query' => $query,
                        'position' => $promo->position,
                        'indexHandle' => $indexHandle,
                        'siteId' => $siteId,
                        'dateCreated' => $now,
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])
                    ->execute();
            } catch (\Exception $e) {
                $this->logError('Failed to track promotion analytics', [
                    'promotionId' => $promo->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get query length distribution
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getQueryLengthDistribution(?int $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('query');

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        $distribution = [
            '1 word' => 0,
            '2-3 words' => 0,
            '4+ words' => 0,
        ];

        foreach ($results as $row) {
            $wordCount = str_word_count($row['query']);
            $count = (int)$row['count'];

            if ($wordCount === 1) {
                $distribution['1 word'] += $count;
            } elseif ($wordCount >= 2 && $wordCount <= 3) {
                $distribution['2-3 words'] += $count;
            } else {
                $distribution['4+ words'] += $count;
            }
        }

        return [
            'labels' => array_keys($distribution),
            'values' => array_values($distribution),
        ];
    }

    /**
     * Get word cloud data
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getWordCloudData(?int $siteId, string $dateRange = 'last30days', int $limit = 50): array
    {
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('query');

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        $words = [];
        $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were'];

        foreach ($results as $row) {
            // Simple tokenization
            $tokens = explode(' ', strtolower(trim($row['query'])));
            foreach ($tokens as $token) {
                $token = trim($token);
                // Skip empty or stop words
                if ($token === '' || in_array($token, $stopWords)) {
                    continue;
                }
                // Skip numbers/symbols if desired, or keep them
                $words[$token] = ($words[$token] ?? 0) + (int)$row['count'];
            }
        }

        arsort($words);
        $topWords = array_slice($words, 0, $limit);

        $cloudData = [];
        foreach ($topWords as $word => $count) {
            $cloudData[] = [
                'text' => $word,
                'weight' => $count,
            ];
        }

        return $cloudData;
    }

    /**
     * Get zero-result clusters (content gaps)
     * Groups similar failed queries together
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getZeroResultClusters(?int $siteId, string $dateRange = 'last30days', int $limit = 20): array
    {
        // 1. Get all zero-result queries (excluding handled searches: redirected or showed promotions)
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count', 'MAX(dateCreated) as lastSearched'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit * 3); // Get more candidates for clustering

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $failedQueries = $query->all();
        $clusters = [];

        foreach ($failedQueries as $row) {
            $term = strtolower(trim($row['query']));
            $matched = false;

            // Try to add to an existing cluster
            foreach ($clusters as &$cluster) {
                // Simple similarity check: contains or is contained by representative
                // or Levenshtein distance is small
                $rep = $cluster['representative'];

                if (str_contains($rep, $term) || str_contains($term, $rep) || levenshtein($term, $rep) <= 2) {
                    $cluster['count'] += (int)$row['count'];
                    $cluster['queries'][] = $row['query'];
                    // Update representative if this term is more frequent (handled by sorting order)
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $clusters[] = [
                    'representative' => $term,
                    'count' => (int)$row['count'],
                    'queries' => [$row['query']],
                    'lastSearched' => $row['lastSearched'],
                ];
            }
        }

        // Sort clusters by count
        usort($clusters, fn($a, $b) => $b['count'] <=> $a['count']);

        // Convert lastSearched from UTC to user's timezone for display
        $userTz = new \DateTimeZone(Craft::$app->getTimeZone());
        $result = array_slice($clusters, 0, $limit);
        foreach ($result as &$cluster) {
            if (!empty($cluster['lastSearched'])) {
                $utcDate = new \DateTime($cluster['lastSearched'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone($userTz);
                $cluster['lastSearched'] = $utcDate->format('Y-m-d H:i:s');
            }
        }

        return $result;
    }

    /**
     * Get analytics summary
     *
     * @param string $dateRange Date range filter
     * @param int|null $linkId Optional filter (not used for search, kept for compatibility)
     * @return array Analytics summary data
     * @since 5.0.0
     */
    public function getAnalyticsSummary(string $dateRange = 'last7days', ?int $linkId = null): array
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');
        $this->applyDateRangeFilter($query, $dateRange);

        $totalSearches = (int)$query->count();
        $uniqueVisitors = (int)$query->select('COUNT(DISTINCT ip)')->scalar();
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getChartData(?int $siteId, string $dateRange = 'last30days'): array
    {
        $localDateExpr = $this->getLocalDateExpression('dateCreated');

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
     * Get most common search queries
     *
     * @param int|null $siteId
     * @param int $limit
     * @param string|null $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getMostCommonSearches(?int $siteId, int $limit = 10, ?string $dateRange = null): array
    {
        $query = (new Query())
            ->select(['query', 'siteId', 'COUNT(*) as count', 'SUM(resultsCount) as totalResults', 'MAX(dateCreated) as lastSearched'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy(['query', 'siteId'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        if ($dateRange !== null) {
            $this->applyDateRangeFilter($query, $dateRange);
        }

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        // Convert lastSearched dates from UTC to user's timezone
        $userTz = new \DateTimeZone(Craft::$app->getTimeZone());
        foreach ($results as &$result) {
            if (!empty($result['lastSearched'])) {
                $utcDate = new \DateTime($result['lastSearched'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone($userTz);
                $result['lastSearched'] = $utcDate->format('Y-m-d H:i:s');
            }
        }

        return $results;
    }

    /**
     * Get recent searches
     *
     * @param int|null $siteId
     * @param int $limit
     * @param bool|null $hasResults
     * @param string|null $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getRecentSearches(?int $siteId, int $limit = 5, ?bool $hasResults = null, ?string $dateRange = null): array
    {
        $query = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            if ($hasResults) {
                // With results: found search results OR was redirected OR showed promotions
                $query->andWhere(['or', ['isHit' => 1], ['wasRedirected' => 1], ['>', 'promotionsShown', 0]]);
            } else {
                // No results: no search results AND not redirected AND no promotions (true content gaps)
                $query->andWhere(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0]);
            }
        }

        if ($dateRange !== null) {
            $tz = new \DateTimeZone(Craft::$app->getTimeZone());

            switch ($dateRange) {
                case 'today':
                    $start = new \DateTime('now', $tz);
                    $start->setTime(0, 0, 0);
                    $start->setTimezone(new \DateTimeZone('UTC'));
                    $query->andWhere(['>=', 'dateCreated', $start->format('Y-m-d H:i:s')]);
                    break;
                case 'yesterday':
                    $start = new \DateTime('now', $tz);
                    $start->modify('-1 day')->setTime(0, 0, 0);
                    $start->setTimezone(new \DateTimeZone('UTC'));

                    $end = new \DateTime('now', $tz);
                    $end->setTime(0, 0, 0);
                    $end->setTimezone(new \DateTimeZone('UTC'));

                    $query->andWhere(['>=', 'dateCreated', $start->format('Y-m-d H:i:s')]);
                    $query->andWhere(['<', 'dateCreated', $end->format('Y-m-d H:i:s')]);
                    break;
                case 'last7days':
                    $start = new \DateTime('now', $tz);
                    $start->modify('-7 days');
                    $start->setTimezone(new \DateTimeZone('UTC'));
                    $query->andWhere(['>=', 'dateCreated', $start->format('Y-m-d H:i:s')]);
                    break;
                case 'last30days':
                    $start = new \DateTime('now', $tz);
                    $start->modify('-30 days');
                    $start->setTimezone(new \DateTimeZone('UTC'));
                    $query->andWhere(['>=', 'dateCreated', $start->format('Y-m-d H:i:s')]);
                    break;
                case 'last90days':
                    $start = new \DateTime('now', $tz);
                    $start->modify('-90 days');
                    $start->setTimezone(new \DateTimeZone('UTC'));
                    $query->andWhere(['>=', 'dateCreated', $start->format('Y-m-d H:i:s')]);
                    break;
                case 'all':
                    // No date filter
                    break;
            }
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
     *
     * @param int|null $siteId
     * @param bool|null $hasResults
     * @param string|null $dateRange
     * @return int
     * @since 5.0.0
     */
    public function getAnalyticsCount(?int $siteId = null, ?bool $hasResults = null, ?string $dateRange = null): int
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            if ($hasResults) {
                // With results: found search results OR was redirected OR showed promotions
                $query->andWhere(['or', ['isHit' => 1], ['wasRedirected' => 1], ['>', 'promotionsShown', 0]]);
            } else {
                // No results: no search results AND not redirected AND no promotions (true content gaps)
                $query->andWhere(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0]);
            }
        }

        if ($dateRange !== null) {
            $this->applyDateRangeFilter($query, $dateRange);
        }

        return (int)$query->count();
    }

    /**
     * Get device breakdown
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getDeviceBreakdown(?int $siteId, string $dateRange = 'last30days'): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getBrowserBreakdown(?int $siteId, string $dateRange = 'last30days'): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getOsBreakdown(?int $siteId, string $dateRange = 'last30days'): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getBotStats(?int $siteId, string $dateRange = 'last30days'): array
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
     * Export analytics data
     *
     * @param int|null $siteId Optional site ID to filter by
     * @param string $dateRange Date range to filter
     * @return array Export data (rows, headers, jsonData)
     * @since 5.0.0
     */
    public function exportAnalytics(?int $siteId, string $dateRange): array
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

        // Check if there's any data to export
        if (empty($results)) {
            throw new \Exception('No data to export for the selected period.');
        }

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

    /**
     * Delete an analytic record
     *
     * @param int $id
     * @return bool
     * @since 5.0.0
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
     * @param int|null $siteId
     * @return int
     * @since 5.0.0
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
     *
     * @param Query $query
     * @param string $dateRange
     * @param string|null $column
     * @return void
     * @since 5.0.0
     */
    public function applyDateRangeFilter(Query $query, string $dateRange, ?string $column = null): void
    {
        $column = $column ?: 'dateCreated';
        DateRangeHelper::applyToQuery($query, $dateRange, $column);
    }

    /**
     * Clean up old analytics based on retention setting
     *
     * @return int Number of records deleted
     * @since 5.0.0
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
     * @since 5.0.0
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
            $this->logWarning('Failed to get location from IP', ['error' => $e->getMessage()]);
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

    /**
     * Detect the source of the search request
     *
     * Detection logic:
     * - CP request: Craft::$app->getRequest()->getIsCpRequest() returns true
     * - Frontend: Referrer is from same site (same host as current request)
     * - API: No referrer or referrer is from different host
     *
     * @param \craft\web\Request $request
     * @param string|null $referer
     * @return string The detected source (frontend, cp, or api)
     */
    private function _detectSource(\craft\web\Request $request, ?string $referer): string
    {
        // Check if this is a CP request
        if ($request->getIsCpRequest()) {
            return 'cp';
        }

        // Check referrer to determine frontend vs API
        if ($referer) {
            // Parse the referrer URL
            $referrerHost = parse_url($referer, PHP_URL_HOST);
            $currentHost = $request->getHostName();

            // If referrer is from same host, it's a frontend search
            if ($referrerHost && $currentHost && strcasecmp($referrerHost, $currentHost) === 0) {
                return 'frontend';
            }
        }

        // No referrer or external referrer = likely API call
        return 'api';
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

    /**
     * Classify search intent based on query patterns
     *
     * @param string $query The search query
     * @return string|null The classified intent
     * @since 5.0.0
     */
    // TODO: Consider expanding intent categories later:
    // - 'local' for "near me", "[city]" queries
    // - 'support' for "help", "support", "problem", "issue" queries
    public function classifyIntent(string $query): ?string
    {
        $query = strtolower(trim($query));

        // Question patterns (informational questions)
        $questionPatterns = [
            '/^(what|how|why|when|where|who|which|can|does|is|are|do|will|should)\b/',
            '/\?$/',
        ];
        foreach ($questionPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return 'question';
            }
        }

        // Product patterns (shopping intent)
        $productPatterns = [
            '/\b(buy|price|cost|cheap|discount|sale|order|shop|store|deal)\b/',
            '/\b(review|compare|best|top|vs|versus)\b/',
            '/\b(shipping|delivery|return|warranty)\b/',
            '/\$\d+/',
        ];
        foreach ($productPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return 'product';
            }
        }

        // Navigational patterns (looking for specific page/brand)
        $navigationalPatterns = [
            '/\b(login|signin|sign in|account|dashboard|contact|about|home|page)\b/',
            '/\b(\.com|\.org|\.net|\.io)\b/',
        ];
        foreach ($navigationalPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return 'navigational';
            }
        }

        // Default to informational for general queries
        return 'informational';
    }

    /**
     * Get intent breakdown
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getIntentBreakdown(?int $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['intent', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['intent' => null]])
            ->groupBy('intent')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'intent' => $row['intent'],
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return [
            'data' => $data,
            'labels' => array_column($data, 'intent'),
            'values' => array_column($data, 'count'),
            'percentages' => array_column($data, 'percentage'),
        ];
    }

    /**
     * Get source breakdown (frontend, cp, api, custom sources)
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getSourceBreakdown(?int $siteId, string $dateRange = 'last30days'): array
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
     * Get average execution time over time for performance chart
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getPerformanceData(?int $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select([
                'DATE(dateCreated) as date',
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('DATE(dateCreated)')
            ->orderBy(['date' => SORT_ASC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return [
            'labels' => array_column($results, 'date'),
            'avgTime' => array_map(fn($r) => round((float)$r['avgTime'], 2), $results),
            'minTime' => array_map(fn($r) => round((float)$r['minTime'], 2), $results),
            'maxTime' => array_map(fn($r) => round((float)$r['maxTime'], 2), $results),
            'searches' => array_map(fn($r) => (int)$r['searches'], $results),
        ];
    }

    /**
     * Get cache hit statistics
     * Note: Cache hits are identified by executionTime = 0
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getCacheStats(?int $siteId, string $dateRange = 'last30days'): array
    {
        // Total searches
        $totalQuery = (new Query())
            ->from('{{%searchmanager_analytics}}');

        $this->applyDateRangeFilter($totalQuery, $dateRange);

        if ($siteId) {
            $totalQuery->andWhere(['siteId' => $siteId]);
        }

        $total = (int)$totalQuery->count();

        // Cache hits (executionTime = 0)
        $cacheHitQuery = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['executionTime' => 0]);

        $this->applyDateRangeFilter($cacheHitQuery, $dateRange);

        if ($siteId) {
            $cacheHitQuery->andWhere(['siteId' => $siteId]);
        }

        $cacheHits = (int)$cacheHitQuery->count();
        $cacheMisses = $total - $cacheHits;

        return [
            'total' => $total,
            'cacheHits' => $cacheHits,
            'cacheMisses' => $cacheMisses,
            'hitRate' => $total > 0 ? round(($cacheHits / $total) * 100, 1) : 0,
            'missRate' => $total > 0 ? round(($cacheMisses / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Get top performing queries (fastest response time)
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getTopPerformingQueries(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'query',
                'siteId',
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
                'AVG(resultsCount) as avgResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['>', 'executionTime', 0]) // Exclude cache hits
            ->andWhere(['>', 'resultsCount', 0]) // Only queries with results
            ->groupBy(['query', 'siteId'])
            ->having(['>=', 'COUNT(*)', 3]) // At least 3 searches for reliable avg
            ->orderBy(['avgTime' => SORT_ASC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($r) {
            $siteName = null;
            if (!empty($r['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($r['siteId']);
                $siteName = $site ? $site->name : null;
            }
            return [
                'query' => $r['query'],
                'siteId' => $r['siteId'],
                'siteName' => $siteName,
                'avgTime' => round((float)$r['avgTime'], 2),
                'minTime' => round((float)$r['minTime'], 2),
                'maxTime' => round((float)$r['maxTime'], 2),
                'searches' => (int)$r['searches'],
                'avgResults' => round((float)$r['avgResults'], 1),
            ];
        }, $results);
    }

    /**
     * Get worst performing queries (slowest response time)
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getWorstPerformingQueries(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
    {
        $query = (new Query())
            ->select([
                'query',
                'siteId',
                'AVG(executionTime) as avgTime',
                'MIN(executionTime) as minTime',
                'MAX(executionTime) as maxTime',
                'COUNT(*) as searches',
                'AVG(resultsCount) as avgResults',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->andWhere(['>', 'executionTime', 0]) // Exclude cache hits
            ->groupBy(['query', 'siteId'])
            ->having(['>=', 'COUNT(*)', 3]) // At least 3 searches for reliable avg
            ->orderBy(['avgTime' => SORT_DESC])
            ->limit($limit);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        return array_map(function($r) {
            $siteName = null;
            if (!empty($r['siteId'])) {
                $site = Craft::$app->getSites()->getSiteById($r['siteId']);
                $siteName = $site ? $site->name : null;
            }
            return [
                'query' => $r['query'],
                'siteId' => $r['siteId'],
                'siteName' => $siteName,
                'avgTime' => round((float)$r['avgTime'], 2),
                'minTime' => round((float)$r['minTime'], 2),
                'maxTime' => round((float)$r['maxTime'], 2),
                'searches' => (int)$r['searches'],
                'avgResults' => round((float)$r['avgResults'], 1),
            ];
        }, $results);
    }

    /**
     * Get country breakdown
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getCountryBreakdown(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.0.0
     */
    public function getCityBreakdown(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
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
     * Get peak usage hours
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.0.0
     */
    public function getPeakUsageHours(?int $siteId, string $dateRange = 'last30days'): array
    {
        $hourExpr = $this->getLocalHourExpression('dateCreated');

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
     * Get trending queries - compares current period to previous period
     *
     * @param int|null $siteId Site ID filter
     * @param string $dateRange Date range for current period
     * @param int $limit Number of queries to return
     * @return array Queries with trend data (up, down, new, same)
     * @since 5.0.0
     */
    public function getTrendingQueries(?int $siteId, string $dateRange = 'last7days', int $limit = 10): array
    {
        // Calculate date ranges for current and previous periods
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $now = new \DateTime('now', $tz);

        switch ($dateRange) {
            case 'today':
                $currentStart = (clone $now)->setTime(0, 0, 0);
                $previousStart = (clone $currentStart)->modify('-1 day');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'yesterday':
                $currentStart = (clone $now)->modify('-1 day')->setTime(0, 0, 0);
                $currentEnd = (clone $currentStart)->modify('+1 day')->modify('-1 second');
                $previousStart = (clone $currentStart)->modify('-1 day');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                $now = $currentEnd; // Use yesterday's end as "now"
                break;
            case 'last7days':
                $currentStart = (clone $now)->modify('-7 days');
                $previousStart = (clone $now)->modify('-14 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'last30days':
                $currentStart = (clone $now)->modify('-30 days');
                $previousStart = (clone $now)->modify('-60 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'last90days':
                $currentStart = (clone $now)->modify('-90 days');
                $previousStart = (clone $now)->modify('-180 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            default: // 'all' - compare last 30 days vs previous 30 days
                $currentStart = (clone $now)->modify('-30 days');
                $previousStart = (clone $now)->modify('-60 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
        }

        // Get current period queries
        $currentQuery = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($currentStart)])
            ->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($now)])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit * 2); // Get more to account for filtering

        if ($siteId) {
            $currentQuery->andWhere(['siteId' => $siteId]);
        }

        $currentResults = $currentQuery->all();

        // Get previous period queries for comparison
        $previousQuery = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($previousStart)])
            ->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($previousEnd)])
            ->groupBy('query');

        if ($siteId) {
            $previousQuery->andWhere(['siteId' => $siteId]);
        }

        $previousResults = $previousQuery->all();

        // Index previous results by query
        $previousCounts = [];
        foreach ($previousResults as $row) {
            $previousCounts[strtolower($row['query'])] = (int)$row['count'];
        }

        // Calculate trends
        $trending = [];
        foreach ($currentResults as $row) {
            $query = $row['query'];
            $currentCount = (int)$row['count'];
            $queryKey = strtolower($query);
            $previousCount = $previousCounts[$queryKey] ?? 0;

            // Calculate percentage change
            if ($previousCount === 0) {
                $trend = 'new';
                $changePercent = 100;
            } elseif ($currentCount > $previousCount) {
                $trend = 'up';
                $changePercent = round((($currentCount - $previousCount) / $previousCount) * 100);
            } elseif ($currentCount < $previousCount) {
                $trend = 'down';
                $changePercent = round((($previousCount - $currentCount) / $previousCount) * 100);
            } else {
                $trend = 'same';
                $changePercent = 0;
            }

            $trending[] = [
                'query' => $query,
                'count' => $currentCount,
                'previousCount' => $previousCount,
                'trend' => $trend,
                'changePercent' => $changePercent,
            ];
        }

        // Sort by absolute change (most movement first), but keep high-count items visible
        usort($trending, function($a, $b) {
            // Prioritize significant trends with decent volume
            $aScore = $a['count'] * ($a['changePercent'] / 100 + 1);
            $bScore = $b['count'] * ($b['changePercent'] / 100 + 1);
            return $bScore <=> $aScore;
        });

        return array_slice($trending, 0, $limit);
    }

    /**
     * Get average execution time
     *
     * @param int|null $siteId
     * @param int $days
     * @return float
     * @since 5.0.0
     */
    public function getAverageExecutionTime(?int $siteId, int $days = 30): float
    {
        $query = (new Query())
            ->select(['AVG(executionTime) as avgTime'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['executionTime' => null]])
            ->andWhere(['source' => 'frontend'])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $result = $query->scalar();
        return round((float)$result, 2);
    }

    /**
     * Get unique queries count
     *
     * @param int|null $siteId
     * @param int $days
     * @return int
     * @since 5.0.0
     */
    public function getUniqueQueriesCount(?int $siteId, int $days = 30): int
    {
        $query = (new Query())
            ->select(['COUNT(DISTINCT query) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['source' => 'frontend'])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return (int)$query->scalar();
    }

    // =========================================================================
    // QUERY RULES ANALYTICS
    // =========================================================================

    /**
     * Get top triggered query rules
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.10.0
     */
    public function getTopTriggeredRules(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.10.0
     */
    public function getRulesByActionType(?int $siteId, string $dateRange = 'last30days'): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.10.0
     */
    public function getQueriesTriggeringRules(?int $siteId, string $dateRange = 'last30days', int $limit = 15): array
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

    // =========================================================================
    // PROMOTIONS ANALYTICS
    // =========================================================================

    /**
     * Get top promotions by impressions
     *
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.10.0
     */
    public function getTopPromotions(?int $siteId, string $dateRange = 'last30days', int $limit = 10): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @return array
     * @since 5.10.0
     */
    public function getPromotionsByPosition(?int $siteId, string $dateRange = 'last30days'): array
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
     * @param int|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     * @since 5.10.0
     */
    public function getQueriesTriggeringPromotions(?int $siteId, string $dateRange = 'last30days', int $limit = 15): array
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
     * Get analytics for a specific query rule
     *
     * @param int $ruleId The query rule ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     * @since 5.10.0
     */
    public function getRuleAnalytics(int $ruleId, string $dateRange = 'last7days'): array
    {
        $localDateExpr = $this->getLocalDateExpression('dateCreated');

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

        // Get recent triggers
        $recentTriggers = (clone $query)
            ->select(['*'])
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
     * Get analytics for a specific promotion
     *
     * @param int $promotionId The promotion ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     * @since 5.10.0
     */
    public function getPromotionAnalytics(int $promotionId, string $dateRange = 'last7days'): array
    {
        $localDateExpr = $this->getLocalDateExpression('dateCreated');

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

        // Get recent impressions
        $recentImpressions = (clone $query)
            ->select(['*'])
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

    /**
     * Normalize daily rows into a contiguous local-date range.
     *
     * @param array $rows
     * @param string $dateRange
     * @param array $fields
     * @param bool $datesAreLocal
     * @return array
     */
    private function normalizeDailyCounts(array $rows, string $dateRange, array $fields, bool $datesAreLocal = false): array
    {
        if (empty($rows)) {
            return [];
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $bounds = DateRangeHelper::getBounds($dateRange);
        $startLocal = $bounds['start'] ? (clone $bounds['start'])->setTimezone($tz) : null;
        $endLocal = $bounds['end'] ? (clone $bounds['end'])->setTimezone($tz)->modify('-1 day') : new \DateTime('now', $tz);

        $map = [];
        foreach ($rows as $row) {
            if (empty($row['date'])) {
                continue;
            }

            if ($datesAreLocal) {
                $key = $row['date'];
            } else {
                $rowDate = new \DateTime($row['date'], new \DateTimeZone('UTC'));
                $key = $rowDate->setTimezone($tz)->format('Y-m-d');
            }

            if (!isset($map[$key])) {
                $map[$key] = array_fill_keys($fields, 0);
            }
            foreach ($fields as $field) {
                $map[$key][$field] += (int)($row[$field] ?? 0);
            }
        }

        if ($startLocal === null) {
            ksort($map);
            $normalized = [];
            foreach ($map as $date => $values) {
                $normalized[] = ['date' => $date] + $values;
            }
            return $normalized;
        }

        $startLocal->setTime(0, 0, 0);
        $endLocal->setTime(0, 0, 0);
        $cursor = clone $startLocal;
        $normalized = [];

        while ($cursor <= $endLocal) {
            $key = $cursor->format('Y-m-d');
            $values = $map[$key] ?? array_fill_keys($fields, 0);
            $normalized[] = ['date' => $key] + $values;
            $cursor->modify('+1 day');
        }

        return $normalized;
    }

    /**
     * Build a local-date SQL expression for the given column.
     *
     * @param string $column
     * @return \yii\db\Expression
     */
    private function getLocalDateExpression(string $column): \yii\db\Expression
    {
        $offset = $this->getMysqlTimezoneOffset();
        return new \yii\db\Expression(
            "DATE(CONVERT_TZ([[{$column}]], '+00:00', :offset))",
            [':offset' => $offset]
        );
    }

    /**
     * Build a local-hour SQL expression for the given column.
     *
     * @param string $column
     * @return \yii\db\Expression
     */
    private function getLocalHourExpression(string $column): \yii\db\Expression
    {
        $offset = $this->getMysqlTimezoneOffset();
        return new \yii\db\Expression(
            "HOUR(CONVERT_TZ([[{$column}]], '+00:00', :offset))",
            [':offset' => $offset]
        );
    }

    /**
     * Get the local timezone offset for MySQL CONVERT_TZ.
     *
     * @return string
     */
    private function getMysqlTimezoneOffset(): string
    {
        $timezone = Craft::$app->getTimeZone();
        $dateTime = new \DateTime('now', new \DateTimeZone($timezone));
        return $dateTime->format('P');
    }
}
