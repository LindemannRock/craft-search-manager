<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\StopWords;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\search\Tokenizer;
use lindemannrock\searchmanager\SearchManager;

/**
 * Abstract Search Engine Backend
 *
 * Base class for backends that use the internal SearchEngine (MySQL, PostgreSQL, Redis, File).
 * Provides common implementations for index, search, delete, etc.
 *
 * External backends (Algolia, Meilisearch, Typesense) should extend BaseBackend directly.
 */
abstract class AbstractSearchEngineBackend extends BaseBackend
{
    /**
     * @var array<string, SearchEngine> Search engine instances per index
     */
    protected array $searchEngines = [];

    /**
     * @var array<string, StorageInterface> Storage instances per index
     */
    protected array $storages = [];

    // =========================================================================
    // ABSTRACT METHODS - Must be implemented by each backend
    // =========================================================================

    /**
     * Create a storage instance for this backend
     *
     * @param string $fullIndexName Full index name (with prefix already applied)
     * @return StorageInterface
     */
    abstract protected function createStorage(string $fullIndexName): StorageInterface;

    /**
     * Get the error message prefix for logging (e.g., "MySQL", "Redis")
     *
     * @return string
     */
    abstract protected function getBackendLabel(): string;

    // =========================================================================
    // STORAGE & ENGINE MANAGEMENT
    // =========================================================================

    /**
     * Get or create storage instance
     *
     * NOTE: Expects RAW index handle (without prefix). Applies prefix internally.
     *
     * @param string $indexHandle Raw index handle (e.g., 'all-sites')
     * @return StorageInterface
     */
    public function getStorage(string $indexHandle): StorageInterface
    {
        $fullIndexName = $this->getFullIndexName($indexHandle);

        if (!isset($this->storages[$fullIndexName])) {
            $this->storages[$fullIndexName] = $this->createStorage($fullIndexName);
        }

        return $this->storages[$fullIndexName];
    }

