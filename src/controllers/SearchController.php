<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
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
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['track-click', 'track-search'];

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Disable CSRF for anonymous analytics tracking endpoints.
        // These are fire-and-forget, low-risk endpoints with no authenticated session to protect.
        // CSRF tokens don't work with full-page static caching (Blitz, Servd, etc.)
        // because the token baked into cached HTML becomes stale across sessions.
        // Real protection against analytics pollution will come via Search API Keys (rate limiting + per-key validation).
        if (in_array($action->id, ['track-click', 'track-search'], true)) {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
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

        try {
            // Track per index with shared session ID for accurate per-index aggregation
            $sessionId = count($handlesToTrack) > 1 ? \craft\helpers\StringHelper::UUID() : null;
            foreach ($handlesToTrack as $handle) {
                SearchManager::$plugin->analytics->trackSearch(
                    $handle,
                    $query,
                    $resultsCount,
                    null, // No execution time for explicit tracking
                    $backend,
                    $siteId,
                    [
                        'source' => $source,
                        'trigger' => $trigger,
                    ],
                    $sessionId,
                );
            }

            $this->logDebug('Explicit search tracking', [
                'query' => $query,
                'indices' => implode(',', $handlesToTrack),
                'trigger' => $trigger,
                'source' => $source,
                'resultsCount' => $resultsCount,
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
}
