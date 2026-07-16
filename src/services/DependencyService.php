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
     * The permission groups are independent, so a caller holding only a delete
     * permission may not be allowed to view the referenced entity type. Each
     * usage kind maps to the permission that grants viewing that section;
     * {@see formatInUseError()} shows a count instead of names without it.
     */
    private const KIND_VIEW_PERMISSIONS = [
        'widget' => 'searchManager:manageWidgetConfigs',
        'apiKey' => 'searchManager:manageApiKeys',
        'index' => 'searchManager:manageIndices',
    ];

    private const KIND_COUNT_MESSAGES = [
        'widget' => '{count, plural, =1{# widget} other{# widgets}}',
        'apiKey' => '{count, plural, =1{# API key} other{# API keys}}',
        'index' => '{count, plural, =1{# index} other{# indices}}',
    ];

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
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
                'kind' => 'index',
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
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
                'kind' => 'widget',
            ];
        }

        foreach (ApiKey::findAll() as $apiKey) {
            if (!in_array($handle, $apiKey->allowedIndices, true)) {
                continue;
            }

            $usages[] = [
                'type' => Craft::t('search-manager', 'API key'),
                'label' => $apiKey->name,
                'kind' => 'apiKey',
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
     */
    public function getApiKeyUsages(string $handle): array
    {
        $usages = [];

        foreach (SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($handle) as $widgetConfig) {
            $usages[] = [
                'type' => Craft::t('search-manager', 'Widget'),
                'label' => $widgetConfig->name,
                'kind' => 'widget',
            ];
        }

        return $usages;
    }

    /**
     * @return array<int, array{type: string, label: string, kind: string}>
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
                'kind' => 'widget',
            ];
        }

        return $usages;
    }

    /**
     * Format the delete-guard block message. Usage names are shown only for
     * kinds the current user holds the view permission for; other kinds are
     * summarized as a count so entity names don't leak across permission
     * groups (a name is only actionable to someone who can open that section
     * anyway). No identity (guest/console) fails closed to counts.
     *
     * @param array<int, array{type: string, label: string, kind: string}> $usages
     */
    public function formatInUseError(string $name, array $usages): string
    {
        $user = Craft::$app->getUser();
        $usageLabels = [];
        $hiddenCounts = [];

        foreach ($usages as $usage) {
            $viewPermission = self::KIND_VIEW_PERMISSIONS[$usage['kind']] ?? null;
            if ($viewPermission === null || $user->checkPermission($viewPermission)) {
                $usageLabels[] = $usage['type'] . ': ' . $usage['label'];
            } else {
                $hiddenCounts[$usage['kind']] = ($hiddenCounts[$usage['kind']] ?? 0) + 1;
            }
        }

        foreach ($hiddenCounts as $kind => $count) {
            $usageLabels[] = Craft::t('search-manager', self::KIND_COUNT_MESSAGES[$kind], ['count' => $count]);
        }

        return Craft::t('search-manager', 'Cannot delete “{name}” — it is in use by: {usages}.', [
            'name' => $name,
            'usages' => implode(', ', $usageLabels),
        ]);
    }
}
