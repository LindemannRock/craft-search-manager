<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use lindemannrock\base\helpers\PluginHelper;
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
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Permission checks based on action
        switch ($action->id) {
            case 'rebuild-all-indices':
            case 'clear-backend-storage':
                $this->requirePermission('searchManager:rebuildIndices');
                break;
            case 'clear-device-cache':
            case 'clear-search-cache':
            case 'clear-autocomplete-cache':
            case 'clear-all-caches':
                $this->requirePermission('searchManager:clearCache');
                break;
            case 'clear-all-analytics':
                $this->requirePermission('searchManager:clearAnalytics');
                break;
        }

        return true;
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
            $backend = SearchManager::$plugin->backend->getActiveBackend();
            $backendName = $backend ? $backend->getName() : 'unknown';
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

                    // Reset index metadata to reflect empty state
                    $index->updateStats(0);

                    $this->logDebug('Cleared index via backend and reset metadata', [
                        'index' => $index->handle,
                        'backend' => $backendName,
                    ]);
                }
            }

            // Clean up orphaned data (indices that exist in storage but not in config/database)
            // This handles cases where prefix changed or indices were deleted
            if ($backendName === 'mysql' || $backendName === 'pgsql') {
                $orphanedHandles = Craft::$app->getDb()->createCommand(
                    'SELECT DISTINCT indexHandle FROM {{%searchmanager_search_documents}}'
                )->queryColumn();

                $knownHandles = array_map(fn($idx) => $idx->handle, $indices);
                $settings = SearchManager::$plugin->getSettings();
                $prefix = $settings->indexPrefix ?? '';

                // Also include prefixed versions of known handles
                $allKnownHandles = $knownHandles;
                if ($prefix) {
                    foreach ($knownHandles as $handle) {
                        $allKnownHandles[] = $prefix . $handle;
                    }
                }

                foreach ($orphanedHandles as $orphanedHandle) {
                    if (!in_array($orphanedHandle, $allKnownHandles)) {
                        // This is orphaned data - delete directly from tables
                        $tables = [
                            '{{%searchmanager_search_documents}}',
                            '{{%searchmanager_search_terms}}',
                            '{{%searchmanager_search_titles}}',
                            '{{%searchmanager_search_ngrams}}',
                            '{{%searchmanager_search_ngram_counts}}',
                            '{{%searchmanager_search_metadata}}',
                        ];

                        foreach ($tables as $table) {
                            Craft::$app->getDb()->createCommand()
                                ->delete($table, ['indexHandle' => $orphanedHandle])
                                ->execute();
                        }

                        $this->logDebug('Cleared orphaned index data', [
                            'index' => $orphanedHandle,
                        ]);
                    }
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
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'device');
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
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'search');
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
     * Clear autocomplete cache
     */
    public function actionClearAutocompleteCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            SearchManager::$plugin->autocomplete->clearCache();

            $settings = SearchManager::$plugin->getSettings();
            if ($settings->cacheStorageMethod === 'redis') {
                $message = Craft::t('search-manager', 'Autocomplete cache cleared successfully.');
            } else {
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
                $fileCount = 0;
                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                }
                $message = Craft::t('search-manager', 'Autocomplete cache cleared successfully ({count} files).', ['count' => $fileCount]);
            }

            $this->logInfo('Autocomplete cache cleared via utility');

            return $this->asJson([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear autocomplete cache', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear autocomplete cache.'),
            ]);
        }
    }

    /**
     * Clear all caches (device + search + autocomplete)
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
                    $autocompleteKeys = $redis->executeCommand('SMEMBERS', ['searchmanager-autocomplete-keys']) ?: [];

                    // Delete search cache keys
                    foreach ($searchKeys as $key) {
                        $cache->delete($key);
                    }

                    // Delete device cache keys
                    foreach ($deviceKeys as $key) {
                        $cache->delete($key);
                    }

                    // Delete autocomplete cache keys
                    foreach ($autocompleteKeys as $key) {
                        $cache->delete($key);
                    }

                    // Clear the tracking sets
                    $redis->executeCommand('DEL', ['searchmanager-search-keys']);
                    $redis->executeCommand('DEL', ['searchmanager-device-keys']);
                    $redis->executeCommand('DEL', ['searchmanager-autocomplete-keys']);
                }

                $message = Craft::t('search-manager', 'All caches cleared successfully.');
            } else {
                $totalFiles = 0;

                // Clear device cache
                $deviceCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'device');
                if (is_dir($deviceCachePath)) {
                    $files = glob($deviceCachePath . '/*.cache');
                    $totalFiles += count($files ?: []);
                    FileHelper::clearDirectory($deviceCachePath);
                }

                // Clear search cache
                $searchCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'search');
                if (is_dir($searchCachePath)) {
                    $files = glob($searchCachePath . '/*.cache');
                    $totalFiles += count($files ?: []);
                    FileHelper::clearDirectory($searchCachePath);
                }

                // Clear autocomplete cache
                $autocompleteCachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
                if (is_dir($autocompleteCachePath)) {
                    $files = glob($autocompleteCachePath . '/*.cache');
                    $totalFiles += count($files ?: []);
                    FileHelper::clearDirectory($autocompleteCachePath);
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
