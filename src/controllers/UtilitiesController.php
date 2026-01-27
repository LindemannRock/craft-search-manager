<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\helpers\App;
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
            case 'clear-storage-by-type':
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
     * Clear device detection cache
     */
    public function actionClearDeviceCache(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $settings = SearchManager::$plugin->getSettings();
            $cache = Craft::$app->cache;
            $useRedis = $settings->cacheStorageMethod === 'redis' && $cache instanceof \yii\redis\Cache;

            if ($settings->cacheStorageMethod === 'redis' && !$useRedis) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file clear', [
                    'cacheClass' => get_class($cache),
                ]);
            }

            if ($useRedis) {
                $redis = $cache->redis;

                // Get all device cache keys from tracking set
                $keys = $redis->executeCommand('SMEMBERS', ['searchmanager-device-keys']) ?: [];

                // Delete device cache keys
                foreach ($keys as $key) {
                    $cache->delete($key);
                }

                // Clear the tracking set
                $redis->executeCommand('DEL', ['searchmanager-device-keys']);

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
            $cache = Craft::$app->cache;
            $useRedis = $settings->cacheStorageMethod === 'redis' && $cache instanceof \yii\redis\Cache;

            if ($settings->cacheStorageMethod === 'redis' && !$useRedis) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file clear', [
                    'cacheClass' => get_class($cache),
                ]);
            }

            if ($useRedis) {
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

            $cache = Craft::$app->cache;
            $useRedis = $settings->cacheStorageMethod === 'redis' && $cache instanceof \yii\redis\Cache;

            if ($settings->cacheStorageMethod === 'redis' && !$useRedis) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file clear', [
                    'cacheClass' => get_class($cache),
                ]);
            }

            if ($useRedis) {
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

        if (!in_array($type, $validTypes)) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Invalid storage type: {type}', ['type' => $type]),
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
                'error' => Craft::t('search-manager', 'Failed to clear {type} storage.', ['type' => $type]),
            ]);
        }
    }

    /**
     * Get storage statistics for all backend types
     */
    public function actionGetStorageStats(): Response
    {
        $this->requireAcceptsJson();

        try {
            $stats = [
                'database' => $this->getDatabaseStats(),
                'redis' => $this->getRedisStats(),
                'file' => $this->getFileStats(),
            ];

            return $this->asJson([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage stats', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to get storage statistics.'),
            ]);
        }
    }

    /**
     * Clear ALL database storage (MySQL or PostgreSQL)
     */
    private function clearDatabaseStorage(): array
    {
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
        ];

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

        $config = $this->getRedisConfig();

        if (empty($config['host'])) {
            return [
                'success' => false,
                'error' => Craft::t('search-manager', 'Redis is not configured.'),
            ];
        }

        $redis = new \Redis();

        try {
            $host = $this->resolveEnvVar($config['host'], '127.0.0.1');
            $port = (int)$this->resolveEnvVar($config['port'], 6379);
            $password = $this->resolveEnvVar($config['password'], null);
            $database = (int)$this->resolveEnvVar($config['database'], 0);

            $redis->connect($host, $port);

            if ($password) {
                $redis->auth($password);
            }

            $redis->select($database);

            // Find all Search Manager keys (pattern: sm:idx:*)
            $keys = $redis->keys('sm:idx:*');
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
            return [
                'success' => false,
                'error' => Craft::t('search-manager', 'Redis connection failed: {error}', ['error' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Clear ALL file storage
     */
    private function clearFileStorage(): array
    {
        $runtimePath = Craft::$app->getPath()->getRuntimePath();
        $indicesPath = $runtimePath . '/search-manager/indices';

        if (!is_dir($indicesPath)) {
            return [
                'success' => true,
                'message' => Craft::t('search-manager', 'File storage is already empty.'),
                'deletedFiles' => 0,
            ];
        }

        $fileCount = $this->countFilesInDirectory($indicesPath);

        FileHelper::removeDirectory($indicesPath);

        // Reset documentCount for all File-backed indices
        $this->resetIndexDocumentCounts('file');

        return [
            'success' => true,
            'message' => Craft::t('search-manager', 'File storage cleared successfully ({count} files deleted). Rebuild affected indices to re-index your content.', [
                'count' => number_format($fileCount),
            ]),
            'deletedFiles' => $fileCount,
        ];
    }

    /**
     * Get database storage statistics (MySQL or PostgreSQL)
     */
    private function getDatabaseStats(): array
    {
        try {
            $db = Craft::$app->getDb();
            $driverName = $db->getDriverName();
            $driverLabel = $driverName === 'pgsql' ? 'PostgreSQL' : 'MySQL';

            $documentRows = (int)$db->createCommand(
                'SELECT COUNT(*) FROM {{%searchmanager_search_documents}}'
            )->queryScalar();

            $termRows = (int)$db->createCommand(
                'SELECT COUNT(*) FROM {{%searchmanager_search_terms}}'
            )->queryScalar();

            $indexHandles = $db->createCommand(
                'SELECT DISTINCT indexHandle FROM {{%searchmanager_search_documents}}'
            )->queryColumn();

            return [
                'available' => true,
                'driver' => $driverName,
                'driverLabel' => $driverLabel,
                'documentRows' => $documentRows,
                'termRows' => $termRows,
                'indexHandles' => $indexHandles,
                'totalRows' => $documentRows + $termRows,
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Redis storage statistics
     */
    private function getRedisStats(): array
    {
        if (!class_exists('\Redis')) {
            return [
                'available' => false,
                'status' => 'extension_not_installed',
            ];
        }

        $config = $this->getRedisConfig();

        if (empty($config['host'])) {
            return [
                'available' => false,
                'status' => 'not_configured',
            ];
        }

        try {
            $redis = new \Redis();

            $host = $this->resolveEnvVar($config['host'], '127.0.0.1');
            $port = (int)$this->resolveEnvVar($config['port'], 6379);
            $password = $this->resolveEnvVar($config['password'], null);
            $database = (int)$this->resolveEnvVar($config['database'], 0);

            $redis->connect($host, $port);

            if ($password) {
                $redis->auth($password);
            }

            $redis->select($database);

            $keys = $redis->keys('sm:idx:*');
            $keyCount = is_array($keys) ? count($keys) : 0;

            return [
                'available' => true,
                'status' => 'connected',
                'keyCount' => $keyCount,
            ];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'status' => 'connection_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get file storage statistics
     */
    private function getFileStats(): array
    {
        $runtimePath = Craft::$app->getPath()->getRuntimePath();
        $indicesPath = $runtimePath . '/search-manager/indices';

        if (!is_dir($indicesPath)) {
            return [
                'available' => true,
                'indexCount' => 0,
                'fileCount' => 0,
            ];
        }

        $indexDirs = glob($indicesPath . '/*', GLOB_ONLYDIR);
        $indexCount = count($indexDirs ?: []);
        $fileCount = $this->countFilesInDirectory($indicesPath);

        return [
            'available' => true,
            'indexCount' => $indexCount,
            'fileCount' => $fileCount,
        ];
    }

    /**
     * Get Redis configuration from settings
     * Looks for any configured backend with backendType 'redis'
     */
    private function getRedisConfig(): array
    {
        // First try to get from ConfiguredBackend model (handles both config and database)
        $backends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();

        foreach ($backends as $backend) {
            if ($backend->backendType === 'redis' && $backend->enabled) {
                return $backend->settings ?? [];
            }
        }

        // Fallback: try to read directly from config file
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (file_exists($configPath)) {
            $config = require $configPath;
            $env = Craft::$app->getConfig()->env;

            $mergedConfig = $config['*'] ?? [];
            if ($env && isset($config[$env])) {
                $mergedConfig = array_merge($mergedConfig, $config[$env]);
            }

            // Check backends for any redis backend
            if (isset($mergedConfig['backends'])) {
                foreach ($mergedConfig['backends'] as $backendConfig) {
                    if (($backendConfig['backendType'] ?? '') === 'redis' && ($backendConfig['enabled'] ?? false)) {
                        return $backendConfig['settings'] ?? [];
                    }
                }
            }
        }

        return [];
    }

    /**
     * Count files recursively in a directory
     */
    private function countFilesInDirectory(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Resolve environment variable
     *
     * @param mixed $value Config value
     * @param mixed $default Default value
     * @return mixed Resolved value
     */
    private function resolveEnvVar(mixed $value, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            return App::env($envVarName) ?? $default;
        }

        return $value;
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

            if (in_array($indexBackendType, $typesToMatch)) {
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
}
