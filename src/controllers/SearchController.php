<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\base\helpers\BooleanHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ApiKeyService;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Search Controller
 *
 * Provides analytics tracking endpoints for the search widget.
 * Public search requests are handled by ApiController and the backend service.
 *
 * @since 5.30.0
 */
class SearchController extends Controller
{
    use LoggingTrait;

    /**
     * Maximum query length to prevent resource exhaustion
     */
    private const MAX_QUERY_LENGTH = 256;

    /**
     * Maximum resultsCount for analytics to prevent pollution
     */
    private const MAX_ANALYTICS_RESULTS_COUNT = 1000;

    /**
     * Maximum tolerated `took` value for widget-reported execution time, in milliseconds.
     * Clamps obviously bogus client values (negative, NaN, multi-minute) to null fallback.
     * 60s is generous — anything above is either a stuck request or a malicious payload.
     */
    private const MAX_WIDGET_TOOK_MS = 60000;

    /**
     * The API key authenticated for a tracking request, or null when enforcement
     * is off / the request is anonymous. Set in {@see beforeAction()}; consumed by
     * {@see actionTrackSearch()} to attribute the analytics row (slice 5).
     *
     * @since 5.47.0
     */
    private ?ApiKey $authenticatedKey = null;

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['track-click', 'track-search'];

