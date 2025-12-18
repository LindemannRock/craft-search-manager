<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use craft\helpers\FileHelper;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\SearchManager;

/**
 * File Backend
 *
 * Search backend using BM25 algorithm with file-based storage
 * Includes fuzzy matching, title boosting, and n-gram based typo tolerance
 * Stores inverted index data in .dat files
 */
class FileBackend extends BaseBackend
{
    private string $_basePath;

    /**
     * @var array<string, SearchEngine> Search engine instances per index
     */
    private array $searchEngines = [];

    /**
     * @var array<string, FileStorage> Storage instances per index
     */
    private array $storages = [];

    public function init(): void
    {
        parent::init();
        $this->_basePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/indices';
        FileHelper::createDirectory($this->_basePath);
    }

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
     * @param string $indexHandle Index handle (without prefix)
     * @return FileStorage
     */
    public function getStorage(string $indexHandle): FileStorage
    {
        // Apply index prefix to get the full index name
        $fullIndexName = $this->getFullIndexName($indexHandle);

        if (!isset($this->storages[$fullIndexName])) {
            $this->storages[$fullIndexName] = new FileStorage($fullIndexName);
        }

        return $this->storages[$fullIndexName];
    }

    public function getName(): string
    {
        return 'file';
    }

    public function isAvailable(): bool
    {
        // File backend is always available (just needs write permissions)
        return is_writable(dirname($this->_basePath)) || is_writable($this->_basePath);
    }

    public function getStatus(): array
    {
        return [
            'name' => 'File',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'path' => $this->_basePath,
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);
            $storage = new FileStorage($fullIndexName);

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
            $this->logError('Failed to index in file storage', [
                'error' => $e->getMessage(),
            ]);
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
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            $successCount = 0;
            foreach ($items as $data) {
                $title = $data['title'] ?? '';
                $content = implode(' ', [
                    $data['content'] ?? '',
                    $data['excerpt'] ?? '',
                    $data['body'] ?? '',
                ]);

                $siteId = $data['siteId'] ?? 1;
                $elementId = $data['objectID'] ?? $data['id'];

                if ($engine->indexDocument($siteId, $elementId, $title, $content)) {
                    $successCount++;
                }
            }

            $this->logInfo('Batch indexed with SearchEngine', [
                'index' => $fullIndexName,
                'count' => $successCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in file storage', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
            $this->logError('Failed to delete from file storage', [
                'error' => $e->getMessage(),
            ]);
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

            // Convert to backend format
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
            $this->logError('File storage search failed', [
                'error' => $e->getMessage(),
            ]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $storage = new FileStorage($fullIndexName);

            // Clear all data for this index
            $storage->clearAll();

            $this->logInfo('Cleared file storage index with SearchEngine', [
                'index' => $fullIndexName,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear file storage index', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
