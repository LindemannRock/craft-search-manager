<?php

namespace lindemannrock\searchmanager\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\search\SearchQuery;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Craft Search Adapter
 *
 * Implements Craft's native search interface
 * Replaces Craft::$app->search to use our multi-backend search engine
 * This makes CP searches and Entry::find()->search() use our backends
 */
class CraftSearchAdapter extends \craft\services\Search
{
    use LoggingTrait;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

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
        // Only works for built-in backends (MySQL, Redis, File)
        $settings = SearchManager::$plugin->getSettings();
        if (!in_array($settings->searchBackend, ['mysql', 'redis', 'file'])) {
            $this->logDebug('Native search replacement not supported for external backends, falling back', [
                'backend' => $settings->searchBackend,
            ]);
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

        // Get index handle for this element type/site
        $indexHandle = $this->getIndexHandleForQuery($query);

        if (!$indexHandle) {
            $this->logDebug('No index found for query, falling back to native search', [
                'elementType' => $query->elementType,
                'siteId' => $query->siteId,
            ]);

            // Fall back to Craft's native search
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
                    'siteId' => $query->siteId ?? 1,
                ]
            );

            // Convert to Craft's expected format: ["elementId-siteId" => score]
            $elementScores = [];
            $siteId = $query->siteId ?? 1;

            if (isset($results['hits']) && !empty($results['hits'])) {
                foreach ($results['hits'] as $i => $hit) {
                    $elementId = $hit['id'] ?? $hit['objectID'] ?? null;

                    if ($elementId && is_numeric($elementId)) {
                        // Use actual score from search results
                        $score = $hit['score'] ?? (count($results['hits']) - $i);
                        // Craft expects format: "elementId-siteId" (e.g., "794-1")
                        $key = $elementId . '-' . $siteId;
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
        // Only works for built-in backends (MySQL, Redis, File)
        $settings = SearchManager::$plugin->getSettings();
        if (!in_array($settings->searchBackend, ['mysql', 'redis', 'file'])) {
            $this->logDebug('Native search replacement not supported for external backends, falling back', [
                'backend' => $settings->searchBackend,
            ]);
            return parent::indexElementAttributes($element, $fieldHandles);
        }

        $this->logDebug('Craft requested element indexing', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'fieldHandles' => $fieldHandles,
        ]);

        // Delegate to our indexing service
        // Note: We index all fields via transformers, not specific fieldHandles
        return SearchManager::$plugin->indexing->indexElement($element);
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
     * Get index handle for an element query
     */
    private function getIndexHandleForQuery(ElementQuery $query): ?string
    {
        $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
        $elementType = $query->elementType;
        $siteId = $query->siteId;

        foreach ($indices as $index) {
            if (!$index->enabled) {
                continue;
            }

            // Match element type
            if ($index->elementType !== $elementType) {
                continue;
            }

            // Match site if specified
            if ($index->siteId && $index->siteId !== $siteId) {
                continue;
            }

            return $index->handle;
        }

        return null;
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
