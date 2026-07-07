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
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Happy-path coverage for the L3 pending-sync pipeline.
 *
 * @since 5.45.0
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
        $expectedCount = $index->getExpectedCount();

        try {
            $index->updateStats(0);
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

            (new BatchSyncJob())->execute(Craft::$app->queue);

            $refreshed = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($index->handle);
            $this->assertNotNull($refreshed);
            $this->assertSame(
                $expectedCount,
                $refreshed->documentCount,
                'Completed BatchSyncJob drains must refresh documentCount metadata.',
            );
        } finally {
            $index->updateStats($originalCount);
        }
    }
}
