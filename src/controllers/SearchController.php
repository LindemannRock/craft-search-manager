<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Search Controller
 *
 * Provides endpoints for the frontend search widget
 */
class SearchController extends Controller
{
    use LoggingTrait;

    /**
     * Maximum query length to prevent resource exhaustion
     */
    private const MAX_QUERY_LENGTH = 256;

    /**
     * Maximum number of indices that can be searched at once
     */
    private const MAX_INDICES_COUNT = 5;

    /**
     * Maximum resultsCount for analytics to prevent pollution
     */
    private const MAX_ANALYTICS_RESULTS_COUNT = 1000;

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['query', 'track-click', 'track-search'];

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Search query endpoint for the search widget
     *
     * Returns results with URLs, titles, and descriptions suitable for display
     *
     * GET /actions/search-manager/search/query?q=test&indices=blog,products
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional, searches all if not specified)
     * - index: Single index handle (legacy, use indices instead)
     * - limit: Max results (default: 10)
     * - siteId: Site ID to search (optional)
     * - hideResultsWithoutUrl: Hide results that don't have a URL (optional, default: false)
     * - skipAnalytics: Skip analytics tracking (default: true for widget, prevents keystroke spam)
     *
     * @return Response
     */
    public function actionQuery(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'results' => [],
                'total' => 0,
                'query' => mb_substr($query, 0, self::MAX_QUERY_LENGTH),
                'error' => 'Query too long (max ' . self::MAX_QUERY_LENGTH . ' characters)',
            ]);
        }

        $limit = (int) $request->getParam('limit', 10);
        // Normalize limit: negative = default, 0 = no limit, positive = capped at 100
        if ($limit < 0) {
            $limit = 10;
        } elseif ($limit > 0) {
            $limit = min(100, $limit);
        }
        // $limit === 0 means "no limit" (passed through to backend)
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;
        $hideResultsWithoutUrl = (bool) $request->getParam('hideResultsWithoutUrl', false);

        // Skip analytics if explicitly requested (e.g., widget passes skipAnalytics=1 to prevent keystroke spam)
        // Default: false (track analytics as normal)
        $skipAnalytics = (bool) $request->getParam('skipAnalytics', false);

        // Debug mode: requires devMode OR searchManager:viewDebug permission
        // This prevents leaking internal index/backend info in production
        $debugParam = $request->getParam('debug');
        $canViewDebug = Craft::$app->config->general->devMode
            || Craft::$app->getUser()->checkPermission('searchManager:viewDebug');
        $includeDebugMeta = $canViewDebug && ($debugParam !== null ? (bool) $debugParam : Craft::$app->config->general->devMode);

        // Get indices from new 'indices' param or legacy 'index' param
        $indicesParam = $request->getParam('indices', '');
        $indexHandle = $request->getParam('index', '');

        // Parse indices - comma-separated string to array
        $indexHandles = [];
        if (!empty($indicesParam)) {
            $indexHandles = array_filter(array_map('trim', explode(',', $indicesParam)));
        } elseif (!empty($indexHandle)) {
            // Legacy single index support
            $indexHandles = [$indexHandle];
        }

        // Cap indices count to prevent fan-out attacks
        if (count($indexHandles) > self::MAX_INDICES_COUNT) {
            $indexHandles = array_slice($indexHandles, 0, self::MAX_INDICES_COUNT);
        }

        if (empty(trim($query))) {
            return $this->asJson([
                'results' => [],
                'total' => 0,
                'query' => $query,
            ]);
        }

        try {
            $options = [
                'limit' => $limit,
                'skipAnalytics' => $skipAnalytics,
            ];

            if ($siteId !== null) {
                $options['siteId'] = $siteId;
            }

            // Get raw search results
            if (count($indexHandles) === 1) {
                // Search single specified index
                $searchResults = SearchManager::$plugin->backend->search($indexHandles[0], $query, $options);
            } elseif (count($indexHandles) > 1) {
                // Search multiple specified indices
                $searchResults = SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $options);
            } else {
                // No indices specified - search all enabled indices
                $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
                // Filter to only enabled indices (disabled indices should not be publicly searchable)
                $allIndexHandles = array_map(
                    fn($idx) => $idx->handle,
                    array_filter($allIndices, fn($idx) => $idx->enabled)
                );

                if (empty($allIndexHandles)) {
                    return $this->asJson([
                        'results' => [],
                        'total' => 0,
                        'query' => $query,
                        'error' => 'No search indices configured',
                    ]);
                }

                $searchResults = SearchManager::$plugin->backend->searchMultiple($allIndexHandles, $query, $options);
            }

            // Transform results for the widget
            $results = [];
            $hits = $searchResults['hits'] ?? [];

            foreach ($hits as $hit) {
                $elementId = $hit['id'] ?? $hit['objectID'] ?? null;
                if (!$elementId) {
                    continue;
                }
                // Cast to int for getElementById (search backends may return strings)
                $elementId = (int) $elementId;

                // Try to get the actual element for URL and additional data
                $element = Craft::$app->elements->getElementById($elementId, null, $siteId);

                if ($element === null) {
                    // Element might have been deleted, skip it
                    continue;
                }

                // Determine URL with proper priority:
                // 1. Transformer-provided custom URL from hit data
                // 2. Element's native URL
                // 3. cpEditUrl only for CP requests (never for frontend)
                $url = $hit['url'] ?? $element->url ?? null;
                if ($url === null && Craft::$app->getRequest()->getIsCpRequest()) {
                    $url = $element->cpEditUrl;
                }

                // Skip results without URL if hideResultsWithoutUrl is enabled
                if ($hideResultsWithoutUrl && $url === null) {
                    continue;
                }

                $result = [
                    'id' => $elementId,
                    'title' => $hit['title'] ?? $element->title ?? 'Untitled',
                    'url' => $url,
                    'description' => $this->getDescription($hit, $element),
                    'section' => $this->getSectionName($element),
                    'type' => $hit['type'] ?? $element::displayName(),
                    'score' => $hit['score'] ?? null,
                ];

                // Add index handle and backend for multi-index searches (debug info)
                if (!empty($hit['_index'])) {
                    $result['_index'] = $hit['_index'];
                    // Get backend name for this index
                    $backend = SearchManager::$plugin->backend->getBackendForIndex($hit['_index']);
                    if ($backend) {
                        $result['backend'] = $backend->getName();
                    }
                }

                // Add site info (for multi-site debugging)
                if ($element->siteId) {
                    $result['siteId'] = $element->siteId;
                    $site = Craft::$app->getSites()->getSiteById($element->siteId);
                    if ($site) {
                        $result['site'] = $site->handle;
                        $result['language'] = $site->language;
                    }
                }

                // Add thumbnail if available
                if (method_exists($element, 'getThumbUrl')) {
                    $result['thumbnail'] = $element->getThumbUrl(80);
                }

                // Add matched fields info (which fields contained the search query)
                if (!empty($hit['matchedIn'])) {
                    $result['matchedIn'] = $hit['matchedIn'];
                }

                // Add promoted flag (result was injected via promotion, not found via search)
                if (!empty($hit['promoted'])) {
                    $result['promoted'] = true;
                }

                // Add boosted flag (result score was boosted via query rule)
                if (!empty($hit['boosted'])) {
                    $result['boosted'] = true;
                }

                $results[] = $result;
            }

            // Build response with optional debug meta
            $response = [
                'results' => $results,
                'total' => $searchResults['total'] ?? count($results),
                'query' => $query,
            ];

            // Include debug meta only when devMode is on OR debug param explicitly set
            if ($includeDebugMeta && !empty($searchResults['meta'])) {
                $response['meta'] = $searchResults['meta'];
                // Add indices searched info
                $response['meta']['indices'] = $indexHandles ?: ['all'];
            }

            return $this->asJson($response);
        } catch (\Throwable $e) {
            $this->logError('Search widget query failed', [
                'query' => $query,
                'indices' => $indexHandles,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'results' => [],
                'total' => 0,
                'query' => $query,
                'error' => Craft::$app->config->general->devMode ? $e->getMessage() : 'Search failed',
            ]);
        }
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
        if (!empty($indicesParam)) {
            $requestedHandles = array_filter(array_map('trim', explode(',', $indicesParam)));

            // Get all enabled index handles
            $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
            $enabledHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );

            // Only allow indices that exist and are enabled
            $indexHandles = array_values(array_intersect($requestedHandles, $enabledHandles));
        }

        // Use 'all' if no valid indices specified
        $indexHandle = !empty($indexHandles) ? implode(',', $indexHandles) : 'all';

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
            // Track with source and trigger info
            SearchManager::$plugin->analytics->trackSearch(
                $indexHandle,
                $query,
                $resultsCount,
                null, // No execution time for explicit tracking
                $backend,
                $siteId,
                [
                    'source' => $source,
                    'trigger' => $trigger, // Will be stored once we add the column
                ]
            );

            $this->logDebug('Explicit search tracking', [
                'query' => $query,
                'indices' => $indexHandle,
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

    /**
     * Get description from hit or element
     */
    private function getDescription(array $hit, mixed $element): ?string
    {
        // Check hit data first
        if (!empty($hit['description'])) {
            return $this->truncate($hit['description'], 150);
        }

        if (!empty($hit['excerpt'])) {
            return $this->truncate($hit['excerpt'], 150);
        }

        if (!empty($hit['content'])) {
            return $this->truncate(strip_tags($hit['content']), 150);
        }

        // Try element fields
        if ($element !== null) {
            // Check for common description field names
            $descriptionFields = ['description', 'excerpt', 'summary', 'intro', 'teaser'];

            foreach ($descriptionFields as $fieldHandle) {
                if (isset($element->$fieldHandle) && !empty($element->$fieldHandle)) {
                    $value = $element->$fieldHandle;
                    if (is_string($value)) {
                        return $this->truncate(strip_tags($value), 150);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get section/type name for grouping
     */
    private function getSectionName(mixed $element): string
    {
        // For entries, get the section name
        if ($element instanceof \craft\elements\Entry) {
            return $element->section->name ?? 'Entries';
        }

        // For other elements, use the display name
        return $element::displayName();
    }

    /**
     * Truncate text to a maximum length
     */
    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
