<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\QueryParser;
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
 *
 * @since 5.0.0
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
    protected function getSearchEngine(string $indexHandle, ?string $languageOverride = null): SearchEngine
    {
        $fullIndexName = $this->getFullIndexName($indexHandle);

        // Determine language for stop-word filtering
        // Priority: explicit override > index language > site language > 'en' default
        $language = 'en';
        $searchIndex = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
        $hasExplicitLanguage = $searchIndex && !empty($searchIndex->language);

        if ($languageOverride) {
            $language = $languageOverride;
        } elseif ($hasExplicitLanguage) {
            $language = $searchIndex->language;
        } elseif ($currentSite = \Craft::$app->getSites()->getCurrentSite()) {
            // Fall back to current site's language (e.g., 'en-US' -> 'en')
            $siteLanguage = $currentSite->language;
            if (!empty($siteLanguage)) {
                $language = strtolower(substr($siteLanguage, 0, 2));
            }
        }

        // Cache key includes language when derived from site (not explicit in index)
        // This ensures each site gets correct stop words
        $cacheKey = $hasExplicitLanguage ? $fullIndexName : $fullIndexName . '_' . $language;

        if (!isset($this->searchEngines[$cacheKey])) {
            $storage = $this->getStorage($indexHandle);
            $settings = SearchManager::$plugin->getSettings();
            $disableStopWords = $searchIndex ? (bool)$searchIndex->disableStopWords : false;

            $this->searchEngines[$cacheKey] = new SearchEngine($storage, $fullIndexName, [
                'language' => $language,
                'enableStopWords' => $settings->enableStopWords ?? true,
                'disableStopWords' => $disableStopWords,
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

        return $this->searchEngines[$cacheKey];
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
                // Build document data JSON for rich search results
                $documentData = $this->buildDocumentData($indexName, $data);

                // Store element metadata for rich autocomplete suggestions
                $storage->storeElement($siteId, $elementId, $title, $elementType, $documentData);

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
                    $documentData = $this->buildDocumentData($indexName, $data);
                    $storage->storeElement($siteId, $elementId, $title, $elementType, $documentData);
                    $successCount++;
                }
            }

            $this->logInfo("Batch indexed in {$this->getBackendLabel()}", [
                'index' => $indexName,
                'count' => $successCount,
                'total' => count($items),
            ]);

            return $successCount > 0;
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
            // Get site ID from options - check raw value first for "all sites" detection
            $rawSiteId = $options['siteId'] ?? null;

            // Pass language override to get correctly configured SearchEngine
            // Derive language from siteId if no explicit language and a specific siteId is provided
            $languageOverride = $options['language'] ?? null;
            if ($languageOverride === null && $rawSiteId !== null && $rawSiteId !== '*' && is_numeric($rawSiteId)) {
                $site = Craft::$app->getSites()->getSiteById((int) $rawSiteId);
                if ($site && !empty($site->language)) {
                    $languageOverride = strtolower(substr($site->language, 0, 2));
                }
            }

            $engine = $this->getSearchEngine($indexName, $languageOverride);
            $storage = $this->getStorage($indexName);

            $limit = $options['limit'] ?? 0;
            $offset = $options['offset'] ?? 0;
            $typeFilter = $options['type'] ?? null;

            // Build search options (pass language for localized operators)
            $searchOptions = [];
            if ($languageOverride) {
                $searchOptions['language'] = $languageOverride;
            }

            // Handle "all sites" search (siteId = '*' or null/not set)
            $searchAllSites = $rawSiteId === '*' || $rawSiteId === null;
            $siteIdOption = $rawSiteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            if ($searchAllSites) {
                $result = $this->searchAllSites($engine, $storage, $indexName, $query, $limit, $offset, $typeFilter, $searchOptions);
            } else {
                $result = $this->searchSingleSite($engine, $storage, $indexName, $query, (int)$siteIdOption, $limit, $offset, $typeFilter, $searchOptions);
            }

            $hits = $result['hits'] ?? [];
            $total = $result['total'] ?? count($hits);

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $indexName,
                'query' => $query,
                'result_count' => count($hits),
                'type_filter' => $typeFilter,
                'all_sites' => $searchAllSites,
            ]);

            return ['hits' => $hits, 'total' => $total];
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
     * @param int $offset
     * @param string|array|null $typeFilter
     * @param array $searchOptions
     * @return array
     */
    protected function searchAllSites(
        SearchEngine $engine,
        StorageInterface $storage,
        string $indexName,
        string $query,
        int $limit,
        int $offset,
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

            $hit = [
                'objectID' => $elementId,
                'id' => $elementId,
                'score' => $data['score'],
                'type' => $elementType,
                'siteId' => $data['siteId'],
            ];

            // Merge stored document data into hit (provides section, _headings, category, etc.)
            if (!empty($info['documentData'])) {
                $hit = array_merge($info['documentData'], $hit);
            }

            $hits[] = $hit;
        }

        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);

        $total = count($hits);

        if ($limit > 0) {
            $hits = array_slice($hits, $offset, $limit);
        } elseif ($offset > 0) {
            $hits = array_slice($hits, $offset);
        }

        // Add matchedIn field indicating which fields matched the query
        // Use first site's ID as default (helper uses per-hit siteId when available)
        $defaultSiteId = !empty($allSites) ? $allSites[0]->id : 1;
        $hits = $this->addMatchedFieldsToHits($hits, $query, $indexName, $defaultSiteId, $storage, count($hits));

        return ['hits' => $hits, 'total' => $total];
    }

    /**
     * Search a single site
     *
     * @param SearchEngine $engine
     * @param StorageInterface $storage
     * @param string $query
     * @param int $siteId
     * @param int $limit
     * @param int $offset
     * @param string|array|null $typeFilter
     * @param array $searchOptions
     * @return array
     */
    protected function searchSingleSite(
        SearchEngine $engine,
        StorageInterface $storage,
        string $indexName,
        string $query,
        int $siteId,
        int $limit,
        int $offset,
        $typeFilter,
        array $searchOptions,
    ): array {
        $results = $engine->search($query, $siteId, 0, $searchOptions);
        $total = count($results);
        if ($limit > 0) {
            $results = array_slice($results, $offset, $limit, true);
        } elseif ($offset > 0) {
            $results = array_slice($results, $offset, null, true);
        }
        $elementIds = array_keys($results);
        $elementInfo = $storage->getElementsByIds($siteId, $elementIds);

        $hits = [];
        foreach ($results as $elementId => $score) {
            $info = $elementInfo[$elementId] ?? null;
            $elementType = $info['elementType'] ?? 'entry';

            if ($typeFilter !== null && !$this->matchesTypeFilter($elementType, $typeFilter)) {
                continue;
            }

            $hit = [
                'objectID' => $elementId,
                'id' => $elementId,
                'score' => $score,
                'type' => $elementType,
            ];

            // Merge stored document data into hit (provides section, _headings, category, etc.)
            if (!empty($info['documentData'])) {
                $hit = array_merge($info['documentData'], $hit);
            }

            $hits[] = $hit;
        }

        // Add matchedIn field indicating which fields matched the query
        $hits = $this->addMatchedFieldsToHits($hits, $query, $indexName, $siteId, $storage, count($hits));

        return ['hits' => $hits, 'total' => $total];
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
     * Build document data JSON for storage
     *
     * Always stores transformer output for rich search results (headings, categories, sections).
     * Strips heavy text fields (content, body, excerpt) to keep storage lean.
     *
     * @param string $indexName Index handle
     * @param array $data Transformer output data
     * @return string|null JSON-encoded document data or null
     */
    protected function buildDocumentData(string $indexName, array $data): ?string
    {
        $storable = $data;

        $json = json_encode($storable, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logWarning('Failed to encode document data', [
                'index' => $indexName,
                'error' => json_last_error_msg(),
            ]);
            return null;
        }

        return $json;
    }

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
     * @param string $indexName Index handle
     * @param int $siteId Site ID
     * @param StorageInterface $storage Storage instance
     * @param int $maxHits Maximum hits to hydrate (0 = all)
     * @return array Hits with matchedIn field added
     */
    protected function addMatchedFieldsToHits(
        array $hits,
        string $query,
        string $indexName,
        int $siteId,
        StorageInterface $storage,
        int $maxHits = 0,
    ): array {
        if (empty($hits) || empty($query)) {
            return $hits;
        }

        $settings = SearchManager::$plugin->getSettings();
        $searchIndex = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexName);
        $disableStopWords = $searchIndex ? (bool)$searchIndex->disableStopWords : false;

        $tokenizer = new Tokenizer();
        $ngramSizes = array_map('intval', explode(',', $settings->ngramSizes ?? '2,3'));
        $ngramGenerator = new \lindemannrock\searchmanager\search\NgramGenerator($ngramSizes);
        $fuzzyMatcher = new \lindemannrock\searchmanager\search\FuzzyMatcher(
            $ngramGenerator,
            $settings->similarityThreshold ?? 0.25,
            $settings->maxFuzzyCandidates ?? 100
        );

        $siteCache = [];
        $elementTermsCache = [];

        $hitCount = count($hits);
        $limit = $maxHits > 0 ? min($maxHits, $hitCount) : $hitCount;

        // Detect phrase queries — extract phrases for highlight context
        $parsedQuery = QueryParser::hasAdvancedOperators($query) ? QueryParser::parse($query) : null;
        $phrases = $parsedQuery !== null ? $parsedQuery->phrases : [];
        $isPhraseOnly = !empty($phrases) && empty($parsedQuery->terms) && empty($parsedQuery->wildcards);

        for ($i = 0; $i < $limit; $i++) {
            $hit = &$hits[$i];
            $elementId = $hit['id'] ?? $hit['objectID'] ?? null;
            if ($elementId === null) {
                unset($hit);
                continue;
            }

            // Use siteId from hit if available (for multi-site searches)
            $hitSiteId = $hit['siteId'] ?? $siteId;

            if (!isset($siteCache[$hitSiteId])) {
                $language = $this->getSearchLanguageForSite($indexName, $hitSiteId);

                // For parsed queries with phrases, only tokenize the non-phrase terms
                // so phrase words don't leak into individual matchedTerms
                if ($parsedQuery !== null && !empty($phrases)) {
                    $nonPhraseTerms = array_merge($parsedQuery->terms, $parsedQuery->wildcards);
                    $queryTerms = [];
                    foreach ($nonPhraseTerms as $term) {
                        $queryTerms = array_merge($queryTerms, $tokenizer->tokenize($term));
                    }
                } else {
                    $queryTerms = $tokenizer->tokenize($query);
                }
                if (($settings->enableStopWords ?? true) && !$disableStopWords) {
                    $stopWords = new StopWords($language);
                    $queryTerms = $stopWords->filter($queryTerms);
                }
                $queryTerms = array_values(array_unique($queryTerms));

                $actualTermSet = [];

                foreach ($queryTerms as $queryTerm) {
                    $termDocs = $storage->getTermDocuments($queryTerm, (int)$hitSiteId);
                    if (!empty($termDocs)) {
                        $actualTermSet[$queryTerm] = true;
                        continue;
                    }

                    $fuzzyTerms = $fuzzyMatcher->findMatches($queryTerm, $storage, (int)$hitSiteId);
                    if (!empty($fuzzyTerms)) {
                        foreach ($fuzzyTerms as $term) {
                            $actualTermSet[$term] = true;
                        }
                    }
                }

                $siteCache[$hitSiteId] = [
                    'actualTerms' => array_keys($actualTermSet),
                ];
            }

            $actualTerms = $siteCache[$hitSiteId]['actualTerms'] ?? [];
            if (empty($actualTerms) && empty($phrases)) {
                unset($hit);
                continue;
            }

            $matchedIn = [];

            $cacheKey = $hitSiteId . ':' . (int)$elementId;
            if (!isset($elementTermsCache[$cacheKey])) {
                $titleTerms = $storage->getTitleTerms($hitSiteId, (int)$elementId);
                $docTerms = $storage->getDocumentTerms($hitSiteId, (int)$elementId);
                $elementTermsCache[$cacheKey] = [
                    'titleTerms' => $titleTerms,
                    'docTermKeys' => array_keys($docTerms),
                ];
            }

            $titleTerms = $elementTermsCache[$cacheKey]['titleTerms'];
            $docTermKeys = $elementTermsCache[$cacheKey]['docTermKeys'];

            // Check if any matched terms appear in title or content
            $titleMatches = array_values(array_intersect($titleTerms, $actualTerms));
            if (!empty($titleMatches)) {
                $matchedIn[] = 'title';
            }

            $contentOnlyTerms = array_diff($docTermKeys, $titleTerms);
            $contentMatches = array_values(array_intersect($contentOnlyTerms, $actualTerms));
            if (!empty($contentMatches)) {
                $matchedIn[] = 'content';
            }

            if (!empty($matchedIn)) {
                $hit['matchedIn'] = array_unique($matchedIn);
                // For phrase-only queries, don't send individual terms — frontend uses matchedPhrases
                $hit['matchedTerms'] = $isPhraseOnly ? [
                    'title' => [],
                    'content' => [],
                ] : [
                    'title' => $titleMatches,
                    'content' => $contentMatches,
                ];
            } elseif ($isPhraseOnly) {
                // Phrase-only queries have no individual terms but the phrase matched in content
                $hit['matchedIn'] = ['content'];
                $hit['matchedTerms'] = [
                    'title' => [],
                    'content' => [],
                ];
            } else {
                // Fallback: keep actual terms so frontend can still highlight
                $hit['matchedTerms'] = [
                    'title' => [],
                    'content' => $actualTerms,
                ];
            }

            // Add phrases for contiguous phrase highlighting
            if (!empty($phrases)) {
                $hit['matchedPhrases'] = $phrases;
            }
            unset($hit);
        }

        return $hits;
    }

    /**
     * Resolve search language for a site
     */
    protected function getSearchLanguageForSite(string $indexHandle, int $siteId): string
    {
        $searchIndex = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
        if ($searchIndex && !empty($searchIndex->language)) {
            return $searchIndex->language;
        }

        $site = \Craft::$app->getSites()->getSiteById($siteId);
        if ($site && !empty($site->language)) {
            return strtolower(substr($site->language, 0, 2));
        }

        return 'en';
    }
}
