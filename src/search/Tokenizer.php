<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Tokenizer
 *
 * Converts text into searchable tokens for indexing and searching.
 * Handles Unicode text, lowercasing, and punctuation boundaries.
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
     * 2. Start tokens with a Unicode letter or number
     * 3. Keep letters, numbers, spacing marks, and enclosing marks within tokens
     * 4. Treat punctuation, symbols, and orphaned marks as boundaries
     *
     * @param string $text Text to tokenize
     * @return array Array of tokens
     */
    public function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // Normalize text consistently across backends and collations.
        $text = $this->normalizeText($text);

        // A token must begin with a letter or number. Unicode spacing marks
        // (Mc) and enclosing marks (Me) may continue it, but can never create
        // a standalone token when leading, orphaned, or punctuation-separated.
        $matched = preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{Mc}\p{Me}]*/u', $text, $matches);
        $tokens = $matched === false ? [] : $matches[0];

        $this->logDebug('Tokenized text', [
            'input_length' => mb_strlen($text),
            'token_count' => count($tokens),
        ]);

        return $tokens;
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

    /**
     * Normalize text for indexing and query tokenization.
     *
     * - Unicode normalization (NFKC/NFKD)
     * - Remove Arabic tatweel/kashida
     * - Fold all Unicode decimal digits to ASCII (Arabic, Persian, Thai, Devanagari, etc.)
     * - Lowercase and remove combining marks (accent folding)
     */
    private function normalizeText(string $text): string
    {
        return TermNormalizer::normalize($text);
    }
}
