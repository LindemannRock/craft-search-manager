<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\search\SearchQuery;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Craft Search Adapter
 *
 * Implements Craft's native search interface for front-end template searches.
 * Control Panel requests stay on Craft's native search service, while site
 * requests can resolve ElementQuery::search() through Search Manager coverage.
 *
 * @since 5.0.0
 */
class CraftSearchAdapter extends \craft\services\Search
{
    use LoggingTrait;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // CRAFT SEARCH INTERFACE IMPLEMENTATION
    // =========================================================================

    /**
     * Search elements using our backend
     * This is called by Craft when using Entry::find()->search('query')
     *
     * @param ElementQuery $query
     * @return array Element IDs with scores
     */
    public function searchElements(ElementQuery $query): array
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return parent::searchElements($query);
        }

        $searchQuery = $query->search;

        if (empty($searchQuery)) {
            return [];
        }

        $this->logDebug('Searching elements via Craft adapter', [
            'query' => $searchQuery,
            'elementType' => $query->elementType,
            'siteId' => $query->siteId,
        ]);

        // Get index for this element type/site
        $index = $this->getIndexForQuery($query);

        if (!$index) {
            $this->logDebug('No index found for query, falling back to native search', [
                'elementType' => $query->elementType,
                'siteId' => $query->siteId,
            ]);

            // Fall back to Craft's native search
            return parent::searchElements($query);
        }

        $indexHandle = $index->handle;
        $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);
        $backendType = $backend?->getName();

        // Only works for built-in backends (MySQL, PostgreSQL, Redis, File)
        if (!SearchManager::$plugin->nativeSearchCoverage->isLocalBackendName($backendType)) {
            $this->logDebug('Native search replacement not supported for resolved index backend, falling back', [
                'index' => $indexHandle,
                'backend' => $backendType,
            ]);
            return parent::searchElements($query);
        }

        try {
            // Parse search query
            $parsedQuery = $this->parseSearchQuery($searchQuery);

            // Search using our backend
            $results = SearchManager::$plugin->backend->search(
                $indexHandle,
                $parsedQuery,
                [
                    'siteId' => SearchSiteScopeHelper::normalize($query->siteId),
                    'limit' => 0,
                ]
            );

            // Convert to Craft's expected format: ["elementId-siteId" => score]
            $elementScores = [];

            if (isset($results['hits']) && !empty($results['hits'])) {
                foreach ($results['hits'] as $i => $hit) {
                    $elementId = SearchHitIdentityHelper::elementId($hit);
                    $hitSiteId = isset($hit['siteId']) && is_numeric($hit['siteId'])
                        ? (int)$hit['siteId']
                        : null;

                    if ($elementId !== null && $hitSiteId !== null) {
                        // Use actual score from search results
                        $score = $hit['score'] ?? (count($results['hits']) - $i);
                        // Craft expects format: "elementId-siteId" (e.g., "794-1")
                        $key = $elementId . '-' . $hitSiteId;
                        $elementScores[$key] = $score;
                    }
                }
            }

            // If no results, return empty array (Craft handles this gracefully)
            if (empty($elementScores)) {
                $this->logDebug('No search results found', ['query' => $searchQuery]);
                return [];
            }

            $this->logDebug('Search completed via adapter', [
                'query' => $searchQuery,
                'index' => $indexHandle,
                'results' => count($elementScores),
                'sampleKeys' => array_slice(array_keys($elementScores), 0, 3),
            ]);

            return $elementScores;
        } catch (\Throwable $e) {
            $this->logError('Search failed, falling back to native search', [
                'error' => $e->getMessage(),
            ]);

            // Fall back to Craft's native search on error
            return parent::searchElements($query);
        }
    }

    /**
     * Index an element (called by Craft when element is saved)
     *
     * @param ElementInterface $element
     * @param array|null $fieldHandles Specific field handles to index (null = all fields)
     * @return bool
     */
    public function indexElementAttributes(ElementInterface $element, ?array $fieldHandles = null): bool
    {
        // Only works for built-in backends (MySQL, PostgreSQL, Redis, File)
        $backendType = SearchManager::$plugin->backend->getActiveBackend()?->getName();
        if (!SearchManager::$plugin->nativeSearchCoverage->isLocalBackendName($backendType)) {
            $this->logDebug('Native search replacement not supported for external backends, falling back', [
                'backend' => $backendType,
            ]);
            return parent::indexElementAttributes($element, $fieldHandles);
        }

        // Search Manager content sync is controlled only by the autoIndex-gated
        // element listeners. This adapter refreshes Craft's native searchindex
        // for fallback coverage and reversible native-search replacement.
        $this->logDebug('Refreshing Craft native searchindex through Search Manager adapter', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'fieldHandles' => $fieldHandles,
        ]);

        return parent::indexElementAttributes($element, $fieldHandles);
    }

    /**
     * Index element field values (called by Craft)
     *
     * @param int $elementId
     * @param string $fieldHandle
     * @param string $siteId
     * @param string|array $value
     * @return void
     */
    public function indexElementFields(int $elementId, string $fieldHandle, string $siteId, string|array $value): void
    {
        // Our backends index all fields via transformers
        // So we don't need to do anything here
        $this->logDebug('Field indexing skipped (handled by transformers)', [
            'elementId' => $elementId,
            'field' => $fieldHandle,
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get index for an element query.
     */
    private function getIndexForQuery(ElementQuery $query): ?SearchIndex
    {
        return SearchManager::$plugin->nativeSearchCoverage->getIndexForQuery($query);
    }

    /**
     * Parse Craft's search query format
     * Handles things like "attribute:value" syntax
     */
    private function parseSearchQuery($searchQuery): string
    {
        if (is_string($searchQuery)) {
            return $searchQuery;
        }

        if ($searchQuery instanceof SearchQuery) {
            // Return the query string if it has one
            return $searchQuery->query ?? '';
        }

        return '';
    }
}
