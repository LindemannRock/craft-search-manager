<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\utilities;

use Craft;
use craft\base\Utility;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\CacheStorageService;

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
        return '@lindemannrock/searchmanager/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $settings = SearchManager::getInstance()->getSettings();
        $user = Craft::$app->getUser();

        $indices = [];
        $totalDocuments = 0;
        $backendDistribution = [];
        $defaultBackendName = null;

        if ($user->getIdentity() && (
            $user->checkPermission('searchManager:manageIndices') ||
            $user->checkPermission('searchManager:manageBackends')
        )) {
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

        // Retained analytics remain manageable after a Pro-to-Standard downgrade.
        $analyticsCount = 0;
        if ($user->getIdentity() && (
            $user->checkPermission('searchManager:exportAnalytics') ||
            $user->checkPermission('searchManager:clearAnalytics')
        )) {
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
        $storageOptions = [];
        $rebuildAllPlan = null;

        $cacheStorage = new CacheStorageService();
        $effectiveCacheStorage = $cacheStorage->getEffectiveStorage();
        if (
            $user->getIdentity()
            && $user->checkPermission('searchManager:clearCache')
            && $effectiveCacheStorage === CacheStorageService::STORAGE_FILE
        ) {
            $deviceCacheFiles = $cacheStorage->countFiles('device');
            $searchCacheFiles = $cacheStorage->countFiles('search');
            $autocompleteCacheFiles = $cacheStorage->countFiles('autocomplete');
        }

        if ($user->getIdentity() && $user->checkPermission('searchManager:rebuildIndices')) {
            $storageOptions = SearchManager::$plugin->storageMaintenance->getProjection()['storageOptions'];
            $rebuildAllPlan = SearchManager::$plugin->dependencies->getRebuildAllPlan();
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
            // The current template has only enumerable-file and non-enumerable
            // application-cache branches. Public wording is handled later.
            'storageMethod' => $effectiveCacheStorage === CacheStorageService::STORAGE_FILE ? 'file' : 'redis',
            'analyticsCount' => $analyticsCount,
            'storageOptions' => $storageOptions,
            'rebuildAllPlan' => $rebuildAllPlan,
            'settings' => $settings,
        ]);
    }
}
