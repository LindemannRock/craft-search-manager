<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes search site scope values across services and backends.
 *
 * @since 5.53.0
 */
class SearchSiteScopeHelper
{
    public const ALL_SITES = '*';

    /**
     * Normalize a search `siteId` option.
     *
     * - null / '*' / empty array: all sites (`*`)
     * - scalar numeric: one positive site ID
     * - non-empty array: unique positive site IDs, sorted for stable cache keys
     *
     * @return int|array<int, int>|string
     */
    public static function normalize(mixed $siteId): int|array|string
    {
        if ($siteId === null || $siteId === self::ALL_SITES || $siteId === '') {
            return self::ALL_SITES;
        }

        if (is_array($siteId)) {
            $siteIds = array_values(array_unique(array_filter(
                array_map('intval', $siteId),
                static fn(int $id): bool => $id > 0,
            )));
            sort($siteIds);

            return $siteIds === [] ? self::ALL_SITES : $siteIds;
        }

        if (is_numeric($siteId)) {
            $id = (int)$siteId;

            return $id > 0 ? $id : self::ALL_SITES;
        }

        return self::ALL_SITES;
    }

    public static function isAllSites(mixed $siteId): bool
    {
        return self::normalize($siteId) === self::ALL_SITES;
    }

    /**
     * @return array<int, int>|null Null means all sites.
     */
    public static function siteIds(mixed $siteId): ?array
    {
        $normalized = self::normalize($siteId);
        if ($normalized === self::ALL_SITES) {
            return null;
        }

        return is_array($normalized) ? $normalized : [$normalized];
    }

    /**
     * Site-scoped rules/promotions/analytics only receive a concrete site when
     * the normalized scope targets exactly one site.
     */
    public static function scopedSiteId(mixed $siteId): ?int
    {
        $siteIds = self::siteIds($siteId);
        if ($siteIds === null || count($siteIds) !== 1) {
            return null;
        }

        return $siteIds[0];
    }
}
