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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Clear Search Cache utility
 */
class ClearSearchCache extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = SearchManager::getInstance()->getSettings()->pluginName;
        return $pluginName;
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
        $indices = SearchIndex::findAll();
        $totalDocuments = 0;

        foreach ($indices as $index) {
            $totalDocuments += $index->documentCount;
        }

        // Gather backend-specific stats
        $backendStats = [];

        switch ($settings->searchBackend) {
            case 'file':
                $cacheFileCount = 0;
                $runtimePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/indices';
                if (is_dir($runtimePath)) {
                    $files = glob($runtimePath . '/*/docs/*.dat');
                    $cacheFileCount = count($files ?: []);
                }
                $backendStats['fileCount'] = $cacheFileCount;
                break;

            case 'mysql':
                // Count rows in searchmanager_search_documents table
                $docCount = (new \craft\db\Query())
                    ->from('{{%searchmanager_search_documents}}')
                    ->count();
                $backendStats['documentRows'] = (int)$docCount;
                break;

            case 'redis':
                // Count Redis keys
                try {
                    $backend = SearchManager::getInstance()->backend->getBackend('redis');
                    if ($backend && $backend->isAvailable()) {
                        // We'd need access to Redis client, skip for now
                        $backendStats['status'] = 'connected';
                    }
                } catch (\Throwable $e) {
                    $backendStats['status'] = 'disconnected';
                }
                break;
        }

        // Count device cache files
        $deviceCacheFiles = 0;
        $deviceCachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/device';
        if (is_dir($deviceCachePath)) {
            $files = glob($deviceCachePath . '/*.cache');
            $deviceCacheFiles = count($files ?: []);
        }

        // Count search cache files
        $searchCacheFiles = 0;
        $searchCachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/search';
        if (is_dir($searchCachePath)) {
            $files = glob($searchCachePath . '/*.cache');
            $searchCacheFiles = count($files ?: []);
        }

        return Craft::$app->getView()->renderTemplate('search-manager/utilities/index', [
            'indexCount' => count($indices),
            'totalDocuments' => $totalDocuments,
            'activeBackend' => $settings->searchBackend,
            'backendStats' => $backendStats,
            'indices' => $indices,
            'deviceCacheFiles' => $deviceCacheFiles,
            'searchCacheFiles' => $searchCacheFiles,
            'settings' => $settings,
        ]);
    }
}
