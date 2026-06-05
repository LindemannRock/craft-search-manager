<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\base\helpers\BooleanHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ApiKeyService;
use yii\web\Response;

/**
 * Search Controller
 *
 * Provides analytics tracking endpoints for the search widget.
 * Search and enrichment are handled by ApiController and EnrichmentService.
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
     * the ping names `index`/`indices`. Tracking is **not** rate-limited (pings
     * are noisy; the cap is scoped to search/autocomplete query volume). When
     * `requireApiKey` is off, the endpoints stay anonymous (backward compatible).
     *
     * CSRF stays disabled for track-* regardless: they're fire-and-forget with no
     * authenticated session to protect, and CSRF tokens break under full-page
     * static caching (Blitz, Servd, etc.) where the baked-in token goes stale.
     *
     * @since 5.30.0
     */
    public function beforeAction($action): bool
    {
        $isTracking = in_array($action->id, ['track-click', 'track-search'], true);

        if ($isTracking) {
            $this->enableCsrfValidation = false;
        }

        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($isTracking && SearchManager::$plugin->getSettings()->requireApiKey) {
            $request = Craft::$app->getRequest();
            $headers = $request->getHeaders();
            $header = $headers->get(ApiKeyService::REQUEST_HEADER);
            $referer = $headers->get('Referer');

            $key = SearchManager::$plugin->apiKeys->authenticateRequest(
                is_string($header) ? $header : null,
                is_string($referer) ? $referer : null,
            );

            // Retain for analytics attribution (slice 5) on track-search.
            $this->authenticatedKey = $key;

            // Enforce the key's allowed indices only when the ping names them
            // (track-click sends `index`, track-search sends `indices`). 403 on
            // an out-of-allowlist index. Tracking is deliberately not rate-limited.
            [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
                (string) $request->getParam('indices', ''),
                (string) $request->getParam('index', ''),
            );
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
        $elementId = $request->getParam('elementId');
        $query = $request->getParam('query', '');
        $indexHandle = $request->getParam('index', '');
        $position = $request->getParam('position');

        if (empty($elementId)) {
            return $this->asJson(['success' => false]);
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
     * - indices: Comma-separated index handles (or empty for all)
     * - resultsCount: Number of results returned
     * - trigger: What triggered tracking ('click', 'enter', 'idle')
     * - source: Source identifier (e.g., 'header-search', 'mobile-nav')
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
        $indicesParam = $request->getParam('indices', '');
        $resultsCount = (int) $request->getParam('resultsCount', 0);
        $trigger = $request->getParam('trigger', 'unknown');
        $source = $request->getParam('source', 'frontend-widget');
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;

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

        // Source: sanitize and limit length (max 64 chars, alphanumeric + dash/underscore)
        $source = preg_replace('/[^a-zA-Z0-9_-]/', '', $source);
        $source = substr($source, 0, 64) ?: 'frontend-widget';

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
            return $this->asJson(['success' => false, 'error' => 'Query is required']);
        }

        // Check if analytics is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!$settings->enableAnalytics) {
            return $this->asJson(['success' => true, 'tracked' => false]);
        }

        // Parse indices and validate against enabled indices
        $indexHandles = [];
        $indicesProvided = false;
        if (!empty($indicesParam)) {
            $indicesProvided = true;
            $requestedHandles = array_filter(array_map('trim', explode(',', $indicesParam)));

            // Get all enabled index handles
            $allIndices = SearchIndex::findAll();
            $enabledHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );

            // Only allow indices that exist and are enabled
            $indexHandles = array_values(array_intersect($requestedHandles, $enabledHandles));
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

            return $this->asJson(['success' => false, 'error' => 'Tracking failed']);
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
}
