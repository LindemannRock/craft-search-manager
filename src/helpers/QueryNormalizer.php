<?php
/**
 * LindemannRock Search Manager
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
     * Normalize a submitted query to the identity used by search cache keys.
     */
    public static function forCacheIdentity(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
    }
}
