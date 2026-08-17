<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\search;

/**
 * Selects result-highlight terms without changing retrieval provenance.
 *
 * @internal
 * @since 5.55.0
 */
final class ResultHighlightTermSelector
{
    /**
     * @param array<string, array<int, array{term: string, matchType: string, similarity: float}>> $resolvedTermsByToken
     * @param list<string> $areaTerms
     * @return list<string>
     */
    public static function forIndexedTerms(array $resolvedTermsByToken, array $areaTerms): array
    {
        $termSet = [];
        foreach ($areaTerms as $term) {
            if (is_string($term) && $term !== '') {
                $termSet[TermNormalizer::normalize($term)] = true;
            }
        }

        return self::select(
            $resolvedTermsByToken,
            static fn(string $term): bool => isset($termSet[TermNormalizer::normalize($term)]),
        );
    }

    /**
     * @param array<string, array<int, array{term: string, matchType: string, similarity: float}>> $resolvedTermsByToken
     * @return list<string>
     */
    public static function forText(array $resolvedTermsByToken, string $text): array
    {
        $normalized = TermNormalizer::normalize($text);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
        $termSet = array_fill_keys(array_values(array_filter(explode(' ', $normalized))), true);

        return self::select(
            $resolvedTermsByToken,
            static fn(string $term): bool => isset($termSet[TermNormalizer::normalize($term)]),
        );
    }

    /**
     * @param array<string, array<int, array{term: string, matchType: string, similarity: float}>> $resolvedTermsByToken
     * @return array<string, true>
     */
    public static function candidateSet(array $resolvedTermsByToken): array
    {
        $terms = [];
        foreach ($resolvedTermsByToken as $entries) {
            foreach ($entries as $entry) {
                $term = TermNormalizer::normalize($entry['term']);
                if ($term !== '') {
                    $terms[$term] = true;
                }
            }
        }

        return $terms;
    }

    /**
     * @param array<string, array<int, array{term: string, matchType: string, similarity: float}>> $resolvedTermsByToken
     * @param callable(string): bool $occursInArea
     * @return list<string>
     */
    private static function select(array $resolvedTermsByToken, callable $occursInArea): array
    {
        $selected = [];

        foreach ($resolvedTermsByToken as $rawToken => $entries) {
            $occurring = [];
            $literal = TermNormalizer::normalize(rtrim((string)$rawToken, '*'));
            $wildcard = str_ends_with((string)$rawToken, '*');

            foreach ($entries as $entry) {
                $term = TermNormalizer::normalize($entry['term']);
                if ($term === '' || !$occursInArea($term)) {
                    continue;
                }

                $occurring[] = [
                    'term' => $term,
                    'exact' => !$wildcard
                        && $term === $literal
                        && $entry['matchType'] === TermResolver::MATCH_EXACT,
                ];
            }

            $hasLiteral = false;
            foreach ($occurring as $candidate) {
                if ($candidate['exact']) {
                    $hasLiteral = true;
                    break;
                }
            }

            foreach ($occurring as $candidate) {
                if (!$hasLiteral || $candidate['exact']) {
                    $selected[$candidate['term']] = true;
                }
            }
        }

        return array_keys($selected);
    }
}
