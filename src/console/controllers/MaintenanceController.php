<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\FileBackendStoragePathHelper;
use lindemannrock\searchmanager\helpers\RedisConnectionHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\PostgreSqlStorage;
use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;
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
     * @var bool Preview orphaned storage handles without deleting data
     */
    public bool $dryRun = false;
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
        if ($actionID === 'purge-orphaned-storage') {
            $options[] = 'type';
            $options[] = 'dryRun';
        }
        if ($actionID === 'status') {
            $options[] = 'verbose';
        }

        return $options;
    }

    /**
     * Map CLI option names with hyphens to their PHP property camelCase forms.
     */
    public function optionAliases(): array
    {
        return [
            'dry-run' => 'dryRun',
        ];
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
     * Purge storage for prefixed handles that no longer have a live index.
     *
     * @since 5.53.0
     */
    public function actionPurgeOrphanedStorage(): int
    {
        $this->stdout("Search Manager - Purge Orphaned Storage\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        $validTypes = ['all', 'database', 'redis', 'file'];
        $type = strtolower($this->type ?: 'all');

        if (!in_array($type, $validTypes, true)) {
            $this->stderr("Error: Invalid type '{$this->type}'\n", Console::FG_RED);
            $this->stdout("Valid types: " . implode(', ', $validTypes) . "\n");
            return ExitCode::USAGE;
        }

        $types = $type === 'all' ? ['database', 'redis', 'file'] : [$type];
        $plan = [];
        foreach ($types as $storageType) {
            $plan[$storageType] = $this->getOrphanedStorageHandlesByType($storageType);
        }

        $totalCandidates = array_sum(array_map('count', $plan));
        if ($totalCandidates === 0) {
            $this->stdout("No orphaned storage handles found for this environment prefix.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        foreach ($plan as $storageType => $handles) {
            if ($handles === []) {
                continue;
            }

            $this->stdout(ucfirst($storageType) . " orphaned handles:\n", Console::FG_YELLOW);
            foreach ($handles as $handle) {
                $this->stdout("  - {$handle}\n");
            }
            $this->stdout("\n");
        }

        if ($this->dryRun) {
            $this->stdout("Dry run only. No storage data was deleted.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->stdout("WARNING: This will delete all storage data for the handles listed above.\n", Console::FG_YELLOW);
        $this->stdout("Only handles carrying this environment's configured prefix are eligible.\n\n");

        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $deleted = 0;
        foreach ($plan as $storageType => $handles) {
            foreach ($handles as $handle) {
                try {
                    $this->clearOrphanedStorageHandle($storageType, $handle);
                    $deleted++;
                    $this->stdout("  Purged {$storageType}: {$handle}\n", Console::FG_GREEN);
                } catch (\Throwable $e) {
                    $this->stderr("  Failed {$storageType}: {$handle} ({$e->getMessage()})\n", Console::FG_RED);
                    $this->logError('Failed to purge orphaned storage handle', [
                        'type' => $storageType,
                        'handle' => $handle,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->stdout("\nPurged {$deleted} orphaned storage handle(s). Rebuild affected indices if needed.\n", Console::FG_GREEN);

        return ExitCode::OK;
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
            $this->stdout("  Compounds: {$dbStats['compoundRows']}\n");
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
        $db = Craft::$app->getDb();
        $driverLabel = $this->getDatabaseDriverLabel();
        $deletedRows = 0;

        foreach ($this->databaseStorageTables() as $table) {
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

                $keys = $this->scanRedisKeys($redis, $pattern);
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
        $deletedFilesTotal = 0;

        foreach (FileBackendStoragePathHelper::configuredBasePaths() as $indicesPath) {
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
     * @return array<int, string>
     */
    private function getOrphanedStorageHandlesByType(string $type): array
    {
        $storageHandles = match ($type) {
            'database' => $this->getDatabaseStorageHandles(),
            'redis' => $this->getRedisStorageHandles(),
            'file' => $this->getFileStorageHandles(),
            default => [],
        };

        $liveFullIndexNames = array_fill_keys($this->getLiveFullIndexNames(), true);
        $orphaned = [];

        foreach ($storageHandles as $handle) {
            if (isset($liveFullIndexNames[$handle])) {
                continue;
            }
            if (!$this->isCurrentEnvironmentStorageHandle($handle)) {
                continue;
            }
            $orphaned[] = $handle;
        }

        $orphaned = array_values(array_unique($orphaned));
        sort($orphaned, SORT_STRING);

        return $orphaned;
    }

    /**
     * @return array<int, string>
     */
    private function getLiveFullIndexNames(): array
    {
        $settings = SearchManager::$plugin->getSettings();
        $handles = [];

        foreach (SearchIndex::findAll() as $index) {
            $handles[] = $settings->getFullIndexName($index->handle);
        }

        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }

    private function isCurrentEnvironmentStorageHandle(string $storageHandle): bool
    {
        $prefix = SearchManager::$plugin->getSettings()->getFullIndexName('');

        return $prefix === '' || str_starts_with($storageHandle, $prefix);
    }

    /**
     * @return array<int, string>
     */
    private function getDatabaseStorageHandles(): array
    {
        $db = Craft::$app->getDb();
        $handles = [];

        foreach ($this->databaseStorageTables() as $table) {
            $tableName = $db->getSchema()->getRawTableName($table);
            if ($db->getTableSchema($tableName) === null) {
                continue;
            }

            $rows = (new \craft\db\Query())
                ->select(['indexHandle'])
                ->distinct()
                ->from($table)
                ->column();
            foreach ($rows as $row) {
                $handles[] = (string)$row;
            }
        }

        $handles = array_values(array_unique(array_filter($handles)));
        sort($handles, SORT_STRING);

        return $handles;
    }

    /**
     * @return array<int, string>
     */
    private function getRedisStorageHandles(): array
    {
        if (!class_exists('\Redis')) {
            return [];
        }

        $handles = [];
        foreach ($this->getResolvedRedisTargets() as $target) {
            $redis = new \Redis();
            try {
                $redis->connect($target['host'], $target['port']);
                if ($target['password']) {
                    $redis->auth($target['password']);
                }
                $redis->select($target['database']);

                foreach ($this->scanRedisKeys($redis, 'sm:idx:*') as $key) {
                    $handle = $this->storageHandleFromRedisKey($key);
                    if ($handle !== null) {
                        $handles[] = $handle;
                    }
                }
            } catch (\Throwable $e) {
                $this->logWarning('Failed to scan Redis storage handles', [
                    'target' => $target['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }

    /**
     * @return array<int, array{key: string, host: string, port: int, password: mixed, database: int, settings: array<string, mixed>}>
     */
    private function getResolvedRedisTargets(): array
    {
        $targets = [];
        $seen = [];

        foreach (ConfiguredBackend::findAll() as $backend) {
            if ($backend->backendType !== 'redis') {
                continue;
            }

            $config = $this->getResolvedRedisConfig($backend);
            if (empty($config['host'])) {
                continue;
            }

            $host = (string)$this->resolveEnvVar($config['host'], '127.0.0.1');
            $port = (int)$this->resolveEnvVar($config['port'], 6379);
            $password = $this->resolveEnvVar($config['password'], null);
            $database = (int)$this->resolveEnvVar($config['database'], 0);
            $key = "{$host}:{$port}:{$database}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $targets[] = [
                'key' => $key,
                'host' => $host,
                'port' => $port,
                'password' => $password,
                'database' => $database,
                'settings' => $config,
            ];
        }

        return $targets;
    }

    private function storageHandleFromRedisKey(string $key): ?string
    {
        $prefix = 'sm:idx:';
        if (!str_starts_with($key, $prefix)) {
            return null;
        }

        $remainder = substr($key, strlen($prefix));
        $separator = strpos($remainder, ':');
        if ($separator === false) {
            return null;
        }

        $handle = substr($remainder, 0, $separator);

        return $handle !== '' ? $handle : null;
    }

    /**
     * @return array<int, string>
     */
    private function getFileStorageHandles(): array
    {
        $handles = [];

        foreach ($this->getFileStorageTargets() as $target) {
            $indicesPath = $target['basePath'];
            if (!is_dir($indicesPath)) {
                continue;
            }

            foreach (glob($indicesPath . '/*', GLOB_ONLYDIR) ?: [] as $indexPath) {
                $handle = basename($indexPath);
                if ($handle !== '') {
                    $handles[] = $handle;
                }
            }
        }

        $handles = array_values(array_unique($handles));
        sort($handles, SORT_STRING);

        return $handles;
    }

    /**
     * @return array<int, array{basePath: string, configuredPath: string|null}>
     */
    private function getFileStorageTargets(): array
    {
        $targets = [[
            'basePath' => FileBackendStoragePathHelper::defaultBasePath(),
            'configuredPath' => null,
        ]];
        $seen = [FileBackendStoragePathHelper::defaultBasePath() => true];

        foreach (ConfiguredBackend::findAll() as $backend) {
            if ($backend->backendType !== 'file') {
                continue;
            }

            $configuredPathValue = $backend->settings['storagePath'] ?? null;
            $configuredPath = is_string($configuredPathValue) ? $configuredPathValue : null;
            try {
                $basePath = FileBackendStoragePathHelper::resolve($configuredPath);
            } catch (\InvalidArgumentException $e) {
                $this->logWarning('Skipping invalid file backend storage path', [
                    'backend' => $backend->handle,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (isset($seen[$basePath])) {
                continue;
            }

            $seen[$basePath] = true;
            $targets[] = [
                'basePath' => $basePath,
                'configuredPath' => is_string($configuredPath) && $configuredPath !== '' ? $configuredPath : null,
            ];
        }

        return $targets;
    }

    private function clearOrphanedStorageHandle(string $type, string $fullIndexHandle): void
    {
        switch ($type) {
            case 'database':
                $this->createDatabaseStorage($fullIndexHandle)->clearAll();
                return;
            case 'redis':
                foreach ($this->getResolvedRedisTargets() as $target) {
                    (new RedisStorage($fullIndexHandle, $target['settings']))->clearAll();
                }
                return;
            case 'file':
                foreach ($this->getFileStorageTargets() as $target) {
                    (new FileStorage($fullIndexHandle, $target['configuredPath']))->clearAll();
                }
                return;
        }
    }

    private function createDatabaseStorage(string $fullIndexHandle): StorageInterface
    {
        return Craft::$app->getDb()->getDriverName() === 'pgsql'
            ? new PostgreSqlStorage($fullIndexHandle)
            : new MySqlStorage($fullIndexHandle);
    }

    /**
     * @return array<int, string>
     */
    private function databaseStorageTables(): array
    {
        return [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
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

        $compoundRows = (int)$db->createCommand(
            'SELECT COUNT(*) FROM {{%searchmanager_search_compounds}}'
        )->queryScalar();

        $indexHandles = $db->createCommand(
            'SELECT DISTINCT [[indexHandle]] FROM (
                SELECT [[indexHandle]] FROM {{%searchmanager_search_documents}}
                UNION
                SELECT [[indexHandle]] FROM {{%searchmanager_search_compounds}}
            ) storage_index_handles
            ORDER BY [[indexHandle]]'
        )->queryColumn();

        return [
            'documentRows' => $documentRows,
            'termRows' => $termRows,
            'compoundRows' => $compoundRows,
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

        $keys = $this->scanRedisKeys($redis, 'sm:idx:*');

        return [
            'status' => 'connected',
            'keyCount' => count($keys),
        ];
    }

    /**
     * Get file storage statistics for a backend
     */
    private function getFileStatsForBackend(\lindemannrock\searchmanager\models\ConfiguredBackend $backend): array
    {
        $settings = $backend->settings ?? [];
        $customPath = $settings['storagePath'] ?? null;

        $indicesPath = FileBackendStoragePathHelper::resolve($customPath);

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
        return RedisConnectionHelper::storageSettings($backend->settings ?? []);
    }

    /**
     * @return array<int, string>
     */
    private function scanRedisKeys(\Redis $redis, string $pattern, int $count = 1000): array
    {
        $keys = [];
        $iterator = null;

        do {
            $batch = $redis->scan($iterator, $pattern, $count);
            if ($batch !== false) {
                foreach ($batch as $key) {
                    $keys[] = (string)$key;
                }
            }
        } while ((int)$iterator > 0);

        return $keys;
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
