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
 * @since 5.0.0
 */
class MaintenanceController extends Controller
{
    use LoggingTrait;

    /**
     * @var string Backend storage type to clear (mysql, redis, file)
     */
    public string $type = '';

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
     *
     * @since 5.0.0
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
     *
     * @since 5.0.0
     */
    public function actionStatus(): int
    {
        $this->stdout("Search Manager - Storage Status\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        // Database Status (MySQL or PostgreSQL)
        $driverLabel = $this->getDatabaseDriverLabel();
        $this->stdout("{$driverLabel} Storage:\n", Console::FG_GREEN);
        try {
            $dbStats = $this->getDatabaseStats();
            $this->stdout("  Documents: {$dbStats['documentRows']}\n");
            $this->stdout("  Terms: {$dbStats['termRows']}\n");
            $this->stdout("  Unique Index Handles: " . implode(', ', $dbStats['indexHandles'] ?: ['(none)']) . "\n");
        } catch (\Throwable $e) {
            $this->stdout("  Status: Error - {$e->getMessage()}\n", Console::FG_RED);
        }

        $this->stdout("\n");

        // Redis Status
        $this->stdout("Redis Storage:\n", Console::FG_GREEN);
        try {
            $settings = SearchManager::$plugin->getSettings();
            $redisConfig = $this->getRedisConfig($settings);

            if (!class_exists('\Redis')) {
                $this->stdout("  Status: Redis extension not installed\n", Console::FG_YELLOW);
            } elseif (empty($redisConfig['host'])) {
                $this->stdout("  Status: Not configured\n", Console::FG_YELLOW);
            } else {
                $redisStats = $this->getRedisStats($redisConfig);
                $this->stdout("  Status: {$redisStats['status']}\n");
                if ($redisStats['status'] === 'connected') {
                    $this->stdout("  Search Manager Keys: {$redisStats['keyCount']}\n");
                }
            }
        } catch (\Throwable $e) {
            $this->stdout("  Status: Error - {$e->getMessage()}\n", Console::FG_RED);
        }

        $this->stdout("\n");

        // File Storage Status
        $this->stdout("File Storage:\n", Console::FG_GREEN);
        try {
            $fileStats = $this->getFileStats();
            $this->stdout("  Index Directories: {$fileStats['indexCount']}\n");
            $this->stdout("  Total Files: {$fileStats['fileCount']}\n");
            $this->stdout("  Path: {$fileStats['path']}\n");
        } catch (\Throwable $e) {
            $this->stdout("  Status: Error - {$e->getMessage()}\n", Console::FG_RED);
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

        $settings = SearchManager::$plugin->getSettings();
        $config = $this->getRedisConfig($settings);

        if (empty($config['host'])) {
            return ['success' => false, 'error' => 'Redis is not configured'];
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
            $pattern = 'sm:idx:*';
            $keys = $redis->keys($pattern);
            $deletedKeys = 0;

            if (!empty($keys)) {
                $deletedKeys = count($keys);
                $redis->del($keys);
            }

            $this->stdout("  Deleted {$deletedKeys} Redis keys matching '{$pattern}'\n");

            return [
                'success' => true,
                'message' => "Redis storage cleared successfully ({$deletedKeys} keys deleted). Rebuild affected indices to re-index your content.",
                'deletedKeys' => $deletedKeys,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Redis connection failed: ' . $e->getMessage()];
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
                'message' => 'File storage is already empty (directory does not exist)',
                'deletedFiles' => 0,
            ];
        }

        // Count files before deletion
        $fileCount = $this->countFilesInDirectory($indicesPath);

        // Delete the entire indices directory
        FileHelper::removeDirectory($indicesPath);

        $this->stdout("  Deleted {$fileCount} files from {$indicesPath}\n");

        return [
            'success' => true,
            'message' => "File storage cleared successfully ({$fileCount} files deleted). Rebuild affected indices to re-index your content.",
            'deletedFiles' => $fileCount,
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
            'status' => 'connected',
            'keyCount' => $keyCount,
        ];
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
     * Get Redis configuration from settings
     */
    private function getRedisConfig($settings): array
    {
        // Try to get from config file first
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (file_exists($configPath)) {
            $config = require $configPath;
            $env = Craft::$app->getConfig()->env;

            $mergedConfig = $config['*'] ?? [];
            if ($env && isset($config[$env])) {
                $mergedConfig = array_merge($mergedConfig, $config[$env]);
            }

            if (isset($mergedConfig['backends']['redis'])) {
                return $mergedConfig['backends']['redis'];
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
