<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\utilities;

use Craft;
use craft\base\Utility;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Clear Search Cache utility
 *
 * @since 5.0.0
 */
class ClearSearchCache extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return SearchManager::$plugin->getSettings()->getFullName();
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'clear-search-cache';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'magnifying-glass';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $settings = SearchManager::getInstance()->getSettings();
        $user = Craft::$app->getUser();

        // Get index data only if user can manage indices
        $indices = [];
        $totalDocuments = 0;
        $backendDistribution = [];
        $defaultBackendName = null;

        if ($user->getIdentity() && $user->checkPermission('searchManager:manageIndices')) {
            $indices = SearchIndex::findAll();

            // Count indices per backend type
            foreach ($indices as $index) {
                $totalDocuments += $index->documentCount;

                $backendType = $index->getEffectiveBackendType() ?: 'file';
                if (!isset($backendDistribution[$backendType])) {
                    $backendDistribution[$backendType] = ['count' => 0, 'documents' => 0];
                }
                $backendDistribution[$backendType]['count']++;
                $backendDistribution[$backendType]['documents'] += $index->documentCount;
            }

            // Get default backend name
            $defaultBackendHandle = $settings->defaultBackendHandle;
            if ($defaultBackendHandle) {
                $defaultBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($defaultBackendHandle);
                if ($defaultBackend) {
                    $defaultBackendName = $defaultBackend->name;
                }
            }
        }

        // Get analytics count only if user can view analytics, scoped to editable sites
        $analyticsCount = 0;
        if ($settings->enableAnalytics && $user->getIdentity() && $user->checkPermission('searchManager:viewAnalytics')) {
            $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
            $analyticsCount = (int) (new \craft\db\Query())
                ->from('{{%searchmanager_analytics}}')
                ->where(['siteId' => $editableSiteIds])
                ->count();
        }

        // Count cache files (only for file storage)
        $deviceCacheFiles = 0;
        $searchCacheFiles = 0;
        $autocompleteCacheFiles = 0;

        // Only count files when using file storage (Redis counts are not displayed)
        if ($settings->cacheStorageMethod === 'file') {
            $deviceCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'device');
            if (is_dir($deviceCachePath)) {
                $files = glob($deviceCachePath . '*.cache');
                $deviceCacheFiles = count($files ?: []);
            }

            $searchCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'search');
            if (is_dir($searchCachePath)) {
                $files = glob($searchCachePath . '*.cache');
                $searchCacheFiles = count($files ?: []);
            }

            $autocompleteCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
            if (is_dir($autocompleteCachePath)) {
                $files = glob($autocompleteCachePath . '*.cache');
                $autocompleteCacheFiles = count($files ?: []);
            }
        }

        return Craft::$app->getView()->renderTemplate('search-manager/utilities/index', [
            'indexCount' => count($indices),
            'totalDocuments' => $totalDocuments,
            'backendDistribution' => $backendDistribution,
            'defaultBackendName' => $defaultBackendName,
            'indices' => $indices,
            'deviceCacheFiles' => $deviceCacheFiles,
            'searchCacheFiles' => $searchCacheFiles,
            'autocompleteCacheFiles' => $autocompleteCacheFiles,
            'storageMethod' => $settings->cacheStorageMethod,
            'analyticsCount' => $analyticsCount,
            'settings' => $settings,
        ]);
    }
}
