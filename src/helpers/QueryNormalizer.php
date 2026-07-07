<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes search query text for identity comparisons and cache keys.
 *
 * @since 5.53.0
 */
class QueryNormalizer
{
    /**
     * Collapse Unicode separator/whitespace runs to a single ASCII space.
     */
    public static function collapseUnicodeWhitespace(string $text): string
    {
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        return trim($normalized ?? $text);
    }

    /**
     * Normalize a submitted query to the identity used by search cache keys.
     */
    public static function forCacheIdentity(string $query): string
    {
        return mb_strtolower(self::collapseUnicodeWhitespace($query));
    }
}
