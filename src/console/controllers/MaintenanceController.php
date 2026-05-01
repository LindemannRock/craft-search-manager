<?php

namespace lindemannrock\searchmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\console\ExitCode;

/**
 * Maintenance commands for search storage
 *
 * @since 5.30.0
 */
class MaintenanceController extends Controller
{
    use LoggingTrait;

    /**
     * @var string Backend storage type to clear (mysql, redis, file)
     */
    public string $type = '';
    /**
     * @var bool Show verbose backend details (like indices list/count)
     */
    public bool $verbose = false;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'clear-storage') {
            $options[] = 'type';
        }
        if ($actionID === 'status') {
            $options[] = 'verbose';
        }

        return $options;
    }

    /**
     * Clear ALL data from a specific backend storage type
     *
     * This is a destructive operation that clears ALL indexed data from the
     * specified backend type (mysql, redis, or file) regardless of which
     * indices currently use that backend.
     *
     * Use this when:
     * - Orphaned data exists from backend changes
     * - You need to completely reset a storage type
     * - Troubleshooting storage issues
     *
     * Example: php craft search-manager/maintenance/clear-storage --type=database
     */
    public function actionClearStorage(): int
    {
        $this->stdout("Search Manager - Clear Storage by Type\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        $validTypes = ['database', 'redis', 'file'];

        if (empty($this->type)) {
            $this->stderr("Error: --type option is required\n", Console::FG_RED);
            $this->stdout("Valid types: " . implode(', ', $validTypes) . "\n");
            $this->stdout("\nUsage: php craft search-manager/maintenance/clear-storage --type=database\n");
            return ExitCode::USAGE;
        }

        $type = strtolower($this->type);

        if (!in_array($type, $validTypes)) {
            $this->stderr("Error: Invalid type '{$this->type}'\n", Console::FG_RED);
            $this->stdout("Valid types: " . implode(', ', $validTypes) . "\n");
            return ExitCode::USAGE;
        }

        $driverLabel = $type === 'database' ? $this->getDatabaseDriverLabel() : $type;
        $this->stdout("WARNING: This will delete ALL data from {$driverLabel} storage!\n", Console::FG_YELLOW);
        $this->stdout("This includes data from ALL indices that have ever used this storage.\n");
        $this->stdout("This action cannot be undone.\n\n");

        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        try {
            $result = $this->clearStorageByType($type);

            if ($result['success']) {
                $this->stdout("\n✓ {$result['message']}\n", Console::FG_GREEN);
                $this->logInfo("Storage cleared via CLI", [
                    'type' => $type,
                    'details' => $result,
                ]);
                return ExitCode::OK;
            }

            $this->stderr("\n✗ {$result['error']}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        } catch (\Throwable $e) {
            $this->stderr("\n✗ Error: {$e->getMessage()}\n", Console::FG_RED);
            $this->logError("Failed to clear storage via CLI", [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }

    /**
     * List available storage types and their current state
     */
    public function actionStatus(): int
    {
        $this->stdout("Search Manager - Storage Status\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        $configuredBackends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
        $redisBackends = array_values(array_filter($configuredBackends, fn($b) => $b->backendType === 'redis'));
        $fileBackends = array_values(array_filter($configuredBackends, fn($b) => $b->backendType === 'file'));
        $dbBackends = array_values(array_filter($configuredBackends, fn($b) => in_array($b->backendType, ['mysql', 'pgsql'], true)));
        $externalBackends = array_values(array_filter($configuredBackends, fn($b) => in_array($b->backendType, ['algolia', 'meilisearch', 'typesense'], true)));

        // Database Status (MySQL or PostgreSQL)
        $driverLabel = $this->getDatabaseDriverLabel();
        $this->stdout("{$driverLabel} Storage:\n", Console::FG_GREEN);
        try {
            $dbStats = $this->getDatabaseStats();
            $this->stdout("  Documents: {$dbStats['documentRows']}\n");
            $this->stdout("  Terms: {$dbStats['termRows']}\n");
            $indexHandles = $dbStats['indexHandles'] ?: [];
            if ($this->verbose) {
                $this->stdout("  Unique Index Handles: " . count($indexHandles) . "\n");
                foreach ($indexHandles as $handle) {
                    $this->stdout("    - {$handle}\n");
                }
            } else {
                $this->stdout("  Unique Index Handles: " . count($indexHandles) . " (use --verbose to list)\n");
            }
            if (!empty($dbBackends)) {
                if ($this->verbose) {
                    $this->stdout("  Configured Backends: " . count($dbBackends) . "\n");
                    foreach ($dbBackends as $backend) {
                        $label = $backend->handle . ($backend->enabled ? '' : ' (disabled)');
                        $this->stdout("    - {$label}\n");
                    }
                } else {
                    $this->stdout("  Configured Backends: " . count($dbBackends) . " (use --verbose to list)\n");
                }
            }
        } catch (\Throwable $e) {
            $this->stdout("  Status: Error - {$e->getMessage()}\n", Console::FG_RED);
        }

        $this->stdout("\n");

        // Redis Status (all configured redis backends)
        $this->stdout("Redis Storage:\n", Console::FG_GREEN);
        if (!class_exists('\Redis')) {
            $this->stdout("  Status: Redis extension not installed\n", Console::FG_YELLOW);
        } elseif (empty($redisBackends)) {
            $this->stdout("  Status: Not configured\n", Console::FG_YELLOW);
        } else {
            foreach ($redisBackends as $backend) {
                try {
                    $resolved = $this->getResolvedRedisConfig($backend);
                    if (empty($resolved['host'])) {
                        $this->stdout("  {$backend->handle}: Not configured\n", Console::FG_YELLOW);
                        continue;
                    }

                    $redisStats = $this->getRedisStats($resolved);
                    $label = "{$backend->handle} (" . ($backend->enabled ? 'enabled' : 'disabled') . ")";
                    $this->stdout("  {$label}:\n");
                    $this->stdout("    Status: {$redisStats['status']}\n");
                    $this->stdout("    Host: {$resolved['host']}:{$resolved['port']} / DB {$resolved['database']}\n");
                    if ($redisStats['status'] === 'connected') {
                        if ($this->verbose) {
                            $this->stdout("    Search Manager Keys: {$redisStats['keyCount']}\n");
                        } else {
                            $this->stdout("    Search Manager Keys: use --verbose to show\n");
                        }
                    }
                } catch (\Throwable $e) {
                    $this->stdout("  {$backend->handle}: Error - {$e->getMessage()}\n", Console::FG_RED);
                }
            }
        }

        $this->stdout("\n");

        // File Storage Status (all configured file backends)
        $this->stdout("File Storage:\n", Console::FG_GREEN);
        if (empty($fileBackends)) {
            $this->stdout("  Status: Not configured\n", Console::FG_YELLOW);
        } else {
            foreach ($fileBackends as $backend) {
                try {
                    $fileStats = $this->getFileStatsForBackend($backend);
                    $label = "{$backend->handle} (" . ($backend->enabled ? 'enabled' : 'disabled') . ")";
                    $this->stdout("  {$label}:\n");
                    $this->stdout("    Index Directories: {$fileStats['indexCount']}\n");
                    $this->stdout("    Total Files: {$fileStats['fileCount']}\n");
                    $this->stdout("    Path: {$fileStats['path']}\n");
                } catch (\Throwable $e) {
                    $this->stdout("  {$backend->handle}: Error - {$e->getMessage()}\n", Console::FG_RED);
                }
            }
        }

        if (!empty($externalBackends)) {
            $this->stdout("\nExternal Backends:\n", Console::FG_GREEN);
            foreach ($externalBackends as $backend) {
                $label = "{$backend->handle} ({$backend->backendType})" . ($backend->enabled ? '' : ' (disabled)');
                $this->stdout("  {$label}:\n");

                $adapter = SearchManager::$plugin->backend->getBackend($backend->backendType);
                if (!$adapter) {
                    $this->stdout("    Status: Unknown backend type\n", Console::FG_YELLOW);
                    continue;
                }

                // Apply configured settings and handle
                $adapter->setConfiguredSettings($backend->settings);
                $adapter->setBackendHandle($backend->handle);

                try {
                    $available = $adapter->isAvailable();
                    $this->stdout("    Status: " . ($available ? 'Connected' : 'Failed') . "\n");
                } catch (\Throwable $e) {
                    $this->stdout("    Status: Error - {$e->getMessage()}\n", Console::FG_RED);
                    continue;
                }

                try {
                    $this->stdout("    Browse: " . ($adapter->supportsBrowse() ? 'Yes' : 'No') . "\n");
                    $this->stdout("    Multi-Query: " . ($adapter->supportsMultipleQueries() ? 'Yes' : 'No') . "\n");
                } catch (\Throwable $e) {
                    $this->stdout("    Capabilities: Error - {$e->getMessage()}\n", Console::FG_RED);
                }

                if ($this->verbose) {
                    try {
                        $indices = $adapter->listIndices();
                        $count = count($indices);
                        $this->stdout("    Indices: {$count}\n");
                        if (!empty($indices)) {
                            foreach ($indices as $index) {
                                $name = $index['name'] ?? $index['uid'] ?? 'Unknown';
                                $entries = $index['entries'] ?? '—';
                                $this->stdout("      - {$name} ({$entries})\n");
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->stdout("    Indices: Error - {$e->getMessage()}\n", Console::FG_RED);
                    }
                } else {
                    $this->stdout("    Indices: use --verbose to list\n");
                }
            }
        }

        return ExitCode::OK;
    }

    /**
     * Clear storage by type
     *
     * @param string $type Backend type (database, redis, file)
     * @return array Result with success, message, and details
     */
    private function clearStorageByType(string $type): array
    {
        switch ($type) {
            case 'database':
                return $this->clearDatabaseStorage();
            case 'redis':
                return $this->clearRedisStorage();
            case 'file':
                return $this->clearFileStorage();
            default:
                return ['success' => false, 'error' => "Unknown storage type: {$type}"];
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
        $driverLabel = $this->getDatabaseDriverLabel();
        $deletedRows = 0;

        foreach ($tables as $table) {
            // Check if table exists before deleting
            $tableName = $db->getSchema()->getRawTableName($table);
            if ($db->getTableSchema($tableName) === null) {
                continue;
            }

            $count = $db->createCommand()->delete($table)->execute();
            $deletedRows += $count;

            $this->stdout("  Cleared {$tableName}: {$count} rows\n");
        }

        return [
            'success' => true,
            'message' => "{$driverLabel} storage cleared successfully ({$deletedRows} total rows deleted). Rebuild affected indices to re-index your content.",
            'deletedRows' => $deletedRows,
        ];
    }

    /**
     * Clear ALL Redis storage
     */
    private function clearRedisStorage(): array
    {
        if (!class_exists('\Redis')) {
            return ['success' => false, 'error' => 'Redis extension is not installed'];
        }

        $configuredBackends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
        $redisBackends = array_values(array_filter($configuredBackends, fn($b) => $b->backendType === 'redis'));

        if (empty($redisBackends)) {
            return ['success' => false, 'error' => 'Redis is not configured'];
        }

        $pattern = 'sm:idx:*';
        $deletedKeysTotal = 0;
        $seenTargets = [];

        foreach ($redisBackends as $backend) {
            $config = $this->getResolvedRedisConfig($backend);
            if (empty($config['host'])) {
                continue;
            }

            $host = $this->resolveEnvVar($config['host'], '127.0.0.1');
            $port = (int)$this->resolveEnvVar($config['port'], 6379);
            $password = $this->resolveEnvVar($config['password'], null);
            $database = (int)$this->resolveEnvVar($config['database'], 0);

            $targetKey = "{$host}:{$port}:{$database}";
            if (isset($seenTargets[$targetKey])) {
                continue;
            }
            $seenTargets[$targetKey] = true;

            $redis = new \Redis();
            try {
                $redis->connect($host, $port);
                if ($password) {
                    $redis->auth($password);
                }
                $redis->select($database);

                $keys = $redis->keys($pattern);
                $deletedKeys = 0;
                if (!empty($keys)) {
                    $deletedKeys = count($keys);
                    $redis->del($keys);
                }

                $deletedKeysTotal += $deletedKeys;
                $this->stdout("  {$targetKey} → Deleted {$deletedKeys} Redis keys matching '{$pattern}'\n");
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Redis connection failed: ' . $e->getMessage()];
            }
        }

        return [
            'success' => true,
            'message' => "Redis storage cleared successfully ({$deletedKeysTotal} keys deleted). Rebuild affected indices to re-index your content.",
            'deletedKeys' => $deletedKeysTotal,
        ];
    }

    /**
     * Clear ALL file storage
     */
    private function clearFileStorage(): array
    {
        $configuredBackends = \lindemannrock\searchmanager\models\ConfiguredBackend::findAll();
        $fileBackends = array_values(array_filter($configuredBackends, fn($b) => $b->backendType === 'file'));

        if (empty($fileBackends)) {
            return [
                'success' => true,
                'message' => 'File storage is already empty (no file backends configured)',
                'deletedFiles' => 0,
            ];
        }

        $deletedFilesTotal = 0;
        $seenPaths = [];

        foreach ($fileBackends as $backend) {
            $stats = $this->getFileStatsForBackend($backend);
            $indicesPath = $stats['path'];

            if (isset($seenPaths[$indicesPath])) {
                continue;
            }
            $seenPaths[$indicesPath] = true;

            if (!is_dir($indicesPath)) {
                $this->stdout("  {$indicesPath} (missing)\n");
                continue;
            }

            $fileCount = $this->countFilesInDirectory($indicesPath);
            FileHelper::removeDirectory($indicesPath);
            $deletedFilesTotal += $fileCount;

            $this->stdout("  Deleted {$fileCount} files from {$indicesPath}\n");
        }

        return [
            'success' => true,
            'message' => "File storage cleared successfully ({$deletedFilesTotal} files deleted). Rebuild affected indices to re-index your content.",
            'deletedFiles' => $deletedFilesTotal,
        ];
    }

    /**
     * Get database storage statistics (MySQL or PostgreSQL)
     */
    private function getDatabaseStats(): array
    {
        $db = Craft::$app->getDb();

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
            'documentRows' => $documentRows,
            'termRows' => $termRows,
            'indexHandles' => $indexHandles,
        ];
    }

    /**
     * Get Redis storage statistics
     */
    private function getRedisStats(array $config): array
    {
        $redis = new \Redis();

        $host = $this->resolveEnvVar($config['host'] ?? null, '127.0.0.1');
        $port = (int)$this->resolveEnvVar($config['port'] ?? null, 6379);
        $password = $this->resolveEnvVar($config['password'] ?? null, null);
        $database = (int)$this->resolveEnvVar($config['database'] ?? null, 0);

        $redis->connect($host, $port);

        if ($password) {
            $redis->auth($password);
        }

        $redis->select($database);

        $keys = $redis->keys('sm:idx:*');
        $keyCount = is_array($keys) ? count($keys) : 0;

        return [
            'status' => 'connected',
            'keyCount' => $keyCount,
        ];
    }

    /**
     * Get file storage statistics for a backend
     */
    private function getFileStatsForBackend(\lindemannrock\searchmanager\models\ConfiguredBackend $backend): array
    {
        $settings = $backend->settings ?? [];
        $customPath = $settings['storagePath'] ?? null;

        if ($customPath !== null && $customPath !== '') {
            $indicesPath = rtrim(\craft\helpers\App::parseEnv($customPath), '/');
        } else {
            $runtimePath = Craft::$app->getPath()->getRuntimePath();
            $indicesPath = $runtimePath . '/search-manager/indices';
        }

        if (!is_dir($indicesPath)) {
            return [
                'indexCount' => 0,
                'fileCount' => 0,
                'path' => $indicesPath,
            ];
        }

        $indexDirs = glob($indicesPath . '/*', GLOB_ONLYDIR);
        $indexCount = count($indexDirs ?: []);
        $fileCount = $this->countFilesInDirectory($indicesPath);

        return [
            'indexCount' => $indexCount,
            'fileCount' => $fileCount,
            'path' => $indicesPath,
        ];
    }

    /**
     * Get resolved Redis configuration for a backend
     */
    private function getResolvedRedisConfig(\lindemannrock\searchmanager\models\ConfiguredBackend $backend): array
    {
        $settings = $backend->settings ?? [];

        $configuredHost = $this->resolveEnvVar($settings['host'] ?? null, null);
        if (!empty($configuredHost)) {
            return [
                'host' => $configuredHost,
                'port' => $this->resolveEnvVar($settings['port'] ?? null, 6379),
                'password' => $this->resolveEnvVar($settings['password'] ?? null, null),
                'database' => $this->resolveEnvVar($settings['database'] ?? null, 0),
            ];
        }

        if (Craft::$app->cache instanceof \yii\redis\Cache) {
            $redisConnection = Craft::$app->cache->redis;
            $craftDatabase = (int) ($redisConnection->database ?? 0);

            $searchDatabase = isset($settings['database']) && $settings['database'] !== ''
                ? (int) $this->resolveEnvVar($settings['database'], 0)
                : $craftDatabase + 1;

            return [
                'host' => $redisConnection->hostname ?? 'localhost',
                'port' => $redisConnection->port ?? 6379,
                'password' => $redisConnection->password ?? null,
                'database' => $searchDatabase,
            ];
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
     * Get the database driver label (MySQL or PostgreSQL)
     */
    private function getDatabaseDriverLabel(): string
    {
        $driverName = Craft::$app->getDb()->getDriverName();
        return $driverName === 'pgsql' ? 'PostgreSQL' : 'MySQL';
    }

    /**
     * Resolve environment variable
     *
     * @param mixed $value Config value
     * @param mixed $default Default value
     * @return mixed Resolved value
     */
    private function resolveEnvVar($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            return \craft\helpers\App::env($envVarName) ?? $default;
        }

        return $value;
    }
}
