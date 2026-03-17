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
use lindemannrock\base\helpers\AnalyticsIpHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Analytics Tracking Service
 *
 * Records search events and related rule/promotion tracking.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
class AnalyticsTrackingService
{
    use LoggingTrait;

    /**
     */
    public function __construct()
    {
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
     * @param string|null $sessionId Optional session ID to group multi-index rows
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
            // Single index handle - require valid index with both enabled and enableAnalytics
            $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
            if (!$index) {
                $this->logDebug('Analytics skipped — index not found', ['indexHandle' => $indexHandle]);
                return;
            }
            if (!$index->enabled || !$index->enableAnalytics) {
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

        $ipState = AnalyticsIpHelper::prepare(
            $request->getUserIP(),
            $settings->anonymizeIpAddress,
            $settings->enableGeoDetection,
            fn(string $ip): string => $this->_hashIpWithSalt($ip),
        );

        if ($ipState['hashError'] !== null) {
            $this->logError('Failed to hash IP address', ['error' => $ipState['hashError']->getMessage()]);
        }

        $ip = $ipState['hashedIp'];
        $ipForGeoLookup = $ipState['geoLookupIp'];

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
                    'sessionId' => $sessionId,
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
     * Classify search intent based on query patterns
     *
     * @param string $query The search query
     * @return string|null The classified intent
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

        // Batch-fetch element titles to avoid N+1 queries
        $elementIds = array_unique(array_filter(array_map(fn($p) => $p->elementId, $matchedPromotions)));
        $elementTitles = [];
        if (!empty($elementIds)) {
            $rows = (new Query())
                ->select(['elements.id', 'content.title'])
                ->from('{{%elements}} elements')
                ->innerJoin('{{%elements_sites}} content', '[[content.elementId]] = [[elements.id]]')
                ->where(['elements.id' => $elementIds])
                ->andWhere(['content.siteId' => $siteId ?? Craft::$app->getSites()->getCurrentSite()->id])
                ->all();
            foreach ($rows as $row) {
                $elementTitles[(int)$row['id']] = $row['title'];
            }
        }

        foreach ($matchedPromotions as $promo) {
            try {
                $elementTitle = $elementTitles[$promo->elementId] ?? null;

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
