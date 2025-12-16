<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Tokenizer
 *
 * Converts text into searchable tokens for indexing and searching.
 * Handles Unicode text, lowercasing, and punctuation removal.
 *
 * @since 5.0.0
 */
class Tokenizer
{
    use LoggingTrait;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Tokenize text into searchable terms
     *
     * Process:
     * 1. Convert to lowercase
     * 2. Remove all punctuation (replace with spaces)
     * 3. Keep only Unicode letters and numbers
     * 4. Split on whitespace
     * 5. Filter empty tokens
     *
     * @param string $text Text to tokenize
     * @return array Array of tokens
     */
    public function tokenize(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Replace all non-letter, non-number Unicode characters with spaces
        // \p{L} matches any Unicode letter
        // \p{N} matches any Unicode number
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        // Split on whitespace and filter empty strings
        $tokens = array_filter(explode(' ', $text), function($token) {
            return $token !== '';
        });

        $this->logDebug('Tokenized text', [
            'input_length' => mb_strlen($text),
            'token_count' => count($tokens),
        ]);

        return array_values($tokens);
    }

    /**
     * Tokenize and count term frequencies
     *
     * @param string $text Text to tokenize
     * @return array Associative array of [term => frequency]
     */
    public function tokenizeAndCount(string $text): array
    {
        $tokens = $this->tokenize($text);
        $termFreqs = array_count_values($tokens);

        $this->logDebug('Tokenized and counted terms', [
            'unique_terms' => count($termFreqs),
            'total_tokens' => count($tokens),
        ]);

        return $termFreqs;
    }

    /**
     * Get the total number of tokens in text
     *
     * @param string $text Text to count
     * @return int Number of tokens
     */
    public function getTokenCount(string $text): int
    {
        return count($this->tokenize($text));
    }
}
