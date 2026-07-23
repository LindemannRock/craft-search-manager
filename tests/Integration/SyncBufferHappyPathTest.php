<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Happy-path coverage for the L3 pending-sync pipeline.
 *
 * @since 5.46.0
 */
final class SyncBufferHappyPathTest extends TestCase
{
    public function testQueueForElementCreatesAPendingRow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $queued = $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
        $this->assertGreaterThanOrEqual(1, $queued, 'queueForElement should report at least one row queued.');

        $row = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($row, 'A pending_syncs row should exist for the target (index, element, site).');
        $this->assertSame('upsert', $row['op']);
        $this->assertSame('pending', $row['status']);
    }

    public function testBatchSyncJobDrainsRowAndWritesDocumentToBackend(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

        // Drain in a loop so that any pre-existing backlog can't crowd our row
        // out of the first batch. Cap iterations so a real bug can't hang the
        // test.
        $maxIterations = 50;
        $iterations = 0;
        while ($this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId) !== null) {
            $job = new BatchSyncJob();
            $job->execute(Craft::$app->queue);
            $iterations++;
            $this->assertLessThanOrEqual($maxIterations, $iterations, 'BatchSyncJob did not drain the target row within the iteration cap.');
        }

        $this->assertNull(
            $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId),
            'Pending row should be drained after BatchSyncJob runs.',
        );
        $this->assertTrue(
            SearchManager::$plugin->backend->documentExists($index->handle, (int) $element->id, (int) $element->siteId),
            'Backend should contain the document after a successful batch sync.',
        );
    }

    public function testRapidQueueForElementCallsCollapseToOneRow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        for ($i = 0; $i < 5; $i++) {
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
        }

        $count = $this->countPendingRows([
            'indexHandle' => $index->handle,
            'elementId' => (int) $element->id,
            'siteId' => (int) $element->siteId,
        ]);
        $this->assertSame(1, $count, 'Composite UPSERT key should collapse rapid same-target queue calls into a single row.');
    }

    public function testBatchSyncJobRefreshesDocumentCountAfterCompletedDrain(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $originalCount = $index->documentCount;
        try {
            $index->updateStats(0);
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

            (new BatchSyncJob())->execute(Craft::$app->queue);

            $backendCount = SearchManager::$plugin->backend->getDocumentCount($index->handle);
            $refreshed = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($index->handle);
            $this->assertNotNull($backendCount);
            $this->assertNotNull($refreshed);
            $this->assertSame(
                $backendCount,
                $refreshed->documentCount,
                'Completed BatchSyncJob drains must refresh documentCount from authoritative backend state.',
            );
        } finally {
            $index->updateStats($originalCount);
        }
    }

    public function testDeferredBatchSyncJobRefreshesDocumentCountAndSchedulesContinuation(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();
        $settings = SearchManager::$plugin->getSettings();
        $originalBatchSize = $settings->syncBatchSize;
        $queueIdsBefore = $this->batchSyncQueueIds();
        $fakeElementId = (int) ((new Query())
            ->from('{{%elements}}')
            ->max('id')) + 1_000_000;

        try {
            $settings->syncBatchSize = 1;
            $index->updateStats(987_654);
            $this->repository->upsertRows([
                [
                    'indexHandle' => $index->handle,
                    'elementType' => Entry::class,
                    'elementId' => (int) $element->id,
                    'siteId' => (int) $element->siteId,
                    'op' => PendingSyncRepository::OP_UPSERT,
                ],
                [
                    'indexHandle' => $index->handle,
                    'elementType' => Entry::class,
                    'elementId' => $fakeElementId,
                    'siteId' => (int) $element->siteId,
                    'op' => PendingSyncRepository::OP_UPSERT,
                ],
            ]);

            $job = new class extends BatchSyncJob {
                private int $budgetChecks = 0;

                protected function hasExceededTimeBudget(float $started): bool
                {
                    return ++$this->budgetChecks > 1;
                }
            };
            $job->execute(Craft::$app->queue);

            $backendCount = $stub->documentCounts[$index->handle] ?? null;
            $refreshed = SearchIndex::findByHandle($index->handle);
            self::assertNotNull($backendCount);
            self::assertNotNull($refreshed);
            self::assertSame(
                $backendCount,
                $refreshed->documentCount,
                'Deferred BatchSyncJob runs must reconcile successfully touched indices before exiting.',
            );
            self::assertNull(
                $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId),
                'The first row should be successfully processed before the run defers.',
            );
            self::assertNotNull(
                $this->fetchPendingRow($index->handle, $fakeElementId, (int) $element->siteId),
                'A due row must remain so the run takes the continuation path.',
            );
            self::assertCount(
                1,
                array_diff($this->batchSyncQueueIds(), $queueIdsBefore),
                'The deferred run must schedule exactly one continuation BatchSyncJob.',
            );
        } finally {
            $settings->syncBatchSize = $originalBatchSize;
            $newQueueIds = array_diff($this->batchSyncQueueIds(), $queueIdsBefore);
            if ($newQueueIds !== []) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->delete('{{%queue}}', ['id' => $newQueueIds])
                    ->execute();
            }
        }
    }

    /**
     * @return int[]
     */
    private function batchSyncQueueIds(): array
    {
        return array_map(
            'intval',
            (new Query())
                ->select(['id'])
                ->from('{{%queue}}')
                ->where(['like', 'job', 'searchmanager'])
                ->andWhere(['like', 'job', 'BatchSyncJob'])
                ->column(),
        );
    }
}
