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
}
