<?php

namespace lindemannrock\searchmanager\helpers;

use Craft;

/**
 * Config File Helper
 *
 * Provides static methods for loading and managing configurations
 * from the search-manager.php config file.
 *
 * Used by: ConfiguredBackend, SearchIndex, WidgetConfig, WidgetConfigService
 *
 * @since 5.0.0
 */
class ConfigFileHelper
{
    /**
     * @var array|null Cached config file contents
     */
    private static ?array $_configCache = null;

    /**
     * Get the full config from search-manager.php
     *
     * @since 5.0.0
     * @return array The config array
     */
    public static function getConfig(): array
    {
        if (self::$_configCache === null) {
            self::$_configCache = Craft::$app->getConfig()->getConfigFromFile('search-manager');
        }

        return self::$_configCache;
    }

    /**
     * Get a specific section from the config file
     *
     * @since 5.0.0
     * @param string $key The config key (e.g., 'backends', 'indices', 'widgets')
     * @return array The config section or empty array if not found
     */
    public static function getConfigSection(string $key): array
    {
        $config = self::getConfig();
        return $config[$key] ?? [];
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
     * @since 5.0.0
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
     * Check if a handle exists in config
     *
     * @since 5.0.0
     * @param string $section The config section key
     * @param string $handle The handle to check
     * @return bool True if handle exists in config
     */
    public static function handleExistsInConfig(string $section, string $handle): bool
    {
        $configs = self::getConfigSection($section);
        return isset($configs[$handle]);
    }

    /**
     * Get a single config by handle
     *
     * @since 5.0.0
     * @param string $section The config section key
     * @param string $handle The handle to get
     * @return array|null The config array or null if not found
     */
    public static function getConfigByHandle(string $section, string $handle): ?array
    {
        $configs = self::getConfigSection($section);
        return $configs[$handle] ?? null;
    }

    /**
     * Clear the config cache
     *
     * Call this if you need to reload the config file (e.g., after file changes)
     *
     * @since 5.0.0
     */
    public static function clearCache(): void
    {
        self::$_configCache = null;
    }

    /**
     * Get all handles from a config section
     *
     * @since 5.0.0
     * @param string $section The config section key
     * @return array Array of handles
     */
    public static function getHandles(string $section): array
    {
        $configs = self::getConfigSection($section);
        return array_keys($configs);
    }

    /**
     * Merge config-sourced items with database items
     *
     * Config items take precedence over database items with the same handle.
     * Returns array keyed by handle.
     *
     * @since 5.0.0
     * @param array $configItems Items from config file (keyed by handle)
     * @param array $databaseItems Items from database (array of objects with 'handle' property)
     * @return array Merged items keyed by handle
     */
    public static function mergeConfigAndDatabase(array $configItems, array $databaseItems): array
    {
        $merged = $configItems;
        $configHandles = array_keys($configItems);

        foreach ($databaseItems as $item) {
            $handle = is_object($item) ? $item->handle : ($item['handle'] ?? null);
            if ($handle && !in_array($handle, $configHandles, true)) {
                $merged[$handle] = $item;
            }
        }

        return $merged;
    }
}