    /**
     * @inheritdoc
     *
     * Slice 4: when `requireApiKey` is enabled, the tracking endpoints are gated
     * behind a valid API key (closes audit #8) — same authenticate + public-key
     * referrer gate as search/autocomplete, plus an allowed-indices check when
     * the ping names `index`/`indexHandles`. Tracking is **not** rate-limited (pings
     * are noisy; the cap is scoped to search/autocomplete query volume). When
     * `requireApiKey` is off, the endpoints stay anonymous (backward compatible).
     *
     * CSRF stays disabled for track-* regardless: they're fire-and-forget with no
     * authenticated session to protect, and CSRF tokens break under full-page
     * static caching (Blitz, Servd, etc.) where the baked-in token goes stale.
     * Browser requests from other origins must match `trackingAllowedOrigins`
     * exactly; same-origin requests remain allowed without config.
     *
     * @since 5.30.0
     */
    public function beforeAction($action): bool
    {
        $isTracking = in_array($action->id, ['track-click', 'track-search'], true);

        if ($isTracking) {
            $this->enableCsrfValidation = false;
            if (!$this->requireTrustedTrackingOrigin()) {
                return false;
            }
        }

        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($isTracking && SearchManager::$plugin->getSettings()->requireApiKey) {
            $request = Craft::$app->getRequest();
            $headers = $request->getHeaders();
            $header = $headers->get(ApiKeyService::REQUEST_HEADER);
            $referer = $headers->get('Referer');
            $origin = $headers->get('Origin');
            $referrerCandidate = SearchManager::$plugin->apiKeys->referrerCandidate($referer, $origin);

            $key = SearchManager::$plugin->apiKeys->authenticateRequest(
                is_string($header) ? $header : null,
                $referrerCandidate,
            );

            // Retain for analytics attribution (slice 5) on track-search.
            $this->authenticatedKey = $key;

            // Enforce the key's allowed indices only when the ping names them.
            // track-click sends a single result `index`; track-search sends
            // `indexHandles`. Tracking is deliberately not rate-limited.
            $trackingIndices = $action->id === 'track-click'
                ? (string) $request->getParam('index', '')
                : (string) $request->getParam('indexHandles', '');
            [$indexHandles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
                $trackingIndices,
            );
            if ($exceededMax) {
                throw new ForbiddenHttpException(Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES]));
            }
            if ($indicesProvided) {
                SearchManager::$plugin->apiKeys->scopeIndices($key, $indexHandles, true);
            }
        }

        return true;
    }

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Track a click on a search result
     *
     * POST /actions/search-manager/search/track-click
     *
     * Parameters:
     * - elementId: The clicked element ID
     * - query: The search query
     * - index: The index handle
     * - position: The position in results (optional)
     *
     * @return Response
     */
    public function actionTrackClick(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = self::normalizeTrackingElementId($request->getParam('elementId'));
        $query = $request->getParam('query', '');
        $indexHandle = $request->getParam('index', '');
        $position = self::normalizeTrackingPosition($request->getParam('position'));

        if ($elementId === null) {
            return $this->asJson(['success' => false]);
        }

        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        if (!self::isEnabledIndexHandle($indexHandle)) {
            $indexHandle = '';
        }

        // Check if analytics is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!$settings->enableAnalytics) {
            return $this->asJson(['success' => true]);
        }

        // Log the click for now (click tracking can be expanded later)
        $this->logDebug('Search result clicked', [
            'elementId' => $elementId,
            'query' => $query,
            'index' => $indexHandle,
            'position' => $position,
        ]);

        return $this->asJson(['success' => true]);
    }

    /**
     * Track a search query for analytics (explicit tracking)
     *
     * This endpoint is called by the widget when the user shows intent:
     * - Clicks a result
     * - Presses Enter
     * - Stops typing for idle timeout (browsing behavior)
     *
     * POST /actions/search-manager/search/track-search
     *
     * Parameters:
     * - q: The search query
     * - indexHandles: Comma-separated index handles (or empty for all)
     * - resultsCount: Number of results returned
     * - trigger: What triggered tracking ('click', 'enter', 'idle')
     * - analyticsSource: Source identifier (e.g., 'header-search', 'mobile-nav')
     * - siteId: Site ID (optional)
     *
     * @return Response
     */
    public function actionTrackSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');
        $indexHandlesParam = $request->getParam('indexHandles', '');
        $resultsCount = (int) $request->getParam('resultsCount', 0);
        $trigger = $request->getParam('trigger', 'unknown');
        $source = $request->getParam('analyticsSource', 'frontend-widget');
        $siteId = self::normalizeTrackingSiteId($request->getParam('siteId'));

        // Validate and sanitize inputs to prevent analytics pollution

        // Query: cap length (same as search endpoints)
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        // Results count: clamp to reasonable range
        $resultsCount = max(0, min(self::MAX_ANALYTICS_RESULTS_COUNT, $resultsCount));

        // Trigger: must be from allowed list
        $validTriggers = ['click', 'enter', 'idle', 'unknown'];
        if (!in_array($trigger, $validTriggers, true)) {
            $trigger = 'unknown';
        }

        // Source: sanitize and limit length to the analytics source column.
        $source = TrackingMetadataHelper::source($source) ?? 'frontend-widget';

        // Widget cache telemetry: the widget knows from the final search response
        // whether the result was cache-hit (meta.cached) and the backend's reported
        // execution time (meta.took). Forwarding those into the intent ping makes
        // dashboard cache stats reflect widget usage. Absent or malformed values
        // fall back to null so legacy / non-widget callers keep working unchanged.
        $executionTime = self::parseWidgetCacheTelemetry(
            $request->getParam('cached'),
            $request->getParam('took'),
        );

        if (empty(trim($query))) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Query is required.')]);
        }

        // Check if analytics is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!$settings->enableAnalytics) {
            return $this->asJson(['success' => true, 'tracked' => false]);
        }

        // Parse indices through the shared resolver so the anonymous path gets
        // the same dedup + fail-closed cap as every other indexHandles consumer
        // (the beforeAction cap only runs when requireApiKey is on).
        [$indexHandles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices($indexHandlesParam);
        if ($exceededMax) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'The indexHandles argument accepts at most {max} indices.', ['max' => SearchIndex::MAX_REQUESTED_INDICES])]);
        }

        // If indices were explicitly provided but none are valid/enabled, don't track
        if ($indicesProvided && empty($indexHandles)) {
            return $this->asJson(['success' => true, 'tracked' => false]);
        }

        // Resolve handles to track — 'all' if none specified
        $handlesToTrack = !empty($indexHandles) ? $indexHandles : ['all'];

        // Get first index's backend for logging (or default)
        $backend = 'unknown';
        if (!empty($indexHandles)) {
            $backendInstance = SearchManager::$plugin->backend->getBackendForIndex($indexHandles[0]);
            if ($backendInstance) {
                $backend = $backendInstance->getName();
            }
        } else {
            $backend = SearchManager::$plugin->getSettings()->defaultBackendHandle ?? 'unknown';
        }

        // Attribute the analytics row to the authenticated key (slice 5).
        // Empty for anonymous requests (requireApiKey off or no key).
        $analyticsOptions = array_merge(
            [
                'source' => $source,
                'trigger' => $trigger,
            ],
            SearchManager::$plugin->apiKeys->attributionOptions($this->authenticatedKey),
        );

        try {
            // Track per index with shared session ID for accurate per-index aggregation
            $sessionId = count($handlesToTrack) > 1 ? \craft\helpers\StringHelper::UUID() : null;
            foreach ($handlesToTrack as $handle) {
                SearchManager::$plugin->analytics->trackSearch(
                    $handle,
                    $query,
                    $resultsCount,
                    // executionTime: 0 if widget reported cache hit, took ms if widget
                    // reported miss, null if the widget didn't report cache state.
                    $executionTime,
                    $backend,
                    $siteId,
                    $analyticsOptions,
                    $sessionId,
                );
            }

            $this->logDebug('Explicit search tracking', [
                'query' => $query,
                'indices' => implode(',', $handlesToTrack),
                'trigger' => $trigger,
                'source' => $source,
                'resultsCount' => $resultsCount,
                'executionTime' => $executionTime,
            ]);

            return $this->asJson(['success' => true, 'tracked' => true]);
        } catch (\Throwable $e) {
            $this->logError('Failed to track search', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Tracking failed')]);
        }
    }

    /**
     * Resolve the analytics `executionTime` value from widget-supplied cache
     * telemetry. The widget passes `cached` (boolean-like) and optionally
     * `took` (ms) from its final search response.
     *
     * Contract:
     *  - cached missing or non-boolean-like        → null (legacy / unknown)
     *  - cached truthy                             → 0.0  (cache hit)
     *  - cached falsy AND took numeric in [0, max] → took (cache miss)
     *  - cached falsy AND took missing/invalid     → null (unknown)
     *
     * Public + static so it can be unit-tested without controller harness.
     *
     * @since 5.46.0
     */
    public static function parseWidgetCacheTelemetry(mixed $cachedRaw, mixed $tookRaw): ?float
    {
        if ($cachedRaw === null || !BooleanHelper::isBooleanLike($cachedRaw)) {
            return null;
        }

        if (BooleanHelper::normalize($cachedRaw)) {
            return 0.0;
        }

        if (!is_numeric($tookRaw)) {
            return null;
        }

        $tookFloat = (float) $tookRaw;
        if ($tookFloat < 0 || $tookFloat > self::MAX_WIDGET_TOOK_MS) {
            return null;
        }

        return $tookFloat;
    }

    /**
     * Resolve an analytics tracking site ID only when it references a known site.
     *
     * Anonymous tracking pings are accepted from cached/static frontends, so an
     * unknown site ID is treated as absent rather than persisted as analytics
     * pollution.
     *
     * @since 5.53.0
     */
    public static function normalizeTrackingSiteId(mixed $rawSiteId): ?int
    {
        if (!is_numeric($rawSiteId) || (int)$rawSiteId <= 0) {
            return null;
        }

        $siteId = (int)$rawSiteId;

        return Craft::$app->getSites()->getSiteById($siteId) !== null ? $siteId : null;
    }

    public static function normalizeTrackingElementId(mixed $rawElementId): ?int
    {
        if (!is_numeric($rawElementId) || (int)$rawElementId <= 0) {
            return null;
        }

        return (int)$rawElementId;
    }

    public static function normalizeTrackingPosition(mixed $rawPosition): ?int
    {
        if (!is_numeric($rawPosition)) {
            return null;
        }

        $position = (int)$rawPosition;

        return $position >= 0 && $position <= self::MAX_ANALYTICS_RESULTS_COUNT ? $position : null;
    }

    public static function normalizeTrackingOrigins(mixed $origins): array
    {
        if (is_string($origins)) {
            $origins = array_map('trim', explode(',', $origins));
        }

        if (!is_array($origins)) {
            return [];
        }

        $normalized = [];
        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                continue;
            }

            $normalizedOrigin = self::normalizeTrackingOrigin($origin);
            if ($normalizedOrigin !== null) {
                $normalized[] = $normalizedOrigin;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function normalizeTrackingOrigin(string $origin): ?string
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '') {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = (int)($parts['port'] ?? self::defaultPort($scheme));

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    public static function trackingOriginAllowed(string $requestOrigin, string $hostInfo, mixed $allowedOrigins): bool
    {
        if (self::sameOrigin($requestOrigin, $hostInfo)) {
            return true;
        }

        $normalizedRequestOrigin = self::normalizeTrackingOrigin($requestOrigin);
        if ($normalizedRequestOrigin === null) {
            return false;
        }

        return in_array($normalizedRequestOrigin, self::normalizeTrackingOrigins($allowedOrigins), true);
    }

    private function requireTrustedTrackingOrigin(): bool
    {
        $request = Craft::$app->getRequest();
        $headers = $request->getHeaders();
        $origin = $headers->get('Origin');
        $referer = $headers->get('Referer');
        if (!method_exists($request, 'getHostInfo')) {
            return true;
        }

        $hostInfo = $request->getHostInfo();
        $isOptions = strtoupper((string)$request->getMethod()) === 'OPTIONS';

        if (is_string($origin) && trim($origin) !== '') {
            if (!self::trackingOriginAllowed($origin, $hostInfo, SearchManager::$plugin->getSettings()->trackingAllowedOrigins)) {
                throw new ForbiddenHttpException(Craft::t('search-manager', 'Cross-origin tracking requests are not allowed.'));
            }

            if (!self::sameOrigin($origin, $hostInfo)) {
                $this->emitTrackingCorsHeaders($origin);
            }

            if ($isOptions) {
                Craft::$app->getResponse()->setStatusCode(204);
                return false;
            }

            return true;
        }

        if (is_string($referer) && trim($referer) !== '' && !self::trackingOriginAllowed($referer, $hostInfo, SearchManager::$plugin->getSettings()->trackingAllowedOrigins)) {
            throw new ForbiddenHttpException(Craft::t('search-manager', 'Cross-origin tracking requests are not allowed.'));
        }

        if ($isOptions) {
            Craft::$app->getResponse()->setStatusCode(204);
            return false;
        }

        return true;
    }

    private function emitTrackingCorsHeaders(string $origin): void
    {
        $responseHeaders = Craft::$app->getResponse()->getHeaders();
        $responseHeaders->set('Access-Control-Allow-Origin', rtrim(trim($origin), '/'));
        $responseHeaders->set('Vary', 'Origin');
        $responseHeaders->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $responseHeaders->set('Access-Control-Allow-Headers', 'Content-Type, Accept, X-Search-Manager-Key');
    }

    private static function sameOrigin(string $candidate, string $hostInfo): bool
    {
        $candidateOrigin = self::normalizeTrackingOrigin($candidate);
        $hostOrigin = self::normalizeTrackingOrigin($hostInfo);
        if ($candidateOrigin === null || $hostOrigin === null) {
            return false;
        }

        return $candidateOrigin === $hostOrigin;
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function isEnabledIndexHandle(mixed $indexHandle): bool
    {
        if (!is_string($indexHandle) || trim($indexHandle) === '') {
            return false;
        }

        $index = SearchIndex::findByHandle(trim($indexHandle));

        return $index !== null && $index->enabled;
    }
}
