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
     * @param string $key The config key (e.g., 'configuredBackends', 'indices', 'widgetConfigs')
     * @return array The config section or empty array if not found
     */
    public static function getConfigSection(string $key): array
    {
        $config = self::getConfig();
        return $config[$key] ?? [];
    }

    /**
     * Get configured backends from config file
     *
     * @return array Array of backend configs keyed by handle
     */
    public static function getConfiguredBackends(): array
    {
        return self::getConfigSection('configuredBackends');
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
     * Get widget configs from config file
     *
     * @return array Array of widget configs keyed by handle
     */
    public static function getWidgetConfigs(): array
    {
        return self::getConfigSection('widgetConfigs');
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
        $configs = self::getConfigSection($section);
        return isset($configs[$handle]);
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
        $configs = self::getConfigSection($section);
        return $configs[$handle] ?? null;
    }

    /**
     * Clear the config cache
     *
     * Call this if you need to reload the config file (e.g., after file changes)
     */
    public static function clearCache(): void
    {
        self::$_configCache = null;
    }

    /**
     * Get all handles from a config section
     *
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
