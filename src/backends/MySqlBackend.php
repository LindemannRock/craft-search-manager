<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\SearchManager;

/**
 * MySQL Backend
 *
 * Search backend using BM25 algorithm with MySQL storage
 * Includes fuzzy matching, title boosting, and n-gram based typo tolerance
 */
class MySqlBackend extends BaseBackend
{
    /**
     * @var array<string, SearchEngine> Search engine instances per index
     */
    private array $searchEngines = [];

    /**
     * @var array<string, MySqlStorage> Storage instances per index
     */
    private array $storages = [];

    /**
     * Get or create SearchEngine instance for an index
     *
     * @param string $indexHandle Index handle
     * @return SearchEngine
     */
    private function getSearchEngine(string $indexHandle): SearchEngine
    {
        if (!isset($this->searchEngines[$indexHandle])) {
            $storage = $this->getStorage($indexHandle);
            $settings = SearchManager::$plugin->getSettings();

            $this->searchEngines[$indexHandle] = new SearchEngine($storage, $indexHandle, [
                'k1' => $settings->bm25K1 ?? 1.5,
                'b' => $settings->bm25B ?? 0.75,
                'titleBoost' => $settings->titleBoostFactor ?? 5.0,
                'exactMatchBoost' => $settings->exactMatchBoostFactor ?? 3.0,
                'phraseBoost' => $settings->phraseBoostFactor ?? 4.0,
                'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
                'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
                'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
            ]);
        }

        return $this->searchEngines[$indexHandle];
    }

    /**
     * Get or create storage instance (public for autocomplete/other services)
     *
     * @param string $indexHandle Index handle
     * @return MySqlStorage
     */
    public function getStorage(string $indexHandle): MySqlStorage
    {
        if (!isset($this->storages[$indexHandle])) {
            $this->storages[$indexHandle] = new MySqlStorage($indexHandle);
        }

        return $this->storages[$indexHandle];
    }

    public function getName(): string
    {
        return 'mysql';
    }

    public function isAvailable(): bool
    {
        // MySQL backend is only available if Craft uses MySQL
        return Craft::$app->getDb()->getDriverName() === 'mysql';
    }

    public function getStatus(): array
    {
        return [
            'name' => 'Craft Database (MySQL)',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'driver' => 'mysql',
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);
            $storage = $this->getStorage($fullIndexName);

            // Extract title and content
            $title = $data['title'] ?? '';
            $content = implode(' ', [
                $data['content'] ?? '',
                $data['excerpt'] ?? '',
                $data['body'] ?? '',
            ]);

            // Get site ID and element ID
            $siteId = $data['siteId'] ?? 1;
            $elementId = $data['objectID'] ?? $data['id'];

            // Get element type: from data, or derive from index name
            $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);

            // Use SearchEngine to index
            $success = $engine->indexDocument($siteId, $elementId, $title, $content);

            if ($success) {
                // Store element metadata for rich autocomplete suggestions
                $storage->storeElement($siteId, $elementId, $title, $elementType);

                $this->logDebug('Document indexed with SearchEngine', [
                    'index' => $fullIndexName,
                    'element_id' => $elementId,
                    'element_type' => $elementType,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in MySQL', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Derive element type from index name or data
     *
     * @param string $indexName Index name
     * @param array $data Element data
     * @return string Element type (product, category, etc.)
     */
    private function deriveElementType(string $indexName, array $data): string
    {
        // Check for common patterns in index name
        $indexLower = strtolower($indexName);

        if (str_contains($indexLower, 'product')) {
            return 'product';
        }

        if (str_contains($indexLower, 'categor')) {
            return 'category';
        }

        if (str_contains($indexLower, 'article') || str_contains($indexLower, 'blog') || str_contains($indexLower, 'post')) {
            return 'article';
        }

        if (str_contains($indexLower, 'page')) {
            return 'page';
        }

        // Fallback: check Craft element class if available
        if (isset($data['_elementType'])) {
            $elementClass = $data['_elementType'];
            if (str_contains($elementClass, 'Category')) {
                return 'category';
            }
            if (str_contains($elementClass, 'Entry')) {
                return 'entry';
            }
            if (str_contains($elementClass, 'Asset')) {
                return 'asset';
            }
        }

        return 'entry'; // Default fallback
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        foreach ($items as $item) {
            $this->index($indexName, $item);
        }
        $this->logInfo('Batch indexed in MySQL', ['count' => count($items)]);
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $fullIndexName,
                    'element_id' => $elementId,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from MySQL', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            // Get site ID from options or use current site
            $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $limit = $options['limit'] ?? 0;

            // Use SearchEngine to search
            $results = $engine->search($query, $siteId, $limit);

            // Convert to backend format (element IDs with scores)
            $hits = [];
            foreach ($results as $elementId => $score) {
                $hits[] = [
                    'objectID' => $elementId,
                    'id' => $elementId,
                    'score' => $score,
                ];
            }

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $fullIndexName,
                'query' => $query,
                'result_count' => count($hits),
            ]);

            return ['hits' => $hits, 'total' => count($hits)];
        } catch (\Throwable $e) {
            $this->logError('MySQL search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $storage = new MySqlStorage($fullIndexName);

            // Clear all data for this index
            $storage->clearAll();

            $this->logInfo('Cleared MySQL index with SearchEngine', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear MySQL index', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
