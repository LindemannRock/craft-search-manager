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
use lindemannrock\searchmanager\models\SearchIndex;

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
