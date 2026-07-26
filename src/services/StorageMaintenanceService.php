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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\FileBackendStoragePathHelper;
use lindemannrock\searchmanager\helpers\RedisConnectionHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;

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
        $configuredUsage = $this->buildConfiguredUsage($backends);
        $stats = [
            'database' => $this->getDatabaseStats(),
            'redis' => $this->getRedisStats($this->getRedisConfig($backends)),
            'file' => $this->getFileStats(),
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
        $backends ??= ConfiguredBackend::findAll();

        foreach ($backends as $backend) {
            if ($backend->backendType === 'redis') {
                return RedisConnectionHelper::storageSettings($backend->settings ?? []);
            }
        }

        return RedisConnectionHelper::storageSettings([]);
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
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function getRedisStats(array $config): array
    {
        if (!class_exists('\Redis')) {
            return [
                'available' => false,
                'status' => 'extension_not_installed',
            ];
        }

        if (empty($config['host'])) {
            return [
                'available' => false,
                'status' => 'not_configured',
            ];
        }

        try {
            $redis = $this->connectRedis($config);
            $keys = $this->scanRedisKeys($redis, 'sm:idx:*');

            return [
                'available' => true,
                'status' => 'connected',
                'keyCount' => count($keys),
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to get Redis storage stats', [
                'error' => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'status' => 'connection_failed',
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to get storage statistics'),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getFileStats(): array
    {
        try {
            $indexCount = 0;
            $fileCount = 0;

            foreach (FileBackendStoragePathHelper::configuredBasePaths() as $indicesPath) {
                if (!is_dir($indicesPath)) {
                    continue;
                }

                $indexDirs = glob($indicesPath . '/*', GLOB_ONLYDIR);
                $indexCount += count($indexDirs ?: []);
                $fileCount += $this->countFilesInDirectory($indicesPath);
            }

            return [
                'available' => true,
                'indexCount' => $indexCount,
                'fileCount' => $fileCount,
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
