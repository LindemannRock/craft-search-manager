<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\search\storage\DocumentKeyStorageInterface;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * SearchEngine
 *
 * Main orchestrator for the BM25-based search engine with fuzzy matching.
 * Coordinates tokenization, stop words filtering, indexing, and searching
 * using pluggable storage backends.
 *
 * Key features:
 * - BM25 ranking algorithm
 * - Fuzzy/typo-tolerant search using n-grams
 * - Title boosting
 * - Exact phrase boosting
 * - Multi-term AND logic
 *
 * @since 5.0.0
 */
class SearchEngine
{
    use LoggingTrait;

    /**
     * @var StorageInterface Storage backend
     */
    private StorageInterface $storage;

    /**
     * @var Tokenizer Tokenization component
     */
    private Tokenizer $tokenizer;

    /**
     * @var StopWords Stop words filter
     */
    private StopWords $stopWords;

    /**
     * @var bool Whether stop words filtering is enabled for this index
     */
    private bool $stopWordsEnabled = true;

    /**
     * @var NgramGenerator N-gram generator
     */
    private NgramGenerator $ngramGenerator;

    /**
     * @var BM25Scorer BM25 scoring algorithm
     */
    private BM25Scorer $scorer;

    /**
     * @var TermResolver Shared Layer-2 term-resolution policy (exact + two-tier fuzzy expansion)
     * @since 5.53.0
     */
    private TermResolver $termResolver;

    /**
     * @var CompoundSuggestionExtractor Filename-like compound extractor
     */
    private CompoundSuggestionExtractor $compoundSuggestionExtractor;

    /**
     * @var array{relaxedMatching: bool, resolvedTerms: array<string, array<int, array{term: string, matchType: string, similarity: float}>>}
     * Debug state of the most recent search() call (per-token resolved terms + relax-on-zero flag)
     */
    private array $lastSearchDebug = ['relaxedMatching' => false, 'resolvedTerms' => []];

    /**
     * @var string Index handle
     */
    private string $indexHandle;

    /**
     * @var float Phrase boost factor
     */
    private float $phraseBoostFactor;

    /**
     * Constructor
     *
     * @param StorageInterface $storage Storage backend
     * @param string $indexHandle Index handle
     * @param array $config Configuration options
     */
    public function __construct(StorageInterface $storage, string $indexHandle, array $config = [])
    {
        $this->setLoggingHandle('search-manager');
        $this->storage = $storage;
        $this->indexHandle = $indexHandle;
        $this->phraseBoostFactor = $config['phraseBoost'] ?? 4.0;

        // Initialize components with configuration
        $this->tokenizer = new Tokenizer();
        $this->stopWords = new StopWords(LanguageNormalizer::normalize(
            isset($config['language']) && is_string($config['language']) ? $config['language'] : null,
        ));
        $this->stopWordsEnabled = ($config['enableStopWords'] ?? true) && !($config['disableStopWords'] ?? false);

        $this->ngramGenerator = new NgramGenerator(
            $config['ngramSizes'] ?? [2, 3]
        );

        $this->scorer = new BM25Scorer(
            $config['k1'] ?? 1.5,
            $config['b'] ?? 0.75,
            $config['titleBoost'] ?? 5.0,
            $config['exactMatchBoost'] ?? 3.0
        );

        $this->termResolver = new TermResolver($storage, [
            'ngramSizes' => $config['ngramSizes'] ?? [2, 3],
            'similarityThreshold' => $config['similarityThreshold'] ?? 0.25,
            'maxFuzzyCandidates' => $config['maxFuzzyCandidates'] ?? 100,
            'enableFuzzy' => $config['enableFuzzy'] ?? true,
        ]);

        $this->compoundSuggestionExtractor = new CompoundSuggestionExtractor($this->tokenizer);

        $this->logDebug('Initialized SearchEngine', [
            'index' => $this->indexHandle,
            'storage' => get_class($storage),
            'config' => $config,
        ]);
    }

    /**
     * Filter stop words if enabled for this index
     */
    private function filterTokens(array $tokens): array
    {
        if (!$this->stopWordsEnabled) {
            return $tokens;
        }

        return $this->stopWords->filter($tokens, true);
    }

    /**
     * Index a document
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param string $title Document title
     * @param string $content Document content (excluding title)
     * @param string|null $language Language code (null = auto-detect from site)
     * @return bool Success
     */
    public function indexDocument(int $siteId, int $elementId, string $title, string $content, ?string $language = null): bool
    {
        return $this->indexDocumentWithResult($siteId, $elementId, $title, $content, $language)['success'];
    }

    /**
     * Index a document and report whether the storage row was newly created.
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param string $title Document title
     * @param string $content Document content (excluding title)
     * @param string|null $language Language code (null = auto-detect from site)
     * @return array{success: bool, wasCreated: bool|null}
     * @since 5.53.0
     */
    public function indexDocumentWithResult(int $siteId, int $elementId, string $title, string $content, ?string $language = null): array
    {
        return $this->indexDocumentWithKeyResult(
            $siteId,
            $elementId,
            SearchHitIdentityHelper::pageDocumentId($elementId, $siteId),
            $title,
            $content,
            $language,
        );
    }

    /**
     * @return array{success: bool, wasCreated: bool|null}
     * @since 5.55.0
     */
    public function indexDocumentWithKeyResult(
        int $siteId,
        int $elementId,
        string $documentKey,
        string $title,
        string $content,
        ?string $language = null,
    ): array {
        $lockName = $this->indexDocumentLockName($siteId, $documentKey);
        $lockAcquired = \Craft::$app->getMutex()->acquire($lockName, 30);
        if (!$lockAcquired) {
            $this->logError('Failed to acquire indexing lock', [
                'index' => $this->indexHandle,
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
            ]);
            return [
                'success' => false,
                'wasCreated' => null,
            ];
        }

        try {
            $startTime = microtime(true);

            // Auto-detect language if not provided
            if ($language === null) {
                $site = \Craft::$app->sites->getSiteById($siteId);
                if ($site) {
                    // Extract language code from site language (en-US → en)
                    $language = LanguageNormalizer::normalize(substr($site->language, 0, 2));
                } else {
                    $language = 'en'; // Fallback
                }
            } else {
                $language = LanguageNormalizer::normalize($language);
            }

            $this->logDebug('Indexing document with language', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
                'language' => $language,
            ]);

            // Tokenize and filter title separately
            $titleTokens = $this->tokenizer->tokenize($title);
            $titleTokens = $this->filterTokens($titleTokens);

            // Tokenize and filter all content
            $allTokens = $this->tokenizer->tokenize($title . ' ' . $content);
            $allTokens = $this->filterTokens($allTokens);

            // Calculate term frequencies and document length
            $termFreqs = array_count_values($allTokens);
            $docLength = count($allTokens);

            $this->logDebug('Indexing document', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
                'language' => $language,
                'doc_length' => $docLength,
                'unique_terms' => count($termFreqs),
                'title_terms' => count($titleTokens),
            ]);

