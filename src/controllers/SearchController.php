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
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['query', 'track-click'];

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
     *
     * @return Response
     */
    public function actionQuery(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');
        $limit = (int) $request->getParam('limit', 10);
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;
        $hideResultsWithoutUrl = (bool) $request->getParam('hideResultsWithoutUrl', false);

        // Debug mode: explicit param overrides devMode default
        $debugParam = $request->getParam('debug');
        $includeDebugMeta = $debugParam !== null
            ? (bool) $debugParam
            : Craft::$app->config->general->devMode;

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
                // No indices specified - search all
                $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
                $allIndexHandles = array_map(fn($idx) => $idx->handle, $allIndices);

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
