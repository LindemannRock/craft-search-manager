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
     * @since 5.54.0
     */
    public const SOURCE_WIDGET_MODAL = 'widget-modal';

    /**
     * @since 5.54.0
     */
    public const SOURCE_WIDGET_PAGE = 'widget-page';

    /**
     * @since 5.54.0
     */
    public const SOURCE_WIDGET_INLINE = 'widget-inline';

    /**
     * @since 5.54.0
     */
    public const SOURCE_TWIG = 'twig';

    /**
     * @since 5.54.0
     */
    public const SOURCE_REST = 'rest';

    /**
     * @since 5.54.0
     */
    public const SOURCE_GRAPHQL = 'graphql';

    /**
     * @since 5.54.0
     */
    public const SOURCE_CP_TEST = 'cp-test';

    /**
     * @since 5.54.0
     */
    public const SOURCE_UNKNOWN = 'unknown';

    /**
     * Normalize an analytics source identifier for the `source` column.
     */
    public static function source(mixed $value): ?string
    {
        return self::normalize($value, 50, false);
    }

    /**
     * Resolve an optional custom source against a deterministic boundary default.
     *
     * @since 5.54.0
     */
    public static function resolveSource(mixed $explicitSource, mixed $defaultSource = null): string
    {
        return self::source($explicitSource)
            ?? self::source($defaultSource)
            ?? self::SOURCE_UNKNOWN;
    }

    /**
     * Resolve a widget type to its deterministic analytics source.
     *
     * @since 5.54.0
     */
    public static function widgetSourceDefault(mixed $widgetType): string
    {
        return match (strtolower(trim((string)$widgetType))) {
            'page' => self::SOURCE_WIDGET_PAGE,
            'inline' => self::SOURCE_WIDGET_INLINE,
            default => self::SOURCE_WIDGET_MODAL,
        };
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

        return substr(preg_replace($pattern, '', trim((string)$value)), 0, $maxLength) ?: null;
    }
}