            // Delete old document data
            $oldDocLength = $this->documentLength($siteId, $elementId, $documentKey);
            $oldTerms = $this->documentTerms($siteId, $elementId, $documentKey);
            $wasCreated = $oldDocLength <= 0 && empty($oldTerms);
            foreach (array_keys($oldTerms) as $term) {
                $this->removeTermDocument($term, $siteId, $elementId, $documentKey);
            }
            $this->deleteDocumentRows($siteId, $elementId, $documentKey);
            $this->deleteTitleRows($siteId, $elementId, $documentKey);
            $this->deleteCompoundRows($siteId, $elementId, $documentKey);

            if ($oldDocLength > 0 || !empty($oldTerms)) {
                $this->storage->updateMetadata($siteId, $oldDocLength, false);
            }

            // Store new document data WITH language
            $this->storeDocumentRows($siteId, $elementId, $documentKey, $termFreqs, $docLength, $language);
            $this->storeTitleRows($siteId, $elementId, $documentKey, $titleTokens);
            $this->storeCompoundRows($siteId, $elementId, $documentKey, $title . ' ' . $content, $language);

            // Update inverted index
            foreach ($termFreqs as $term => $freq) {
                $this->storeTermDocument($term, $siteId, $elementId, $documentKey, $freq, $language);

                // Generate and store n-grams for new terms
                if (!$this->storage->termHasNgrams($term, $siteId)) {
                    $ngrams = $this->ngramGenerator->generate($term);
                    if (!empty($ngrams)) {
                        $this->storage->storeTermNgrams($term, $ngrams, $siteId);
                    }
                }
            }

            // Update metadata
            $this->storage->updateMetadata($siteId, $docLength, true);

