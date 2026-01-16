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
     * GET /actions/search-manager/search/query?q=test&index=main
     *
     * Parameters:
     * - q: Search query (required)
     * - index: Index handle (optional, searches all if not specified)
     * - limit: Max results (default: 10)
     * - siteId: Site ID to search (optional)
     *
     * @return Response
     */
    public function actionQuery(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');
        $indexHandle = $request->getParam('index', '');
        $limit = (int) $request->getParam('limit', 10);
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;

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
            if ($indexHandle) {
                // Search specific index
                $searchResults = SearchManager::$plugin->backend->search($indexHandle, $query, $options);
            } else {
                // Search all indices
                $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
                $indexHandles = array_map(fn($idx) => $idx->handle, $indices);

                if (empty($indexHandles)) {
                    return $this->asJson([
                        'results' => [],
                        'total' => 0,
                        'query' => $query,
                        'error' => 'No search indices configured',
                    ]);
                }

                $searchResults = SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $options);
            }

            // Transform results for the widget
            $results = [];
            $seenIds = []; // Track seen element IDs for deduplication
            $hits = $searchResults['hits'] ?? [];

            foreach ($hits as $hit) {
                $elementId = $hit['id'] ?? $hit['objectID'] ?? null;
                if (!$elementId) {
                    continue;
                }

                // Deduplicate by element ID - keep the first (highest scored) occurrence
                if (isset($seenIds[$elementId])) {
                    continue;
                }
                $seenIds[$elementId] = true;

                // Try to get the actual element for URL and additional data
                $element = Craft::$app->elements->getElementById($elementId, null, $siteId);

                if ($element === null) {
                    // Element might have been deleted, skip it
                    continue;
                }

                $result = [
                    'id' => $elementId,
                    'title' => $hit['title'] ?? $element->title ?? 'Untitled',
                    'url' => $element->url ?? $element->cpEditUrl ?? null,
                    'description' => $this->getDescription($hit, $element),
                    'section' => $this->getSectionName($element),
                    'type' => $hit['type'] ?? $element::displayName(),
                    'score' => $hit['score'] ?? null,
                ];

                // Add thumbnail if available
                if (method_exists($element, 'getThumbUrl')) {
                    $result['thumbnail'] = $element->getThumbUrl(80);
                }

                $results[] = $result;
            }

            return $this->asJson([
                'results' => $results,
                'total' => $searchResults['total'] ?? count($results),
                'query' => $query,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Search widget query failed', [
                'query' => $query,
                'index' => $indexHandle,
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
