<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * NgramGenerator
 *
 * Generates n-grams from terms for fuzzy matching and typo-tolerance.
 * N-grams are contiguous sequences of n characters from a given term.
 *
 * Example: "php" with sizes [2, 3] produces:
 * - Bigrams (2): [" p", "ph", "hp", "p "]
 * - Trigrams (3): [" ph", "php", "hp "]
 *
 * @since 5.0.0
 */
class NgramGenerator
{
    use LoggingTrait;

    /**
     * @var array N-gram sizes to generate
     */
    private array $sizes;

    /**
     * Constructor
     *
     * @param array $sizes N-gram sizes to generate (e.g., [2, 3] for bigrams and trigrams)
     */
    public function __construct(array $sizes = [2, 3])
    {
        $this->setLoggingHandle('search-manager');
        $this->sizes = $sizes;
    }

    /**
     * Generate n-grams for a term
     *
     * Adds padding with spaces to capture word boundaries, which helps
     * differentiate terms like "the" vs "there" (both start with "the").
     *
     * @param string $term The term to generate n-grams for
     * @param array|null $sizes Override default n-gram sizes for this call
     * @return array Array of unique n-grams
     */
    public function generate(string $term, ?array $sizes = null): array
    {
        $sizes = $sizes ?? $this->sizes;

        if (empty($term) || empty($sizes)) {
            return [];
        }

        $ngrams = [];

        // Add padding to capture word boundaries
        $paddedTerm = ' ' . $term . ' ';
        $termLength = mb_strlen($paddedTerm);

        foreach ($sizes as $size) {
            if ($size < 1) {
                continue;
            }

            // Generate n-grams of specified size
            for ($i = 0; $i <= $termLength - $size; $i++) {
                $ngram = mb_substr($paddedTerm, $i, $size);

                // Skip n-grams that are just spaces
                if (trim($ngram) !== '') {
                    $ngrams[] = $ngram;
                }
            }
        }

        // Remove duplicates
        $ngrams = array_unique($ngrams);

        $this->logDebug('Generated n-grams', [
            'term' => $term,
            'sizes' => $sizes,
            'ngram_count' => count($ngrams),
        ]);

        return array_values($ngrams);
    }

    /**
     * Calculate Jaccard similarity between two sets of n-grams
     *
     * Jaccard similarity = |intersection| / |union|
     * Returns a value between 0.0 (no similarity) and 1.0 (identical)
     *
     * @param array $ngrams1 First set of n-grams
     * @param array $ngrams2 Second set of n-grams
     * @return float Similarity score between 0.0 and 1.0
     */
    public function calculateSimilarity(array $ngrams1, array $ngrams2): float
    {
        if (empty($ngrams1) || empty($ngrams2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($ngrams1, $ngrams2));
        $union = count(array_unique(array_merge($ngrams1, $ngrams2)));

        return $intersection / $union;
    }

    /**
     * Calculate similarity between two terms
     *
     * Convenience method that generates n-grams and calculates similarity
     *
     * @param string $term1 First term
     * @param string $term2 Second term
     * @return float Similarity score between 0.0 and 1.0
     */
    public function calculateTermSimilarity(string $term1, string $term2): float
    {
        $ngrams1 = $this->generate($term1);
        $ngrams2 = $this->generate($term2);

        return $this->calculateSimilarity($ngrams1, $ngrams2);
    }

    /**
     * Get adaptive similarity threshold based on term length
     *
     * Shorter terms need lower thresholds because they have fewer n-grams,
     * making it harder to achieve high similarity scores.
     *
     * @param string $term The search term
     * @param float $baseThreshold Base similarity threshold (default: 0.50)
     * @return float Adjusted threshold
     */
    public function getAdaptiveThreshold(string $term, float $baseThreshold = 0.50): float
    {
        $termLength = mb_strlen($term);

        // Apply scaling factor based on term length
        if ($termLength <= 2) {
            // Very short terms: use much lower threshold
            return max(0.1, $baseThreshold * 0.4);
        } elseif ($termLength === 3) {
            // 3-character terms: use lower threshold
            return max(0.15, $baseThreshold * 0.6);
        } elseif ($termLength === 4) {
            // 4-character terms: slightly lower threshold
            return max(0.2, $baseThreshold * 0.8);
        }

        // 5+ character terms: use full threshold
        return $baseThreshold;
    }

    /**
     * Get the configured n-gram sizes
     *
     * @return array Array of n-gram sizes
     */
    public function getSizes(): array
    {
        return $this->sizes;
    }
}
