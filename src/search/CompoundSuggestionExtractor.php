<?php

namespace lindemannrock\searchmanager\search;

/**
 * Extracts filename-like dotted compounds for autocomplete.
 *
 * @since 5.53.0
 */
class CompoundSuggestionExtractor
{
    private const MAX_LENGTH = 255;

    private Tokenizer $tokenizer;

    public function __construct(?Tokenizer $tokenizer = null)
    {
        $this->tokenizer = $tokenizer ?? new Tokenizer();
    }

    /**
     * @return array<string, array{suggestion: string, normalizedSuggestion: string, tokenKey: string, frequency: int}>
     */
    public function extract(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+(?:\.[\p{L}\p{N}]+)+/u', $text, $matches);

        $suggestions = [];
        foreach ($matches[0] as $match) {
            $normalized = trim(TermNormalizer::normalize($match));
            if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
                continue;
            }

            $tokens = $this->tokenizer->tokenize($normalized);
            if (count($tokens) < 2) {
                continue;
            }

            $tokenKey = implode(' ', $tokens);
            if (mb_strlen($tokenKey) > self::MAX_LENGTH) {
                continue;
            }

            if (!isset($suggestions[$normalized])) {
                $suggestions[$normalized] = [
                    'suggestion' => $normalized,
                    'normalizedSuggestion' => $normalized,
                    'tokenKey' => $tokenKey,
                    'frequency' => 0,
                ];
            }

            $suggestions[$normalized]['frequency']++;
        }

        return $suggestions;
    }

    public function isCompoundQuery(string $query): bool
    {
        $query = trim(TermNormalizer::normalize($query));
        if ($query === '' || str_starts_with($query, '.')) {
            return false;
        }

        return (bool) preg_match('/[\p{L}\p{N}]+\.[\p{L}\p{N}]*$/u', $query)
            && count($this->tokenizer->tokenize($query)) >= 2;
    }

    public function normalizePrefix(string $query): string
    {
        return mb_substr(trim(TermNormalizer::normalize($query)), 0, self::MAX_LENGTH);
    }
}
