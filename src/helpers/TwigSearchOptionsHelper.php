<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes Twig-facing search options shared by template variables.
 *
 * @since 5.53.0
 */
class TwigSearchOptionsHelper
{
    public const MAX_QUERY_LENGTH = 256;
    public const SEARCH_DEFAULT_LIMIT = 20;
    public const SEARCH_MAX_LIMIT = 200;
    public const AUTOCOMPLETE_DEFAULT_LIMIT = 10;
    public const AUTOCOMPLETE_MAX_LIMIT = 100;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function normalizeSearchLimitOptions(array $options): array
    {
        return self::normalizeLimitOptions($options, self::SEARCH_DEFAULT_LIMIT, self::SEARCH_MAX_LIMIT);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function normalizeAutocompleteLimitOptions(array $options): array
    {
        return self::normalizeLimitOptions($options, self::AUTOCOMPLETE_DEFAULT_LIMIT, self::AUTOCOMPLETE_MAX_LIMIT);
    }

    /**
     * Normalize Twig-facing search limit options while preserving both accepted
     * caller spellings. `limit` is the backend-native option; `hitsPerPage`
     * mirrors the HTTP/GraphQL argument and is accepted for template parity.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function normalizeLimitOptions(array $options, int $default, int $max): array
    {
        $rawLimit = $options['limit'] ?? $options['hitsPerPage'] ?? $default;
        $limit = is_numeric($rawLimit) ? (int)$rawLimit : $default;
        if ($limit < 1) {
            $limit = $default;
        }
        $limit = min($max, $limit);

        $options['limit'] = $limit;
        unset($options['hitsPerPage']);

        return $options;
    }
}
