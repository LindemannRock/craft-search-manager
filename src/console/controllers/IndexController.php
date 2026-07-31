<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\console\ExitCode;

/**
 * Index management commands
 *
 * @since 5.0.0
 */
class IndexController extends Controller
{
    public $defaultAction = 'list';

    /**
     * @var string|null Index handle for scoped rebuild and clear operations.
     * @since 5.47.0
     */
    public ?string $handle = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['rebuild', 'clear'], true)) {
            $options[] = 'handle';
        }

        return $options;
    }

    /**
     * List all search indices
     */
    public function actionList(): int
    {
        $this->stdout("Search Manager - Indices\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        $indices = SearchIndex::findAll();

        if (empty($indices)) {
            $this->stdout("No indices found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        foreach ($indices as $index) {
            $status = $index->enabled ? '✓' : '✗';
            $this->stdout("[$status] ", $index->enabled ? Console::FG_GREEN : Console::FG_RED);
            $this->stdout("{$index->name} ({$index->handle})\n");
            $this->stdout("    Type: {$index->elementType}\n");
            $this->stdout("    Documents: {$index->documentCount}\n");
            if ($index->lastIndexed) {
                $this->stdout("    Last Indexed: {$index->lastIndexed->format('Y-m-d H:i:s')}\n");
            }
            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Rebuild all indices or a specific index
     */
    public function actionRebuild(): int
    {
        $this->stdout("Search Manager - Rebuild Indices\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        if ($this->handle) {
            $index = SearchIndex::findByHandle($this->handle);
            if (!$index) {
                $this->stderr("Index not found: {$this->handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $result = SearchManager::$plugin->indexing->rebuildIndexResult($this->handle);
            if (!$result['queued']) {
                $this->stderr("Rebuild denied [{$result['reasonCode']}]: {$result['reason']}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("Rebuilding index: {$index->name}...\n", Console::FG_GREEN);
        } else {
            if (!$this->confirm('This will rebuild all indices. Continue?')) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $result = SearchManager::$plugin->indexing->rebuildAllResult();
            if (!$result['queued']) {
                $this->stderr("Rebuild All denied [{$result['reasonCode']}]: {$result['reason']}\n", Console::FG_RED);
                if ($result['collisions'] !== []) {
                    $this->stderr('Colliding handles: ' . implode(', ', $result['collisions']) . "\n", Console::FG_RED);
                }
                return ExitCode::UNSPECIFIED_ERROR;
            }
            foreach ($result['skips'] as $skip) {
                $this->stdout("  - {$skip['handle']} [{$skip['reasonCode']}]: {$skip['reason']}\n", Console::FG_YELLOW);
            }
            $this->stdout("Rebuilding all eligible indices...\n", Console::FG_GREEN);
        }

        $this->stdout("\n✓ Rebuild job(s) queued successfully\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Clear all indices or a specific index
     */
    public function actionClear(): int
    {
        $this->stdout("Search Manager - Clear Indices\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        if ($this->handle) {
            $index = SearchIndex::findByHandle($this->handle);
            if (!$index) {
                $this->stderr("Index not found: {$this->handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (!$this->confirm("Clear index: {$index->name}?")) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $result = SearchManager::$plugin->indexMaintenance->clearIndex($index);
            if ($result['status'] !== 'success') {
                $reasonCode = $result['reasonCode'] ? " [{$result['reasonCode']}]" : '';
                $this->stderr("\n✗ Index was not fully cleared{$reasonCode}: {$result['error']}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $this->stdout("\n✓ Index cleared: {$index->name}\n", Console::FG_GREEN);
        } else {
            if (!$this->confirm('This will clear all indices. Continue?')) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $result = SearchManager::$plugin->indexMaintenance->clearIndices(SearchIndex::findAll());
            foreach ($result['results'] as $item) {
                $this->formatClearResult($item);
            }

            if ($result['status'] !== 'success') {
                $this->stderr("\n✗ One or more indices were not fully cleared.\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("\n✓ All indices cleared\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function formatClearResult(array $result): void
    {
        $label = "{$result['name']} ({$result['handle']})";
        match ($result['status']) {
            'success' => $this->stdout("  ✓ {$label}\n", Console::FG_GREEN),
            'failure' => $this->stderr("  ✗ {$label}" . ($result['reasonCode'] ? " [{$result['reasonCode']}]" : '') . ": {$result['error']}\n", Console::FG_RED),
            'partial' => $this->stderr("  ! {$label} (storage changed; rebuild or retry required)\n", Console::FG_YELLOW),
            'unattempted' => $this->stdout("  - {$label} (unattempted)\n", Console::FG_YELLOW),
            default => null,
        };
    }
}
