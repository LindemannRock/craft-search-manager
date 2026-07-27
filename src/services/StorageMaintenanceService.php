<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
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

/**
 * Owns storage availability, configured usage, clearable counts, and selector eligibility.
 *
 * @since 5.54.0
 */
class StorageMaintenanceService extends Component
{
    use LoggingTrait;

    private const STORAGE_ORDER = ['database', 'redis', 'file'];

    /**
     * Return every local table owned by the database storage implementations.
     *
     * @return list<string>
     */
    public function databaseStorageTables(): array
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
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Build the shared storage-statistics and eligible-selector projection.
     *
     * @return array{
     *   stats: array<string, array<string, mixed>>,
     *   storageOptions: list<array{label: string, value: string}>
     * }
     */
    public function getProjection(): array
    {
        $backends = ConfiguredBackend::findAll();
        $indices = SearchIndex::findAll();
        $configuredUsage = $this->buildConfiguredUsage($backends);
        $stats = [
            'database' => $this->getDatabaseStats(),
            'redis' => $this->getRedisStats($this->getRedisTargets($backends, $indices)),
            'file' => $this->getFileStats($this->getFileTargets($backends, $indices)),
        ];

        foreach (self::STORAGE_ORDER as $type) {
            $stats[$type]['configured'] = $configuredUsage[$type];
        }

        return [
            'stats' => $stats,
            'storageOptions' => $this->buildEligibleOptions($stats),
        ];
    }

    /**
     * Apply the storage-type eligibility rule to an already-probed statistics set.
     *
     * @param array<string, array<string, mixed>> $stats
     * @return list<array{label: string, value: string}>
     */
    public function buildEligibleOptions(array $stats): array
    {
        $options = [];

        foreach (self::STORAGE_ORDER as $type) {
            $typeStats = $stats[$type] ?? [];
            if (($typeStats['available'] ?? false) !== true) {
                continue;
            }

            $count = $this->clearableCount($type, $typeStats);
            if (($typeStats['configured'] ?? false) !== true && $count === 0) {
                continue;
            }

            $options[] = [
                'label' => $this->storageLabel($type, $typeStats, $count),
                'value' => $type,
            ];
        }

        return $options;
    }

    /**
     * Resolve the Redis target used by both statistics and clear operations.
     *
     * Disabled effective backends still establish ownership of their storage.
     * With no Redis backend, Craft's Redis cache remains a safe target for
     * orphan cleanup when it can resolve the standard Search Manager database.
     *
     * @param list<ConfiguredBackend>|null $backends
     * @return array<string, mixed>
     */
    public function getRedisConfig(?array $backends = null): array
    {
        $targets = $this->getRedisTargets($backends);
        return $targets[0]['settings'] ?? RedisConnectionHelper::storageSettings([]);
    }

