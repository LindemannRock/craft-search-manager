<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes analytics tracking metadata before it reaches storage.
 *
 * @since 5.53.0
 */
class TrackingMetadataHelper
{
    /**
     * Normalize an analytics source identifier for the `source` column.
     */
    public static function source(mixed $value): ?string
    {
        return self::normalize($value, 50, false);
    }

    /**
     * Normalize platform metadata for the `platform` column.
     */
    public static function platform(mixed $value): ?string
    {
        return self::normalize($value, 50, true);
    }

    /**
     * Normalize application version metadata for the `appVersion` column.
     */
    public static function appVersion(mixed $value): ?string
    {
        return self::normalize($value, 20, true);
    }

    private static function normalize(mixed $value, int $maxLength, bool $allowSpaceDot): ?string
    {
        if ($value === null) {
            return null;
        }

        $pattern = $allowSpaceDot ? '/[^a-zA-Z0-9 ._-]/' : '/[^a-zA-Z0-9_-]/';

        return substr(preg_replace($pattern, '', (string)$value), 0, $maxLength) ?: null;
    }
}
