<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\interfaces\AutocompleteBackendInterface;
use lindemannrock\searchmanager\interfaces\StorageBackedBackendInterface;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\search\QueryUnderstanding;
use lindemannrock\searchmanager\search\storage\DocumentKeyStorageInterface;
use lindemannrock\searchmanager\search\storage\ElementSuggestionStorageInterface;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\search\TermResolver;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Autocomplete Service
 *
 * Provides search-as-you-type suggestions based on indexed terms.
 *
 * Features:
 * - Prefix matching (query: "te" → "test", "testing", "technical")
 * - Fuzzy suggestions (typo-tolerant)
 * - Multi-word completion via the shared query-understanding/term-resolution
 *   core (#383/#384): the last token is completed, constrained to documents
 *   matching the preceding tokens, so suggestions always have search results
 * - Popular terms (sorted by frequency)
 * - Configurable limits
 *
 * @since 5.0.0
 */
class AutocompleteService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Get autocomplete suggestions for a query
     *
     * @param string $query Partial search query
     * @param string $indexHandle Index to search
     * @param array $options Suggestion options
     * @return array Array of suggestion strings
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $startTime = microtime(true);

        // Get options with defaults from settings
        $settings = SearchManager::$plugin->getSettings();
        $minLength = $options['minLength'] ?? $settings->autocompleteMinLength ?? 2;
        $limit = $options['limit'] ?? $settings->autocompleteLimit ?? 10;
        $fuzzy = $options['fuzzy'] ?? $settings->enableFuzzy ?? true;
        $language = isset($options['language']) && is_string($options['language'])
            ? LanguageNormalizer::normalizeOrNull($options['language'])
            : null;
        $includeMeta = $options['includeMeta'] ?? false;

        // Check if siteId was explicitly provided (for all-sites indices, it won't be)
        $siteIdProvided = isset($options['siteId']) && $options['siteId'] !== null;
        $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

        // Query script is conclusive (e.g. Arabic) — the same heuristic the
        // search backend applies UNCONDITIONALLY, so all-sites suggestions are
        // language-scoped exactly like all-sites search results (audit #388).
        if ($language === null) {
            $language = LanguageNormalizer::detectScriptLanguage($query);
        }

        // Site-language fallback stays gated on an explicit site, mirroring
        // the is_int($siteScope) gate on the search side.
        if ($language === null && $siteIdProvided) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $language = $site
                ? LanguageNormalizer::normalize(substr($site->language, 0, 2))
                : 'en';
        }

        // Validate query length
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        // Layer 1: shared query understanding (#383/#384) — multi-word input
        // is tokenized like search tokenizes it, with the last token flagged
        // as the one being completed.
        $parsed = QueryUnderstanding::parse($query, [
            'language' => $language,
            'forAutocomplete' => true,
        ]);
        $normalizedQuery = $parsed->normalizedQuery;
        $isCompoundQuery = $parsed->isCompound;
        $compoundPrefix = $parsed->compoundPrefix;

        // Cache on the parsed form: "testing tool" (still typing) and
        // "testing tool " (closed token) are different suggestion requests.
        $cacheQuery = $isCompoundQuery
            ? $normalizedQuery
            : implode(' ', $parsed->tokens) . ($parsed->lastTokenIncomplete ? "\u{0001}typing" : '');

        // Apply index prefix to get full index name (matches how data is stored)
        $fullIndexHandle = $settings->getFullIndexName($indexHandle);

        // Check cache first
        $this->logDebug('Autocomplete cache check', [
            'enableAutocompleteCache' => $settings->enableAutocompleteCache,
            'cacheStorageMethod' => $settings->cacheStorageMethod,
            'autocompleteCacheDuration' => $settings->autocompleteCacheDuration,
        ]);

        if ($settings->enableAutocompleteCache) {
            $cacheKey = $this->generateCacheKey('suggest', $fullIndexHandle, $cacheQuery, $siteIdProvided ? $siteId : null, $language);
            $cached = $this->getFromCache($cacheKey, $fullIndexHandle);
            if ($cached !== null) {
                $this->logDebug('Autocomplete cache hit', [
                    'query' => $normalizedQuery,
                    'index' => $fullIndexHandle,
                ]);
                if ($includeMeta) {
                    return [
                        'suggestions' => $cached,
                        'meta' => [
                            'cached' => true,
                            'cacheEnabled' => $settings->enableAutocompleteCache,
                            'cacheDriver' => $this->getCacheDriver(),
                        ],
                    ];
                }
                return $cached;
            }
            $this->logDebug('Autocomplete cache miss', [
                'cacheKey' => $cacheKey,
                'index' => $fullIndexHandle,
            ]);
        }

        $this->logDebug('Generating suggestions', [
            'query' => $normalizedQuery,
            'index' => $fullIndexHandle,
            'language' => $language,
            'fuzzy' => $fuzzy,
        ]);

        // Get storage using DRY approach via BackendService
        $storage = $this->getStorageForIndex($indexHandle);

        // If no storage (external backend like Meilisearch/Algolia/Typesense),
        // try to use the backend's native autocomplete
        if (!$storage) {
            $suggestions = $this->getBackendAutocomplete($indexHandle, $normalizedQuery, [
                'limit' => $limit,
                'siteId' => $siteIdProvided ? $siteId : null,
                'language' => $language,
            ]);

            // Cache and return
            if ($settings->enableAutocompleteCache && !empty($suggestions)) {
                $this->saveToCache($cacheKey, $suggestions, $fullIndexHandle);
            }

            if ($includeMeta) {
                return [
                    'suggestions' => $suggestions,
                    'meta' => [
                        'cached' => false,
                        'cacheEnabled' => $settings->enableAutocompleteCache,
                        'cacheDriver' => $this->getCacheDriver(),
                    ],
                ];
            }
            return $suggestions;
        }

        $suggestions = [];

        if ($isCompoundQuery) {
            $suggestions = array_keys($storage->getCompoundSuggestionsForAutocomplete(
                $compoundPrefix,
                $siteIdProvided ? $siteId : null,
                $language,
                $limit,
            ));

            if ($settings->enableAutocompleteCache) {
                $this->saveToCache($cacheKey, $suggestions, $fullIndexHandle);
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logInfo('Compound suggestions generated', [
                'query' => $query,
                'count' => count($suggestions),
                'duration_ms' => $duration,
            ]);

            if ($includeMeta) {
                return [
                    'suggestions' => $suggestions,
                    'meta' => [
                        'cached' => false,
                        'cacheEnabled' => $settings->enableAutocompleteCache,
                        'cacheDriver' => $this->getCacheDriver(),
                    ],
                ];
            }

            return $suggestions;
        }

        // Layer 2 + keystone filter: complete the last token via the shared
        // resolver, constrained by the preceding tokens' resolved documents.
        $suggestions = $this->buildTokenSuggestions(
            $storage,
            $parsed->tokens,
            $siteIdProvided ? $siteId : null,
            $limit,
            $language,
            $fuzzy,
        );

        // Save to cache
        if ($settings->enableAutocompleteCache) {
            $this->saveToCache($cacheKey, $suggestions, $fullIndexHandle);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logInfo('Suggestions generated', [
            'query' => $query,
            'count' => count($suggestions),
            'duration_ms' => $duration,
        ]);

        if ($includeMeta) {
            return [
                'suggestions' => $suggestions,
                'meta' => [
                    'cached' => false,
                    'cacheEnabled' => $settings->enableAutocompleteCache,
                    'cacheDriver' => $this->getCacheDriver(),
                ],
            ];
        }
        return $suggestions;
    }

    /**
     * Search-as-you-type over the shared Layer-2 policy (#383/#384).
     *
     * The last token is completed via exact + prefix + fuzzy candidates from
     * the shared {@see TermResolver}; preceding tokens resolve through the
     * same policy search uses. A completion is kept only if its documents
     * intersect the AND-intersection of the preceding tokens' resolved
     * doc-sets — so every emitted suggestion is, by construction, a query
     * search satisfies.
     *
     * @param StorageInterface $storage Storage of the index being suggested against
     * @param string[] $tokens Layer-1 tokens (last one is being completed)
     * @param int|null $siteId Site ID (null for all-sites indices)
     * @param int $limit Maximum suggestions
     * @param string|null $language Language filter for prefix completions
     * @param bool $fuzzy Whether fuzzy candidates participate
     * @return string[] Full suggestion strings (preceding tokens + completion)
     */
    private function buildTokenSuggestions(
        StorageInterface $storage,
        array $tokens,
        ?int $siteId,
        int $limit,
        ?string $language,
        bool $fuzzy,
    ): array {
        if ($tokens === [] || $limit < 1) {
            return [];
        }

        try {
            if ($siteId !== null) {
                return $this->buildTokenSuggestionsForSite($storage, $tokens, $siteId, $limit, $language, $fuzzy);
            }

            // All-sites indices: merge per-site suggestions until the limit
            // fills (same pattern the old fuzzy path used).
            $merged = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                foreach ($this->buildTokenSuggestionsForSite($storage, $tokens, (int)$site->id, $limit, $language, $fuzzy) as $suggestion) {
                    if (!in_array($suggestion, $merged, true)) {
                        $merged[] = $suggestion;
                    }
                    if (count($merged) >= $limit) {
                        return $merged;
                    }
                }
            }

            return $merged;
        } catch (\Throwable $e) {
            // Autocomplete degrades gracefully on storage failure; log without
            // trace strings (audit batch 7).
            $this->logError('Failed to build token suggestions', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return [];
        }
    }

    /**
     * @param string[] $tokens
     * @return string[]
     */
    private function buildTokenSuggestionsForSite(
        StorageInterface $storage,
        array $tokens,
        int $siteId,
        int $limit,
        ?string $language,
        bool $fuzzy,
    ): array {
        $resolver = $this->createTermResolver($storage, $fuzzy);

        $completionToken = $tokens[count($tokens) - 1];
        $precedingTokens = array_slice($tokens, 0, -1);

        // Resolve preceding tokens with the same policy search uses and build
        // the AND-intersection of their doc-sets. A token that resolves to no
        // documents is non-restrictive: search's relax-on-zero backstop keeps
        // even those suggestions answerable.
        $intersection = null;
        foreach ($precedingTokens as $token) {
            $resolved = $resolver->resolve($token, $siteId);
            if ($resolved === []) {
                continue;
            }

            $docsByTerm = $storage->getTermDocumentsBatch(array_column($resolved, 'term'), $siteId);
            $tokenDocs = [];
            foreach ($docsByTerm as $docs) {
                $tokenDocs += $docs;
            }

            if ($tokenDocs === []) {
                continue;
            }

            $intersection = $intersection === null
                ? $tokenDocs
                : array_intersect_key($intersection, $tokenDocs);

            if ($intersection === []) {
                // No document satisfies every preceding token, so no completion
                // can produce a strict-AND result — suggest nothing.
                return [];
            }
        }

        // Narrow the intersection to language-matching documents so every
        // completion is strict-AND answerable in the detected language, the
        // same way search language-filters its results (audit #388).
        if ($intersection !== null && $language !== null) {
            $intersection = $this->filterDocsByLanguage($storage, $intersection, $language, $siteId);
            if ($intersection === []) {
                return [];
            }
        }

        // Completion candidates for the token being typed. Multi-token input
        // over-fetches so the keystone filter below has headroom.
        $candidates = $resolver->resolve($completionToken, $siteId, [
            'includePrefix' => true,
            'prefixLimit' => $precedingTokens === [] ? $limit : $limit * 2,
            'language' => $language,
        ]);

        if ($candidates === []) {
            return [];
        }

        $prefix = $precedingTokens === [] ? '' : implode(' ', $precedingTokens) . ' ';

        if ($intersection === null) {
            // Single token (or only non-restrictive preceding tokens): prefix
            // candidates are live and language-filtered at the term level, but
            // fuzzy candidates come from the ngram store, which can outlive a
            // term's last posting ("ghosts", audit #387), and neither the
            // exact nor the fuzzy tier knows document languages — so doc-check
            // fuzzy candidates, and language-check exact + fuzzy ones the way
            // search filters its results (audit #388). Filter before slicing
            // so dropped candidates can't eat suggestion slots.
            $checkTerms = [];
            foreach ($candidates as $candidate) {
                if ($candidate['matchType'] === TermResolver::MATCH_FUZZY
                    || ($language !== null && $candidate['matchType'] === TermResolver::MATCH_EXACT)
                ) {
                    $checkTerms[] = $candidate['term'];
                }
            }
            $docsByTerm = $checkTerms === [] ? [] : $storage->getTermDocumentsBatch($checkTerms, $siteId);

            $languageDocIds = null;
            if ($language !== null && $docsByTerm !== []) {
                $allDocs = [];
                foreach ($docsByTerm as $docs) {
                    $allDocs += $docs;
                }
                $languageDocIds = $this->filterDocsByLanguage($storage, $allDocs, $language, $siteId);
            }

            $suggestions = [];
            foreach ($candidates as $candidate) {
                $isFuzzy = $candidate['matchType'] === TermResolver::MATCH_FUZZY;
                $isExact = $candidate['matchType'] === TermResolver::MATCH_EXACT;

                if ($isFuzzy || ($language !== null && $isExact)) {
                    $docs = $docsByTerm[$candidate['term']] ?? [];
                    if ($isFuzzy && $docs === []) {
                        continue;
                    }
                    if ($languageDocIds !== null && array_intersect_key($docs, $languageDocIds) === []) {
                        continue;
                    }
                }

                $suggestions[] = $prefix . $candidate['term'];
                if (count($suggestions) >= $limit) {
                    break;
                }
            }

            return $suggestions;
        }

        // THE KEYSTONE FILTER: keep a completion only if its documents
        // intersect the preceding intersection; rank by co-occurrence count,
        // then in-intersection frequency, then resolver order.
        $docsByCandidate = $storage->getTermDocumentsBatch(array_column($candidates, 'term'), $siteId);

        $ranked = [];
        foreach ($candidates as $position => $candidate) {
            $shared = array_intersect_key($docsByCandidate[$candidate['term']] ?? [], $intersection);
            if ($shared === []) {
                continue;
            }

            $ranked[] = [
                'term' => $candidate['term'],
                'coverage' => count($shared),
                'frequency' => array_sum($shared),
                'position' => $position,
            ];
        }

        usort($ranked, static function(array $a, array $b): int {
            return [$b['coverage'], $b['frequency'], $a['position']] <=> [$a['coverage'], $a['frequency'], $b['position']];
        });

        $suggestions = [];
        foreach (array_slice($ranked, 0, $limit) as $entry) {
            $suggestions[] = $prefix . $entry['term'];
        }

        return $suggestions;
    }

    /**
     * Keep only the docs whose document language matches $language — the
     * autocomplete counterpart of search's result language filter, sharing
     * {@see LanguageNormalizer::matches()} semantics. Docs are keyed
     * "siteId:documentKey" as returned by getTermDocumentsBatch(); a doc with
     * no recorded language counts as 'en', mirroring search.
     *
     * @param array<string, mixed> $docs
     * @return array<string, mixed>
     */
    private function filterDocsByLanguage(StorageInterface $storage, array $docs, string $language, int $siteId): array
    {
        if ($docs === []) {
            return [];
        }

        $useDocumentKeys = $storage instanceof DocumentKeyStorageInterface && $storage->supportsDocumentKeys();

        $lookupByDocId = [];
        foreach (array_keys($docs) as $docId) {
            $docId = (string)$docId;
            $documentKey = str_contains($docId, ':') ? explode(':', $docId, 2)[1] : $docId;
            $lookupByDocId[$docId] = $useDocumentKeys ? $documentKey : (int)$documentKey;
        }

        $languages = $useDocumentKeys
            ? $storage->getDocumentLanguagesBatchByKeys($siteId, array_values(array_unique($lookupByDocId)))
            : $storage->getDocumentLanguagesBatch($siteId, array_values(array_unique($lookupByDocId)));

        $filtered = [];
        foreach ($docs as $docId => $value) {
            $docLanguage = (string)($languages[$lookupByDocId[(string)$docId]] ?? 'en');
            if (LanguageNormalizer::matches($docLanguage, $language)) {
                $filtered[$docId] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Build the shared Layer-2 resolver with the same settings search uses.
     */
    private function createTermResolver(StorageInterface $storage, bool $fuzzy): TermResolver
    {
        $settings = SearchManager::$plugin->getSettings();

        return new TermResolver($storage, [
            'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
            'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
            'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
            'enableFuzzy' => $fuzzy,
        ]);
    }

    /**
     * Get element-based autocomplete suggestions with type info
     *
     * Returns rich suggestions with element titles and types for display,
     * perfect for showing icons (📦 for products, 🏷️ for categories).
     *
     * @param string $query Partial search query
     * @param string $indexHandle Index to search
     * @param array $options Suggestion options
     * @return array Array of suggestion objects [{text, type, id}, ...]
     */
    public function suggestElements(string $query, string $indexHandle, array $options = []): array
    {
        $startTime = microtime(true);

        // Get options with defaults from settings
        $settings = SearchManager::$plugin->getSettings();
        $minLength = $options['minLength'] ?? $settings->autocompleteMinLength ?? 2;
        $limit = $options['limit'] ?? $settings->autocompleteLimit ?? 10;
        // Match suggest() behavior: null siteId = search all sites
        $siteId = $options['siteId'] ?? null;
        $elementType = $options['type'] ?? null; // Optional filter by type

        // Validate query length
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        // Apply index prefix to get full index name (matches how data is stored)
        $fullIndexHandle = $settings->getFullIndexName($indexHandle);

        $this->logDebug('Generating element suggestions', [
            'query' => $query,
            'index' => $fullIndexHandle,
            'type_filter' => $elementType,
        ]);

        // Get storage using DRY approach via BackendService
        $storage = $this->getStorageForIndex($indexHandle);
        if (!$storage) {
            return [];
        }

        if (!$storage instanceof ElementSuggestionStorageInterface) {
            $this->logError('Storage does not support element suggestions', [
                'index' => $indexHandle,
                'storage' => get_class($storage),
            ]);
            return [];
        }

        // Get element suggestions from storage
        $suggestions = $storage->getElementSuggestions($query, $siteId, $limit, $elementType);

        // Format response
        $results = [];
        foreach ($suggestions as $suggestion) {
            $results[] = [
                'text' => $suggestion['title'],
                'type' => $suggestion['elementType'],
                'id' => (int)$suggestion['elementId'],
                'siteId' => isset($suggestion['siteId']) ? (int)$suggestion['siteId'] : null,
            ];
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logInfo('Element suggestions generated', [
            'query' => $query,
            'count' => count($results),
            'duration_ms' => $duration,
        ]);

        return $results;
    }

    /**
     * Get storage instance for an index
     *
     * Uses BackendService::getBackendForIndex() to get the properly configured
     * backend, then retrieves storage from it. This is the DRY approach - all
     * backend/storage creation logic is centralized in BackendService.
     *
     * @param string $indexHandle Raw index handle (without prefix)
     * @return StorageInterface|null Storage instance or null
     */
    private function getStorageForIndex(string $indexHandle): ?StorageInterface
    {
        try {
            // Use BackendService to get the properly configured backend for this index
            // This handles all the ConfiguredBackend lookup and settings injection
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);

            if (!$backend) {
                $this->logError('No backend available for index', ['index' => $indexHandle]);
                return null;
            }

            if (!$backend instanceof StorageBackedBackendInterface) {
                $this->logWarning('Backend does not support direct storage access', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return null;
            }

            $storage = $backend->getStorage($indexHandle);

            $this->logDebug('Got storage from backend', [
                'index' => $indexHandle,
                'backend' => $backend->getName(),
            ]);

            return $storage;
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage for index', [
                'index' => $indexHandle,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get autocomplete suggestions from the backend directly
     *
     * Used for external backends (Meilisearch, Algolia, Typesense) that don't
     * use the storage abstraction but have native autocomplete/search support.
     *
     * @param string $indexHandle Raw index handle (without prefix)
     * @param string $query Partial search query
     * @param array $options Options like limit, siteId
     * @return array Array of suggestion strings
     */
    private function getBackendAutocomplete(string $indexHandle, string $query, array $options = []): array
    {
        try {
            // Get the backend for this index
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);

            if (!$backend) {
                $this->logError('No backend available for index', ['index' => $indexHandle]);
                return [];
            }

            if (!$backend instanceof AutocompleteBackendInterface) {
                $this->logWarning('Backend does not support autocomplete', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return [];
            }

            if (!$backend->supportsAutocomplete()) {
                $this->logWarning('Backend autocomplete not enabled', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return [];
            }

            $suggestions = $backend->autocomplete($indexHandle, $query, $options);

            $this->logDebug('Got autocomplete from backend', [
                'index' => $indexHandle,
                'backend' => $backend->getName(),
                'suggestions' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logError('Failed to get backend autocomplete', [
                'index' => $indexHandle,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // CACHE METHODS
    // =========================================================================

    /**
     * Generate cache key for autocomplete
     */
    private function generateCacheKey(string $type, string $indexHandle, string $query, ?int $siteId, ?string $language): string
    {
        $keyData = [
            'type' => $type,
            'index' => $indexHandle,
            'query' => $query,
            'siteId' => $siteId,
            'language' => $language,
        ];

        return md5(json_encode($keyData));
    }

    /**
     * Get from autocomplete cache
     */
    private function getFromCache(string $cacheKey, ?string $indexHandle = null): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . $cacheKey;

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id);
            if ($cache !== null) {
                $cached = $cache->get($fullCacheKey);
                if ($cached !== false) {
                    return $cached;
                }
                return null;
            }
        }

        // Use file-based cache (default)
        $cachePath = $this->getCachePath($indexHandle);
        $cacheFile = $cachePath . $cacheKey . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is expired
        $mtime = filemtime($cacheFile);
        if (time() - $mtime > $settings->autocompleteCacheDuration) {
            @unlink($cacheFile);
            return null;
        }

        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        // Use JSON instead of unserialize to prevent object injection attacks
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON (possibly old serialized format) - delete and return miss
            @unlink($cacheFile);
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Save to autocomplete cache
     */
    private function saveToCache(string $cacheKey, array $data, ?string $indexHandle = null): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . $cacheKey;

        $this->logDebug('Saving to autocomplete cache', [
            'cacheKey' => $cacheKey,
            'storageMethod' => $settings->cacheStorageMethod,
            'duration' => $settings->autocompleteCacheDuration,
            'dataCount' => count($data),
        ]);

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id);
            if ($cache !== null) {
                try {
                    $cache->set($fullCacheKey, $data, $settings->autocompleteCacheDuration);

                    // Track key in set for selective deletion
                    $redis = $cache->redis;
                    $redis->executeCommand('SADD', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete'), $fullCacheKey]);

                    $this->logDebug('Saved to Redis autocomplete cache', ['key' => $fullCacheKey]);
                } catch (\Throwable $e) {
                    $this->logError('Failed to save to Redis autocomplete cache', [
                        'key' => $fullCacheKey,
                        'error' => $e->getMessage(),
                    ]);
                }

                return;
            }
        }

        // Use file-based cache (default)
        try {
            $cachePath = $this->getCachePath($indexHandle);

            // Create directory if it doesn't exist
            if (!is_dir($cachePath)) {
                \craft\helpers\FileHelper::createDirectory($cachePath);
                $this->logDebug('Created autocomplete cache directory', ['path' => $cachePath]);
            }

            $cacheFile = $cachePath . $cacheKey . '.cache';
            // Use JSON instead of serialize to prevent object injection attacks on read
            $result = file_put_contents($cacheFile, json_encode($data, JSON_THROW_ON_ERROR));

            if ($result === false) {
                $this->logError('Failed to write autocomplete cache file', ['file' => $cacheFile]);
            } else {
                $this->logDebug('Saved to file autocomplete cache', [
                    'file' => $cacheFile,
                    'bytes' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to save to file autocomplete cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache path for autocomplete
     */
    private function getCachePath(?string $indexHandle = null): string
    {
        $base = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
        return $indexHandle ? $base . $indexHandle . '/' : $base;
    }

    /**
     * Get cache driver label for meta output
     */
    private function getCacheDriver(): string
    {
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->cacheStorageMethod !== 'redis') {
            return 'file';
        }

        $cache = Craft::$app->cache;
        if ($cache instanceof \yii\redis\Cache) {
            return 'redis';
        }

        $className = get_class($cache);
        $classNameLower = strtolower($className);

        if (str_contains($classNameLower, 'memcache')) {
            return 'memcached';
        }
        if (str_contains($classNameLower, 'file')) {
            return 'file';
        }
        if (str_contains($classNameLower, 'apcu') || str_contains($classNameLower, '\\apc')) {
            return 'apcu';
        }
        if (str_contains($classNameLower, 'dummy') || str_contains($classNameLower, 'array')) {
            return 'none';
        }
        if (str_contains($classNameLower, 'db') || str_contains($classNameLower, 'database')) {
            return 'database';
        }

        $parts = explode('\\', $className);
        $driverName = strtolower(str_replace(['Cache', 'cache'], '', end($parts)));

        return $driverName ?: 'unknown';
    }

    /**
     * Clear autocomplete cache for an index
     */
    public function clearCache(?string $indexHandle = null): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullIndexHandle = null;
        if ($indexHandle !== null) {
            $fullIndexHandle = $settings->getFullIndexName($indexHandle);
        }

        if ($settings->cacheStorageMethod === 'redis') {
            $cache = PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id);
            if ($cache !== null) {
                $redis = $cache->redis;
                $keys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete')]);

                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        // If indexHandle specified, only delete keys for that index
                        if ($fullIndexHandle === null || str_contains($key, $fullIndexHandle)) {
                            $cache->delete($key);
                            $redis->executeCommand('SREM', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete'), $key]);
                        }
                    }
                }

                $this->logInfo('Cleared autocomplete cache (Redis)', ['index' => $indexHandle]);
                return;
            }
        }

        // File-based cache
        $cachePath = $this->getCachePath($fullIndexHandle);

        if (is_dir($cachePath)) {
            \craft\helpers\FileHelper::clearDirectory($cachePath);
        }

        $this->logInfo('Cleared autocomplete cache (file)', ['index' => $indexHandle]);
    }
}
