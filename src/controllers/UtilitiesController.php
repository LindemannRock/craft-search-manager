<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Utilities Controller
 */
class UtilitiesController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Rebuild all indices
     */
    public function actionRebuildAllIndices(): Response
    {
        $this->requirePostRequest();

        try {
            SearchManager::$plugin->indexing->rebuildAll();

            $this->logInfo('All indices rebuild queued via utility');

            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'All indices rebuild has been queued.')
            );
        } catch (\Throwable $e) {
            $this->logError('Failed to queue index rebuild', [
                'error' => $e->getMessage(),
            ]);

            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Failed to queue index rebuild.')
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Clear backend storage (works for all backends)
     */
    public function actionClearBackendStorage(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $settings = SearchManager::$plugin->getSettings();
            $backend = SearchManager::$plugin->backend->getActiveBackend();
            $backendName = $settings->searchBackend;
            $clearedCount = 0;

            if (!$backend) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'No active backend available.'),
                ]);
            }

            // Get all indices and clear each one
            $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
            foreach ($indices as $index) {
                if ($backend->clearIndex($index->handle)) {
                    $clearedCount++;
                    $this->logDebug('Cleared index via backend', [
                        'index' => $index->handle,
                        'backend' => $backendName,
                    ]);
                }
            }

            $this->logInfo('Backend storage cleared via utility', [
                'backend' => $backendName,
                'indicesCleared' => $clearedCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', '{backend} storage cleared successfully ({count} indices).', [
                    'backend' => ucfirst($backendName),
                    'count' => $clearedCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear backend storage', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear backend storage.'),
            ]);
        }
    }

    /**
     * Clear device detection cache
     */
    public function actionClearDeviceCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $settings = SearchManager::$plugin->getSettings();

            if ($settings->cacheStorageMethod === 'redis') {
                $cache = Craft::$app->cache;
                if ($cache instanceof \yii\redis\Cache) {
                    $redis = $cache->redis;

                    // Get all device cache keys from tracking set
                    $keys = $redis->executeCommand('SMEMBERS', ['searchmanager-device-keys']) ?: [];

                    // Delete device cache keys
                    foreach ($keys as $key) {
                        $cache->delete($key);
                    }

                    // Clear the tracking set
                    $redis->executeCommand('DEL', ['searchmanager-device-keys']);
                }

                $message = Craft::t('search-manager', 'Device cache cleared successfully.');
            } else {
                $cachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/device';
                $fileCount = 0;

                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                    FileHelper::clearDirectory($cachePath);
                }

                $message = Craft::t('search-manager', 'Device cache cleared successfully ({count} files).', ['count' => $fileCount]);
            }

            $this->logInfo('Device cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear device cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear device cache.'),
            ]);
        }
    }

    /**
     * Clear search results cache
     */
    public function actionClearSearchCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $settings = SearchManager::$plugin->getSettings();

            if ($settings->cacheStorageMethod === 'redis') {
                SearchManager::$plugin->backend->clearAllSearchCache();
                $message = Craft::t('search-manager', 'Search cache cleared successfully.');
            } else {
                $cachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/search';
                $fileCount = 0;

                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                    FileHelper::clearDirectory($cachePath);
                }

                $message = Craft::t('search-manager', 'Search cache cleared successfully ({count} files).', ['count' => $fileCount]);
            }

            $this->logInfo('Search cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear search cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear search cache.'),
            ]);
        }
    }

    /**
     * Clear all caches (device + search)
     */
    public function actionClearAllCaches(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $settings = SearchManager::$plugin->getSettings();

            if ($settings->cacheStorageMethod === 'redis') {
                $cache = Craft::$app->cache;
                if ($cache instanceof \yii\redis\Cache) {
                    $redis = $cache->redis;

                    // Get all cache keys from tracking sets
                    $searchKeys = $redis->executeCommand('SMEMBERS', ['searchmanager-search-keys']) ?: [];
                    $deviceKeys = $redis->executeCommand('SMEMBERS', ['searchmanager-device-keys']) ?: [];

                    // Delete search cache keys
                    foreach ($searchKeys as $key) {
                        $cache->delete($key);
                    }

                    // Delete device cache keys
                    foreach ($deviceKeys as $key) {
                        $cache->delete($key);
                    }

                    // Clear the tracking sets
                    $redis->executeCommand('DEL', ['searchmanager-search-keys']);
                    $redis->executeCommand('DEL', ['searchmanager-device-keys']);
                }

                $message = Craft::t('search-manager', 'All caches cleared successfully.');
            } else {
                $totalFiles = 0;

                // Clear device cache
                $deviceCachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/device';
                if (is_dir($deviceCachePath)) {
                    $files = glob($deviceCachePath . '/*.cache');
                    $totalFiles += count($files ?: []);
                    FileHelper::clearDirectory($deviceCachePath);
                }

                // Clear search cache
                $searchCachePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/cache/search';
                if (is_dir($searchCachePath)) {
                    $files = glob($searchCachePath . '/*.cache');
                    $totalFiles += count($files ?: []);
                    FileHelper::clearDirectory($searchCachePath);
                }

                $message = Craft::t('search-manager', 'All caches cleared successfully ({count} files).', ['count' => $totalFiles]);
            }

            $this->logInfo('All caches cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear all caches', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear all caches.'),
            ]);
        }
    }

    /**
     * Clear all analytics data
     */
    public function actionClearAllAnalytics(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            // Delete all analytics data
            $rowCount = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_analytics}}')
                ->execute();

            $this->logInfo('All analytics data cleared via utility', [
                'rowsDeleted' => $rowCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'All analytics data cleared successfully ({count} records deleted).', [
                    'count' => $rowCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear analytics data', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear analytics data.'),
            ]);
        }
    }
}
