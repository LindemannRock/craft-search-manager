<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * BM25Scorer
 *
 * Implements the BM25 (Okapi BM25) ranking algorithm for information retrieval.
 * BM25 is a probabilistic ranking function that scores documents based on
 * term frequency, document frequency, and document length normalization.
 *
 * Formula: BM25(D, Q) = IDF(q) * (f(q,D) * (k1 + 1)) / (f(q,D) + k1 * (1 - b + b * |D| / avgdl))
 *
 * Where:
 * - f(q,D) = term frequency in document D
 * - |D| = document length
 * - avgdl = average document length in collection
 * - k1 = term frequency saturation parameter (default: 1.5)
 * - b = length normalization parameter (default: 0.75)
 * - IDF(q) = inverse document frequency
 *
 * @since 5.0.0
 */
class BM25Scorer
{
    use LoggingTrait;

    /**
     * @var float Term frequency saturation parameter
     * Controls how quickly term frequency saturates.
     * Higher values = less saturation (term frequency matters more)
     * Typical range: 1.2 to 2.0
     */
    private float $k1;

    /**
     * @var float Document length normalization parameter
     * Controls how much document length affects the score.
     * 0 = no normalization, 1 = full normalization
     * Typical range: 0.5 to 0.8
     */
    private float $b;

    /**
     * @var float Boost factor for terms in title
     * Multiplier applied to BM25 score when term appears in title
     */
    private float $titleBoost;

    /**
     * @var float Boost factor for exact phrase matches
     * Multiplier applied to total score for exact phrase matches
     */
    private float $exactMatchBoost;

    /**
     * Constructor
     *
     * @param float $k1 Term frequency saturation (default: 1.5)
     * @param float $b Document length normalization (default: 0.75)
     * @param float $titleBoost Title boost factor (default: 5.0)
     * @param float $exactMatchBoost Exact match boost factor (default: 3.0)
     */
    public function __construct(
        float $k1 = 1.5,
        float $b = 0.75,
        float $titleBoost = 5.0,
        float $exactMatchBoost = 3.0,
    ) {
        $this->setLoggingHandle('search-manager');
        $this->k1 = $k1;
        $this->b = $b;
        $this->titleBoost = $titleBoost;
        $this->exactMatchBoost = $exactMatchBoost;

        $this->logDebug('Initialized BM25Scorer', [
            'k1' => $this->k1,
            'b' => $this->b,
            'titleBoost' => $this->titleBoost,
            'exactMatchBoost' => $this->exactMatchBoost,
        ]);
    }

    /**
     * Calculate BM25 relevance score for a term in a document
     *
     * @param int $termFreq Term frequency in the document
     * @param int $docFreq Number of documents containing the term
     * @param int $docLength Document length in tokens
     * @param float $avgDocLength Average document length across the index
     * @param int $totalDocs Total number of documents in the index
     * @return float BM25 score
     */
    public function score(
        int $termFreq,
        int $docFreq,
        int $docLength,
        float $avgDocLength,
        int $totalDocs,
    ): float {
        // Prevent division by zero
        if ($totalDocs === 0 || $docFreq === 0 || $avgDocLength <= 0) {
            return 0.0;
        }

        // Calculate IDF (Inverse Document Frequency)
        // Uses BM25's IDF formula with smoothing
        $idf = log(1 + (($totalDocs - $docFreq + 0.5) / ($docFreq + 0.5)));

        // Calculate normalized term frequency
        // This is where k1 and b parameters come into play
        $normalizedTF = ($termFreq * ($this->k1 + 1)) /
                        ($termFreq + $this->k1 * (1 - $this->b + $this->b * ($docLength / $avgDocLength)));

        $score = $idf * $normalizedTF;

        return $score;
    }

    /**
     * Apply title boost to a score
     *
     * @param float $score Base BM25 score
     * @return float Boosted score
     */
    public function applyTitleBoost(float $score): float
    {
        return $score * $this->titleBoost;
    }

    /**
     * Apply exact match boost to a score
     *
     * @param float $score Base score
     * @return float Boosted score
     */
    public function applyExactMatchBoost(float $score): float
    {
        return $score * $this->exactMatchBoost;
    }

    /**
     * Get the k1 parameter
     *
     * @return float
     */
    public function getK1(): float
    {
        return $this->k1;
    }

    /**
     * Get the b parameter
     *
     * @return float
     */
    public function getB(): float
    {
        return $this->b;
    }

    /**
     * Get the title boost factor
     *
     * @return float
     */
    public function getTitleBoost(): float
    {
        return $this->titleBoost;
    }

    /**
     * Get the exact match boost factor
     *
     * @return float
     */
    public function getExactMatchBoost(): float
    {
        return $this->exactMatchBoost;
    }
}
