<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\twigextensions;

use lindemannrock\searchmanager\SearchManager;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Plugin Name Twig Extension
 *
 * Provides centralized access to plugin name variations in Twig templates.
 *
 * Usage in templates:
 * - {{ searchHelper.displayName }}             // "Search" (singular, no Manager)
 * - {{ searchHelper.pluralDisplayName }}       // "Searches" (plural, no Manager)
 * - {{ searchHelper.fullName }}                // "Search Manager" (as configured)
 * - {{ searchHelper.lowerDisplayName }}        // "search" (lowercase singular)
 * - {{ searchHelper.pluralLowerDisplayName }}  // "searches" (lowercase plural)
 */
class PluginNameExtension extends AbstractExtension implements GlobalsInterface
{
    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Search Manager - Plugin Name Helper';
    }

    /**
     * Make plugin name helper available as global Twig variable
     *
     * @return array
     */
    public function getGlobals(): array
    {
        return [
            'searchHelper' => new PluginNameHelper(),
        ];
    }
}

/**
 * Plugin Name Helper
 *
 * Helper class that exposes Settings methods as properties for clean Twig syntax.
 */
class PluginNameHelper
{
    /**
     * Get display name (singular, without "Manager")
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return SearchManager::$plugin->getSettings()->getDisplayName();
    }

    /**
     * Get plural display name (without "Manager")
     *
     * @return string
     */
    public function getPluralDisplayName(): string
    {
        return SearchManager::$plugin->getSettings()->getPluralDisplayName();
    }

    /**
     * Get full plugin name (as configured)
     *
     * @return string
     */
    public function getFullName(): string
    {
        return SearchManager::$plugin->getSettings()->getFullName();
    }

    /**
     * Get lowercase display name (singular, without "Manager")
     *
     * @return string
     */
    public function getLowerDisplayName(): string
    {
        return SearchManager::$plugin->getSettings()->getLowerDisplayName();
    }

    /**
     * Get lowercase plural display name (without "Manager")
     *
     * @return string
     */
    public function getPluralLowerDisplayName(): string
    {
        return SearchManager::$plugin->getSettings()->getPluralLowerDisplayName();
    }

    /**
     * Magic getter to allow property-style access in Twig
     * Enables: {{ searchHelper.displayName }} instead of {{ searchHelper.getDisplayName() }}
     *
     * @param string $name
     * @return string|null
     */
    public function __get(string $name): ?string
    {
        $method = 'get' . ucfirst($name);
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        return null;
    }
}
