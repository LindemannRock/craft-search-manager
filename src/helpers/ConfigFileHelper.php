<?php

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;

/**
 * Config File Helper
 *
 * Provides static methods for loading and managing configurations
 * from the search-manager.php config file.
 *
 * Used by: ConfiguredBackend, SearchIndex, WidgetConfig, WidgetConfigService
 *
 * @since 5.30.0
 */
class ConfigFileHelper
{
    private const PLUGIN_HANDLE = 'search-manager';

    /**
     * Get the full config from search-manager.php
     *
     * @return array The config array
     */
    public static function getConfig(): array
    {
        return BaseConfigFileHelper::getConfig(self::PLUGIN_HANDLE);
    }

    /**
     * Get a specific section from the config file
     *
     * @param string $key The config key (e.g., 'backends', 'indices', 'widgets')
     * @return array The config section or empty array if not found
     */
    public static function getConfigSection(string $key): array
    {
        return BaseConfigFileHelper::getConfigSection(self::PLUGIN_HANDLE, $key);
    }

    /**
     * Get backends from config file
     *
     * @since 5.28.0
     * @return array Array of backend configs keyed by handle
     */
    public static function getConfiguredBackends(): array
    {
        return self::getConfigSection('backends');
    }

    /**
     * Get indices from config file
     *
     * @return array Array of index configs keyed by handle
     */
    public static function getIndices(): array
    {
        return self::getConfigSection('indices');
    }

    /**
     * Get widgets from config file
     *
     * @since 5.30.0
     * @return array Array of widget configs keyed by handle
     */
    public static function getWidgetConfigs(): array
    {
        return self::getConfigSection('widgets');
    }

    /**
     * Get widget styles from config file
     *
     * @since 5.39.0
     * @return array Array of widget style configs keyed by handle
     */
    public static function getWidgetStyles(): array
    {
        return self::getConfigSection('widgetStyles');
    }

    /**
     * Check if a handle exists in config
     *
     * @param string $section The config section key
     * @param string $handle The handle to check
     * @return bool True if handle exists in config
     */
    public static function handleExistsInConfig(string $section, string $handle): bool
    {
        return BaseConfigFileHelper::handleExistsInConfig(self::PLUGIN_HANDLE, $section, $handle);
    }

    /**
     * Get a single config by handle
     *
     * @param string $section The config section key
     * @param string $handle The handle to get
     * @return array|null The config array or null if not found
     */
    public static function getConfigByHandle(string $section, string $handle): ?array
    {
        return BaseConfigFileHelper::getConfigByHandle(self::PLUGIN_HANDLE, $section, $handle);
    }

    /**
     * Clear the config cache
     *
     * Call this if you need to reload the config file (e.g., after file changes)
     */
    public static function clearCache(): void
    {
        BaseConfigFileHelper::clearCache(self::PLUGIN_HANDLE);
    }

    /**
     * Get all handles from a config section
     *
     * @param string $section The config section key
     * @return array Array of handles
     */
    public static function getHandles(string $section): array
    {
        return BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, $section);
    }

    /**
     * Merge config-sourced items with database items
     *
     * Config items take precedence over database items with the same handle.
     * Returns array keyed by handle.
     *
     * @param array $configItems Items from config file (keyed by handle)
     * @param array $databaseItems Items from database (array of objects with 'handle' property)
     * @return array Merged items keyed by handle
     */
    public static function mergeConfigAndDatabase(array $configItems, array $databaseItems): array
    {
        return BaseConfigFileHelper::mergeConfigAndDatabase($configItems, $databaseItems);
    }
}
