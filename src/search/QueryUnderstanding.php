<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

/**
 * QueryUnderstanding
 *
 * Layer-1 entry point of the shared search/autocomplete core: one place that
 * turns raw user input into a fully-populated {@see ParsedQuery} both surfaces
 * consume. Composes the existing {@see QueryParser} (operators, phrases, field
 * filters), {@see Tokenizer}/{@see TermNormalizer} (multilingual tokenization
 * stays byte-identical to indexing), and {@see CompoundSuggestionExtractor}
 * (filename-like dotted compounds).
 *
 * On top of QueryParser's output it fills the Layer-1 fields:
 * - `normalizedQuery` — Unicode-normalized, whitespace-collapsed input
 * - `tokens` — the plain AND/OR terms, tokenized exactly like indexed content
 * - `isCompound` / `compoundPrefix` — dotted-compound detection ("config.php")
 * - `lastTokenIncomplete` — autocomplete-only: whether the final token is
 *   still being typed (input not ended with whitespace or a closing quote)
 *
 * Stop-word filtering is deliberately NOT applied here: it is an engine
 * concern configured per index (enableStopWords + language) and stays in
 * Layer 3.
 *
 * @since 5.53.0
 */
final class QueryUnderstanding
{
    /**
     * Parse raw input into a fully-populated ParsedQuery.
     *
     * @param string $query Raw user input
     * @param array $options Supported keys:
     *   - language: ?string  Language code for localized boolean operators
     *   - forAutocomplete: bool  Compute `lastTokenIncomplete` (default false)
     * @return ParsedQuery
     */
    public static function parse(string $query, array $options = []): ParsedQuery
    {
        $language = isset($options['language']) && is_string($options['language'])
            ? $options['language']
            : null;

        $parsed = QueryParser::parse($query, $language);

        $normalized = TermNormalizer::normalize($query);
        $parsed->normalizedQuery = trim((string)preg_replace('/\s+/u', ' ', $normalized));

        // Tokenize the plain terms the same way indexed content is tokenized,
        // so a term like "config.php" yields the tokens the index stores.
        $tokenizer = new Tokenizer();
        $parsed->tokens = $parsed->terms === []
            ? []
            : $tokenizer->tokenize(implode(' ', $parsed->terms));

        $compoundExtractor = new CompoundSuggestionExtractor($tokenizer);
        $parsed->isCompound = $compoundExtractor->isCompoundQuery($query);
        $parsed->compoundPrefix = $parsed->isCompound
            ? $compoundExtractor->normalizePrefix($query)
            : '';

        if (!empty($options['forAutocomplete'])) {
            // The last token counts as "still being typed" unless the input is
            // closed by trailing whitespace or a closing quote.
            $parsed->lastTokenIncomplete = $parsed->tokens !== []
                && !preg_match('/["\s]$/u', $query);
        }

        return $parsed;
    }
}
