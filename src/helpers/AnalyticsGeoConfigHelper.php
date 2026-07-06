<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\searchmanager\SearchManager;

/**
 * Shared analytics geo lookup configuration.
 *
 * @since 5.53.0
 */
final class AnalyticsGeoConfigHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        $settings = SearchManager::$plugin->getSettings();

        return [
            'provider' => $settings->geoProvider ?? 'ip-api.com',
            'apiKey' => $settings->geoApiKey ?? null,
            'logCategory' => SearchManager::$plugin->id,
        ];
    }
}
