<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * FuzzyMatcher
 *
 * Finds similar terms using n-gram based fuzzy matching for typo-tolerance.
 * Uses Jaccard similarity on n-grams to find terms similar to the search query.
 *
 * This enables searches like "phd" to match "php" or "javascirpt" to match "javascript".
 *
 * @since 5.0.0
 */
class FuzzyMatcher
{
    use LoggingTrait;

    /**
     * @var NgramGenerator
     */
    private NgramGenerator $ngramGenerator;

    /**
     * @var float Minimum similarity threshold
     */
    private float $similarityThreshold;

    /**
     * @var int Maximum number of fuzzy candidates to process
     */
    private int $maxCandidates;

    /**
     * Constructor
     *
     * @param NgramGenerator $ngramGenerator N-gram generator instance
     * @param float $similarityThreshold Minimum similarity threshold (default: 0.25)
     * @param int $maxCandidates Maximum fuzzy candidates to process (default: 100)
     */
    public function __construct(
        NgramGenerator $ngramGenerator,
        float $similarityThreshold = 0.25,
        int $maxCandidates = 100,
    ) {
        $this->setLoggingHandle('search-manager');
        $this->ngramGenerator = $ngramGenerator;
        $this->similarityThreshold = $similarityThreshold;
        $this->maxCandidates = $maxCandidates;
    }

    /**
     * Find fuzzy matches for a search term using the storage layer
     *
     * @param string $searchTerm The term to find matches for
     * @param StorageInterface $storage Storage layer to query
     * @param int $siteId Site ID to search within
     * @return array Array of matching terms sorted by similarity (highest first)
     */
    public function findMatches(string $searchTerm, StorageInterface $storage, int $siteId): array
    {
        // Generate n-grams for the search term
        $searchNgrams = $this->ngramGenerator->generate($searchTerm);

        if (empty($searchNgrams)) {
            $this->logDebug('No n-grams generated for search term', [
                'term' => $searchTerm,
            ]);
            return [];
        }

        // Get adaptive threshold based on term length
        $adaptiveThreshold = $this->ngramGenerator->getAdaptiveThreshold(
            $searchTerm,
            $this->similarityThreshold
        );

        $this->logDebug('Finding fuzzy matches', [
            'term' => $searchTerm,
            'ngram_count' => count($searchNgrams),
            'threshold' => $adaptiveThreshold,
        ]);

        // Query storage for similar terms based on n-gram overlap
        $candidates = $storage->getTermsByNgramSimilarity(
            $searchNgrams,
            $siteId,
            $adaptiveThreshold
        );

        // Limit candidates for performance
        if (count($candidates) > $this->maxCandidates) {
            $candidates = array_slice($candidates, 0, $this->maxCandidates, true);
            $this->logDebug('Limited fuzzy candidates', [
                'max_candidates' => $this->maxCandidates,
            ]);
        }

        $matchedTerms = array_keys($candidates);

        $this->logDebug('Found fuzzy match candidates', [
            'term' => $searchTerm,
            'candidate_count' => count($candidates),
            'matched_terms' => $matchedTerms,
        ]);

        // Return terms sorted by similarity (already sorted by storage layer)
        return $matchedTerms;
    }

    /**
     * Calculate similarity between two terms
     *
     * @param string $term1 First term
     * @param string $term2 Second term
     * @return float Similarity score between 0.0 and 1.0
     */
    public function calculateSimilarity(string $term1, string $term2): float
    {
        return $this->ngramGenerator->calculateTermSimilarity($term1, $term2);
    }

    /**
     * Check if two terms are similar enough based on threshold
     *
     * @param string $term1 First term
     * @param string $term2 Second term
     * @return bool True if similarity meets threshold
     */
    public function areSimilar(string $term1, string $term2): bool
    {
        $similarity = $this->calculateSimilarity($term1, $term2);
        $threshold = $this->ngramGenerator->getAdaptiveThreshold($term1, $this->similarityThreshold);

        return $similarity >= $threshold;
    }

    /**
     * Get the similarity threshold
     *
     * @return float
     */
    public function getSimilarityThreshold(): float
    {
        return $this->similarityThreshold;
    }

    /**
     * Get the maximum candidates limit
     *
     * @return int
     */
    public function getMaxCandidates(): int
    {
        return $this->maxCandidates;
    }
}
