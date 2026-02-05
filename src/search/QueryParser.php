<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * QueryParser
 *
 * Parses search queries and extracts advanced operators:
 * - Phrase search: "exact phrase"
 * - NOT operator: test NOT entry
 * - Field search: title:test or content:test
 * - Wildcards: test* (prefix matching)
 * - Boosting: test^2 entry
 * - Boolean: test OR entry, test AND entry
 *
 * Supports localized operators for: English, German, French, Spanish, Arabic
 *
 * Query parsing order:
 * 1. Extract quoted phrases → "exact phrase"
 * 2. Extract field filters → title:test
 * 3. Extract NOT terms → NOT spam
 * 4. Extract wildcards → test*
 * 5. Extract boosts → test^2
 * 6. Detect boolean operator → OR/AND
 * 7. Remaining tokens → regular terms
 *
 * @since 5.0.0
 */
class QueryParser
{
    use LoggingTrait;

    /**
     * Localized boolean operators by language
     * All operators are case-insensitive
     */
    private const LOCALIZED_OPERATORS = [
        'en' => ['and' => 'AND', 'or' => 'OR', 'not' => 'NOT'],
        'de' => ['and' => 'UND', 'or' => 'ODER', 'not' => 'NICHT'],
        'fr' => ['and' => 'ET', 'or' => 'OU', 'not' => 'SAUF'],
        'es' => ['and' => 'Y', 'or' => 'O', 'not' => 'NO'],
        // Arabic: support common spelling variations
        // OR: أو (with hamza) and او (without hamza)
        // NOT: ليس (formal "is not") and لا (common "no/not")
        'ar' => ['and' => ['و'], 'or' => ['أو', 'او'], 'not' => ['ليس', 'لا']],
    ];

    /**
     * @var string Current language for localized operators
     */
    private string $language = 'en';

    /**
     * Parse a search query string into structured components
     *
     * @since 5.0.0
     * @param string $query Raw search query
     * @param string|null $language Language code for localized operators (default: 'en')
     * @return ParsedQuery Structured query object
     */
    public static function parse(string $query, ?string $language = null): ParsedQuery
    {
        $parser = new self();
        $parser->setLoggingHandle('search-manager');

        // Set language (use generic code if regional variant, e.g., de-DE → de)
        if ($language) {
            $parser->language = str_contains($language, '-')
                ? substr($language, 0, 2)
                : $language;
        }

        $parsed = new ParsedQuery();
        $parsed->originalQuery = $query;

        // Clean up whitespace
        $query = trim($query);

        if (empty($query)) {
            return $parsed;
        }

        $parser->logDebug('Parsing query', ['query' => $query, 'language' => $parser->language]);

        // Step 1: Extract quoted phrases first (they take precedence)
        $query = $parser->extractPhrases($query, $parsed);

        // Step 2: Extract field-specific searches (title:test, content:blog)
        $query = $parser->extractFieldFilters($query, $parsed);

        // Step 3: Extract NOT terms (test NOT spam)
        $query = $parser->extractNotTerms($query, $parsed);

        // Step 4: Extract wildcards (test*)
        $query = $parser->extractWildcards($query, $parsed);

        // Step 5: Extract boost factors (test^2)
        $query = $parser->extractBoosts($query, $parsed);

        // Step 6: Detect boolean operator (OR/AND)
        $query = $parser->detectOperator($query, $parsed);

        // Step 7: Remaining tokens are regular search terms
        $query = $parser->extractRegularTerms($query, $parsed);

        $parser->logDebug('Query parsed', $parsed->toArray());

        return $parsed;
    }

    /**
     * Check if a query string has advanced operators
     *
     * @since 5.0.0
     * @param string $query Raw query string
     * @return bool
     */
    public static function hasAdvancedOperators(string $query): bool
    {
        // Quick check without full parsing
        // Check syntax operators first
        if (str_contains($query, '"') ||         // Phrases
            str_contains($query, ':') ||         // Field filters
            str_contains($query, '*') ||         // Wildcards
            str_contains($query, '^')) {         // Boosts
            return true;
        }

        // Build list of all boolean operators from LOCALIZED_OPERATORS constant
        $allOperators = self::getAllBooleanOperators();

        // Build regex pattern for all operators (unicode-aware)
        $escapedOperators = array_map('preg_quote', $allOperators);
        $pattern = '/\s+(' . implode('|', $escapedOperators) . ')\s+/iu';

        return (bool)preg_match($pattern, $query);
    }

