<?php

namespace lindemannrock\searchmanager\search;

use IntlChar;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use Normalizer;

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

        // Normalize text consistently across backends and collations.
        $text = $this->normalizeText($text);

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
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if ($normalized !== false && $normalized !== null) {
                $text = $normalized;
            }
        }

        // Tatweel/kashida is collation-ignorable in MySQL _ai collations.
        $text = preg_replace('/\x{0640}/u', '', $text);

        // Fold any Unicode decimal digit (Thai, Devanagari, Bengali, etc.) to ASCII.
        if (class_exists(IntlChar::class)) {
            $text = preg_replace_callback('/\p{Nd}/u', static function(array $m): string {
                $value = IntlChar::charDigitValue(IntlChar::ord($m[0]));
                return $value >= 0 ? (string)$value : $m[0];
            }, $text);
        } else {
            // Safe fallback when ext-intl is unavailable.
            $text = strtr($text, [
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]);
        }

        $text = mb_strtolower($text, 'UTF-8');

        // Accent/diacritic folding (e.g. jalapeño -> jalapeno, maámoul -> maamoul).
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
            if ($decomposed !== false && $decomposed !== null) {
                $text = $decomposed;
            }
        }
        $text = preg_replace('/\p{Mn}+/u', '', $text);

        return $text;
    }
}
