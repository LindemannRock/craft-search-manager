<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\search\QueryParser;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\StopWords;
use lindemannrock\searchmanager\search\storage\DocumentKeyStorageInterface;
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

    protected function documentKeyStorage(StorageInterface $storage): ?DocumentKeyStorageInterface
    {
        if ($storage instanceof DocumentKeyStorageInterface && $storage->supportsDocumentKeys()) {
            return $storage;
        }

        return null;
    }

    private function assertSplitStorageCapability(string $indexName, StorageInterface $storage): bool
    {
        $splitSections = SearchIndex::findByHandle($indexName)?->usesSplitSections() ?? false;
        if (!$splitSections || $this->documentKeyStorage($storage) !== null) {
            return true;
        }

        $this->logError('Split Sections requires storage that supports document keys', [
            'index' => $indexName,
            'storage' => get_class($storage),
        ]);

        return false;
    }

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

        if (is_string($languageOverride) && LanguageNormalizer::normalizeOrNull($languageOverride) !== null) {
            $language = LanguageNormalizer::normalize($languageOverride);
        } elseif ($hasExplicitLanguage) {
            $language = LanguageNormalizer::normalize((string)$searchIndex->language);
        } elseif ($currentSite = \Craft::$app->getSites()->getCurrentSite()) {
            // Fall back to current site's generic language (e.g., en-US -> en).
            $siteLanguage = $currentSite->language;
            if (!empty($siteLanguage)) {
                $language = LanguageNormalizer::normalize(substr($siteLanguage, 0, 2));
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
        return $this->indexWithResult($indexName, $data)['success'];
    }

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        try {
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);
            if (!$this->assertSplitStorageCapability($indexName, $storage)) {
                return [
                    'success' => false,
                    'wasCreated' => null,
                ];
            }

            // Extract title and content
            $title = $data['title'] ?? '';
            $content = implode(' ', [
                $data['content'] ?? '',
                $data['excerpt'] ?? '',
                $data['body'] ?? '',
            ]);

            $data = SearchHitIdentityHelper::normalizeHit($data);

            // Get site ID and element ID
            $siteId = $data['siteId'] ?? 1;
            $elementId = SearchHitIdentityHelper::elementId($data);
            if ($elementId === null) {
                throw new \InvalidArgumentException('Document must have either "elementId", "id", or "objectID" field');
            }

            // Get element type: from data, or derive from index name
            $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);
            $documentKey = SearchHitIdentityHelper::documentId($data) ?? SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);

            // Use SearchEngine to index
            $indexResult = $engine->indexDocumentWithKeyResult($siteId, $elementId, $documentKey, $title, $content);
            $success = $indexResult['success'];

            if ($success) {
                // Build document data JSON for rich search results
                $documentData = $this->buildDocumentData($indexName, $data);

                // Store element metadata for rich autocomplete suggestions
                $documentStorage = $this->documentKeyStorage($storage);
                if ($documentStorage !== null) {
                    $documentStorage->storeElementByKey($siteId, $elementId, $documentKey, $title, $elementType, $documentData);
                } else {
                    $storage->storeElement($siteId, $elementId, $title, $elementType, $documentData);
                }

                $this->logDebug('Document indexed with SearchEngine', [
                    'index' => $indexName,
                    'element_id' => $elementId,
                    'element_type' => $elementType,
                ]);
            }

            return [
                'success' => $success,
                'wasCreated' => $indexResult['wasCreated'],
            ];
        } catch (\Throwable $e) {
            $this->logError("Failed to index in {$this->getBackendLabel()}", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'wasCreated' => null,
            ];
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
            if (!$this->assertSplitStorageCapability($indexName, $storage)) {
                return false;
            }

            $successCount = 0;
            foreach ($items as $data) {
                $data = SearchHitIdentityHelper::normalizeHit($data);
                $title = $data['title'] ?? '';
                $content = implode(' ', [
                    $data['content'] ?? '',
                    $data['excerpt'] ?? '',
                    $data['body'] ?? '',
                ]);

                $siteId = $data['siteId'] ?? 1;
                $elementId = SearchHitIdentityHelper::elementId($data);
                if ($elementId === null) {
                    continue;
                }
                $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);
                $documentKey = SearchHitIdentityHelper::documentId($data) ?? SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);

                if ($engine->indexDocumentWithKeyResult($siteId, $elementId, $documentKey, $title, $content)['success']) {
                    $documentData = $this->buildDocumentData($indexName, $data);
                    $documentStorage = $this->documentKeyStorage($storage);
                    if ($documentStorage !== null) {
                        $documentStorage->storeElementByKey($siteId, $elementId, $documentKey, $title, $elementType, $documentData);
                    } else {
                        $storage->storeElement($siteId, $elementId, $title, $elementType, $documentData);
                    }
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
     * @since 5.56.0
     */
    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        $elementIds = array_values(array_unique(array_filter(
            array_map('intval', $elementIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($elementIds === []) {
            return [];
        }

        $storage = $this->getStorage($indexName);
        $index = SearchIndex::findByHandle($indexName);
        $splitSections = $index?->usesSplitSections() ?? false;
        $documentStorage = $this->documentKeyStorage($storage);
        if ($splitSections && $documentStorage === null) {
            $this->logWarning('Cannot fetch split-section promotion documents without document-key storage', [
                'index' => $indexName,
            ]);
            return [];
        }

        $documents = [];
        foreach ($this->documentFetchSiteIds($index, $siteId) as $resolvedSiteId) {
            if ($splitSections && $documentStorage !== null) {
                foreach ($elementIds as $elementId) {
                    $documentKeys = $documentStorage->getDocumentKeysByParent($resolvedSiteId, $elementId);
                    $elementInfo = $documentStorage->getElementsByDocumentKeys($resolvedSiteId, $documentKeys);
                    foreach ($documentKeys as $documentKey) {
                        $info = $elementInfo[$documentKey] ?? null;
                        if ($info === null) {
                            continue;
                        }

                        $elementType = $this->documentTypeFromElementInfo($info);
                        $documents[] = $this->buildSearchHit($info, [
                            'objectID' => $elementId,
                            'id' => $elementId,
                            'elementId' => $elementId,
                            'backendId' => (string)$documentKey,
                            'type' => $elementType,
                            'elementType' => $elementType,
                            'siteId' => $resolvedSiteId,
                        ]);
                    }
                }
                continue;
            }

            $elementInfo = $storage->getElementsByIds($resolvedSiteId, $elementIds);
            foreach ($elementIds as $elementId) {
                $info = $elementInfo[$elementId] ?? null;
                if ($info === null) {
                    continue;
                }

                $elementType = $this->documentTypeFromElementInfo($info);
                $documents[] = $this->buildSearchHit($info, [
                    'objectID' => $elementId,
                    'id' => $elementId,
                    'elementId' => $elementId,
                    'backendId' => SearchHitIdentityHelper::pageDocumentId($elementId, $resolvedSiteId),
                    'type' => $elementType,
                    'elementType' => $elementType,
                    'siteId' => $resolvedSiteId,
                ]);
            }
        }

        return $this->bestDocumentsByElementId($documents, $elementIds, $siteId);
    }

    /**
     * @inheritdoc
     */
    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return $this->deleteWithResult($indexName, $elementId, $siteId)['success'];
    }

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        try {
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $existed = !empty($storage->getDocumentTerms($siteId, $elementId));

            if (!$existed) {
                return [
                    'success' => true,
                    'existed' => false,
                ];
            }

            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $indexName,
                    'element_id' => $elementId,
                ]);
            }

            return [
                'success' => $success,
                'existed' => $success ? true : null,
            ];
        } catch (\Throwable $e) {
            $this->logError("Failed to delete from {$this->getBackendLabel()}", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'existed' => null,
            ];
        }
    }

    protected function deleteByBackendId(string $indexName, string $backendId): bool
    {
        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            if (preg_match('/^(\d+)_(\d+)(?:_|$)/', $backendId, $match)) {
                $elementId = (int)$match[1];
                $siteId = (int)$match[2];
            } else {
                $elementId = (int)$backendId;
            }

            return $this->getSearchEngine($indexName)->deleteDocumentByKey((int)$siteId, $elementId, $backendId);
        } catch (\Throwable $e) {
            $this->logError("Failed to delete {$this->getBackendLabel()} document by backend ID", [
                'index' => $indexName,
                'backendId' => $backendId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        try {
            $storage = $this->getStorage($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $splitSections = SearchIndex::findByHandle($indexName)?->usesSplitSections() ?? false;
            $documentStorage = $this->documentKeyStorage($storage);
            if ($documentStorage === null) {
                if ($splitSections) {
                    $this->logError('Cannot delete split-section orphans because storage does not support document keys', [
                        'index' => $indexName,
                        'storage' => get_class($storage),
                    ]);

                    return false;
                }

                return true;
            }

            $keep = array_flip(array_map('strval', $keepBackendIds));
            $success = true;
            foreach ($documentStorage->getDocumentKeysByParent((int)$siteId, $elementId) as $documentKey) {
                if (isset($keep[(string)$documentKey])) {
                    continue;
                }

                if (!$this->getSearchEngine($indexName)->deleteDocumentByKey((int)$siteId, $elementId, (string)$documentKey)) {
                    $success = false;
                }
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError("Failed to delete {$this->getBackendLabel()} orphan documents", [
                'index' => $indexName,
                'elementId' => $elementId,
                'siteId' => $siteId,
                'error' => $e->getMessage(),
            ]);

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

            $this->clearCachedIndexInstances($fullIndexName);

            $this->logInfo("Cleared {$this->getBackendLabel()} index with SearchEngine", ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError("Failed to clear {$this->getBackendLabel()} index", ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function clearCachedIndexInstances(string $fullIndexName): void
    {
        foreach (array_keys($this->searchEngines) as $cacheKey) {
            if ($cacheKey === $fullIndexName || str_starts_with($cacheKey, $fullIndexName . '_')) {
                unset($this->searchEngines[$cacheKey]);
            }
        }

        unset($this->storages[$fullIndexName]);
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
            $siteScope = SearchSiteScopeHelper::normalize($options['siteId'] ?? null);

            // Pass language override to get correctly configured SearchEngine
            // Derive language from siteId if no explicit language and a specific siteId is provided
            $languageOverride = isset($options['language']) && is_string($options['language'])
                ? LanguageNormalizer::normalizeOrNull($options['language'])
                : null;
            if ($languageOverride === null && is_int($siteScope)) {
                $site = Craft::$app->getSites()->getSiteById($siteScope);
                if ($site && !empty($site->language)) {
                    $languageOverride = LanguageNormalizer::normalize(substr($site->language, 0, 2));
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

            if ($siteScope === SearchSiteScopeHelper::ALL_SITES) {
                $result = $this->searchAllSites($engine, $storage, $indexName, $query, $limit, $offset, $typeFilter, $searchOptions);
            } elseif (is_array($siteScope)) {
                $result = $this->searchSiteSet($engine, $storage, $indexName, $query, $siteScope, $limit, $offset, $typeFilter, $searchOptions);
            } else {
                $result = $this->searchSingleSite($engine, $storage, $indexName, $query, $siteScope, $limit, $offset, $typeFilter, $searchOptions);
            }

            $hits = $result['hits'] ?? [];
            $total = $result['total'] ?? count($hits);

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $indexName,
                'query' => $query,
                'result_count' => count($hits),
                'type_filter' => $typeFilter,
                'all_sites' => $siteScope === SearchSiteScopeHelper::ALL_SITES,
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
        $allSites = Craft::$app->getSites()->getAllSites();
        $siteIds = array_map(static fn($site): int => (int)$site->id, $allSites);

        return $this->searchSiteSet($engine, $storage, $indexName, $query, $siteIds, $limit, $offset, $typeFilter, $searchOptions);
    }

    /**
     * Search exactly the provided site IDs and merge results by score.
     *
     * @param array<int, int> $siteIds
     * @param string|array|null $typeFilter
     * @return array
     */
    protected function searchSiteSet(
        SearchEngine $engine,
        StorageInterface $storage,
        string $indexName,
        string $query,
        array $siteIds,
        int $limit,
        int $offset,
        $typeFilter,
        array $searchOptions,
    ): array {
        $allResults = [];
        $splitSections = SearchIndex::findByHandle($indexName)?->usesSplitSections() ?? false;
        if ($splitSections) {
            if (!$this->assertSplitStorageCapability($indexName, $storage)) {
                return ['hits' => [], 'total' => 0];
            }

            $searchOptions['returnDocumentKeys'] = true;
        }
        $documentStorage = $this->documentKeyStorage($storage);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            $siteResults = $engine->search($query, $siteId, 0, $searchOptions);
            foreach ($siteResults as $documentKey => $score) {
                $elementId = $splitSections ? $this->elementIdFromDocumentKey((string)$documentKey) : (int)$documentKey;
                $compositeKey = $siteId . ':' . $documentKey;
                $allResults[$compositeKey] = [
                    'elementId' => $elementId,
                    'documentKey' => (string)$documentKey,
                    'score' => $score,
                    'siteId' => $siteId,
                ];
            }
        }

        $elementInfoBySite = [];
        $elementIdsBySite = [];
        foreach ($allResults as $data) {
            if ($splitSections) {
                $elementIdsBySite[(int)$data['siteId']][(string)$data['documentKey']] = true;
            } else {
                $elementIdsBySite[(int)$data['siteId']][(int)$data['elementId']] = true;
            }
        }
        foreach ($elementIdsBySite as $siteId => $idSet) {
            $elementInfoBySite[$siteId] = $splitSections && $documentStorage !== null
                ? $documentStorage->getElementsByDocumentKeys($siteId, array_keys($idSet))
                : $storage->getElementsByIds($siteId, array_keys($idSet));
        }

        $hits = [];
        foreach ($allResults as $data) {
            $elementId = $data['elementId'];
            $siteId = (int)$data['siteId'];
            $elementInfo = $elementInfoBySite[$siteId] ?? [];
            $info = $elementInfo[$data['documentKey']] ?? $elementInfo[$elementId] ?? null;
            $elementType = $this->documentTypeFromElementInfo($info);
            $backendId = $splitSections ? (string)$data['documentKey'] : SearchHitIdentityHelper::pageDocumentId($elementId, $data['siteId']);

            if ($typeFilter !== null && !$this->matchesTypeFilter($elementType, $typeFilter)) {
                continue;
            }

            $hit = $this->buildSearchHit($info, [
                'objectID' => $elementId,
                'id' => $elementId,
                'elementId' => $elementId,
                'backendId' => $backendId,
                'score' => $data['score'],
                'type' => $elementType,
                'elementType' => $elementType,
                'siteId' => $siteId,
            ]);

            $hits[] = SearchHitIdentityHelper::normalizeHit($hit);
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
        $defaultSiteId = !empty($siteIds) ? (int)$siteIds[0] : 1;
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
        $splitSections = SearchIndex::findByHandle($indexName)?->usesSplitSections() ?? false;
        if ($splitSections) {
            if (!$this->assertSplitStorageCapability($indexName, $storage)) {
                return ['hits' => [], 'total' => 0];
            }

            $searchOptions['returnDocumentKeys'] = true;
        }
        $documentStorage = $this->documentKeyStorage($storage);
        $results = $engine->search($query, $siteId, 0, $searchOptions);
        $elementInfo = [];

        if ($typeFilter !== null) {
            $elementInfo = $splitSections && $documentStorage !== null
                ? $documentStorage->getElementsByDocumentKeys($siteId, array_keys($results))
                : $storage->getElementsByIds($siteId, array_keys($results));
            $results = array_filter(
                $results,
                function(float $score, int|string $documentKey) use ($elementInfo, $typeFilter): bool {
                    $info = $elementInfo[$documentKey] ?? null;
                    $elementType = $this->documentTypeFromElementInfo($info);

                    return $this->matchesTypeFilter($elementType, $typeFilter);
                },
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $total = count($results);
        if ($limit > 0) {
            $results = array_slice($results, $offset, $limit, true);
        } elseif ($offset > 0) {
            $results = array_slice($results, $offset, null, true);
        }

        if ($typeFilter === null) {
            $elementInfo = $splitSections && $documentStorage !== null
                ? $documentStorage->getElementsByDocumentKeys($siteId, array_keys($results))
                : $storage->getElementsByIds($siteId, array_keys($results));
        }

        $hits = [];
        foreach ($results as $documentKey => $score) {
            $elementId = $splitSections ? $this->elementIdFromDocumentKey((string)$documentKey) : (int)$documentKey;
            $info = $elementInfo[$documentKey] ?? $elementInfo[$elementId] ?? null;
            $elementType = $this->documentTypeFromElementInfo($info);
            $backendId = $splitSections ? (string)$documentKey : SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);

            $hit = $this->buildSearchHit($info, [
                'objectID' => $elementId,
                'id' => $elementId,
                'elementId' => $elementId,
                'backendId' => $backendId,
                'score' => $score,
                'type' => $elementType,
                'elementType' => $elementType,
                'siteId' => $siteId,
            ]);

            $hits[] = SearchHitIdentityHelper::normalizeHit($hit);
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
        $allowedTypes = array_map(static fn($type): string => strtolower(trim((string)$type)), $allowedTypes);

        return in_array($elementType, $allowedTypes, true);
    }

    /**
     * @param array<string, mixed>|null $elementInfo
     */
    private function documentTypeFromElementInfo(?array $elementInfo): string
    {
        $documentData = is_array($elementInfo !== null ? ($elementInfo['documentData'] ?? null) : null)
            ? $elementInfo['documentData']
            : [];

        foreach ([$documentData['type'] ?? null, $documentData['elementType'] ?? null, $elementInfo['elementType'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        return 'entry';
    }

    /**
     * @param array<string, mixed>|null $elementInfo
     * @param array<string, mixed> $baseHit
     * @return array<string, mixed>
     */
    private function buildSearchHit(?array $elementInfo, array $baseHit): array
    {
        $documentData = is_array($elementInfo['documentData'] ?? null) ? $elementInfo['documentData'] : [];
        $hit = array_merge($baseHit, $documentData);
        $documentType = $this->documentTypeFromElementInfo(['elementType' => $baseHit['elementType'] ?? null, 'documentData' => $hit]);

        $hit['type'] = $documentType;
        $hit['elementType'] = $documentType;

        return $hit;
    }

    private function elementIdFromDocumentKey(string $documentKey): int
    {
        if (preg_match('/^(\d+)(?:_|$)/', $documentKey, $match)) {
            return (int)$match[1];
        }

        return (int)$documentKey;
    }

    /**
     * @return array<int, int>
     */
    private function documentFetchSiteIds(?SearchIndex $index, ?int $siteId): array
    {
        if ($siteId !== null) {
            return [$siteId];
        }

        $indexSiteIds = is_array($index?->siteId ?? null)
            ? array_values(array_filter(array_map('intval', $index->siteId), static fn(int $id): bool => $id > 0))
            : [];
        if ($indexSiteIds !== []) {
            sort($indexSiteIds);
            return array_values(array_unique($indexSiteIds));
        }

        $siteIds = array_map(
            static fn($site): int => (int)$site->id,
            Craft::$app->getSites()->getAllSites(),
        );
        sort($siteIds);

        return array_values(array_unique($siteIds));
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

        $hitCount = count($hits);
        $limit = $maxHits > 0 ? min($maxHits, $hitCount) : $hitCount;

        // Detect phrase queries — extract phrases for highlight context
        $parsedQuery = QueryParser::hasAdvancedOperators($query) ? QueryParser::parse($query) : null;
        $phrases = $parsedQuery !== null ? $parsedQuery->phrases : [];
        $isPhraseOnly = !empty($phrases) && empty($parsedQuery->terms) && empty($parsedQuery->wildcards);
        $elementTermsCache = $this->preloadMatchedFieldTerms($hits, $siteId, $storage, $limit);

        for ($i = 0; $i < $limit; $i++) {
            $hit = &$hits[$i];
            $elementId = SearchHitIdentityHelper::elementId($hit);
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
                $termDocsByTerm = $queryTerms !== []
                    ? $storage->getTermDocumentsBatch($queryTerms, (int)$hitSiteId)
                    : [];

                foreach ($queryTerms as $queryTerm) {
                    $termDocs = $termDocsByTerm[$queryTerm] ?? [];
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

            $cacheKey = $hitSiteId . ':' . ($hit['backendId'] ?? (int)$elementId);
            $titleTerms = $elementTermsCache[$cacheKey]['titleTerms'] ?? [];
            $docTermKeys = $elementTermsCache[$cacheKey]['docTermKeys'] ?? [];

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
     * Preload title/content terms for the hits this response will decorate.
     *
     * @param array<int, mixed> $hits
     * @return array<string, array{titleTerms: array<int, string>, docTermKeys: array<int, string>}>
     */
    private function preloadMatchedFieldTerms(array $hits, int $defaultSiteId, StorageInterface $storage, int $limit): array
    {
        $documentKeysBySite = [];
        for ($i = 0; $i < $limit; $i++) {
            if (!isset($hits[$i]) || !is_array($hits[$i])) {
                continue;
            }

            $elementId = SearchHitIdentityHelper::elementId($hits[$i]);
            if ($elementId === null) {
                continue;
            }

            $hitSiteId = isset($hits[$i]['siteId']) && is_numeric($hits[$i]['siteId'])
                ? (int)$hits[$i]['siteId']
                : $defaultSiteId;
            $documentKey = is_string($hits[$i]['backendId'] ?? null)
                ? (string)$hits[$i]['backendId']
                : (string)$elementId;
            $documentKeysBySite[$hitSiteId][$documentKey] = $documentKey;
        }

        $elementTermsCache = [];
        $documentStorage = $this->documentKeyStorage($storage);
        foreach ($documentKeysBySite as $siteId => $documentKeys) {
            $ids = array_values($documentKeys);
            $titleTermsByElement = $documentStorage !== null
                ? $documentStorage->getTitleTermsBatchByKeys((int)$siteId, $ids)
                : $storage->getTitleTermsBatch((int)$siteId, array_map('intval', $ids));
            $docTermsByElement = $documentStorage !== null
                ? $documentStorage->getDocumentTermsBatchByKeys((int)$siteId, $ids)
                : $storage->getDocumentTermsBatch((int)$siteId, array_map('intval', $ids));

            foreach ($ids as $documentKey) {
                $elementTermsCache[(int)$siteId . ':' . $documentKey] = [
                    'titleTerms' => $titleTermsByElement[$documentKey] ?? $titleTermsByElement[(int)$documentKey] ?? [],
                    'docTermKeys' => array_keys($docTermsByElement[$documentKey] ?? $docTermsByElement[(int)$documentKey] ?? []),
                ];
            }
        }

        return $elementTermsCache;
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
