<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;

/**
 * Base test case for search-manager integration tests.
 *
 * Extends the shared {@see IntegrationTestCase} for component snapshot/restore
 * and generic Query helpers, and layers plugin-specific shorthand on top:
 *  - direct accessors for the sync services
 *  - per-test buffer truncation so prior runs can't crowd a target row out of
 *    the BatchSyncJob claim window
 *  - {@see installStubBackend()} convenience wrapper
 *  - {@see findWorkingIndexAndElement()} live-data discovery helper
 *
 * Subclasses can override `setUp()` for additional fixture work but should
 * call `parent::setUp()` to keep buffer isolation.
 *
 * @since 5.45.0
 */
abstract class TestCase extends IntegrationTestCase
{
    protected PendingSyncRepository $repository;
    protected PendingSyncProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        SearchIndex::clearCache();
        $this->repository = SearchManager::$plugin->pendingSyncs;
        $this->processor = SearchManager::$plugin->pendingSyncProcessor;
        $this->truncateBuffer();
    }

    protected function tearDown(): void
    {
        $this->truncateBuffer();
        SearchIndex::clearCache();
        // Parent restores swapped components (including any StubBackend) after
        // our buffer cleanup runs against the real DB.
        parent::tearDown();
    }

    /**
     * Swap `SearchManager::$plugin->backend` for a {@see StubBackend} so the
     * test can observe which operations the processor drove and force partial-
     * failure paths. Auto-restored in tearDown by the base class.
     */
    protected function installStubBackend(): StubBackend
    {
        $stub = new StubBackend();
        $this->swapPluginComponent('search-manager', 'backend', $stub);

        return $stub;
    }

    /**
     * Wipe `searchmanager_pending_syncs` between tests. The buffer is
     * transient — production rows live there only for the brief window
     * between a save event and the next BatchSyncJob drain — so a
     * truncate-all here doesn't risk eating real CP data.
     */
    protected function truncateBuffer(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', '1=1')
            ->execute();
    }

    /**
     * Return the first enabled `craft\elements\Entry` index whose criteria
     * accepts at least one live entry, or null if none can be found.
     *
     * Tests that need a working (index, element) pair use this so the suite
     * doesn't hard-code IDs that drift with the test install.
     *
     * @return array{0: SearchIndex, 1: ElementInterface}|null
     */
    protected function findWorkingIndexAndElement(): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled) {
                continue;
            }
            if ($index->elementType !== \craft\elements\Entry::class) {
                continue;
            }

            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }

            $entries = \craft\elements\Entry::find()
                ->siteId($siteId)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->limit(20)
                ->all();

            foreach ($entries as $entry) {
                if ($index->matchesElement($entry)) {
                    return [$index, $entry];
                }
            }
        }

        return null;
    }

    /**
     * Thin wrapper over {@see IntegrationTestCase::countRows()} pinned to the
     * pending_syncs table — every existing test calls into this shape.
     *
     * @param array<string, mixed>|array<int, mixed> $condition
     */
    protected function countPendingRows(array $condition = []): int
    {
        return $this->countRows('{{%searchmanager_pending_syncs}}', $condition);
    }

    /**
     * Thin wrapper over {@see IntegrationTestCase::fetchRow()} for the
     * composite-key lookup the sync tests use.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchPendingRow(string $indexHandle, int $elementId, int $siteId): ?array
    {
        return $this->fetchRow('{{%searchmanager_pending_syncs}}', [
            'indexHandle' => $indexHandle,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]);
    }
}