    /**
     * Resolve and deduplicate every effective Redis storage target.
     *
     * @param list<ConfiguredBackend>|null $backends
     * @param list<SearchIndex>|null $indices
     * @return list<array{
     *   key: string,
     *   host: string,
     *   port: int,
     *   password: mixed,
     *   database: int,
     *   settings: array<string, mixed>,
     *   backendHandles: list<string>,
     *   indexHandles: list<string>,
     *   fallback: bool
     * }>
     */
    public function getRedisTargets(?array $backends = null, ?array $indices = null): array
    {
        $backends ??= ConfiguredBackend::findAll();
        $indices ??= SearchIndex::findAll();
        $targetsByKey = [];
        $backendsByHandle = [];
        foreach ($backends as $backend) {
            $backendsByHandle[$backend->handle] = $backend;
        }
        $redisBackends = array_values(array_filter(
            $backends,
            static fn(ConfiguredBackend $backend): bool => $backend->backendType === 'redis',
        ));

        foreach ($redisBackends as $backend) {
            $settings = RedisConnectionHelper::storageSettings($backend->settings ?? []);
            if (empty($settings['host'])) {
                continue;
            }

            $key = $this->redisTargetKey($settings);
            if (!isset($targetsByKey[$key])) {
                $targetsByKey[$key] = $this->redisTarget($key, $settings, false);
            }
            $targetsByKey[$key]['backendHandles'][] = $backend->handle;
        }

        if ($redisBackends === []) {
            $settings = RedisConnectionHelper::storageSettings([]);
            if (!empty($settings['host'])) {
                $key = $this->redisTargetKey($settings);
                $targetsByKey[$key] = $this->redisTarget($key, $settings, true);
            }
        }

        foreach ($indices as $index) {
            $backend = $backendsByHandle[$index->getEffectiveBackend() ?? ''] ?? null;
            if ($backend === null || $backend->backendType !== 'redis') {
                continue;
            }

            $settings = RedisConnectionHelper::storageSettings($backend->settings ?? []);
            if (empty($settings['host'])) {
                continue;
            }
            $key = $this->redisTargetKey($settings);
            if (isset($targetsByKey[$key])) {
                $targetsByKey[$key]['indexHandles'][] = $index->handle;
            }
        }

        foreach ($targetsByKey as &$target) {
            $target['backendHandles'] = $this->sortedUnique($target['backendHandles']);
            $target['indexHandles'] = $this->sortedUnique($target['indexHandles']);
        }
        unset($target);

        ksort($targetsByKey, SORT_STRING);
        return array_values($targetsByKey);
    }

