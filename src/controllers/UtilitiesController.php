<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Controller;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\FileBackendStoragePathHelper;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Utilities Controller
 *
 * @since 5.0.0
 */
class UtilitiesController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
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
            case 'clear-storage-by-type':
            case 'get-storage-stats':
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

        // Check for handle collisions before proceeding
        $collisions = $this->getHandleCollisions();
        if (!empty($collisions)) {
            $handleList = implode(', ', $collisions);
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Cannot rebuild indices: Handle collision detected. The following handles exist in both config and database: {handles}. Please resolve these conflicts first.', [
                    'handles' => $handleList,
                ])
            );
            return $this->redirectToPostedUrl();
        }

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
                Craft::t('search-manager', 'Failed to queue index rebuild')
            );
        }

        return $this->redirectToPostedUrl();
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
            $cache = $settings->cacheStorageMethod === 'redis'
                ? PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id)
                : null;

            if ($cache !== null) {
                $redis = $cache->redis;

                // Get all device cache keys from tracking set
                $keys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'device')]) ?: [];

                // Delete device cache keys
                foreach ($keys as $key) {
                    $cache->delete($key);
                }

                // Clear the tracking set
                $redis->executeCommand('DEL', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'device')]);

                $message = Craft::t('search-manager', 'Device cache cleared successfully');
            } else {
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'device');
                $fileCount = 0;

                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                    FileHelper::clearDirectory($cachePath);
                }

                $message = Craft::t('search-manager', 'Device cache cleared successfully ({count} files)', ['count' => $fileCount]);
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
                'error' => Craft::t('search-manager', 'Failed to clear device cache'),
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
                $message = Craft::t('search-manager', 'Search cache cleared successfully');
            } else {
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'search');
                $fileCount = 0;

                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                    FileHelper::clearDirectory($cachePath);
                }

                $message = Craft::t('search-manager', 'Search cache cleared successfully ({count} files)', ['count' => $fileCount]);
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
                'error' => Craft::t('search-manager', 'Failed to clear search cache'),
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
            $cache = $settings->cacheStorageMethod === 'redis'
                ? PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id)
                : null;

            if ($cache !== null) {
                $message = Craft::t('search-manager', 'Autocomplete cache cleared successfully');
            } else {
                $cachePath = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
                $fileCount = 0;
                if (is_dir($cachePath)) {
                    $files = glob($cachePath . '/*.cache');
                    $fileCount = count($files ?: []);
                }
                $message = Craft::t('search-manager', 'Autocomplete cache cleared successfully ({count} files)', ['count' => $fileCount]);
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
                'error' => Craft::t('search-manager', 'Failed to clear autocomplete cache'),
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

            $cache = $settings->cacheStorageMethod === 'redis'
                ? PluginHelper::getRedisCacheOrLog(SearchManager::$plugin->id)
                : null;

            if ($cache !== null) {
                $redis = $cache->redis;

                // Get all cache keys from tracking sets
                $searchKeys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'search')]) ?: [];
                $deviceKeys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'device')]) ?: [];
                $autocompleteKeys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete')]) ?: [];

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
                $redis->executeCommand('DEL', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'search')]);
                $redis->executeCommand('DEL', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'device')]);
                $redis->executeCommand('DEL', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete')]);

                $message = Craft::t('search-manager', 'All caches cleared successfully');
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

                $message = Craft::t('search-manager', 'All caches cleared successfully ({count} files)', ['count' => $totalFiles]);
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
                'error' => Craft::t('search-manager', 'Failed to clear all caches'),
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
            $rowCount = SearchManager::$plugin->analytics->clearAnalytics();

            $this->logInfo('All analytics data cleared via utility', [
                'rowsDeleted' => $rowCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('search-manager', 'All analytics data cleared successfully ({count} records deleted)', [
                    'count' => $rowCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear analytics data', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear analytics data'),
            ]);
        }
    }

    /**
     * Clear ALL data from a specific backend storage type (database, redis, or file)
     *
     * This is a maintenance function to clear orphaned data when backends change
     */
    public function actionClearStorageByType(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = strtolower($this->request->getRequiredBodyParam('type'));
        $validTypes = ['database', 'redis', 'file'];

        if (!in_array($type, $validTypes, true)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Invalid storage type: {type}', ['type' => $type]),
            ]);
        }

        // Check for handle collisions before proceeding
        $collisions = $this->getHandleCollisions();
        if (!empty($collisions)) {
            $handleList = implode(', ', $collisions);
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Cannot clear storage: Handle collision detected. The following handles exist in both config and database: {handles}. Please resolve these conflicts first by removing duplicates from either config or database.', [
                    'handles' => $handleList,
                ]),
            ]);
        }

        try {
            $result = match ($type) {
                'database' => $this->clearDatabaseStorage(),
                'redis' => $this->clearRedisStorage(),
                'file' => $this->clearFileStorage(),
            };

            if ($result['success']) {
                $this->logInfo('Storage cleared by type via utility', [
                    'type' => $type,
                    'details' => $result,
                ]);

                return $this->asJson([
                    'success' => true,
                    'message' => $result['message'],
                ]);
            }

            return $this->asJson([
                'success' => false,
                'error' => $result['error'],
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear storage by type', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to clear {type} storage', ['type' => $type]),
            ]);
        }
    }

    /**
     * Get storage statistics for all backend types
     */
    public function actionGetStorageStats(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $projection = SearchManager::$plugin->storageMaintenance->getProjection();

            return $this->asJson([
                'success' => true,
                'stats' => $projection['stats'],
                'storageOptions' => $projection['storageOptions'],
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage stats', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to get storage statistics'),
            ]);
        }
    }

    /**
     * Clear ALL database storage (MySQL or PostgreSQL)
     */
    private function clearDatabaseStorage(): array
    {
        $tables = SearchManager::$plugin->storageMaintenance->databaseStorageTables();
        $db = Craft::$app->getDb();
        $driverName = $db->getDriverName();
        $driverLabel = $driverName === 'pgsql' ? 'PostgreSQL' : 'MySQL';
        $deletedRows = 0;

        foreach ($tables as $table) {
            $tableName = $db->getSchema()->getRawTableName($table);
            if ($db->getTableSchema($tableName) === null) {
                continue;
            }

            $count = $db->createCommand()->delete($table)->execute();
            $deletedRows += $count;
        }

        // Reset documentCount for all database-backed indices (mysql or pgsql)
        $this->resetIndexDocumentCounts('database');

        return [
            'success' => true,
            'message' => Craft::t('search-manager', '{driver} storage cleared successfully ({count} rows deleted). Rebuild affected indices to re-index your content.', [
                'driver' => $driverLabel,
                'count' => number_format($deletedRows),
            ]),
            'deletedRows' => $deletedRows,
        ];
    }

    /**
     * Clear ALL Redis storage
     */
    private function clearRedisStorage(): array
    {
        if (!class_exists('\Redis')) {
            return [
                'success' => false,
                'error' => Craft::t('search-manager', 'Redis extension is not installed.'),
            ];
        }

        $storageMaintenance = SearchManager::$plugin->storageMaintenance;
        $config = $storageMaintenance->getRedisConfig();

        if (empty($config['host'])) {
            return [
                'success' => false,
                'error' => Craft::t('search-manager', 'Redis is not configured.'),
            ];
        }

        try {
            $redis = $storageMaintenance->connectRedis($config);

            // Find all Search Manager keys without blocking Redis like KEYS does.
            $keys = $storageMaintenance->scanRedisKeys($redis, 'sm:idx:*');
            $deletedKeys = 0;

            if (!empty($keys)) {
                $deletedKeys = count($keys);
                $redis->del($keys);
            }

            // Reset documentCount for all Redis-backed indices
            $this->resetIndexDocumentCounts('redis');

            return [
                'success' => true,
                'message' => Craft::t('search-manager', 'Redis storage cleared successfully ({count} keys deleted). Rebuild affected indices to re-index your content.', [
                    'count' => number_format($deletedKeys),
                ]),
                'deletedKeys' => $deletedKeys,
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Redis storage', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to clear {type} storage', ['type' => 'Redis']),
            ];
        }
    }

    /**
     * Clear ALL file storage
     */
    private function clearFileStorage(): array
    {
        $deletedFilesTotal = 0;

        foreach (FileBackendStoragePathHelper::configuredBasePaths() as $indicesPath) {
            if (!is_dir($indicesPath)) {
                continue;
            }

            $fileCount = SearchManager::$plugin->storageMaintenance->countFilesInDirectory($indicesPath);
            FileHelper::removeDirectory($indicesPath);

            $deletedFilesTotal += $fileCount;
        }

        // Reset documentCount even when storage was already empty: stale
        // metadata is still part of the clear operation's state.
        $this->resetIndexDocumentCounts('file');

        if ($deletedFilesTotal === 0) {
            return [
                'success' => true,
                'message' => Craft::t('search-manager', 'File storage is already empty.'),
                'deletedFiles' => 0,
            ];
        }

        return [
            'success' => true,
            'message' => Craft::t('search-manager', 'File storage cleared successfully ({count} files deleted). Rebuild affected indices to re-index your content.', [
                'count' => number_format($deletedFilesTotal),
            ]),
            'deletedFiles' => $deletedFilesTotal,
        ];
    }

    /**
     * Reset documentCount to 0 for all indices using a specific backend type
     *
     * @param string $backendType Backend type (database, redis, file)
     */
    private function resetIndexDocumentCounts(string $backendType): void
    {
        $indices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
        $settings = SearchManager::$plugin->getSettings();
        $defaultBackendHandle = $settings->defaultBackendHandle ?? '';

        // For 'database', match both mysql and pgsql backend types
        $typesToMatch = $backendType === 'database' ? ['mysql', 'pgsql'] : [$backendType];

        foreach ($indices as $index) {
            $indexBackendType = $index->effectiveBackendType ?? $this->getBackendTypeFromHandle($defaultBackendHandle);

            if (in_array($indexBackendType, $typesToMatch, true)) {
                $index->updateStats(0);
                $this->logDebug('Reset documentCount for index', [
                    'index' => $index->handle,
                    'backendType' => $indexBackendType,
                ]);
            }
        }
    }

    /**
     * Get backend type from a configured backend handle
     *
     * @param string $handle Backend handle
     * @return string Backend type (mysql, redis, file, etc.)
     */
    private function getBackendTypeFromHandle(string $handle): string
    {
        if (empty($handle)) {
            return 'mysql';
        }

        $configuredBackend = SearchManager::$plugin->getConfiguredBackend($handle);
        if ($configuredBackend) {
            return $configuredBackend->backendType ?: 'mysql';
        }

        return 'mysql';
    }

    /**
     * Get list of handles that exist in both config and database
     *
     * @return array List of colliding handles
     */
    private function getHandleCollisions(): array
    {
        // Use the same operational config-index projection as findAll(), so
        // malformed items cannot create phantom collision blockers.
        $configHandles = array_map(
            static fn(\lindemannrock\searchmanager\models\SearchIndex $index): string => $index->handle,
            \lindemannrock\searchmanager\models\SearchIndex::loadFromConfig(),
        );

        if (empty($configHandles)) {
            return [];
        }

        // Get handles from database that are marked as 'database' source
        $dbHandles = Craft::$app->getDb()
            ->createCommand()
            ->setSql('SELECT handle FROM {{%searchmanager_indices}} WHERE source = :source')
            ->bindValue(':source', 'database')
            ->queryColumn();

        // Find collisions
        return array_intersect($configHandles, $dbHandles);
    }
}