    /**
     * Get all boolean operators from all languages
     *
     * @return array Flat array of all operator strings
     */
    private static function getAllBooleanOperators(): array
    {
        $operators = [];

        foreach (self::LOCALIZED_OPERATORS as $lang => $ops) {
            foreach (['and', 'or', 'not'] as $type) {
                $op = $ops[$type];
                if (is_array($op)) {
                    $operators = array_merge($operators, $op);
                } else {
                    $operators[] = $op;
                }
            }
        }

        return array_unique($operators);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get all NOT operators (English + localized)
     *
     * @return array Array of NOT operator strings
     */
    private function getNotOperators(): array
    {
        $operators = ['NOT']; // Always include English

        // Add localized operator(s) if available and different from English
        if (isset(self::LOCALIZED_OPERATORS[$this->language])) {
            $localized = self::LOCALIZED_OPERATORS[$this->language]['not'];
            // Support both single string and array of alternatives
            $localizedArray = is_array($localized) ? $localized : [$localized];
            foreach ($localizedArray as $op) {
                if ($op !== 'NOT') {
                    $operators[] = $op;
                }
            }
        }

        return $operators;
    }

    /**
     * Get all OR operators (English + localized)
     *
     * @return array Array of OR operator strings
     */
    private function getOrOperators(): array
    {
        $operators = ['OR']; // Always include English

        // Add localized operator(s) if available and different from English
        if (isset(self::LOCALIZED_OPERATORS[$this->language])) {
            $localized = self::LOCALIZED_OPERATORS[$this->language]['or'];
            // Support both single string and array of alternatives
            $localizedArray = is_array($localized) ? $localized : [$localized];
            foreach ($localizedArray as $op) {
                if ($op !== 'OR') {
                    $operators[] = $op;
                }
            }
        }

        return $operators;
    }

    /**
     * Get all AND operators (English + localized)
     *
     * @return array Array of AND operator strings
     */
    private function getAndOperators(): array
    {
        $operators = ['AND']; // Always include English

        // Add localized operator(s) if available and different from English
        if (isset(self::LOCALIZED_OPERATORS[$this->language])) {
            $localized = self::LOCALIZED_OPERATORS[$this->language]['and'];
            // Support both single string and array of alternatives
            $localizedArray = is_array($localized) ? $localized : [$localized];
            foreach ($localizedArray as $op) {
                if ($op !== 'AND') {
                    $operators[] = $op;
                }
            }
        }

        return $operators;
    }

    // =========================================================================
    // EXTRACTION METHODS
    // =========================================================================

    /**
     * Extract quoted phrases from query
     *
     * Example: 'test "exact phrase" entry' → phrases: ['exact phrase'], returns: 'test entry'
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with phrases removed
     */
    private function extractPhrases(string $query, ParsedQuery $parsed): string
    {
        // Match anything within double quotes
        if (preg_match_all('/"([^"]+)"/', $query, $matches)) {
            foreach ($matches[1] as $phrase) {
                $phrase = trim($phrase);
                if (!empty($phrase)) {
                    $parsed->phrases[] = $phrase;
                    $this->logDebug('Extracted phrase', ['phrase' => $phrase]);
                }
            }

            // Remove all quoted phrases from query
            $query = preg_replace('/"[^"]+"/', '', $query);
        }

        return $query;
    }

    /**
     * Extract field-specific filters from query
     *
     * Example: 'title:blog content:test' → fieldFilters: ['title' => ['blog'], 'content' => ['test']]
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with field filters removed (but terms added back as regular search)
     */
    private function extractFieldFilters(string $query, ParsedQuery $parsed): string
    {
        $extractedTerms = [];

        // Match patterns like "field:term" or "field:term1,term2"
        if (preg_match_all('/(\w+):(\S+)/', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = $match[1];
                $value = $match[2];

                // Remove boost suffix if present (title:test^2 → title:test)
                $value = preg_replace('/\^[\d.]+$/', '', $value);

                // Support comma-separated values
                $terms = explode(',', $value);

                if (!isset($parsed->fieldFilters[$field])) {
                    $parsed->fieldFilters[$field] = [];
                }

                foreach ($terms as $term) {
                    $term = trim($term);
                    if (!empty($term)) {
                        $parsed->fieldFilters[$field][] = $term;
                        $extractedTerms[] = $term; // Keep terms for searching
                    }
                }

                $this->logDebug('Extracted field filter', [
                    'field' => $field,
                    'terms' => $terms,
                ]);
            }

            // Remove field filter syntax but keep the terms
            $query = preg_replace('/\w+:(\S+)/', '$1', $query);
        }

        return $query;
    }

    /**
     * Extract NOT terms from query
     *
     * Example: 'test NOT spam NOT unwanted' → notTerms: ['spam', 'unwanted']
     * Supports localized NOT operators (e.g., NICHT for German, SAUF for French)
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with NOT terms removed
     */
    private function extractNotTerms(string $query, ParsedQuery $parsed): string
    {
        // Build regex pattern for all NOT operators (English + localized)
        $notOperators = $this->getNotOperators();
        $pattern = '/\s+(' . implode('|', array_map('preg_quote', $notOperators)) . ')\s+(\S+)/iu';

        // Match "NOT term" patterns (case-insensitive, unicode)
        if (preg_match_all($pattern, $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $notTerm = trim($match[2]);
                if (!empty($notTerm)) {
                    $parsed->notTerms[] = $notTerm;
                    $this->logDebug('Extracted NOT term', [
                        'operator' => $match[1],
                        'term' => $notTerm,
                    ]);
                }
            }

            // Remove NOT terms from query
            $removePattern = '/\s+(' . implode('|', array_map('preg_quote', $notOperators)) . ')\s+\S+/iu';
            $query = preg_replace($removePattern, '', $query);
        }

        return $query;
    }

    /**
     * Extract wildcard terms from query
     *
     * Example: 'test* java*' → wildcards: ['test', 'java']
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with wildcards removed
     */
    private function extractWildcards(string $query, ParsedQuery $parsed): string
    {
        // Match terms ending with asterisk
        if (preg_match_all('/(\w+)\*/', $query, $matches)) {
            foreach ($matches[1] as $wildcardTerm) {
                $wildcardTerm = trim($wildcardTerm);
                if (!empty($wildcardTerm)) {
                    $parsed->wildcards[] = $wildcardTerm;
                    $this->logDebug('Extracted wildcard', ['term' => $wildcardTerm]);
                }
            }

            // Remove wildcards from query
            $query = preg_replace('/\w+\*/', '', $query);
        }

        return $query;
    }

    /**
     * Extract boost factors from query
     *
     * Example: 'test^2 entry^1.5' → boosts: ['test' => 2.0, 'entry' => 1.5]
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with boost markers removed
     */
    private function extractBoosts(string $query, ParsedQuery $parsed): string
    {
        // Match patterns like "term^2" or "term^1.5"
        if (preg_match_all('/(\w+)\^([\d.]+)/', $query, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $term = $match[1];
                $boost = (float)$match[2];

                if ($boost > 0) {
                    $parsed->boosts[$term] = $boost;
                    $this->logDebug('Extracted boost', [
                        'term' => $term,
                        'boost' => $boost,
                    ]);
                }
            }

            // Remove boost markers but keep the terms
            $query = preg_replace('/(\w+)\^[\d.]+/', '$1', $query);
        }

        return $query;
    }

    /**
     * Detect boolean operator (OR/AND)
     *
     * Example: 'test OR entry' → operator: 'OR'
     * Supports localized operators (e.g., ODER for German, OU for French)
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Query with operator removed
     */
    private function detectOperator(string $query, ParsedQuery $parsed): string
    {
        // Build regex patterns for OR and AND operators (English + localized)
        $orOperators = $this->getOrOperators();
        $andOperators = $this->getAndOperators();

        $orPattern = '/\s+(' . implode('|', array_map('preg_quote', $orOperators)) . ')\s+/iu';
        $andPattern = '/\s+(' . implode('|', array_map('preg_quote', $andOperators)) . ')\s+/iu';

        // Check for OR operator (case-insensitive, unicode)
        if (preg_match($orPattern, $query, $match)) {
            $parsed->operator = 'OR';
            $query = preg_replace($orPattern, ' ', $query);
            $this->logDebug('Detected OR operator', ['matched' => $match[1]]);
        }
        // Check for explicit AND operator (case-insensitive, unicode)
        elseif (preg_match($andPattern, $query, $match)) {
            $parsed->operator = 'AND';
            $query = preg_replace($andPattern, ' ', $query);
            $this->logDebug('Detected AND operator', ['matched' => $match[1]]);
        }
        // Default to AND
        else {
            $parsed->operator = 'AND';
        }

        return $query;
    }

    /**
     * Extract remaining regular search terms
     *
     * @param string $query Query string
     * @param ParsedQuery $parsed Parsed query object to populate
     * @return string Empty string (all terms extracted)
     */
    private function extractRegularTerms(string $query, ParsedQuery $parsed): string
    {
        // Clean up extra whitespace
        $query = preg_replace('/\s+/', ' ', trim($query));

        if (!empty($query)) {
            // Split into individual terms
            $terms = explode(' ', $query);

            foreach ($terms as $term) {
                $term = trim($term);
                if (!empty($term)) {
                    $parsed->terms[] = $term;
                }
            }

            if (!empty($parsed->terms)) {
                $this->logDebug('Extracted regular terms', ['terms' => $parsed->terms]);
            }
        }

        return '';
    }
}
