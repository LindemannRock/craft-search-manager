<?php

namespace lindemannrock\searchmanager\search;

/**
 * ParsedQuery
 *
 * Structured representation of a parsed search query with all operators extracted.
 * This class holds the components of a complex search query after parsing.
 *
 * Examples:
 * - "test entry" → terms: ['test', 'entry'], operator: 'AND'
 * - "test OR entry" → terms: ['test', 'entry'], operator: 'OR'
 * - '"exact phrase"' → phrases: ['exact phrase']
 * - 'test NOT spam' → terms: ['test'], notTerms: ['spam']
 * - 'title:blog' → fieldFilters: ['title' => ['blog']]
 * - 'test*' → wildcards: ['test']
 * - 'test^2 entry' → terms: ['test', 'entry'], boosts: ['test' => 2.0]
 *
 * @since 5.0.0
 */
class ParsedQuery
{
    /**
     * @var array Regular search terms (after removing operators)
     * Example: ['test', 'entry']
     */
    public array $terms = [];

    /**
     * @var array Exact phrase searches (content within quotes)
     * Example: ['exact phrase', 'another phrase']
     */
    public array $phrases = [];

    /**
     * @var array Terms to exclude from results (NOT operator)
     * Example: ['spam', 'unwanted']
     */
    public array $notTerms = [];

    /**
     * @var array Field-specific search filters
     * Format: ['field' => ['term1', 'term2']]
     * Example: ['title' => ['blog'], 'content' => ['test']]
     */
    public array $fieldFilters = [];

    /**
     * @var array Wildcard terms (prefix matching)
     * Example: ['test', 'java'] for queries "test*" and "java*"
     */
    public array $wildcards = [];

    /**
     * @var array Per-term boost factors
     * Format: ['term' => boostFactor]
     * Example: ['test' => 2.0, 'entry' => 1.5]
     */
    public array $boosts = [];

    /**
     * @var string Boolean operator for combining terms
     * Values: 'AND' (default) or 'OR'
     */
    public string $operator = 'AND';

    /**
     * @var string Original raw query string (before parsing)
     */
    public string $originalQuery = '';

    /**
     * Check if query is empty (no searchable content)
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->terms) &&
               empty($this->phrases) &&
               empty($this->wildcards) &&
               empty($this->fieldFilters);
    }

    /**
     * Check if query has advanced operators
     *
     * @return bool True if query uses any advanced features
     */
    public function hasAdvancedOperators(): bool
    {
        return !empty($this->phrases) ||
               !empty($this->notTerms) ||
               !empty($this->fieldFilters) ||
               !empty($this->wildcards) ||
               !empty($this->boosts);
    }

    /**
     * Get all searchable terms (terms + phrases as terms)
     *
     * @return array Combined array of all searchable content
     */
    public function getAllSearchableTerms(): array
    {
        $allTerms = $this->terms;

        // Add phrase words as individual terms for fallback
        foreach ($this->phrases as $phrase) {
            $words = explode(' ', $phrase);
            $allTerms = array_merge($allTerms, $words);
        }

        // Add wildcard terms
        $allTerms = array_merge($allTerms, $this->wildcards);

        // Add field filter terms
        foreach ($this->fieldFilters as $field => $terms) {
            $allTerms = array_merge($allTerms, $terms);
        }

        return array_unique($allTerms);
    }

    /**
     * Get boost factor for a term
     *
     * @param string $term The term to check
     * @return float Boost factor (1.0 if no boost specified)
     */
    public function getBoostFactor(string $term): float
    {
        return $this->boosts[$term] ?? 1.0;
    }

    /**
     * Convert to array for debugging/logging
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'original' => $this->originalQuery,
            'terms' => $this->terms,
            'phrases' => $this->phrases,
            'notTerms' => $this->notTerms,
            'fieldFilters' => $this->fieldFilters,
            'wildcards' => $this->wildcards,
            'boosts' => $this->boosts,
            'operator' => $this->operator,
            'isEmpty' => $this->isEmpty(),
            'hasAdvanced' => $this->hasAdvancedOperators(),
        ];
    }
}
