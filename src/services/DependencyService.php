<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Resolves Search Manager entity dependencies for destructive action guards.
 *
 * @since 5.53.0
 */
class DependencyService extends Component
{
    /**
     * @return array<int, array{type: string, label: string}>
     */
    public function getBackendUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchIndex::findAll() as $index) {
            if ($index->backend !== $handle) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Index'),
                'label' => $index->name,
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    public function getIndexUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widgetConfig) {
            if (!in_array($handle, $widgetConfig->getIndexHandles(), true)) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
            ];
        }

        foreach (ApiKey::findAll() as $apiKey) {
            if (!in_array($handle, $apiKey->allowedIndices, true)) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'API key'),
                'label' => $apiKey->name,
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    public function getApiKeyUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($handle) as $widgetConfig) {
            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string}>
     */
    public function getStyleUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widgetConfig) {
            if ($widgetConfig->styleHandle !== $handle) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
            ];
        }

        return $usages;
    }

    /**
     * @param array<int, array{type: string, label: string}> $usages
     */
    public function formatInUseError(string $name, array $usages): string
    {
        $usageLabels = array_map(
            static fn(array $usage): string => $usage['type'] . ': ' . $usage['label'],
            $usages,
        );

        return Craft::t('search-manager', 'Cannot delete “{name}” — it is in use by: {usages}.', [
            'name' => $name,
            'usages' => implode(', ', $usageLabels),
        ]);
    }
}
