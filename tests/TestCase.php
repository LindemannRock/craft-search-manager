<?php

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for search-manager integration tests.
 *
 * Provides shorthand accessors for the plugin's sync services and a clean
 * starting state — every test begins with an empty `searchmanager_pending_syncs`
 * table so backlog from prior runs can't crowd a test's target row out of the
 * BatchSyncJob claim window.
 *
 * Subclasses can override `setUp()` for additional fixture work but should
 * call `parent::setUp()` to keep buffer isolation.
 *
 * @since 5.45.0
 */
abstract class TestCase extends BaseTestCase
{
    protected PendingSyncRepository $repository;
    protected PendingSyncProcessor $processor;

    private ?BackendService $originalBackend = null;

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
        if ($this->originalBackend !== null) {
            SearchManager::$plugin->set('backend', $this->originalBackend);
            $this->originalBackend = null;
        }
        $this->truncateBuffer();
        SearchIndex::clearCache();
        parent::tearDown();
    }

    /**
     * Swap `SearchManager::$plugin->backend` for a StubBackend so the test can
     * observe which operations the processor drove and force partial-failure
     * paths. The original backend is restored automatically in tearDown().
     */
    protected function installStubBackend(): StubBackend
    {
        if ($this->originalBackend === null) {
            $this->originalBackend = SearchManager::$plugin->backend;
        }
        $stub = new StubBackend();
        SearchManager::$plugin->set('backend', $stub);

        return $stub;
    }

    /**
     * Wipe `searchmanager_pending_syncs` to remove any rows left behind by
     * prior runs or manual testing.
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
     * Count rows in `searchmanager_pending_syncs` filtered by the given
     * condition (Yii Query `where` format).
     *
     * @param array<string, mixed>|array $condition
     */
    protected function countPendingRows(array $condition = []): int
    {
        $query = (new Query())->from('{{%searchmanager_pending_syncs}}');
        if (!empty($condition)) {
            $query->where($condition);
        }

        return (int) $query->count();
    }

    /**
     * Fetch a single pending row by composite key, or null.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchPendingRow(string $indexHandle, int $elementId, int $siteId): ?array
    {
        $row = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where([
                'indexHandle' => $indexHandle,
                'elementId' => $elementId,
                'siteId' => $siteId,
            ])
            ->one();

        return $row ?: null;
    }
}
