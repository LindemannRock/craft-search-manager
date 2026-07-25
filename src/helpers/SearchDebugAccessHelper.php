<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use Craft;

/**
 * Centralizes access checks for debug search metadata.
 *
 * @since 5.53.0
 */
class SearchDebugAccessHelper
{
    public static function canExposeDebugMeta(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->devMode
            || Craft::$app->getUser()->checkPermission('searchManager:viewDebug');
    }

    /**
     * Remove debug metadata unless it was explicitly requested and authorized.
     *
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     * @since 5.54.0
     */
    public static function filterDebugMeta(array $results, bool $debugEnabled): array
    {
        if (!$debugEnabled || !self::canExposeDebugMeta()) {
            unset($results['meta']);
        }

        return $results;
    }
}