    /**
     * Get or create SearchEngine instance for an index
     *
     * NOTE: Expects RAW index handle (without prefix). Applies prefix internally.
     *
     * @param string $indexHandle Raw index handle (e.g., 'all-sites')
     * @return SearchEngine
     */
    protected function getSearchEngine(string $indexHandle): SearchEngine
    {
        $fullIndexName = $this->getFullIndexName($indexHandle);

        if (!isset($this->searchEngines[$fullIndexName])) {
            $storage = $this->getStorage($indexHandle);
            $settings = SearchManager::$plugin->getSettings();

            $this->searchEngines[$fullIndexName] = new SearchEngine($storage, $fullIndexName, [
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

        return $this->searchEngines[$fullIndexName];
    }

    // =========================================================================
    // INDEX OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function index(string $indexName, array $data): bool
    {
        try {
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
            $this->logError("Failed to index in {$this->getBackendLabel()}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        try {
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
                $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);

                if ($engine->indexDocument($siteId, $elementId, $title, $content)) {
                    $storage->storeElement($siteId, $elementId, $title, $elementType);
                    $successCount++;
                }
            }

            $this->logInfo("Batch indexed in {$this->getBackendLabel()}", [
                'index' => $indexName,
                'count' => $successCount,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError("Failed to batch index in {$this->getBackendLabel()}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
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
            $this->logError("Failed to delete from {$this->getBackendLabel()}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function clearIndex(string $indexName): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);

            $this->logInfo("clearIndex called", [
                'inputIndexName' => $indexName,
                'fullIndexName' => $fullIndexName,
            ]);

            $storage = $this->getStorage($indexName);
            $storage->clearAll();

            // Clear cached instances
            unset($this->searchEngines[$fullIndexName]);
            unset($this->storages[$fullIndexName]);

            $this->logInfo("Cleared {$this->getBackendLabel()} index with SearchEngine", ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError("Failed to clear {$this->getBackendLabel()} index", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $storage = $this->getStorage($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

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

    // =========================================================================
    // SEARCH
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

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
                $hits = $this->searchAllSites($engine, $storage, $query, $limit, $typeFilter, $searchOptions);
            } else {
                $hits = $this->searchSingleSite($engine, $storage, $query, (int)$siteIdOption, $limit, $typeFilter, $searchOptions);
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
            $this->logError("{$this->getBackendLabel()} search failed", ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    /**
     * Search across all sites
     *
     * @param SearchEngine $engine
     * @param StorageInterface $storage
     * @param string $query
     * @param int $limit
     * @param string|array|null $typeFilter
     * @param array $searchOptions
     * @return array
     */
    protected function searchAllSites(
        SearchEngine $engine,
        StorageInterface $storage,
        string $query,
        int $limit,
        $typeFilter,
        array $searchOptions,
    ): array {
        $allResults = [];
        $allSites = Craft::$app->getSites()->getAllSites();

        foreach ($allSites as $site) {
            $siteResults = $engine->search($query, $site->id, 0, $searchOptions);
            foreach ($siteResults as $elementId => $score) {
                $compositeKey = $site->id . ':' . $elementId;
                $allResults[$compositeKey] = [
                    'elementId' => $elementId,
                    'score' => $score,
                    'siteId' => $site->id,
                ];
            }
        }

        $hits = [];
        foreach ($allResults as $data) {
            $elementId = $data['elementId'];
            $elementInfo = $storage->getElementsByIds($data['siteId'], [$elementId]);
            $info = $elementInfo[$elementId] ?? null;
            $elementType = $info['elementType'] ?? 'entry';

            if ($typeFilter !== null && !$this->matchesTypeFilter($elementType, $typeFilter)) {
                continue;
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

        // Add matchedIn field indicating which fields matched the query
        // Use first site's ID as default (helper uses per-hit siteId when available)
        $defaultSiteId = !empty($allSites) ? $allSites[0]->id : 1;
        $hits = $this->addMatchedFieldsToHits($hits, $query, $defaultSiteId, $storage);

        return $hits;
    }

    /**
     * Search a single site
     *
     * @param SearchEngine $engine
     * @param StorageInterface $storage
     * @param string $query
     * @param int $siteId
     * @param int $limit
     * @param string|array|null $typeFilter
     * @param array $searchOptions
     * @return array
     */
    protected function searchSingleSite(
        SearchEngine $engine,
        StorageInterface $storage,
        string $query,
        int $siteId,
        int $limit,
        $typeFilter,
        array $searchOptions,
    ): array {
        $results = $engine->search($query, $siteId, $limit, $searchOptions);
        $elementIds = array_keys($results);
        $elementInfo = $storage->getElementsByIds($siteId, $elementIds);

        $hits = [];
        foreach ($results as $elementId => $score) {
            $info = $elementInfo[$elementId] ?? null;
            $elementType = $info['elementType'] ?? 'entry';

            if ($typeFilter !== null && !$this->matchesTypeFilter($elementType, $typeFilter)) {
                continue;
            }

            $hits[] = [
                'objectID' => $elementId,
                'id' => $elementId,
                'score' => $score,
                'type' => $elementType,
            ];
        }

        // Add matchedIn field indicating which fields matched the query
        $hits = $this->addMatchedFieldsToHits($hits, $query, $siteId, $storage);

        return $hits;
    }

    /**
     * Check if element type matches the filter
     *
     * @param string $elementType
     * @param string|array $typeFilter
     * @return bool
     */
    protected function matchesTypeFilter(string $elementType, $typeFilter): bool
    {
        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', $typeFilter);
        return in_array($elementType, $allowedTypes, true);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Derive element type from index name or data
     *
     * @param string $indexName Index name
     * @param array $data Element data
     * @return string Element type (product, category, etc.)
     */
    protected function deriveElementType(string $indexName, array $data): string
    {
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

        return 'entry';
    }

    // =========================================================================
    // MATCHED FIELDS DETECTION
    // =========================================================================

    /**
     * Add matchedIn field to hits indicating which fields matched the query
     *
     * This method determines whether the query matched in 'title', 'content', or both
     * by checking if query terms appear in the stored title terms and document terms.
     *
     * @param array $hits Search results
     * @param string $query Original search query
     * @param int $siteId Site ID
     * @param StorageInterface $storage Storage instance
     * @return array Hits with matchedIn field added
     */
    protected function addMatchedFieldsToHits(array $hits, string $query, int $siteId, StorageInterface $storage): array
    {
        if (empty($hits) || empty($query)) {
            return $hits;
        }

        // Tokenize and filter query terms (same as SearchEngine does)
        $tokenizer = new Tokenizer();
        $stopWords = new StopWords('en'); // TODO: Could detect language from site

        $queryTerms = $tokenizer->tokenize($query);
        $queryTerms = $stopWords->filter($queryTerms);

        if (empty($queryTerms)) {
            return $hits;
        }

        foreach ($hits as &$hit) {
            $elementId = $hit['id'] ?? $hit['objectID'] ?? null;
            if ($elementId === null) {
                continue;
            }

            // Use siteId from hit if available (for multi-site searches)
            $hitSiteId = $hit['siteId'] ?? $siteId;

            $matchedIn = [];

            // Get title terms for this element
            $titleTerms = $storage->getTitleTerms($hitSiteId, (int)$elementId);

            // Get all document terms
            $docTerms = $storage->getDocumentTerms($hitSiteId, (int)$elementId);
            $docTermKeys = array_keys($docTerms);

            // Check if any query terms match in title
            $titleMatches = array_intersect($queryTerms, $titleTerms);
            if (!empty($titleMatches)) {
                $matchedIn[] = 'title';
            }

            // Check if any query terms match in content (terms not in title)
            $contentOnlyTerms = array_diff($docTermKeys, $titleTerms);
            $contentMatches = array_intersect($queryTerms, $contentOnlyTerms);
            if (!empty($contentMatches)) {
                $matchedIn[] = 'content';
            }

            // If we have doc terms but no specific matches found, it might be fuzzy matching
            // In that case, just indicate both fields as potential matches
            if (empty($matchedIn) && !empty($docTermKeys)) {
                // Check for partial/fuzzy matches in title
                foreach ($queryTerms as $queryTerm) {
                    foreach ($titleTerms as $titleTerm) {
                        if (str_contains($titleTerm, $queryTerm) || str_contains($queryTerm, $titleTerm)) {
                            $matchedIn[] = 'title';
                            break 2;
                        }
                    }
                }

                // Check for partial/fuzzy matches in content
                foreach ($queryTerms as $queryTerm) {
                    foreach ($contentOnlyTerms as $contentTerm) {
                        if (str_contains($contentTerm, $queryTerm) || str_contains($queryTerm, $contentTerm)) {
                            $matchedIn[] = 'content';
                            break 2;
                        }
                    }
                }
            }

            if (!empty($matchedIn)) {
                $hit['matchedIn'] = array_unique($matchedIn);
            }
        }

        return $hits;
    }
}