            // Terms dropped by this re-index may have lost their last posting;
            // runs after the new postings store so surviving terms are skipped.
            $this->cleanupOrphanedTermNgrams(array_keys($oldTerms), $siteId);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logInfo('Document indexed', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
                'duration_ms' => $duration,
            ]);

            return [
                'success' => true,
                'wasCreated' => $wasCreated,
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to index document', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'wasCreated' => null,
            ];
        } finally {
            \Craft::$app->getMutex()->release($lockName);
        }
    }

    private function indexDocumentLockName(int $siteId, int|string $documentKey): string
    {
        return sprintf('search-manager:index-document:%s:%d:%s', $this->indexHandle, $siteId, (string)$documentKey);
    }

    private function documentKeyStorage(): ?DocumentKeyStorageInterface
    {
        if ($this->storage instanceof DocumentKeyStorageInterface && $this->storage->supportsDocumentKeys()) {
            return $this->storage;
        }

        return null;
    }

    private function assertDocumentKeySupported(int $siteId, int $elementId, string $documentKey): void
    {
        if ($this->documentKeyStorage() !== null) {
            return;
        }

        if ($documentKey === SearchHitIdentityHelper::pageDocumentId($elementId, $siteId)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Storage backend %s does not support document keys required by split-section indexing for document "%s".',
            get_class($this->storage),
            $documentKey,
        ));
    }

    /**
     * @return array<string, int>
     */
    private function documentTerms(int $siteId, int $elementId, string $documentKey): array
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            return $documentStorage->getDocumentTermsByKey($siteId, $documentKey);
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);

        return $this->storage->getDocumentTerms($siteId, $elementId);
    }

    private function documentLength(int $siteId, int $elementId, string $documentKey): int
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            return $documentStorage->getDocumentLengthByKey($siteId, $documentKey);
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);

        return $this->storage->getDocumentLength($siteId, $elementId);
    }

    private function storeDocumentRows(int $siteId, int $elementId, string $documentKey, array $termFreqs, int $docLength, string $language): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->storeDocumentByKey($siteId, $elementId, $documentKey, $termFreqs, $docLength, $language);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->storeDocument($siteId, $elementId, $termFreqs, $docLength, $language);
    }

    private function storeTermDocument(string $term, int $siteId, int $elementId, string $documentKey, int $frequency, string $language): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->storeTermDocumentByKey($term, $siteId, $elementId, $documentKey, $frequency, $language);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->storeTermDocument($term, $siteId, $elementId, $frequency, $language);
    }

    private function removeTermDocument(string $term, int $siteId, int $elementId, string $documentKey): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->removeTermDocumentByKey($term, $siteId, $documentKey);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->removeTermDocument($term, $siteId, $elementId);
    }

    /**
     * Delete the ngram signature of any term that no longer has postings for
     * the site. Without this, a deleted term stays fuzzy-matchable forever
     * ("ghost" suggestions, audit #387) and the ngram store only shrinks on
     * clearSite/clearAll.
     *
     * @param list<string> $terms Terms whose postings were just removed
     */
    private function cleanupOrphanedTermNgrams(array $terms, int $siteId): void
    {
        if ($terms === []) {
            return;
        }

        $docsByTerm = $this->storage->getTermDocumentsBatch($terms, $siteId);

        foreach ($terms as $term) {
            if (!empty($docsByTerm[$term])) {
                continue;
            }

            $this->storage->removeTermNgrams($term, $this->ngramGenerator->generate($term), $siteId);
        }
    }

    private function deleteDocumentRows(int $siteId, int $elementId, string $documentKey): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->deleteDocumentByKey($siteId, $documentKey);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->deleteDocument($siteId, $elementId);
    }

    private function storeTitleRows(int $siteId, int $elementId, string $documentKey, array $titleTokens): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->storeTitleTermsByKey($siteId, $elementId, $documentKey, $titleTokens);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->storeTitleTerms($siteId, $elementId, $titleTokens);
    }

    private function deleteTitleRows(int $siteId, int $elementId, string $documentKey): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->deleteTitleTermsByKey($siteId, $documentKey);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->deleteTitleTerms($siteId, $elementId);
    }

    private function storeCompoundRows(int $siteId, int $elementId, string $documentKey, string $content, string $language): void
    {
        $suggestions = $this->compoundSuggestionExtractor->extract($content);
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->storeCompoundSuggestionsByKey($siteId, $elementId, $documentKey, $suggestions, $language);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->storeCompoundSuggestions($siteId, $elementId, $suggestions, $language);
    }

    private function deleteCompoundRows(int $siteId, int $elementId, string $documentKey): void
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage !== null) {
            $documentStorage->deleteCompoundSuggestionsByKey($siteId, $documentKey);
            return;
        }

        $this->assertDocumentKeySupported($siteId, $elementId, $documentKey);
        $this->storage->deleteCompoundSuggestions($siteId, $elementId);
    }

    /**
     * Search for documents matching a query
     *
     * Supports operators:
     * - "term1 term2" → AND (default - must match all terms)
     * - "term1 OR term2" → OR (match any term)
     * - "term1 AND term2" → AND (explicit - must match all terms)
     * - "exact phrase" → Phrase search (quoted strings)
     * - term NOT excluded → Exclude terms (NOT operator)
     * - title:term → Field-specific search
     * - term* → Wildcard/prefix search
     * - term^2 → Boost specific terms
     *
     * @param string $query Search query
     * @param int $siteId Site ID
     * @param int $limit Maximum results (0 = no limit)
     * @param array $options Search options (language filter, etc.)
     * @return array Results array [elementId => score]
     */
    public function search(string $query, int $siteId, int $limit = 0, array $options = []): array
    {
        try {
            // Check if query has advanced operators - use new parser
            if (QueryParser::hasAdvancedOperators($query)) {
                // Get language for localized operators (API can override site language)
                $language = LanguageNormalizer::normalize(
                    isset($options['language']) && is_string($options['language']) ? $options['language'] : null,
                    $this->getSiteLanguage($siteId),
                );
                $parsed = QueryParser::parse($query, $language);
                return $this->searchWithParsedQuery($parsed, $siteId, $limit, $options);
            }

            // Fall back to existing simple search for backwards compatibility
            return $this->searchSimple($query, $siteId, $limit, $options);
        } catch (\Throwable $e) {
            $this->logError('Search failed', [
                'query' => $query,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get debug state of the most recent search: per-token resolved terms
     * (term, matchType, similarity) and whether the relax-on-zero backstop
     * fired. Consumed by the backend layer for matchedIn detection and the
     * debug meta exposed to the widget debug toolbar.
     *
     * @return array{relaxedMatching: bool, resolvedTerms: array<string, array<int, array{term: string, matchType: string, similarity: float}>>}
     * @since 5.53.0
     */
    public function getLastSearchDebug(): array
    {
        return $this->lastSearchDebug;
    }

    private function resetSearchDebug(): void
    {
        $this->lastSearchDebug = ['relaxedMatching' => false, 'resolvedTerms' => []];
    }

    /**
     * Search with parsed query (advanced operators)
     *
     * @param ParsedQuery $parsed Parsed query object
     * @param int $siteId Site ID
     * @param int $limit Maximum results (0 = no limit)
     * @param array $options Search options (language, etc.)
     * @return array Results array [elementId => score]
     */
    public function searchWithParsedQuery(ParsedQuery $parsed, int $siteId, int $limit = 0, array $options = []): array
    {
        try {
            $startTime = microtime(true);
            $this->resetSearchDebug();

            $this->logDebug('Advanced search with parsed query', $parsed->toArray());

            // If query is empty after parsing, return no results
            if ($parsed->isEmpty()) {
                return [];
            }

            // Get index statistics
            $totalDocs = $this->storage->getTotalDocCount($siteId);
            $avgDocLength = $this->storage->getAverageDocLength($siteId);

            if ($totalDocs === 0) {
                return [];
            }

            $docScores = [];
            $hasScoreComponent = false;

            // Process phrases (exact matches)
            if (!empty($parsed->phrases)) {
                $docScores = $this->searchPhrases($parsed->phrases, $siteId, $totalDocs, $avgDocLength);
                $hasScoreComponent = true;
            }

            // Process regular terms
            $boostMatchesByTerm = [];
            if (!empty($parsed->terms)) {
                $termScores = $this->searchTerms($parsed->terms, $parsed->operator, $siteId, $totalDocs, $avgDocLength, $boostMatchesByTerm);
                $docScores = $hasScoreComponent
                    ? $this->mergeScores($docScores, $termScores, $parsed->operator)
                    : $termScores;
                $hasScoreComponent = true;
            }

            // Process wildcards
            if (!empty($parsed->wildcards)) {
                $wildcardScores = $this->searchWildcards($parsed->wildcards, $siteId, $totalDocs, $avgDocLength);
                $docScores = $hasScoreComponent
                    ? $this->mergeScores($docScores, $wildcardScores, $parsed->operator)
                    : $wildcardScores;
                $hasScoreComponent = true;
            }

            // Process field filters
            if (!empty($parsed->fieldFilters)) {
                $docScores = $this->applyFieldFilters($docScores, $parsed->fieldFilters, $siteId);
            }

            // Apply boost factors
            if (!empty($parsed->boosts)) {
                $docScores = $this->applyBoosts($docScores, $parsed->boosts, $boostMatchesByTerm);
            }

            // Exclude NOT terms
            if (!empty($parsed->notTerms)) {
                $docScores = $this->excludeNotTerms($docScores, $parsed->notTerms, $siteId);
            }

            // Filter by language if specified
            $languageFilter = isset($options['language']) && is_string($options['language'])
                ? LanguageNormalizer::normalizeOrNull($options['language'])
                : null;
            if ($languageFilter !== null) {
                $docScores = $this->filterByLanguage($docScores, $languageFilter, $siteId);
            }

            // Sort by score (highest first)
            arsort($docScores);

            // Apply limit
            if ($limit > 0) {
                $docScores = array_slice($docScores, 0, $limit, true);
            }

            $finalResults = !empty($options['returnDocumentKeys'])
                ? $this->convertDocIdsToDocumentKeys($docScores)
                : $this->convertDocIdsToElementIds($docScores);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logInfo('Advanced search completed', [
                'query' => $parsed->originalQuery,
                'site_id' => $siteId,
                'result_count' => count($finalResults),
                'duration_ms' => $duration,
            ]);

            return $finalResults;
        } catch (\Throwable $e) {
            $this->logError('Advanced search failed', [
                'query' => $parsed->originalQuery ?? 'unknown',
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Simple search (original implementation for backwards compatibility)
     *
     * @param string $query Search query
     * @param int $siteId Site ID
     * @param int $limit Maximum results (0 = no limit)
     * @param array $options Search options
     * @return array Results array [elementId => score]
     */
    private function searchSimple(string $query, int $siteId, int $limit = 0, array $options = []): array
    {
        try {
            $startTime = microtime(true);

            $this->resetSearchDebug();

            // Layer 1: shared query understanding (normalize, tokenize, operator)
            $parsed = QueryUnderstanding::parse($query, [
                'language' => isset($options['language']) && is_string($options['language'])
                    ? $options['language']
                    : null,
            ]);
            $operator = $parsed->operator;
            $tokens = array_values($this->filterTokens($parsed->tokens));

            if (empty($tokens)) {
                $this->logDebug('Empty query after filtering', ['query' => $query]);
                return [];
            }

            $this->logDebug('Search operator detected', [
                'query' => $query,
                'operator' => $operator,
                'tokens' => $tokens,
            ]);

            // Get index statistics once
            $totalDocs = $this->storage->getTotalDocCount($siteId);
            $avgDocLength = $this->storage->getAverageDocLength($siteId);

            if ($totalDocs === 0) {
                $this->logDebug('No documents in index', ['site_id' => $siteId]);
                return [];
            }

            // Layer 2 + scoring: the shared resolver-driven path (see searchTerms)
            $matchedDocIdsByTerm = [];
            $scoresBeforeOperator = [];
            $results = $this->searchTerms(
                $tokens,
                $operator,
                $siteId,
                $totalDocs,
                $avgDocLength,
                $matchedDocIdsByTerm,
                true,
                $scoresBeforeOperator,
            );

            // Relax-on-zero backstop: when strict AND over the resolved token
            // sets yields nothing for a multi-token query, broaden to OR over
            // the SAME resolved sets instead of dead-ending — BM25 summation
            // still ranks multi-token-coverage documents first.
            if ($results === [] && $operator === 'AND' && count($tokens) > 1 && $scoresBeforeOperator !== []) {
                $results = $scoresBeforeOperator;
                $this->lastSearchDebug['relaxedMatching'] = true;

                $this->logInfo('Relaxed AND to OR after zero-result intersection', [
                    'query' => $query,
                    'tokens' => $tokens,
                ]);
            }

            // Apply exact match boost for multi-term queries
            if (count($tokens) > 1) {
                $results = $this->applyExactMatchBoostToOrderedMatches($results, $tokens, $siteId);
            }

            // Filter by language if specified
            $languageFilter = isset($options['language']) && is_string($options['language'])
                ? LanguageNormalizer::normalizeOrNull($options['language'])
                : null;
            if ($languageFilter !== null) {
                $results = $this->filterByLanguage($results, $languageFilter, $siteId);
            }

            // Sort by score (highest first)
            arsort($results);

            // Apply limit if specified
            if ($limit > 0) {
                $results = array_slice($results, 0, $limit, true);
            }

            $finalResults = !empty($options['returnDocumentKeys'])
                ? $this->convertDocIdsToDocumentKeys($results)
                : $this->convertDocIdsToElementIds($results);

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logInfo('Search completed', [
                'query' => $query,
                'site_id' => $siteId,
                'result_count' => count($finalResults),
                'duration_ms' => $duration,
            ]);

            return $finalResults;
        } catch (\Throwable $e) {
            $this->logError('Search failed', [
                'query' => $query,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // ADVANCED SEARCH HELPER METHODS
    // =========================================================================

    /**
     * Search for exact phrases
     *
     * @param array $phrases Array of phrase strings
     * @param int $siteId Site ID
     * @param int $totalDocs Total document count
     * @param float $avgDocLength Average document length
     * @return array Document scores [docId => score]
     */
    private function searchPhrases(array $phrases, int $siteId, int $totalDocs, float $avgDocLength): array
    {
        $docScores = [];

        foreach ($phrases as $phrase) {
            // Tokenize phrase for candidate search
            $phraseTokens = $this->tokenizer->tokenize($phrase);
            $phraseTokens = $this->filterTokens($phraseTokens);

            if (empty($phraseTokens)) {
                continue;
            }

            // Phase 1: AND search to find candidate documents (fast, uses inverted index)
            $candidateScores = $this->searchTerms($phraseTokens, 'AND', $siteId, $totalDocs, $avgDocLength);

            if (empty($candidateScores)) {
                continue;
            }

            // Phase 2: Verify exact phrase in stored content (precise)
            $verifiedScores = $this->verifyPhraseInContent($candidateScores, $phrase, $siteId);

            // Apply phrase boost to verified results
            foreach ($verifiedScores as $docId => $score) {
                $verifiedScores[$docId] = $score * $this->phraseBoostFactor;
            }

            // Merge with existing scores
            foreach ($verifiedScores as $docId => $score) {
                $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
            }
        }

        return $docScores;
    }

    /**
     * Verify that an exact phrase exists in stored document content
     *
     * Uses documentData from the storage layer to check for the phrase.
     * Comparison is case-insensitive with normalized whitespace.
     *
     * @param array $candidateScores Candidate document scores [docId => score]
     * @param string $phrase The exact phrase to find
     * @param int $siteId Site ID
     * @return array Filtered scores — only docs containing the phrase
     */
    private function verifyPhraseInContent(array $candidateScores, string $phrase, int $siteId): array
    {
        // Normalize the phrase for comparison
        $normalizedPhrase = $this->normalizeForPhraseMatch($phrase);

        if (empty($normalizedPhrase)) {
            return $candidateScores;
        }

        $documentKeys = [];
        foreach (array_keys($candidateScores) as $docId) {
            $documentKeys[$this->documentKeyFromDocId((string)$docId)] = (string)$docId;
        }

        // Batch-fetch documentData for all candidates
        $documentStorage = $this->documentKeyStorage();
        $elements = $documentStorage !== null
            ? $documentStorage->getElementsByDocumentKeys($siteId, array_keys($documentKeys))
            : $this->storage->getElementsByIds($siteId, array_map('intval', array_keys($documentKeys)));

        $verified = [];
        foreach ($documentKeys as $documentKey => $docId) {
            $elementData = $elements[$documentKey] ?? $elements[(int)$documentKey] ?? null;
            if ($elementData === null) {
                continue;
            }

            $docData = $elementData['documentData'] ?? [];
            $title = $elementData['title'] ?? '';

            // Build searchable text from title + stored matching fields.
            $searchableText = $title;
            if (!empty($docData['content'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($docData['content']);
            }
            if (!empty($docData['_bodyClean'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($docData['_bodyClean']);
            }
            if (!empty($docData['_snippetFields']) && is_array($docData['_snippetFields'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($this->stringifyFieldValues($docData['_snippetFields']));
            } elseif (!empty($docData['_fields']) && is_array($docData['_fields'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($this->stringifyFieldValues($docData['_fields']));
            }
            if (!empty($docData['excerpt'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($docData['excerpt']);
            }

            $normalizedText = $this->normalizeForPhraseMatch($searchableText);

            if (str_contains($normalizedText, $normalizedPhrase)) {
                $verified[$docId] = $candidateScores[$docId];
            }
        }

        $this->logDebug('Phrase verification', [
            'phrase' => $phrase,
            'candidates' => count($candidateScores),
            'verified' => count($verified),
        ]);

        return $verified;
    }

    /**
     * Normalize text for phrase matching
     *
     * Lowercases, collapses whitespace, strips punctuation that isn't
     * part of the phrase semantics.
     */
    private function normalizeForPhraseMatch(string $text): string
    {
        return QueryNormalizer::collapseUnicodeWhitespace(mb_strtolower($text));
    }

    /**
     * Strip HTML tags for phrase verification
     */
    private function stripHtmlForPhrase(string $html): string
    {
        $text = strip_tags($html);

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Apply exact-match boost only when stored title/content contains the
     * normalized query terms as an ordered contiguous sequence.
     *
     * @param array<string, float|int> $docScores
     * @param string[] $tokens
     * @return array<string, float|int>
     */
    private function applyExactMatchBoostToOrderedMatches(array $docScores, array $tokens, int $siteId): array
    {
        if (empty($docScores) || count($tokens) < 2) {
            return $docScores;
        }

        $documentKeysByDocId = [];
        foreach (array_keys($docScores) as $docId) {
            $documentKeysByDocId[(string) $docId] = $this->documentKeyFromDocId((string)$docId);
        }

        $documentStorage = $this->documentKeyStorage();
        $elements = $documentStorage !== null
            ? $documentStorage->getElementsByDocumentKeys($siteId, array_values(array_unique($documentKeysByDocId)))
            : $this->storage->getElementsByIds($siteId, array_map('intval', array_values(array_unique($documentKeysByDocId))));

        foreach ($documentKeysByDocId as $docId => $documentKey) {
            $elementData = $elements[$documentKey] ?? $elements[(int)$documentKey] ?? null;
            if ($elementData === null) {
                continue;
            }

            $searchableText = $this->buildPhraseMatchText($elementData);
            if ($searchableText === '') {
                continue;
            }

            $contentTokens = array_values($this->filterTokens($this->tokenizer->tokenize($searchableText)));
            if ($this->containsOrderedTokenSequence($contentTokens, $tokens)) {
                $docScores[$docId] = $this->scorer->applyExactMatchBoost((float) $docScores[$docId]);
            }
        }

        return $docScores;
    }

    /**
     * @param array<string, mixed> $elementData
     */
    private function buildPhraseMatchText(array $elementData): string
    {
        $parts = [];
        if (isset($elementData['title']) && is_scalar($elementData['title'])) {
            $parts[] = (string) $elementData['title'];
        }

        $docData = $elementData['documentData'] ?? [];
        if (is_array($docData)) {
            foreach (['content', '_bodyClean', 'body', 'excerpt'] as $field) {
                if (isset($docData[$field]) && is_scalar($docData[$field])) {
                    $parts[] = $this->stripHtmlForPhrase((string) $docData[$field]);
                }
            }
            if (isset($docData['_snippetFields']) && is_array($docData['_snippetFields'])) {
                $parts[] = $this->stringifyFieldValues($docData['_snippetFields']);
            } elseif (isset($docData['_fields']) && is_array($docData['_fields'])) {
                $parts[] = $this->stringifyFieldValues($docData['_fields']);
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function stringifyFieldValues(array $fields): string
    {
        $values = [];
        foreach ($fields as $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $values[] = (string)$item;
                    }
                }
                continue;
            }

            if (is_scalar($value)) {
                $values[] = (string)$value;
            }
        }

        return implode(' ', $values);
    }

    /**
     * @param string[] $haystack
     * @param string[] $needle
     */
    private function containsOrderedTokenSequence(array $haystack, array $needle): bool
    {
        $needleCount = count($needle);
        if ($needleCount === 0 || count($haystack) < $needleCount) {
            return false;
        }

        $lastStart = count($haystack) - $needleCount;
        for ($start = 0; $start <= $lastStart; $start++) {
            for ($offset = 0; $offset < $needleCount; $offset++) {
                if ($haystack[$start + $offset] !== $needle[$offset]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Search for regular terms
     *
     * @param array $terms Array of search terms
     * @param string $operator 'AND' or 'OR'
     * @param int $siteId Site ID
     * @param int $totalDocs Total document count
     * @param float $avgDocLength Average document length
     * @param array<string, array<string, bool>>|null $matchedDocIdsByTerm Matched doc IDs keyed by normalized query term
     * @param bool $expandTerms Resolve tokens through the shared TermResolver (false for
     *        terms that already come FROM the index, e.g. wildcard expansions)
     * @param array|null $scoresBeforeOperator Receives the pre-operator OR scores so callers
     *        can implement the relax-on-zero backstop without re-querying
     * @return array Document scores [docId => score]
     */
    private function searchTerms(
        array $terms,
        string $operator,
        int $siteId,
        int $totalDocs,
        float $avgDocLength,
        ?array &$matchedDocIdsByTerm = null,
        bool $expandTerms = true,
        ?array &$scoresBeforeOperator = null,
    ): array {
        $matchedDocIdsByTerm ??= [];

        // Tokenize and filter terms
        $processedTerms = [];
        foreach ($terms as $term) {
            $tokens = $this->tokenizer->tokenize($term);
            $tokens = $this->filterTokens($tokens);
            $processedTerms = array_merge($processedTerms, $tokens);
        }

        if (empty($processedTerms)) {
            return [];
        }

        $termMatches = [];
        $docScores = [];
        $allDocIds = [];
        $resolvedByIndex = []; // token position => resolver entries
        $termDocsCache = []; // resolved term => [docId => freq], reused during scoring

        // Layer 2: resolve each token through the shared policy — exact plus
        // two-tier fuzzy expansion — so a token is satisfied by ANY of its
        // resolved terms ("tool" → {tool, tools}).
        foreach ($processedTerms as $termIndex => $token) {
            $termMatches[$termIndex] = [];

            if ($expandTerms) {
                $resolved = $this->termResolver->resolve($token, $siteId);

                $this->lastSearchDebug['resolvedTerms'][$token] = array_map(
                    static fn(array $entry): array => [
                        'term' => $entry['term'],
                        'matchType' => $entry['matchType'],
                        'similarity' => $entry['similarity'],
                    ],
                    $resolved,
                );
            } else {
                // Pre-expanded terms exist in the index by construction —
                // look them up verbatim instead of re-expanding each one.
                $resolved = [[
                    'term' => $token,
                    'matchType' => TermResolver::MATCH_EXACT,
                    'similarity' => 1.0,
                    'weight' => 1.0,
                ]];
            }

            $resolvedByIndex[$termIndex] = $resolved;

            // Batch-fetch docs for all newly-seen resolved terms in one query.
            $termsToFetch = [];
            foreach ($resolved as $entry) {
                if (!array_key_exists($entry['term'], $termDocsCache)) {
                    $termsToFetch[] = $entry['term'];
                }
            }

            if ($termsToFetch !== []) {
                $docsByTerm = $this->storage->getTermDocumentsBatch($termsToFetch, $siteId);
                foreach ($termsToFetch as $fetchedTerm) {
                    $termDocsCache[$fetchedTerm] = $docsByTerm[$fetchedTerm] ?? [];
                }
            }

            foreach ($resolved as $entry) {
                foreach (array_keys($termDocsCache[$entry['term']]) as $docId) {
                    $termMatches[$termIndex][$docId] = true;
                    $matchedDocIdsByTerm[$token][$docId] = true;
                    $allDocIds[$docId] = true;
                }
            }
        }

        if (empty($allDocIds)) {
            return [];
        }

        // Batch fetch document lengths
        $docLengths = $this->documentLengthsForDocIds(array_keys($allDocIds));

        // Batch fetch title terms once for the whole matched set (see searchSimple).
        $titleTermsByDocId = $this->preloadTitleTerms(array_keys($allDocIds), $siteId);

        // Calculate BM25 scores: fuzzy-resolved terms contribute at their
        // resolver weight (similarity × fuzzyWeight) so exact matches always
        // outrank fuzzy-only ones; the title boost applies to exact terms only.
        foreach ($resolvedByIndex as $resolved) {
            foreach ($resolved as $entry) {
                $termDocs = $termDocsCache[$entry['term']] ?? [];

                if (empty($termDocs)) {
                    continue;
                }

                $docFreq = count($termDocs);

                foreach ($termDocs as $docId => $freq) {
                    $docLen = $docLengths[$docId] ?? 1;

                    $score = $this->scorer->score(
                        $freq,
                        $docFreq,
                        $docLen,
                        $avgDocLength,
                        $totalDocs
                    ) * $entry['weight'];

                    if ($entry['matchType'] === TermResolver::MATCH_EXACT
                        && in_array($entry['term'], $titleTermsByDocId[$docId] ?? [], true)
                    ) {
                        $score = $this->scorer->applyTitleBoost($score);
                    }

                    $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                }
            }
        }

        if ($scoresBeforeOperator !== null) {
            $scoresBeforeOperator = $docScores;
        }

        // Apply operator logic
        if ($operator === 'AND') {
            $validDocs = $this->findDocumentsMatchingAllTerms($termMatches);
            $docScores = array_intersect_key($docScores, array_flip($validDocs));
        }

        return $docScores;
    }

    /**
     * Search with wildcards (prefix matching)
     *
     * @param array $wildcards Array of wildcard prefixes
     * @param int $siteId Site ID
     * @param int $totalDocs Total document count
     * @param float $avgDocLength Average document length
     * @return array Document scores [docId => score]
     */
    private function searchWildcards(array $wildcards, int $siteId, int $totalDocs, float $avgDocLength): array
    {
        $expandedTerms = [];

        foreach ($wildcards as $prefix) {
            // Use proper prefix search from storage layer
            $matches = $this->storage->getTermsByPrefix($prefix, $siteId);

            $this->logDebug('Wildcard prefix expanded', [
                'prefix' => $prefix,
                'matches_count' => count($matches),
                'matches' => array_slice($matches, 0, 10), // Log first 10 for debugging
            ]);

            // Surface the expansion in debug/matchedIn under its wildcard form.
            $this->lastSearchDebug['resolvedTerms'][$prefix . '*'] = array_map(
                static fn(string $match): array => [
                    'term' => $match,
                    'matchType' => TermResolver::MATCH_PREFIX,
                    'similarity' => 1.0,
                ],
                $matches,
            );

            // Add all matching terms
            foreach ($matches as $match) {
                $expandedTerms[] = $match;
            }
        }

        if (empty($expandedTerms)) {
            $this->logDebug('No wildcard matches found', [
                'wildcards' => $wildcards,
            ]);
            return [];
        }

        // Remove duplicates
        $expandedTerms = array_unique($expandedTerms);

        $this->logDebug('Searching with expanded wildcard terms', [
            'term_count' => count($expandedTerms),
            'terms' => array_slice($expandedTerms, 0, 20), // Log first 20
        ]);

        // Search with expanded terms verbatim — they come FROM the index, so
        // the resolver's fuzzy expansion would only add noise per term.
        return $this->searchTerms($expandedTerms, 'OR', $siteId, $totalDocs, $avgDocLength, expandTerms: false);
    }

    /**
     * Apply field filters to results
     *
     * @param array $docScores Current document scores
     * @param array $fieldFilters Field filters ['field' => ['term1', 'term2']]
     * @param int $siteId Site ID
     * @return array Filtered document scores
     */
    private function applyFieldFilters(array $docScores, array $fieldFilters, int $siteId): array
    {
        $documentKeys = [];
        foreach (array_keys($docScores) as $docId) {
            $documentKeys[] = $this->documentKeyFromDocId((string)$docId);
        }
        $documentKeys = array_values(array_unique($documentKeys));
        $documentStorage = $this->documentKeyStorage();

        $titleTermsByElement = [];
        if (isset($fieldFilters['title']) || isset($fieldFilters['content'])) {
            $titleTermsByElement = $documentStorage !== null
                ? $documentStorage->getTitleTermsBatchByKeys($siteId, $documentKeys)
                : $this->storage->getTitleTermsBatch($siteId, array_map('intval', $documentKeys));
        }

        $documentTermsByElement = [];
        if (isset($fieldFilters['content'])) {
            $documentTermsByElement = $documentStorage !== null
                ? $documentStorage->getDocumentTermsBatchByKeys($siteId, $documentKeys)
                : $this->storage->getDocumentTermsBatch($siteId, array_map('intval', $documentKeys));
        }

        $filteredScores = [];

        foreach ($docScores as $docId => $score) {
            $matchesAllFilters = true;

            foreach ($fieldFilters as $field => $terms) {
                if ($field === 'title') {
                    // Check if any of the terms are in the title
                    $documentKey = $this->documentKeyFromDocId((string)$docId);
                    $titleTerms = $titleTermsByElement[$documentKey] ?? $titleTermsByElement[(int)$documentKey] ?? [];

                    $hasMatch = false;
                    foreach ($terms as $term) {
                        if (in_array($term, $titleTerms, true)) {
                            $hasMatch = true;
                            break;
                        }
                    }

                    if (!$hasMatch) {
                        $matchesAllFilters = false;
                        break;
                    }
                } elseif ($field === 'content') {
                    // Content is the exact inverse of title attribution: only
                    // document terms not present in the title qualify.
                    $documentKey = $this->documentKeyFromDocId((string)$docId);
                    $titleTerms = $titleTermsByElement[$documentKey] ?? $titleTermsByElement[(int)$documentKey] ?? [];
                    $documentTerms = $documentTermsByElement[$documentKey] ?? $documentTermsByElement[(int)$documentKey] ?? [];
                    $contentTerms = array_diff(array_keys($documentTerms), $titleTerms);

                    $hasMatch = false;
                    foreach ($terms as $term) {
                        if (in_array($term, $contentTerms, true)) {
                            $hasMatch = true;
                            break;
                        }
                    }

                    if (!$hasMatch) {
                        $matchesAllFilters = false;
                        break;
                    }
                }
            }

            if ($matchesAllFilters) {
                $filteredScores[$docId] = $score;
            }
        }

        return $filteredScores;
    }

    /**
     * Apply boost factors to document scores
     *
     * @param array $docScores Current document scores
     * @param array $boosts Boost factors ['term' => factor]
     * @return array Boosted document scores
     */
    private function applyBoosts(array $docScores, array $boosts, array $matchedDocIdsByTerm): array
    {
        foreach ($boosts as $term => $boost) {
            $tokens = $this->filterTokens($this->tokenizer->tokenize((string)$term));

            foreach (array_unique($tokens) as $token) {
                foreach (array_keys($matchedDocIdsByTerm[$token] ?? []) as $docId) {
                    if (isset($docScores[$docId])) {
                        $docScores[$docId] *= (float)$boost;
                    }
                }
            }
        }

        return $docScores;
    }

    /**
     * Exclude documents containing NOT terms
     *
     * @param array $docScores Current document scores
     * @param array $notTerms Terms to exclude
     * @param int $siteId Site ID
     * @return array Filtered document scores
     */
    private function excludeNotTerms(array $docScores, array $notTerms, int $siteId): array
    {
        $excludedDocIds = [];
        $tokensByValue = [];

        foreach ($notTerms as $notTerm) {
            foreach ($this->filterTokens($this->tokenizer->tokenize($notTerm)) as $token) {
                $tokensByValue[$token] = true;
            }
        }

        if ($tokensByValue === []) {
            return $docScores;
        }

        $termDocsByToken = $this->storage->getTermDocumentsBatch(array_keys($tokensByValue), $siteId);
        foreach ($termDocsByToken as $termDocs) {
            foreach (array_keys($termDocs) as $docId) {
                $excludedDocIds[$docId] = true;
            }
        }

        // Remove excluded documents from results
        return array_diff_key($docScores, $excludedDocIds);
    }

    /**
     * Merge two score arrays based on operator
     *
     * @param array $scores1 First score array
     * @param array $scores2 Second score array
     * @param string $operator 'AND' or 'OR'
     * @return array Merged scores
     */
    private function mergeScores(array $scores1, array $scores2, string $operator): array
    {
        if ($operator === 'AND') {
            if (empty($scores1) || empty($scores2)) {
                return [];
            }

            // Only keep documents in both sets
            $merged = [];
            foreach ($scores1 as $docId => $score1) {
                if (isset($scores2[$docId])) {
                    $merged[$docId] = $score1 + $scores2[$docId];
                }
            }
            return $merged;
        }

        if (empty($scores1)) {
            return $scores2;
        }

        if (empty($scores2)) {
            return $scores1;
        }

        // OR: Combine all documents
        $merged = $scores1;
        foreach ($scores2 as $docId => $score2) {
            $merged[$docId] = ($merged[$docId] ?? 0) + $score2;
        }
        return $merged;
    }

    /**
     * Filter results by language
     *
     * @param array $docScores Document scores
     * @param string $language Language code to filter by
     * @param int $siteId Site ID
     * @return array Filtered document scores
     */
    private function filterByLanguage(array $docScores, string $language, int $siteId): array
    {
        $filtered = [];
        $documentKeysBySite = [];

        foreach (array_keys($docScores) as $docId) {
            $parts = explode(':', $docId);
            $elemSiteId = (int)$parts[0];
            $documentKeysBySite[$elemSiteId][] = $this->documentKeyFromDocId((string)$docId);
        }

        $languagesByDocId = [];
        foreach ($documentKeysBySite as $elemSiteId => $documentKeys) {
            $documentStorage = $this->documentKeyStorage();
            $languagesByDocument = $documentStorage !== null
                ? $documentStorage->getDocumentLanguagesBatchByKeys((int)$elemSiteId, array_values(array_unique($documentKeys)))
                : $this->storage->getDocumentLanguagesBatch((int)$elemSiteId, array_map('intval', array_values(array_unique($documentKeys))));
            foreach ($languagesByDocument as $documentKey => $docLanguage) {
                $languagesByDocId[$elemSiteId . ':' . $documentKey] = $docLanguage;
            }
        }

        foreach ($docScores as $docId => $score) {
            $parts = explode(':', $docId);
            $elemSiteId = (int)$parts[0];
            $docKey = $elemSiteId . ':' . $this->documentKeyFromDocId((string)$docId);

            $docLanguage = $languagesByDocId[$docKey] ?? 'en';

            // Check if language matches (exact or generic match)
            if (LanguageNormalizer::matches($docLanguage, $language)) {
                $filtered[$docId] = $score;
            }
        }

        $this->logDebug('Filtered by language', [
            'language' => $language,
            'before' => count($docScores),
            'after' => count($filtered),
        ]);

        return $filtered;
    }

    /**
     * Convert document IDs to element IDs
     *
     * @param array $docScores Document scores [siteId:elementId => score]
     * @return array Element scores [elementId => score]
     */
    private function convertDocIdsToElementIds(array $docScores): array
    {
        $finalResults = [];

        foreach ($docScores as $docId => $score) {
            // Handle both formats: "siteId:elementId" or just "elementId"
            if (is_string($docId) && str_contains($docId, ':')) {
                $parts = explode(':', $docId);
                $elementId = (int)($parts[1] ?? $parts[0] ?? 0);
            } else {
                $elementId = (int)$docId;
            }

            if ($elementId > 0) {
                $finalResults[$elementId] = $score;
            }
        }

        return $finalResults;
    }

    /**
     * @param array<string, float|int> $docScores
     * @return array<string, float|int>
     */
    private function convertDocIdsToDocumentKeys(array $docScores): array
    {
        $finalResults = [];
        foreach ($docScores as $docId => $score) {
            $finalResults[$this->documentKeyFromDocId((string)$docId)] = $score;
        }

        return $finalResults;
    }

    // =========================================================================
    // DOCUMENT MANAGEMENT
    // =========================================================================

    /**
     * Delete a document from the index
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return bool Success
     */
    public function deleteDocument(int $siteId, int $elementId, ?bool &$existed = null): bool
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage === null) {
            return $this->deleteDocumentByKey($siteId, $elementId, SearchHitIdentityHelper::pageDocumentId($elementId, $siteId), $existed);
        }

        $documentKeys = $documentStorage->getDocumentKeysByParent($siteId, $elementId);
        if ($documentKeys === []) {
            $documentKeys = [SearchHitIdentityHelper::pageDocumentId($elementId, $siteId)];
        }

        $success = true;
        $existed = false;
        foreach ($documentKeys as $documentKey) {
            $documentExisted = null;
            if (!$this->deleteDocumentByKey($siteId, $elementId, (string)$documentKey, $documentExisted)) {
                $success = false;
            }
            if ($documentExisted === true) {
                $existed = true;
            }
        }

        return $success;
    }

    public function deleteDocumentByKey(int $siteId, int $elementId, string $documentKey, ?bool &$existed = null): bool
    {
        $lockName = $this->indexDocumentLockName($siteId, $documentKey);
        $lockAcquired = \Craft::$app->getMutex()->acquire($lockName, 30);
        if (!$lockAcquired) {
            $this->logError('Failed to acquire deletion lock', [
                'index' => $this->indexHandle,
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
            ]);
            $existed = null;
            return false;
        }

        try {
            // Get document info before deletion
            $docLength = $this->documentLength($siteId, $elementId, $documentKey);
            $terms = $this->documentTerms($siteId, $elementId, $documentKey);
            $existed = $docLength > 0 || !empty($terms);

            // Remove from inverted index
            foreach (array_keys($terms) as $term) {
                $this->removeTermDocument($term, $siteId, $elementId, $documentKey);
            }
            $this->cleanupOrphanedTermNgrams(array_keys($terms), $siteId);

            // Delete document and title data
            $this->deleteDocumentRows($siteId, $elementId, $documentKey);
            $this->deleteTitleRows($siteId, $elementId, $documentKey);
            $this->deleteCompoundRows($siteId, $elementId, $documentKey);

            // Missing-document deletes are valid no-ops from the pending-sync
            // path. Only subtract metadata when the document actually existed.
            if ($existed) {
                $this->storage->updateMetadata($siteId, $docLength, false);
            }

            $this->logInfo('Document deleted', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
            ]);

            return true;
        } catch (\Throwable $e) {
            $existed = null;
            $this->logError('Failed to delete document', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'document_key' => $documentKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        } finally {
            \Craft::$app->getMutex()->release($lockName);
        }
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Preload title terms for a set of docIds in one batched storage call,
     * keyed by docId, so the BM25 title boost is an in-memory lookup instead of
     * a per-document {@see StorageInterface::getTitleTerms()} query (an N+1 that
     * made common, high-hit-count terms scale linearly with the matched set).
     *
     * Behaviour matches the previous per-document check exactly: title terms are
     * fetched for the given $siteId and a term counts as a title match when it
     * appears in that document's title-term list.
     *
     * @param list<string> $docIds "siteId:elementId" docIds for the matched set
     * @param int $siteId Site ID the search ran against
     * @return array<string, string[]> Map of docId => title terms
     */
    private function preloadTitleTerms(array $docIds, int $siteId): array
    {
        if (empty($docIds)) {
            return [];
        }

        $documentKeys = [];
        $docIdsByElement = [];
        foreach ($docIds as $docId) {
            $documentKey = $this->documentKeyFromDocId((string)$docId);
            $documentKeys[$documentKey] = true;
            $docIdsByElement[$documentKey][] = (string)$docId;
        }

        $documentStorage = $this->documentKeyStorage();
        $byElement = $documentStorage !== null
            ? $documentStorage->getTitleTermsBatchByKeys($siteId, array_keys($documentKeys))
            : $this->storage->getTitleTermsBatch($siteId, array_map('intval', array_keys($documentKeys)));

        $byDocId = [];
        foreach ($docIdsByElement as $documentKey => $ids) {
            $terms = $byElement[$documentKey] ?? $byElement[(int)$documentKey] ?? [];
            foreach ($ids as $docId) {
                $byDocId[$docId] = $terms;
            }
        }

        return $byDocId;
    }

    /**
     * @param list<string|int> $docIds
     * @return array<string, int>
     */
    private function documentLengthsForDocIds(array $docIds): array
    {
        $documentStorage = $this->documentKeyStorage();
        if ($documentStorage === null) {
            return $this->storage->getDocumentLengthsBatch($this->groupDocIdsBySite($docIds));
        }

        $lengths = [];
        foreach ($docIds as $docId) {
            $docId = (string)$docId;
            $parts = explode(':', $docId, 2);
            if (!isset($parts[0], $parts[1])) {
                continue;
            }

            $lengths[$docId] = $documentStorage->getDocumentLengthByKey((int)$parts[0], $parts[1]);
        }

        return $lengths;
    }

    /**
     * Find documents that match ALL query terms
     *
     * @param array $termMatches [termIndex => [docId => true]]
     * @return array Array of docIds
     */
    private function findDocumentsMatchingAllTerms(array $termMatches): array
    {
        if (empty($termMatches)) {
            return [];
        }

        // Start with docs from first term
        $validDocs = array_keys(reset($termMatches));

        // Intersect with docs from other terms
        foreach ($termMatches as $docs) {
            $validDocs = array_intersect($validDocs, array_keys($docs));

            // Early exit if no common documents
            if (empty($validDocs)) {
                return [];
            }
        }

        return $validDocs;
    }

    /**
     * Group document IDs by site for batch operations
     *
     * @param array $docIds Array of "siteId:elementId" strings
     * @return array [siteId => [documentKeys]]
     */
    private function groupDocIdsBySite(array $docIds): array
    {
        $grouped = [];

        foreach ($docIds as $docId) {
            $parts = explode(':', $docId);
            $siteId = (int)$parts[0];
            $documentKey = $this->documentKeyFromDocId((string)$docId);

            if (!isset($grouped[$siteId])) {
                $grouped[$siteId] = [];
            }

            $grouped[$siteId][] = $documentKey;
        }

        return $grouped;
    }

    private function documentKeyFromDocId(string $docId): string
    {
        if (str_contains($docId, ':')) {
            return (string)explode(':', $docId, 2)[1];
        }

        return $docId;
    }

    /**
     * Get the language code for a site
     *
     * @param int $siteId Site ID
     * @return string Language code (e.g., 'en', 'de', 'fr')
     */
    private function getSiteLanguage(int $siteId): string
    {
        try {
            $site = \Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                // Get language from site (e.g., 'de-DE' → 'de')
                return LanguageNormalizer::normalize(substr($site->language, 0, 2));
            }
        } catch (\Throwable $e) {
            $this->logWarning('Could not get site language', [
                'siteId' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }

        return 'en'; // Default to English
    }
}