    /**
     * Resolve and deduplicate every effective File storage target.
     *
     * @param list<ConfiguredBackend>|null $backends
     * @param list<SearchIndex>|null $indices
     * @return list<array{
     *   key: string,
     *   basePath: string,
     *   configuredPath: string|null,
     *   backendHandles: list<string>,
     *   indexHandles: list<string>
     * }>
     */
    public function getFileTargets(?array $backends = null, ?array $indices = null): array
    {
        $backends ??= ConfiguredBackend::findAll();
        $indices ??= SearchIndex::findAll();
        $backendsByHandle = [];
        foreach ($backends as $backend) {
            $backendsByHandle[$backend->handle] = $backend;
        }
        $defaultPath = FileBackendStoragePathHelper::defaultBasePath();
        $targetsByPath = [
            $defaultPath => [
                'key' => $defaultPath,
                'basePath' => $defaultPath,
                'configuredPath' => null,
                'backendHandles' => [],
                'indexHandles' => [],
            ],
        ];

        foreach ($backends as $backend) {
            if ($backend->backendType !== 'file') {
                continue;
            }

            $configuredValue = $backend->settings['storagePath'] ?? null;
            $configuredPath = is_string($configuredValue) && trim($configuredValue) !== ''
                ? $configuredValue
                : null;
            try {
                $basePath = FileBackendStoragePathHelper::resolve($configuredPath);
            } catch (\InvalidArgumentException $e) {
                $this->logWarning('Skipping invalid file backend storage path', [
                    'backend' => $backend->handle,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $targetsByPath[$basePath] ??= [
                'key' => $basePath,
                'basePath' => $basePath,
                'configuredPath' => $configuredPath,
                'backendHandles' => [],
                'indexHandles' => [],
            ];
            $targetsByPath[$basePath]['backendHandles'][] = $backend->handle;
        }

        foreach ($indices as $index) {
            $backend = $backendsByHandle[$index->getEffectiveBackend() ?? ''] ?? null;
            if ($backend === null || $backend->backendType !== 'file') {
                continue;
            }

            try {
                $basePath = FileBackendStoragePathHelper::resolve(
                    is_string($backend->settings['storagePath'] ?? null)
                        ? $backend->settings['storagePath']
                        : null,
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (isset($targetsByPath[$basePath])) {
                $targetsByPath[$basePath]['indexHandles'][] = $index->handle;
            }
        }

        foreach ($targetsByPath as &$target) {
            $target['backendHandles'] = $this->sortedUnique($target['backendHandles']);
            $target['indexHandles'] = $this->sortedUnique($target['indexHandles']);
        }
        unset($target);

        ksort($targetsByPath, SORT_STRING);
        return array_values($targetsByPath);
    }

    /**
     * Open and select the resolved Redis target.
     *
     * @param array<string, mixed> $config
     */
    public function connectRedis(array $config): \Redis
    {
        $redis = new \Redis();
        $redis->connect((string)$config['host'], (int)$config['port']);

        if (!empty($config['password'])) {
            $redis->auth($config['password']);
        }

        $redis->select((int)$config['database']);

        return $redis;
    }

    /**
     * @return list<string>
     */
    public function scanRedisKeys(\Redis $redis, string $pattern, int $count = 1000): array
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
     * Count files recursively in a directory.
     */
    public function countFilesInDirectory(string $dir): int
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
     * @param list<ConfiguredBackend> $backends
     * @return array{database: bool, redis: bool, file: bool}
     */
    public function buildConfiguredUsage(array $backends): array
    {
        $usage = [
            'database' => false,
            'redis' => false,
            'file' => false,
        ];

        foreach ($backends as $backend) {
            $storageType = match ($backend->backendType) {
                'mysql', 'pgsql' => 'database',
                'redis' => 'redis',
                'file' => 'file',
                default => null,
            };

            if ($storageType !== null) {
                $usage[$storageType] = true;
            }
        }

        return $usage;
    }

    /**
     * Get database storage statistics (MySQL or PostgreSQL).
     *
     * @return array<string, mixed>
     */
    private function getDatabaseStats(): array
    {
        try {
            $db = Craft::$app->getDb();
            $driverName = $db->getDriverName();
            $driverLabel = $driverName === 'pgsql' ? 'PostgreSQL' : 'MySQL';

            $tableRows = [];
            foreach ($this->databaseStorageTables() as $table) {
                $rawTableName = $db->getSchema()->getRawTableName($table);
                $tableRows[$table] = $db->getTableSchema($rawTableName) === null
                    ? 0
                    : (int)(new Query())->from($table)->count();
            }

            $documentRows = $tableRows['{{%searchmanager_search_documents}}'];
            $termRows = $tableRows['{{%searchmanager_search_terms}}'];
            $compoundRows = $tableRows['{{%searchmanager_search_compounds}}'];

            $indexHandles = $db->createCommand(
                'SELECT DISTINCT [[indexHandle]] FROM (
                    SELECT [[indexHandle]] FROM {{%searchmanager_search_documents}}
                    UNION
                    SELECT [[indexHandle]] FROM {{%searchmanager_search_compounds}}
                ) storage_index_handles
                ORDER BY [[indexHandle]]'
            )->queryColumn();

            return [
                'available' => true,
                'driver' => $driverName,
                'driverLabel' => $driverLabel,
                'documentRows' => $documentRows,
                'termRows' => $termRows,
                'compoundRows' => $compoundRows,
                'tableRows' => $tableRows,
                'indexHandles' => $indexHandles,
                'totalRows' => array_sum($tableRows),
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to get database storage stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to get storage statistics'),
            ];
        }
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @return array<string, mixed>
     */
    private function getRedisStats(array $targets): array
    {
        if (!class_exists('\Redis')) {
            return [
                'available' => false,
                'status' => 'extension_not_installed',
            ];
        }

        if ($targets === []) {
            return [
                'available' => false,
                'status' => 'not_configured',
            ];
        }

        $targetStats = [];
        $keyCount = 0;
        $connected = 0;

        foreach ($targets as $target) {
            try {
                $redis = $this->connectRedis($target['settings']);
                $keys = $this->scanRedisKeys($redis, 'sm:idx:*');
                $count = count($keys);
                $keyCount += $count;
                $connected++;
                $targetStats[] = [
                    'key' => $target['key'],
                    'status' => 'connected',
                    'keyCount' => $count,
                    'backendHandles' => $target['backendHandles'],
                    'indexHandles' => $target['indexHandles'],
                    'fallback' => $target['fallback'],
                ];
            } catch (\Throwable $e) {
                $this->logError('Failed to get Redis storage stats', [
                    'target' => $target['key'],
                    'error' => $e->getMessage(),
                ]);
                $targetStats[] = [
                    'key' => $target['key'],
                    'status' => 'connection_failed',
                    'keyCount' => 0,
                    'backendHandles' => $target['backendHandles'],
                    'indexHandles' => $target['indexHandles'],
                    'fallback' => $target['fallback'],
                    'error' => Craft::$app->getConfig()->getGeneral()->devMode
                        ? $e->getMessage()
                        : Craft::t('search-manager', 'Failed to get storage statistics'),
                ];
            }
        }

        return [
            'available' => $connected > 0,
            'status' => $connected === count($targets)
                ? 'connected'
                : ($connected > 0 ? 'partial' : 'connection_failed'),
            'keyCount' => $keyCount,
            'targets' => $targetStats,
        ];
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @return array<string, mixed>
     */
    private function getFileStats(array $targets): array
    {
        try {
            $indexCount = 0;
            $fileCount = 0;
            $targetStats = [];

            foreach ($targets as $target) {
                $indicesPath = $target['basePath'];
                if (!is_dir($indicesPath)) {
                    $targetStats[] = [
                        'key' => $target['key'],
                        'path' => $indicesPath,
                        'indexCount' => 0,
                        'fileCount' => 0,
                        'backendHandles' => $target['backendHandles'],
                        'indexHandles' => $target['indexHandles'],
                    ];
                    continue;
                }

                $indexDirs = glob($indicesPath . '/*', GLOB_ONLYDIR);
                $targetIndexCount = count($indexDirs ?: []);
                $targetFileCount = $this->countFilesInDirectory($indicesPath);
                $indexCount += $targetIndexCount;
                $fileCount += $targetFileCount;
                $targetStats[] = [
                    'key' => $target['key'],
                    'path' => $indicesPath,
                    'indexCount' => $targetIndexCount,
                    'fileCount' => $targetFileCount,
                    'backendHandles' => $target['backendHandles'],
                    'indexHandles' => $target['indexHandles'],
                ];
            }

            return [
                'available' => true,
                'indexCount' => $indexCount,
                'fileCount' => $fileCount,
                'targets' => $targetStats,
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to get file storage stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to get storage statistics'),
            ];
        }
    }

    /**
     * Clear one complete local storage type through the canonical inventory.
     *
     * @return array<string, mixed>
     */
    public function clearStorageByType(string $type): array
    {
        if (!in_array($type, self::STORAGE_ORDER, true)) {
            return [
                'status' => 'failure',
                'success' => false,
                'type' => $type,
                'count' => 0,
                'changed' => false,
                'error' => Craft::t('search-manager', 'Invalid storage type: {type}', ['type' => $type]),
                'results' => [],
            ];
        }

        $collisions = $this->getHandleCollisions();
        if ($collisions !== []) {
            return [
                'status' => 'failure',
                'success' => false,
                'type' => $type,
                'count' => 0,
                'changed' => false,
                'error' => Craft::t('search-manager', 'Cannot clear storage: Handle collision detected. The following handles exist in both config and database: {handles}. Please resolve these conflicts first by removing duplicates from either config or database.', [
                    'handles' => implode(', ', $collisions),
                ]),
                'results' => [],
            ];
        }

        return match ($type) {
            'database' => $this->clearDatabaseStorage(),
            'redis' => $this->clearRedisStorage(),
            'file' => $this->clearFileStorage(),
        };
    }

    /**
     * Build the deterministic orphan-maintenance plan for selected types.
     *
     * @param list<string> $types
     * @return array<string, list<string>>
     */
    public function getOrphanedStoragePlan(array $types): array
    {
        $liveHandles = array_fill_keys($this->getLiveFullIndexNames(), true);
        $plan = [];

        foreach ($types as $type) {
            $storageHandles = match ($type) {
                'database' => $this->getDatabaseStorageHandles(),
                'redis' => $this->getRedisStorageHandles(),
                'file' => $this->getFileStorageHandles(),
                default => [],
            };

            $orphans = [];
            foreach ($storageHandles as $handle) {
                if (isset($liveHandles[$handle]) || !$this->isCurrentEnvironmentStorageHandle($handle)) {
                    continue;
                }
                $orphans[] = $handle;
            }
            $plan[$type] = $this->sortedUnique($orphans);
        }

        return $plan;
    }

    /**
     * Purge one planned orphan handle from every effective target of its type.
     *
     * @return array<string, mixed>
     */
    public function purgeOrphanedStorageHandle(string $type, string $fullIndexHandle): array
    {
        $errors = [];
        $attempted = 0;
        $succeeded = 0;

        $operations = match ($type) {
            'database' => [fn() => $this->createDatabaseStorage($fullIndexHandle)->clearAll()],
            'redis' => array_map(
                static fn(array $target): \Closure => static fn() => (new RedisStorage(
                    $fullIndexHandle,
                    $target['settings'],
                ))->clearAll(),
                $this->getRedisTargets(),
            ),
            'file' => array_map(
                static fn(array $target): \Closure => static fn() => (new FileStorage(
                    $fullIndexHandle,
                    $target['configuredPath'],
                ))->clearAll(),
                $this->getFileTargets(),
            ),
            default => [],
        };

        foreach ($operations as $operation) {
            $attempted++;
            try {
                $operation();
                $succeeded++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $status = match (true) {
            $attempted > 0 && $succeeded === $attempted => 'success',
            $succeeded > 0 => 'partial',
            default => 'failure',
        };

        return [
            'status' => $status,
            'success' => $status === 'success',
            'type' => $type,
            'handle' => $fullIndexHandle,
            'attemptedTargets' => $attempted,
            'successfulTargets' => $succeeded,
            'errors' => $errors,
        ];
    }

    /**
     * Clear all eight database storage tables in one transaction.
     *
     * @return array<string, mixed>
     */
    protected function clearDatabaseStorage(): array
    {
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        $deletedRows = 0;

        try {
            foreach ($this->databaseStorageTables() as $table) {
                $tableName = $db->getSchema()->getRawTableName($table);
                if ($db->getTableSchema($tableName) === null) {
                    continue;
                }
                $deletedRows += $this->deleteDatabaseTable($table);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $this->logError('Failed to clear database storage', ['error' => $e->getMessage()]);
            return $this->storageFailure('database');
        }

        $indexHandles = $this->indexHandlesForStorageType('database');
        $reconciliation = $this->reconcileClearedIndices($indexHandles);
        $driverLabel = $db->getDriverName() === 'pgsql' ? 'PostgreSQL' : 'MySQL';

        return $this->storageCompletion(
            'database',
            $deletedRows,
            [[
                'status' => $reconciliation['success'] ? 'success' : 'partial',
                'target' => $driverLabel,
                'deletedCount' => $deletedRows,
                'indexHandles' => $indexHandles,
                'reconciliation' => $reconciliation,
            ]],
            Craft::t('search-manager', '{driver} storage cleared successfully ({count} rows deleted). Rebuild affected indices to re-index your content.', [
                'driver' => $driverLabel,
                'count' => number_format($deletedRows),
            ]),
        );
    }

    protected function deleteDatabaseTable(string $table): int
    {
        return Craft::$app->getDb()->createCommand()->delete($table)->execute();
    }

    /**
     * @return array<string, mixed>
     */
    protected function clearRedisStorage(): array
    {
        if (!class_exists('\Redis')) {
            return $this->storageFailure(
                'redis',
                Craft::t('search-manager', 'Redis extension is not installed.'),
            );
        }

        $targets = $this->getRedisTargets();
        if ($targets === []) {
            return $this->storageFailure(
                'redis',
                Craft::t('search-manager', 'Redis is not configured.'),
            );
        }

        $results = [];
        $deleted = 0;
        $stop = false;
        foreach ($targets as $target) {
            if ($stop) {
                $results[] = $this->unattemptedStorageTarget($target['key'], $target['indexHandles']);
                continue;
            }

            $result = $this->clearRedisTarget($target);
            if ($result['status'] === 'success') {
                $reconciliation = $this->reconcileClearedIndices($target['indexHandles']);
                $result['reconciliation'] = $reconciliation;
                if (!$reconciliation['success']) {
                    $result['status'] = 'partial';
                }
            }
            $deleted += (int)$result['deletedCount'];
            $results[] = $result;
            if ($result['status'] === 'partial') {
                $stop = true;
            }
        }

        return $this->storageCompletion(
            'redis',
            $deleted,
            $results,
            Craft::t('search-manager', 'Redis storage cleared successfully ({count} keys deleted). Rebuild affected indices to re-index your content.', [
                'count' => number_format($deleted),
            ]),
        );
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    protected function clearRedisTarget(array $target): array
    {
        try {
            $redis = $this->connectRedis($target['settings']);
            $keys = $this->scanRedisKeys($redis, 'sm:idx:*');
        } catch (\Throwable $e) {
            return [
                'status' => 'failure',
                'target' => $target['key'],
                'deletedCount' => 0,
                'indexHandles' => $target['indexHandles'],
                'error' => $e->getMessage(),
            ];
        }

        if ($keys === []) {
            return [
                'status' => 'success',
                'target' => $target['key'],
                'deletedCount' => 0,
                'indexHandles' => $target['indexHandles'],
            ];
        }

        try {
            $deleted = $redis->del($keys);
            return [
                'status' => (int)$deleted === count($keys) ? 'success' : 'partial',
                'target' => $target['key'],
                'deletedCount' => (int)$deleted,
                'indexHandles' => $target['indexHandles'],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'partial',
                'target' => $target['key'],
                'deletedCount' => 0,
                'indexHandles' => $target['indexHandles'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function clearFileStorage(): array
    {
        $results = [];
        $deleted = 0;
        $stop = false;

        foreach ($this->getFileTargets() as $target) {
            if ($stop) {
                $results[] = $this->unattemptedStorageTarget($target['key'], $target['indexHandles']);
                continue;
            }

            $result = $this->clearFileTarget($target);
            if ($result['status'] === 'success') {
                $reconciliation = $this->reconcileClearedIndices($target['indexHandles']);
                $result['reconciliation'] = $reconciliation;
                if (!$reconciliation['success']) {
                    $result['status'] = 'partial';
                }
            }
            $deleted += (int)$result['deletedCount'];
            $results[] = $result;
            if ($result['status'] === 'partial') {
                $stop = true;
            }
        }

        $message = $deleted === 0
            ? Craft::t('search-manager', 'File storage is already empty.')
            : Craft::t('search-manager', 'File storage cleared successfully ({count} files deleted). Rebuild affected indices to re-index your content.', [
                'count' => number_format($deleted),
            ]);

        return $this->storageCompletion('file', $deleted, $results, $message);
    }

    /**
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    protected function clearFileTarget(array $target): array
    {
        $path = $target['basePath'];
        if (!is_dir($path)) {
            return [
                'status' => 'success',
                'target' => $target['key'],
                'deletedCount' => 0,
                'indexHandles' => $target['indexHandles'],
            ];
        }

        $before = $this->countFilesInDirectory($path);
        try {
            FileHelper::removeDirectory($path);
            return [
                'status' => 'success',
                'target' => $target['key'],
                'deletedCount' => $before,
                'indexHandles' => $target['indexHandles'],
            ];
        } catch (\Throwable $e) {
            $remaining = $this->countFilesInDirectory($path);
            return [
                'status' => $remaining < $before ? 'partial' : 'failure',
                'target' => $target['key'],
                'deletedCount' => max(0, $before - $remaining),
                'indexHandles' => $target['indexHandles'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param list<string> $indexHandles
     * @return array{success: bool, updated: list<string>, failed: list<string>}
     */
    protected function reconcileClearedIndices(array $indexHandles): array
    {
        $updated = [];
        $failed = [];

        foreach ($indexHandles as $handle) {
            $index = SearchIndex::findByHandle($handle);
            if ($index === null) {
                continue;
            }

            $countUpdated = $index->updateStats(0);
            $cacheSucceeded = true;
            try {
                SearchManager::$plugin->backend->clearSearchCache($handle);
            } catch (\Throwable $e) {
                $cacheSucceeded = false;
                $this->logError('Failed to clear search cache after storage maintenance', [
                    'index' => $handle,
                    'error' => $e->getMessage(),
                ]);
            }
            try {
                SearchManager::$plugin->autocomplete->clearCache($handle);
            } catch (\Throwable $e) {
                $cacheSucceeded = false;
                $this->logError('Failed to clear autocomplete cache after storage maintenance', [
                    'index' => $handle,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($countUpdated && $cacheSucceeded) {
                $updated[] = $handle;
            } else {
                $failed[] = $handle;
            }
        }

        return [
            'success' => $failed === [],
            'updated' => $updated,
            'failed' => $failed,
        ];
    }

    /**
     * @return list<string>
     */
    private function indexHandlesForStorageType(string $type): array
    {
        $types = $type === 'database' ? ['mysql', 'pgsql'] : [$type];
        $handles = [];

        foreach (SearchIndex::findAll() as $index) {
            $backendType = $index->getEffectiveBackendType();
            if ($backendType === null && $type === 'database') {
                $backendType = 'mysql';
            }
            if (in_array($backendType, $types, true)) {
                $handles[] = $index->handle;
            }
        }

        return $this->sortedUnique($handles);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function storageCompletion(string $type, int $count, array $results, string $message): array
    {
        $statuses = array_column($results, 'status');
        $hasPartial = in_array('partial', $statuses, true);
        $hasFailure = in_array('failure', $statuses, true);
        $hasSuccess = in_array('success', $statuses, true);
        $status = match (true) {
            $hasPartial || ($hasSuccess && $hasFailure) => 'partial',
            $hasFailure => 'failure',
            default => 'success',
        };

        return [
            'status' => $status,
            'success' => $status === 'success',
            'type' => $type,
            'count' => $count,
            'changed' => $count > 0 || $hasPartial || $hasSuccess,
            'message' => $status === 'success' ? $message : null,
            'error' => $status === 'success'
                ? null
                : Craft::t('search-manager', 'Failed to clear {type} storage', ['type' => ucfirst($type)]),
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storageFailure(string $type, ?string $error = null): array
    {
        return [
            'status' => 'failure',
            'success' => false,
            'type' => $type,
            'count' => 0,
            'changed' => false,
            'message' => null,
            'error' => $error ?? Craft::t('search-manager', 'Failed to clear {type} storage', [
                'type' => ucfirst($type),
            ]),
            'results' => [],
        ];
    }

    /**
     * @param list<string> $indexHandles
     * @return array<string, mixed>
     */
    private function unattemptedStorageTarget(string $target, array $indexHandles): array
    {
        return [
            'status' => 'unattempted',
            'target' => $target,
            'deletedCount' => 0,
            'indexHandles' => $indexHandles,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function redisTargetKey(array $settings): string
    {
        return sprintf(
            '%s:%d:%d',
            (string)$settings['host'],
            (int)$settings['port'],
            (int)$settings['database'],
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function redisTarget(string $key, array $settings, bool $fallback): array
    {
        return [
            'key' => $key,
            'host' => (string)$settings['host'],
            'port' => (int)$settings['port'],
            'password' => $settings['password'] ?? null,
            'database' => (int)$settings['database'],
            'settings' => $settings,
            'backendHandles' => [],
            'indexHandles' => [],
            'fallback' => $fallback,
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            $values,
            static fn(string $value): bool => $value !== '',
        )));
        sort($values, SORT_STRING);
        return $values;
    }

    /**
     * @return list<string>
     */
    private function getLiveFullIndexNames(): array
    {
        $settings = SearchManager::$plugin->getSettings();
        return $this->sortedUnique(array_map(
            static fn(SearchIndex $index): string => $settings->getFullIndexName($index->handle),
            SearchIndex::findAll(),
        ));
    }

    private function isCurrentEnvironmentStorageHandle(string $storageHandle): bool
    {
        $prefix = SearchManager::$plugin->getSettings()->getFullIndexName('');
        return $prefix === '' || str_starts_with($storageHandle, $prefix);
    }

    /**
     * @return list<string>
     */
    private function getDatabaseStorageHandles(): array
    {
        $db = Craft::$app->getDb();
        $handles = [];

        foreach ($this->databaseStorageTables() as $table) {
            $rawTableName = $db->getSchema()->getRawTableName($table);
            if ($db->getTableSchema($rawTableName) === null) {
                continue;
            }
            foreach ((new Query())->select(['indexHandle'])->distinct()->from($table)->column() as $handle) {
                $handles[] = (string)$handle;
            }
        }

        return $this->sortedUnique($handles);
    }

    /**
     * @return list<string>
     */
    private function getRedisStorageHandles(): array
    {
        if (!class_exists('\Redis')) {
            return [];
        }

        $handles = [];
        foreach ($this->getRedisTargets() as $target) {
            try {
                $redis = $this->connectRedis($target['settings']);
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

        return $this->sortedUnique($handles);
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
     * @return list<string>
     */
    private function getFileStorageHandles(): array
    {
        $handles = [];
        foreach ($this->getFileTargets() as $target) {
            if (!is_dir($target['basePath'])) {
                continue;
            }
            foreach (glob($target['basePath'] . '/*', GLOB_ONLYDIR) ?: [] as $path) {
                $handles[] = basename($path);
            }
        }
        return $this->sortedUnique($handles);
    }

    private function createDatabaseStorage(string $fullIndexHandle): StorageInterface
    {
        return Craft::$app->getDb()->getDriverName() === 'pgsql'
            ? new PostgreSqlStorage($fullIndexHandle)
            : new MySqlStorage($fullIndexHandle);
    }

    /**
     * @return list<string>
     */
    private function getHandleCollisions(): array
    {
        $configHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::loadFromConfig(),
        );
        if ($configHandles === []) {
            return [];
        }

        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_indices}}')
            ->where(['source' => 'database'])
            ->column();

        return $this->sortedUnique(array_values(array_intersect($configHandles, $databaseHandles)));
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function clearableCount(string $type, array $stats): int
    {
        return max(0, (int) match ($type) {
            'database' => $stats['totalRows'] ?? 0,
            'redis' => $stats['keyCount'] ?? 0,
            'file' => $stats['fileCount'] ?? 0,
            default => throw new \InvalidArgumentException("Unknown storage type: {$type}"),
        });
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function storageLabel(string $type, array $stats, int $count): string
    {
        $formattedCount = Craft::$app->getFormatter()->asInteger($count);

        return match ($type) {
            'database' => ($stats['driverLabel'] ?? Craft::t('search-manager', 'Database'))
                . ' (' . $this->rowCountLabel($count, $formattedCount) . ')',
            'redis' => 'Redis (' . $this->keyCountLabel($count, $formattedCount) . ')',
            'file' => Craft::t('search-manager', 'File') . ' ('
                . $this->fileCountLabel($count, $formattedCount) . ')',
            default => throw new \InvalidArgumentException("Unknown storage type: {$type}"),
        };
    }

    private function rowCountLabel(int $count, string $formattedCount): string
    {
        return $count === 1
            ? Craft::t('search-manager', '{count} row', ['count' => $formattedCount])
            : Craft::t('search-manager', '{count} rows', ['count' => $formattedCount]);
    }

    private function keyCountLabel(int $count, string $formattedCount): string
    {
        return $count === 1
            ? Craft::t('search-manager', '{count} key', ['count' => $formattedCount])
            : Craft::t('search-manager', '{count} keys', ['count' => $formattedCount]);
    }

    private function fileCountLabel(int $count, string $formattedCount): string
    {
        return $count === 1
            ? Craft::t('search-manager', '{count} file', ['count' => $formattedCount])
            : Craft::t('search-manager', '{count} files', ['count' => $formattedCount]);
    }
}
