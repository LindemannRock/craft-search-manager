<?php

namespace lindemannrock\searchmanager\search\storage;

/**
 * StorageInterface
 *
 * Defines the contract for search engine storage implementations.
 * Implementations handle persistence of inverted index data to various backends
 * (MySQL, Files, Redis, etc.)
 *
 * @since 5.0.0
 */
interface StorageInterface
{
    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * Store a document in the index
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param array $termFreqs Term frequencies [term => frequency]
     * @param int $docLength Document length in tokens
     * @param string $language Language code (e.g., 'en', 'ar', 'de')
     * @return void
     */
    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void;

    /**
     * Get all terms for a document with their frequencies
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return array Term frequencies [term => frequency]
     */
    public function getDocumentTerms(int $siteId, int $elementId): array;

    /**
     * Delete a document from the index
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return void
     */
    public function deleteDocument(int $siteId, int $elementId): void;

    /**
     * Get document length
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return int Document length
     */
    public function getDocumentLength(int $siteId, int $elementId): int;

    /**
     * Get document lengths for multiple documents in batch
     *
     * @since 5.0.0
     * @param array $docIds Array of [siteId => [...elementIds]]
     * @return array Lengths indexed by "siteId:elementId"
     */
    public function getDocumentLengthsBatch(array $docIds): array;

    /**
     * Get document language
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string Language code (default: 'en')
     */
    public function getDocumentLanguage(int $siteId, int $elementId): string;

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * Store a term-document association
     *
     * @since 5.0.0
     * @param string $term The term
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param int $frequency Term frequency in document
     * @param string $language Language code (e.g., 'en', 'ar')
     * @return void
     */
    public function storeTermDocument(string $term, int $siteId, int $elementId, int $frequency, string $language = 'en'): void;

    /**
     * Get all documents for a term
     *
     * @since 5.0.0
     * @param string $term The term
     * @param int $siteId Site ID
     * @return array Documents with frequencies ["siteId:elementId" => frequency]
     */
    public function getTermDocuments(string $term, int $siteId): array;

    /**
     * Remove a term-document association
     *
     * @since 5.0.0
     * @param string $term The term
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return void
     */
    public function removeTermDocument(string $term, int $siteId, int $elementId): void;

    /**
     * Get terms for autocomplete suggestions
     *
     * Returns terms with their frequencies, sorted by frequency (most common first).
     * Used for autocomplete/type-ahead functionality.
     *
     * @since 5.0.0
     * @param int|null $siteId Site ID (null for all sites)
     * @param string|null $language Language filter (e.g., 'en', 'ar')
     * @param int $limit Maximum terms to return
     * @return array Terms with frequencies [term => frequency]
     */
    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000): array;

    // =========================================================================
    // ELEMENT OPERATIONS (for autocomplete suggestions)
    // =========================================================================

    /**
     * Store element metadata for autocomplete suggestions
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param string $title Full title for display
     * @param string $elementType Element type (product, category, etc.)
     * @return void
     */
    public function storeElement(int $siteId, int $elementId, string $title, string $elementType): void;

    /**
     * Get element info for a list of element IDs
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param array $elementIds Array of element IDs
     * @return array Map of elementId => ['title' => ..., 'elementType' => ...]
     */
    public function getElementsByIds(int $siteId, array $elementIds): array;

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * Store title terms for a document
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param array $titleTerms Array of terms
     * @return void
     */
    public function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void;

    /**
     * Get title terms for a document
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return array Array of terms
     */
    public function getTitleTerms(int $siteId, int $elementId): array;

    /**
     * Delete title terms for a document
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return void
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void;

    // =========================================================================
    // N-GRAM OPERATIONS
    // =========================================================================

    /**
     * Store n-grams for a term
     *
     * @since 5.0.0
     * @param string $term The term
     * @param array $ngrams Array of n-grams
     * @param int $siteId Site ID
     * @return void
     */
    public function storeTermNgrams(string $term, array $ngrams, int $siteId): void;

    /**
     * Check if a term already has n-grams stored
     *
     * @since 5.0.0
     * @param string $term The term
     * @param int $siteId Site ID
     * @return bool
     */
    public function termHasNgrams(string $term, int $siteId): bool;

    /**
     * Get terms by n-gram similarity
     *
     * Returns terms that have similar n-grams to the provided set,
     * sorted by similarity score (highest first).
     *
     * @since 5.0.0
     * @param array $ngrams N-grams to match
     * @param int $siteId Site ID
     * @param float $threshold Minimum similarity threshold
     * @param int $limit Maximum candidates to return (default: 100)
     * @return array [term => similarity_score]
     */
    public function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold, int $limit = 100): array;

    /**
     * Get terms by prefix (for wildcard search)
     *
     * Returns all terms that start with the given prefix.
     * Used for wildcard searches like "test*" to match "test", "testing", "tested", etc.
     *
     * @since 5.0.0
     * @param string $prefix Prefix to match
     * @param int $siteId Site ID
     * @return array Array of matching terms
     */
    public function getTermsByPrefix(string $prefix, int $siteId): array;

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * Get total document count
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @return int Total documents
     */
    public function getTotalDocCount(int $siteId): int;

    /**
     * Get total length (sum of all document lengths)
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @return int Total length
     */
    public function getTotalLength(int $siteId): int;

    /**
     * Get average document length
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @return float Average length
     */
    public function getAverageDocLength(int $siteId): float;

    /**
     * Update metadata after document operations
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @param int $docLength Document length (can be negative for deletion)
     * @param bool $isAddition True if adding, false if removing
     * @return void
     */
    public function updateMetadata(int $siteId, int $docLength, bool $isAddition): void;

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * Clear all index data for a site
     *
     * @since 5.0.0
     * @param int $siteId Site ID
     * @return void
     */
    public function clearSite(int $siteId): void;

    /**
     * Clear all index data
     *
     * @since 5.0.0
     * @return void
     */
    public function clearAll(): void;
}
