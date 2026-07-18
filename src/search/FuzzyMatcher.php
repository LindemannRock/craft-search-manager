<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

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
     * Minimum length for a term to qualify as a fuzzy candidate.
     *
     * Exact and prefix matching use separate paths and are not subject to
     * this floor.
     *
     * @since 5.54.0
     */
    public const MIN_CANDIDATE_LENGTH = 3;

    /**
     * Maximum query length for the zero-typo tier.
     *
     * @since 5.54.0
     */
    public const SHORT_TERM_MAX_LENGTH = 3;

    /**
     * Maximum query length for the one-typo tier.
     *
     * @since 5.54.0
     */
    public const MEDIUM_TERM_MAX_LENGTH = 7;

    /**
     * Typo budget for query terms up to {@see self::SHORT_TERM_MAX_LENGTH}.
     *
     * @since 5.54.0
     */
    public const SHORT_TERM_TYPO_BUDGET = 0;

    /**
     * Typo budget for query terms up to {@see self::MEDIUM_TERM_MAX_LENGTH}.
     *
     * @since 5.54.0
     */
    public const MEDIUM_TERM_TYPO_BUDGET = 1;

    /**
     * Typo budget for longer query terms.
     *
     * @since 5.54.0
     */
    public const LONG_TERM_TYPO_BUDGET = 2;

    /**
     * Total typo cost assigned to a difference in the first character.
     *
     * @since 5.54.0
     */
    public const FIRST_CHARACTER_TYPO_COST = 2;

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

        $this->logInfo('Finding fuzzy matches', [
            'term' => $searchTerm,
            'ngram_count' => count($searchNgrams),
            'ngrams' => $searchNgrams,
            'threshold' => $adaptiveThreshold,
        ]);

        // Query storage for similar terms (storage layer applies maxCandidates limit)
        $candidates = $storage->getTermsByNgramSimilarity(
            $searchNgrams,
            $siteId,
            $adaptiveThreshold,
            $this->maxCandidates // Pass configurable limit to storage
        );

        $matchedTerms = [];
        foreach (array_keys($candidates) as $candidate) {
            $candidate = (string)$candidate;

            if (
                mb_strlen($candidate) < self::MIN_CANDIDATE_LENGTH
                || !self::isCandidateWithinTypoBudget($searchTerm, $candidate)
            ) {
                continue;
            }

            $matchedTerms[] = $candidate;
        }

        $this->logInfo('Fuzzy match candidates found', [
            'term' => $searchTerm,
            'candidate_count' => count($candidates),
            'max_limit' => $this->maxCandidates,
            'matched_terms' => array_slice($matchedTerms, 0, 10), // First 10
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
     * Check whether a fetched fuzzy candidate satisfies the precision policy.
     *
     * Prefix extensions are completion matches and bypass typo budgeting.
     * Other candidates must fit the query-length tier after an additional
     * first-character penalty is applied to their Damerau-Levenshtein distance.
     *
     * @param string $queryTerm Normalized query term
     * @param string $candidate Normalized candidate term
     * @return bool Whether the candidate may participate in fuzzy matching
     * @since 5.54.0
     */
    public static function isCandidateWithinTypoBudget(string $queryTerm, string $candidate): bool
    {
        if ($queryTerm === '' || $candidate === '') {
            return false;
        }

        if (str_starts_with($candidate, $queryTerm)) {
            return true;
        }

        $queryLength = mb_strlen($queryTerm);
        $typoBudget = match (true) {
            $queryLength <= self::SHORT_TERM_MAX_LENGTH => self::SHORT_TERM_TYPO_BUDGET,
            $queryLength <= self::MEDIUM_TERM_MAX_LENGTH => self::MEDIUM_TERM_TYPO_BUDGET,
            default => self::LONG_TERM_TYPO_BUDGET,
        };

        $distance = self::damerauLevenshteinDistance($queryTerm, $candidate);
        if (mb_substr($queryTerm, 0, 1) !== mb_substr($candidate, 0, 1)) {
            $distance += self::FIRST_CHARACTER_TYPO_COST - 1;
        }

        return $distance <= $typoBudget;
    }

    /**
     * Calculate the mb-safe Damerau-Levenshtein distance between two terms.
     *
     * @param string $source Source term
     * @param string $target Target term
     * @return int Number of insertions, deletions, substitutions, and adjacent transpositions
     */
    private static function damerauLevenshteinDistance(string $source, string $target): int
    {
        $sourceCharacters = mb_str_split($source);
        $targetCharacters = mb_str_split($target);
        $sourceLength = count($sourceCharacters);
        $targetLength = count($targetCharacters);
        $maximumDistance = $sourceLength + $targetLength;

        $distance = array_fill(0, $sourceLength + 2, array_fill(0, $targetLength + 2, 0));
        $distance[0][0] = $maximumDistance;

        for ($sourceIndex = 0; $sourceIndex <= $sourceLength; $sourceIndex++) {
            $distance[$sourceIndex + 1][0] = $maximumDistance;
            $distance[$sourceIndex + 1][1] = $sourceIndex;
        }

        for ($targetIndex = 0; $targetIndex <= $targetLength; $targetIndex++) {
            $distance[0][$targetIndex + 1] = $maximumDistance;
            $distance[1][$targetIndex + 1] = $targetIndex;
        }

        /** @var array<string, int> $lastMatchingRow */
        $lastMatchingRow = [];

        for ($sourceIndex = 1; $sourceIndex <= $sourceLength; $sourceIndex++) {
            $lastMatchingColumn = 0;

            for ($targetIndex = 1; $targetIndex <= $targetLength; $targetIndex++) {
                $matchingSourceIndex = $lastMatchingRow[$targetCharacters[$targetIndex - 1]] ?? 0;
                $matchingTargetIndex = $lastMatchingColumn;
                $substitutionCost = 1;

                if ($sourceCharacters[$sourceIndex - 1] === $targetCharacters[$targetIndex - 1]) {
                    $substitutionCost = 0;
                    $lastMatchingColumn = $targetIndex;
                }

                $distance[$sourceIndex + 1][$targetIndex + 1] = min(
                    $distance[$sourceIndex][$targetIndex] + $substitutionCost,
                    $distance[$sourceIndex + 1][$targetIndex] + 1,
                    $distance[$sourceIndex][$targetIndex + 1] + 1,
                    $distance[$matchingSourceIndex][$matchingTargetIndex]
                        + ($sourceIndex - $matchingSourceIndex - 1)
                        + 1
                        + ($targetIndex - $matchingTargetIndex - 1),
                );
            }

            $lastMatchingRow[$sourceCharacters[$sourceIndex - 1]] = $sourceIndex;
        }

        return $distance[$sourceLength + 1][$targetLength + 1];
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
