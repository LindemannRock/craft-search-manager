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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ConfiguredBackend;
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
     * @var string Backend storage type to clear (database, redis, file)
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
     * specified backend type (database, redis, or file) regardless of which
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
            $result = SearchManager::$plugin->storageMaintenance->clearStorageByType($type);
            foreach ($result['results'] as $target) {
                $label = (string)($target['target'] ?? $type);
                $deleted = (int)($target['deletedCount'] ?? 0);
                match ($target['status']) {
                    'success' => $this->stdout("  ✓ {$label}: {$deleted} removed\n", Console::FG_GREEN),
                    'failure' => $this->stderr("  ✗ {$label}: failed before mutation\n", Console::FG_RED),
                    'partial' => $this->stderr("  ! {$label}: irreversible partial result\n", Console::FG_YELLOW),
                    'unattempted' => $this->stdout("  - {$label}: unattempted\n", Console::FG_YELLOW),
                    default => null,
                };
            }

            if ($result['success']) {
                $this->stdout("\n✓ {$result['message']}\n", Console::FG_GREEN);
                $this->logInfo("Storage cleared via CLI", [
                    'type' => $type,
                    'details' => $result,
                ]);
                return ExitCode::OK;
            }

            $prefix = $result['status'] === 'partial' ? '!' : '✗';
            $this->stderr("\n{$prefix} {$result['error']}\n", Console::FG_RED);
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
        $plan = SearchManager::$plugin->storageMaintenance->getOrphanedStoragePlan($types);

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

        $successful = [];
        $failed = [];
        foreach ($plan as $storageType => $handles) {
            foreach ($handles as $handle) {
                $result = SearchManager::$plugin->storageMaintenance
                    ->purgeOrphanedStorageHandle($storageType, $handle);
                if ($result['status'] === 'success') {
                    $successful[] = "{$storageType}:{$handle}";
                    $this->stdout("  Purged {$storageType}: {$handle}\n", Console::FG_GREEN);
                } else {
                    $failed[] = "{$storageType}:{$handle}";
                    $details = implode('; ', $result['errors']);
                    $this->stderr("  Failed {$storageType}: {$handle} ({$details})\n", Console::FG_RED);
                    $this->logError('Failed to purge orphaned storage handle', [
                        'type' => $storageType,
                        'handle' => $handle,
                        'errors' => $result['errors'],
                    ]);
                }
            }
        }

        if ($failed === []) {
            $this->stdout(
                "\nPurge complete: " . count($successful) . " orphaned storage handle(s) removed. Rebuild affected indices if needed.\n",
                Console::FG_GREEN,
            );
            return ExitCode::OK;
        }

        if ($successful !== []) {
            $this->stderr(
                "\nPurge partially completed: " . count($successful) . " succeeded, " . count($failed) . " failed.\n",
                Console::FG_YELLOW,
            );
        } else {
            $this->stderr(
                "\nPurge failed: all " . count($failed) . " orphaned storage handle(s) failed.\n",
                Console::FG_RED,
            );
        }

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List available storage types and their current state
     */
    public function actionStatus(): int
    {
        $this->stdout("Search Manager - Storage Status\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        $configuredBackends = ConfiguredBackend::findAll();
        $externalBackends = array_values(array_filter($configuredBackends, fn($b) => in_array($b->backendType, ['algolia', 'meilisearch', 'typesense'], true)));
        $stats = SearchManager::$plugin->storageMaintenance->getProjection()['stats'];

        $database = $stats['database'];
        $driverLabel = $database['driverLabel'] ?? 'Database';
        $this->stdout("{$driverLabel} Storage:\n", Console::FG_GREEN);
        if (($database['available'] ?? false) === true) {
            $this->stdout("  Documents: {$database['documentRows']}\n");
            $this->stdout("  Terms: {$database['termRows']}\n");
            $this->stdout("  Compounds: {$database['compoundRows']}\n");
            $indexHandles = $database['indexHandles'] ?: [];
            if ($this->verbose) {
                $this->stdout("  Unique Index Handles: " . count($indexHandles) . "\n");
                foreach ($indexHandles as $handle) {
                    $this->stdout("    - {$handle}\n");
                }
            } else {
                $this->stdout("  Unique Index Handles: " . count($indexHandles) . " (use --verbose to list)\n");
            }
        } else {
            $this->stdout("  Status: Error\n", Console::FG_RED);
        }

        $this->stdout("\n");
        $this->stdout("Redis Storage:\n", Console::FG_GREEN);
        $redis = $stats['redis'];
        $this->stdout("  Status: " . ($redis['status'] ?? 'not_configured') . "\n");
        foreach (($redis['targets'] ?? []) as $target) {
            $this->stdout("  {$target['key']}:\n");
            $this->stdout("    Status: {$target['status']}\n");
            if ($this->verbose) {
                $this->stdout("    Search Manager Keys: {$target['keyCount']}\n");
            }
        }

        $this->stdout("\n");
        $this->stdout("File Storage:\n", Console::FG_GREEN);
        foreach (($stats['file']['targets'] ?? []) as $target) {
            $this->stdout("  {$target['path']}:\n");
            $this->stdout("    Index Directories: {$target['indexCount']}\n");
            $this->stdout("    Total Files: {$target['fileCount']}\n");
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
     * Get the database driver label (MySQL or PostgreSQL)
     */
    private function getDatabaseDriverLabel(): string
    {
        $driverName = Craft::$app->getDb()->getDriverName();
        return $driverName === 'pgsql' ? 'PostgreSQL' : 'MySQL';
    }
}
