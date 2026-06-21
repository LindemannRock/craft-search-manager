<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;
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
     * @var FuzzyMatcher Fuzzy matching component
     */
    private FuzzyMatcher $fuzzyMatcher;

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

        $this->fuzzyMatcher = new FuzzyMatcher(
            $this->ngramGenerator,
            $config['similarityThreshold'] ?? 0.25,
            $config['maxFuzzyCandidates'] ?? 100
        );

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
                'language' => $language,
                'doc_length' => $docLength,
                'unique_terms' => count($termFreqs),
                'title_terms' => count($titleTokens),
            ]);

            // Delete old document data
            $oldDocLength = $this->storage->getDocumentLength($siteId, $elementId);
            $oldTerms = $this->storage->getDocumentTerms($siteId, $elementId);
            foreach (array_keys($oldTerms) as $term) {
                $this->storage->removeTermDocument($term, $siteId, $elementId);
            }
            $this->storage->deleteDocument($siteId, $elementId);
            $this->storage->deleteTitleTerms($siteId, $elementId);

            if ($oldDocLength > 0 || !empty($oldTerms)) {
                $this->storage->updateMetadata($siteId, $oldDocLength, false);
            }

            // Store new document data WITH language
            $this->storage->storeDocument($siteId, $elementId, $termFreqs, $docLength, $language);
            $this->storage->storeTitleTerms($siteId, $elementId, $titleTokens);

            // Update inverted index
            foreach ($termFreqs as $term => $freq) {
                $this->storage->storeTermDocument($term, $siteId, $elementId, $freq, $language);

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

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logInfo('Document indexed', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'duration_ms' => $duration,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to index document', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
            $allDocIds = [];

            // Process phrases (exact matches)
            if (!empty($parsed->phrases)) {
                $docScores = $this->searchPhrases($parsed->phrases, $siteId, $totalDocs, $avgDocLength);
                $allDocIds = array_keys($docScores);
            }

            // Process regular terms
            $boostMatchesByTerm = [];
            if (!empty($parsed->terms)) {
                $termScores = $this->searchTerms($parsed->terms, $parsed->operator, $siteId, $totalDocs, $avgDocLength, $boostMatchesByTerm);
                $docScores = $this->mergeScores($docScores, $termScores, $parsed->operator);
                $allDocIds = array_unique(array_merge($allDocIds, array_keys($termScores)));
            }

            // Process wildcards
            if (!empty($parsed->wildcards)) {
                $wildcardScores = $this->searchWildcards($parsed->wildcards, $siteId, $totalDocs, $avgDocLength);
                $docScores = $this->mergeScores($docScores, $wildcardScores, $parsed->operator);
                $allDocIds = array_unique(array_merge($allDocIds, array_keys($wildcardScores)));
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

            // Convert to element IDs
            $finalResults = $this->convertDocIdsToElementIds($docScores);

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

            // Detect operator (OR/AND)
            $operator = 'AND'; // Default to AND
            if (stripos($query, ' OR ') !== false) {
                $operator = 'OR';
                $query = preg_replace('/\s+OR\s+/i', ' ', $query); // Remove OR operator for tokenization
            } elseif (stripos($query, ' AND ') !== false) {
                $query = preg_replace('/\s+AND\s+/i', ' ', $query); // Remove AND operator for tokenization
            }

            // Tokenize and filter query
            $tokens = $this->tokenizer->tokenize($query);
            $tokens = $this->filterTokens($tokens);

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

            // Track which documents match each term and actual terms used for scoring
            $termMatches = [];
            $docScores = [];
            $allDocIds = [];
            $termsForScoring = []; // Maps original term to actual terms used (exact or fuzzy)
            $termDocsCache = []; // actualTerm => [docId => freq], reused during scoring to avoid re-querying

            // For each query token, find matching documents
            foreach ($tokens as $termIndex => $term) {
                $termMatches[$termIndex] = [];
                $termsForScoring[$term] = [];

                // Try exact match first
                $termDocs = $this->storage->getTermDocuments($term, $siteId);

                if (empty($termDocs)) {
                    // Fuzzy fallback
                    $fuzzyTerms = $this->fuzzyMatcher->findMatches($term, $this->storage, $siteId);

                    $this->logInfo('Fuzzy fallback activated', [
                        'query_term' => $term,
                        'fuzzy_matches_found' => count($fuzzyTerms),
                        'fuzzy_terms' => $fuzzyTerms,
                    ]);

                    // Batch-fetch docs for all fuzzy candidates in one query
                    // instead of one query per candidate (was an N+1).
                    $fuzzyDocsByTerm = !empty($fuzzyTerms)
                        ? $this->storage->getTermDocumentsBatch($fuzzyTerms, $siteId)
                        : [];

                    foreach ($fuzzyTerms as $fuzzyTerm) {
                        $fuzzyDocs = $fuzzyDocsByTerm[$fuzzyTerm] ?? [];
                        $termsForScoring[$term][] = $fuzzyTerm; // Track fuzzy term for scoring
                        $termDocsCache[$fuzzyTerm] = $fuzzyDocs;

                        $this->logDebug('Documents for fuzzy term', [
                            'fuzzy_term' => $fuzzyTerm,
                            'doc_count' => count($fuzzyDocs),
                        ]);

                        foreach ($fuzzyDocs as $docId => $freq) {
                            $termMatches[$termIndex][$docId] = true;
                            $allDocIds[$docId] = true;
                        }
                    }
                } else {
                    $termsForScoring[$term][] = $term; // Track exact term for scoring
                    $termDocsCache[$term] = $termDocs;
                    foreach (array_keys($termDocs) as $docId) {
                        $termMatches[$termIndex][$docId] = true;
                        $allDocIds[$docId] = true;
                    }
                }
            }

            // Early exit if no matches
            if (empty($allDocIds)) {
                $this->logDebug('No matching documents', ['query' => $query]);
                return [];
            }

            // Batch fetch document lengths
            $docLengths = $this->storage->getDocumentLengthsBatch(
                $this->groupDocIdsBySite(array_keys($allDocIds))
            );

            // Batch fetch title terms once for the whole matched set, so the
            // title boost below is an in-memory lookup rather than one storage
            // query per matched document (the getTitleTerms() N+1).
            $titleTermsByDocId = $this->preloadTitleTerms(array_keys($allDocIds), $siteId);

            // Calculate BM25 scores using actual matched terms (exact or fuzzy)
            foreach ($tokens as $originalTerm) {
                $actualTerms = $termsForScoring[$originalTerm] ?? [];

                foreach ($actualTerms as $actualTerm) {
                    // Reuse the docs fetched during matching; fall back to a
                    // direct lookup only if a term somehow wasn't cached.
                    $termDocs = $termDocsCache[$actualTerm]
                        ?? $this->storage->getTermDocuments($actualTerm, $siteId);

                    if (!empty($termDocs)) {
                        $docFreq = count($termDocs);

                        foreach ($termDocs as $docId => $freq) {
                            $docLen = $docLengths[$docId] ?? 1;

                            $score = $this->scorer->score(
                                $freq,
                                $docFreq,
                                $docLen,
                                $avgDocLength,
                                $totalDocs
                            );

                            // Apply title boost if term in title
                            if (in_array($actualTerm, $titleTermsByDocId[$docId] ?? [], true)) {
                                $score = $this->scorer->applyTitleBoost($score);
                            }

                            $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                        }
                    }
                }
            }

            // Apply operator logic
            if ($operator === 'OR') {
                // OR: Return all documents that match ANY term (no filtering needed)
                $results = $docScores;
            } else {
                // AND: Filter to documents matching ALL terms
                $validDocs = $this->findDocumentsMatchingAllTerms($termMatches);
                $results = array_intersect_key($docScores, array_flip($validDocs));
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

            // Convert docId format from "siteId:elementId" to just elementId
            $finalResults = [];
            foreach ($results as $docId => $score) {
                $parts = explode(':', $docId);
                $elementId = (int)($parts[1] ?? $parts[0]);
                $finalResults[$elementId] = $score;
            }

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

        // Extract element IDs from docId format (siteId:elementId)
        $elementIds = [];
        foreach (array_keys($candidateScores) as $docId) {
            $parts = explode(':', (string) $docId);
            $elementId = (int) ($parts[1] ?? $parts[0]);
            $elementIds[$elementId] = (string) $docId;
        }

        // Batch-fetch documentData for all candidates
        $elements = $this->storage->getElementsByIds($siteId, array_keys($elementIds));

        $verified = [];
        foreach ($elementIds as $elementId => $docId) {
            $elementData = $elements[$elementId] ?? null;
            if ($elementData === null) {
                continue;
            }

            $docData = $elementData['documentData'] ?? [];
            $title = $elementData['title'] ?? '';

            // Build searchable text from title + content
            $searchableText = $title;
            if (!empty($docData['content'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($docData['content']);
            }
            if (!empty($docData['body'])) {
                $searchableText .= ' ' . $this->stripHtmlForPhrase($docData['body']);
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
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Strip HTML tags for phrase verification
     */
    private function stripHtmlForPhrase(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
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

        $elementIdsByDocId = [];
        foreach (array_keys($docScores) as $docId) {
            $parts = explode(':', (string) $docId);
            $elementId = (int) ($parts[1] ?? $parts[0]);
            $elementIdsByDocId[(string) $docId] = $elementId;
        }

        $elements = $this->storage->getElementsByIds($siteId, array_values(array_unique($elementIdsByDocId)));

        foreach ($elementIdsByDocId as $docId => $elementId) {
            $elementData = $elements[$elementId] ?? null;
            if ($elementData === null) {
                continue;
            }

            $searchableText = $this->buildPhraseMatchText($elementData);
            if ($searchableText === '') {
                continue;
            }

            $contentTokens = $this->filterTokens($this->tokenizer->tokenize($searchableText));
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
            foreach (['content', 'body', 'excerpt'] as $field) {
                if (isset($docData[$field]) && is_scalar($docData[$field])) {
                    $parts[] = $this->stripHtmlForPhrase((string) $docData[$field]);
                }
            }
        }

        return trim(implode(' ', $parts));
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
     * @return array Document scores [docId => score]
     */
    private function searchTerms(
        array $terms,
        string $operator,
        int $siteId,
        int $totalDocs,
        float $avgDocLength,
        ?array &$matchedDocIdsByTerm = null,
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
        $termsForScoring = [];
        $termDocsCache = []; // actualTerm => [docId => freq], reused during scoring

        // For each term, find matching documents
        foreach ($processedTerms as $termIndex => $term) {
            $termMatches[$termIndex] = [];
            $termsForScoring[$term] = [];

            // Try exact match first
            $termDocs = $this->storage->getTermDocuments($term, $siteId);

            if (empty($termDocs)) {
                // Fuzzy fallback
                $fuzzyTerms = $this->fuzzyMatcher->findMatches($term, $this->storage, $siteId);

                // Batch-fetch docs for all fuzzy candidates in one query
                // instead of one query per candidate (was an N+1).
                $fuzzyDocsByTerm = !empty($fuzzyTerms)
                    ? $this->storage->getTermDocumentsBatch($fuzzyTerms, $siteId)
                    : [];

                foreach ($fuzzyTerms as $fuzzyTerm) {
                    $fuzzyDocs = $fuzzyDocsByTerm[$fuzzyTerm] ?? [];
                    $termsForScoring[$term][] = $fuzzyTerm;
                    $termDocsCache[$fuzzyTerm] = $fuzzyDocs;

                    foreach ($fuzzyDocs as $docId => $freq) {
                        $termMatches[$termIndex][$docId] = true;
                        $matchedDocIdsByTerm[$term][$docId] = true;
                        $allDocIds[$docId] = true;
                    }
                }
            } else {
                $termsForScoring[$term][] = $term;
                $termDocsCache[$term] = $termDocs;
                foreach (array_keys($termDocs) as $docId) {
                    $termMatches[$termIndex][$docId] = true;
                    $matchedDocIdsByTerm[$term][$docId] = true;
                    $allDocIds[$docId] = true;
                }
            }
        }

        if (empty($allDocIds)) {
            return [];
        }

        // Batch fetch document lengths
        $docLengths = $this->storage->getDocumentLengthsBatch(
            $this->groupDocIdsBySite(array_keys($allDocIds))
        );

        // Batch fetch title terms once for the whole matched set (see searchSimple).
        $titleTermsByDocId = $this->preloadTitleTerms(array_keys($allDocIds), $siteId);

        // Calculate BM25 scores
        foreach ($processedTerms as $originalTerm) {
            $actualTerms = $termsForScoring[$originalTerm] ?? [];

            foreach ($actualTerms as $actualTerm) {
                // Reuse the docs fetched during matching; fall back to a direct
                // lookup only if a term somehow wasn't cached.
                $termDocs = $termDocsCache[$actualTerm]
                    ?? $this->storage->getTermDocuments($actualTerm, $siteId);

                if (!empty($termDocs)) {
                    $docFreq = count($termDocs);

                    foreach ($termDocs as $docId => $freq) {
                        $docLen = $docLengths[$docId] ?? 1;

                        $score = $this->scorer->score(
                            $freq,
                            $docFreq,
                            $docLen,
                            $avgDocLength,
                            $totalDocs
                        );

                        // Apply title boost if term in title
                        if (in_array($actualTerm, $titleTermsByDocId[$docId] ?? [], true)) {
                            $score = $this->scorer->applyTitleBoost($score);
                        }

                        $docScores[$docId] = ($docScores[$docId] ?? 0) + $score;
                    }
                }
            }
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

        // Search with expanded terms
        return $this->searchTerms($expandedTerms, 'OR', $siteId, $totalDocs, $avgDocLength);
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
        // For now, only support 'title' and 'content' fields
        // This is a simplified implementation - full field support requires indexing changes

        $titleTermsByElement = [];
        if (isset($fieldFilters['title'])) {
            $titleElementIds = [];
            foreach (array_keys($docScores) as $docId) {
                $parts = explode(':', (string)$docId);
                $titleElementIds[] = (int)($parts[1] ?? $parts[0]);
            }

            $titleTermsByElement = $this->storage->getTitleTermsBatch($siteId, array_values(array_unique($titleElementIds)));
        }

        $filteredScores = [];

        foreach ($docScores as $docId => $score) {
            $matchesAllFilters = true;

            foreach ($fieldFilters as $field => $terms) {
                if ($field === 'title') {
                    // Check if any of the terms are in the title
                    $parts = explode(':', $docId);
                    $elementId = (int)($parts[1] ?? $parts[0]);
                    $titleTerms = $titleTermsByElement[$elementId] ?? [];

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
                }
                // Content field is default, so we assume documents already match
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
        if (empty($scores1)) {
            return $scores2;
        }

        if (empty($scores2)) {
            return $scores1;
        }

        if ($operator === 'AND') {
            // Only keep documents in both sets
            $merged = [];
            foreach ($scores1 as $docId => $score1) {
                if (isset($scores2[$docId])) {
                    $merged[$docId] = $score1 + $scores2[$docId];
                }
            }
            return $merged;
        } else {
            // OR: Combine all documents
            $merged = $scores1;
            foreach ($scores2 as $docId => $score2) {
                $merged[$docId] = ($merged[$docId] ?? 0) + $score2;
            }
            return $merged;
        }
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
        $elementIdsBySite = [];

        foreach (array_keys($docScores) as $docId) {
            $parts = explode(':', $docId);
            $elemSiteId = (int)$parts[0];
            $elementId = (int)($parts[1] ?? $parts[0]);
            $elementIdsBySite[$elemSiteId][] = $elementId;
        }

        $languagesByDocId = [];
        foreach ($elementIdsBySite as $elemSiteId => $elementIds) {
            $languagesByElement = $this->storage->getDocumentLanguagesBatch((int)$elemSiteId, array_values(array_unique($elementIds)));
            foreach ($languagesByElement as $elementId => $docLanguage) {
                $languagesByDocId[$elemSiteId . ':' . $elementId] = $docLanguage;
            }
        }

        foreach ($docScores as $docId => $score) {
            $parts = explode(':', $docId);
            $elemSiteId = (int)$parts[0];
            $elementId = (int)($parts[1] ?? $parts[0]);
            $docKey = $elemSiteId . ':' . $elementId;

            $docLanguage = $languagesByDocId[$docKey] ?? 'en';

            // Check if language matches (exact or generic match)
            if ($this->languageMatches($docLanguage, $language)) {
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
     * Check if document language matches filter language
     *
     * @param string $docLanguage Document language (e.g., 'ar', 'en')
     * @param string $filterLanguage Filter language (e.g., 'ar', 'ar-sa')
     * @return bool True if matches
     */
    private function languageMatches(string $docLanguage, string $filterLanguage): bool
    {
        // Exact match
        if ($docLanguage === $filterLanguage) {
            return true;
        }

        // Generic match (filter: 'ar' matches doc: 'ar-sa')
        if (str_contains($docLanguage, '-')) {
            $docGeneric = substr($docLanguage, 0, 2);
            if ($docGeneric === $filterLanguage) {
                return true;
            }
        }

        // Reverse: filter: 'ar-sa' matches doc: 'ar'
        if (str_contains($filterLanguage, '-')) {
            $filterGeneric = substr($filterLanguage, 0, 2);
            if ($docLanguage === $filterGeneric) {
                return true;
            }
        }

        return false;
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
    public function deleteDocument(int $siteId, int $elementId): bool
    {
        try {
            // Get document info before deletion
            $docLength = $this->storage->getDocumentLength($siteId, $elementId);
            $terms = $this->storage->getDocumentTerms($siteId, $elementId);

            // Remove from inverted index
            foreach (array_keys($terms) as $term) {
                $this->storage->removeTermDocument($term, $siteId, $elementId);
            }

            // Delete document and title data
            $this->storage->deleteDocument($siteId, $elementId);
            $this->storage->deleteTitleTerms($siteId, $elementId);

            // Missing-document deletes are valid no-ops from the pending-sync
            // path. Only subtract metadata when the document actually existed.
            if ($docLength > 0 || !empty($terms)) {
                $this->storage->updateMetadata($siteId, $docLength, false);
            }

            $this->logInfo('Document deleted', [
                'site_id' => $siteId,
                'element_id' => $elementId,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete document', [
                'site_id' => $siteId,
                'element_id' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
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

        $elementIds = [];
        $docIdsByElement = [];
        foreach ($docIds as $docId) {
            $parts = explode(':', (string)$docId);
            $elementId = (int)($parts[1] ?? $parts[0]);
            $elementIds[$elementId] = true;
            $docIdsByElement[$elementId][] = (string)$docId;
        }

        $byElement = $this->storage->getTitleTermsBatch($siteId, array_keys($elementIds));

        $byDocId = [];
        foreach ($docIdsByElement as $elementId => $ids) {
            $terms = $byElement[$elementId] ?? [];
            foreach ($ids as $docId) {
                $byDocId[$docId] = $terms;
            }
        }

        return $byDocId;
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
     * @return array [siteId => [elementIds]]
     */
    private function groupDocIdsBySite(array $docIds): array
    {
        $grouped = [];

        foreach ($docIds as $docId) {
            $parts = explode(':', $docId);
            $siteId = (int)$parts[0];
            $elementId = (int)($parts[1] ?? $parts[0]);

            if (!isset($grouped[$siteId])) {
                $grouped[$siteId] = [];
            }

            $grouped[$siteId][] = $elementId;
        }

        return $grouped;
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
