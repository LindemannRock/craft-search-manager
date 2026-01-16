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
            // Pass raw handle - getSearchEngine/getStorage apply prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

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
                    'index' => $indexName,
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
            // Pass raw handle - getSearchEngine/getStorage apply prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

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

                // Get element type: from data, or derive from index name
                $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);

                if ($engine->indexDocument($siteId, $elementId, $title, $content)) {
                    // Store element metadata for rich autocomplete suggestions
                    $storage->storeElement($siteId, $elementId, $title, $elementType);
                    $successCount++;
                }
            }

            $this->logInfo('Batch indexed with SearchEngine', [
                'index' => $indexName,
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
            // Pass raw handle - getSearchEngine applies prefix internally
            $engine = $this->getSearchEngine($indexName);

            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $indexName,
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
            // Pass raw handle - getSearchEngine/getStorage apply prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

            // Get site ID from options - check raw value first for "all sites" detection
            $rawSiteId = $options['siteId'] ?? null;
            $limit = $options['limit'] ?? 0;
            $typeFilter = $options['type'] ?? null;

            // Handle "all sites" search (siteId = '*' or null/not set)
            $searchAllSites = $rawSiteId === '*' || $rawSiteId === null;
            $siteIdOption = $rawSiteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            if ($searchAllSites) {
                // Search across all sites and combine results
                // For all-sites search, show each site version separately (no deduplication)
                $allResults = [];
                $allSites = Craft::$app->getSites()->getAllSites();

                foreach ($allSites as $site) {
                    $siteResults = $engine->search($query, $site->id, 0);
                    foreach ($siteResults as $elementId => $score) {
                        // Use composite key to keep results from all sites (no deduplication)
                        $compositeKey = $site->id . ':' . $elementId;
                        $allResults[$compositeKey] = [
                            'elementId' => $elementId,
                            'score' => $score,
                            'siteId' => $site->id,
                        ];
                    }
                }

                $hits = [];
                foreach ($allResults as $compositeKey => $data) {
                    $elementId = $data['elementId'];
                    $elementInfo = $storage->getElementsByIds($data['siteId'], [$elementId]);
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    if ($typeFilter !== null) {
                        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', $typeFilter);
                        if (!in_array($elementType, $allowedTypes, true)) {
                            continue;
                        }
                    }

                    $hits[] = [
                        'objectID' => $elementId,
                        'id' => $elementId,
                        'score' => $data['score'],
                        'type' => $elementType,
                        'siteId' => $data['siteId'],
                    ];
                }

                usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
                if ($limit > 0) {
                    $hits = array_slice($hits, 0, $limit);
                }
            } else {
                $siteId = (int)$siteIdOption;
                $results = $engine->search($query, $siteId, $limit);
                $elementIds = array_keys($results);
                $elementInfo = $storage->getElementsByIds($siteId, $elementIds);

                $hits = [];
                foreach ($results as $elementId => $score) {
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    if ($typeFilter !== null) {
                        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', $typeFilter);
                        if (!in_array($elementType, $allowedTypes, true)) {
                            continue;
                        }
                    }

                    $hits[] = [
                        'objectID' => $elementId,
                        'id' => $elementId,
                        'score' => $score,
                        'type' => $elementType,
                    ];
                }
            }

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $indexName,
                'query' => $query,
                'result_count' => count($hits),
                'type_filter' => $typeFilter,
                'all_sites' => $searchAllSites,
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
            // Pass raw handle - getStorage applies prefix internally
            $storage = $this->getStorage($indexName);

            // Clear all data for this index
            $storage->clearAll();

            // Clear cached instances
            $fullIndexName = $this->getFullIndexName($indexName);
            unset($this->searchEngines[$fullIndexName]);
            unset($this->storages[$fullIndexName]);

            $this->logInfo('Cleared file storage index with SearchEngine', [
                'index' => $indexName,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear file storage index', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            // Pass raw handle - getStorage applies prefix internally
            $storage = $this->getStorage($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

            // Check if document has any terms indexed (means it exists)
            $terms = $storage->getDocumentTerms($siteId, $elementId);

            return !empty($terms);
        } catch (\Throwable $e) {
            $this->logError('Failed to check document existence', [
                'index' => $indexName,
                'elementId' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * List all indices with file sizes
     */
    public function listIndices(): array
    {
        // Get base indices from parent
        $indices = parent::listIndices();

        // Add file size for each index
        foreach ($indices as &$index) {
            $fullIndexName = $this->getFullIndexName($index['handle']);
            $indexPath = $this->_basePath . '/' . $fullIndexName;

            if (is_dir($indexPath)) {
                $index['dataSize'] = $this->getDirectorySize($indexPath);
            }
        }

        return $indices;
    }

    /**
     * Calculate total size of a directory
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        if (!is_dir($path)) {
            return $size;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
