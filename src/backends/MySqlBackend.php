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
            $storage = $this->getStorage($fullIndexName);

            // Get site ID from options - check raw value first for "all sites" detection
            $rawSiteId = $options['siteId'] ?? null;
            $limit = $options['limit'] ?? 0;
            $typeFilter = $options['type'] ?? null;

            // Build search options (pass language for localized operators)
            $searchOptions = [];
            if (isset($options['language'])) {
                $searchOptions['language'] = $options['language'];
            }

            // Handle "all sites" search (siteId = '*' or null/not set)
            $searchAllSites = $rawSiteId === '*' || $rawSiteId === null;
            $siteIdOption = $rawSiteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            if ($searchAllSites) {
                // Search across all sites and combine results
                // For all-sites search, show each site version separately (no deduplication)
                // This is useful for CP testing to verify multi-site indexing
                $allResults = [];
                $allSites = Craft::$app->getSites()->getAllSites();

                foreach ($allSites as $site) {
                    $siteResults = $engine->search($query, $site->id, 0, $searchOptions);
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

                // Convert to hits format
                $hits = [];
                foreach ($allResults as $compositeKey => $data) {
                    $elementId = $data['elementId'];
                    $elementInfo = $storage->getElementsByIds($data['siteId'], [$elementId]);
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    // Filter by type if specified
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

                // Sort by score descending
                usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);

                // Apply limit if set
                if ($limit > 0) {
                    $hits = array_slice($hits, 0, $limit);
                }
            } else {
                // Single site search
                $siteId = (int)$siteIdOption;
                $results = $engine->search($query, $siteId, $limit, $searchOptions);

                // Get element IDs for enrichment
                $elementIds = array_keys($results);

                // Fetch element info (type, title) for all results
                $elementInfo = $storage->getElementsByIds($siteId, $elementIds);

                // Convert to backend format with type info
                $hits = [];
                foreach ($results as $elementId => $score) {
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    // Filter by type if specified
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
                'index' => $fullIndexName,
                'query' => $query,
                'result_count' => count($hits),
                'type_filter' => $typeFilter,
                'all_sites' => $searchAllSites,
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

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $storage = $this->getStorage($fullIndexName);
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
}
