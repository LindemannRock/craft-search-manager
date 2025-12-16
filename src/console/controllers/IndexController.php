<?php

namespace lindemannrock\searchmanager\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\console\ExitCode;

/**
 * Index management commands
 */
class IndexController extends Controller
{
    public $defaultAction = 'list';

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
    public function actionRebuild(?string $handle = null): int
    {
        $this->stdout("Search Manager - Rebuild Indices\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        if ($handle) {
            $index = SearchIndex::findByHandle($handle);
            if (!$index) {
                $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("Rebuilding index: {$index->name}...\n", Console::FG_GREEN);
            SearchManager::$plugin->indexing->rebuildIndex($handle);
        } else {
            if (!$this->confirm('This will rebuild all indices. Continue?')) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $this->stdout("Rebuilding all indices...\n", Console::FG_GREEN);
            SearchManager::$plugin->indexing->rebuildAll();
        }

        $this->stdout("\n✓ Rebuild job(s) queued successfully\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Clear all indices or a specific index
     */
    public function actionClear(?string $handle = null): int
    {
        $this->stdout("Search Manager - Clear Indices\n", Console::FG_CYAN);
        $this->stdout(str_repeat('=', 60) . "\n\n");

        if ($handle) {
            $index = SearchIndex::findByHandle($handle);
            if (!$index) {
                $this->stderr("Index not found: {$handle}\n", Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (!$this->confirm("Clear index: {$index->name}?")) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            SearchManager::$plugin->backend->clearIndex($handle);
            $this->stdout("\n✓ Index cleared: {$index->name}\n", Console::FG_GREEN);
        } else {
            if (!$this->confirm('This will clear all indices. Continue?')) {
                $this->stdout("Operation cancelled.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $indices = SearchIndex::findAll();
            foreach ($indices as $index) {
                SearchManager::$plugin->backend->clearIndex($index->handle);
            }

            $this->stdout("\n✓ All indices cleared\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}
