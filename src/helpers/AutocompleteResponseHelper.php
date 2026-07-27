<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

/**
 * Finalizes merged public autocomplete response lists.
 *
 * Source lists are flattened in caller-provided order, deduplicated while
 * keeping the first-ranked occurrence, and sliced only after deduplication.
 *
 * @internal
 * @since 5.54.0
 */
final class AutocompleteResponseHelper
{
    /**
     * @param iterable<array<int, string>> $sources
     * @return array<int, string>
     */
    public static function suggestions(iterable $sources, int $limit): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($sources as $suggestions) {
            foreach ($suggestions as $suggestion) {
                if (isset($seen[$suggestion])) {
                    continue;
                }

                $seen[$suggestion] = true;
                $deduplicated[] = $suggestion;
            }
        }

        return array_slice($deduplicated, 0, max(0, $limit));
    }

    /**
     * @param iterable<array<int, array<string, mixed>>> $sources
     * @return array<int, array<string, mixed>>
     */
    public static function results(iterable $sources, int $limit): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($sources as $results) {
            foreach ($results as $result) {
                $key = implode(':', [
                    (string)($result['siteId'] ?? ''),
                    (string)($result['id'] ?? ''),
                    (string)($result['type'] ?? ''),
                ]);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $deduplicated[] = $result;
            }
        }

        return array_slice($deduplicated, 0, max(0, $limit));
    }
}
