<?php

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Event;

/**
 * Verifies that the batch sync pipeline preserves the public event contract
 * of `IndexingService::indexElementNow()`:
 *
 *   - `EVENT_BEFORE_INDEX` fires for elements that pass all gates, once per
 *     (element, site) pair per BatchSyncJob run.
 *   - `EVENT_AFTER_INDEX` fires only for rows that were included in a
 *     successful `backend->batchIndex()` call.
 *   - Cancelling `EVENT_BEFORE_INDEX` via `$event->isValid = false` skips ALL
 *     rows for that (element, site) pair without an error/retry.
 *
 * This was a regression caught during L3 close-out before commit.
 *
 * @since 5.45.0
 */
final class SyncBufferEventParityTest extends TestCase
{
    /** @var list<IndexEvent> */
    private array $beforeEvents = [];

    /** @var list<IndexEvent> */
    private array $afterEvents = [];

    private ?\Closure $beforeHandler = null;
    private ?\Closure $afterHandler = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeEvents = [];
        $this->afterEvents = [];

        $this->beforeHandler = function (IndexEvent $event): void {
            $this->beforeEvents[] = $event;
        };
        $this->afterHandler = function (IndexEvent $event): void {
            $this->afterEvents[] = $event;
        };

        Event::on(IndexingService::class, IndexingService::EVENT_BEFORE_INDEX, $this->beforeHandler);
        Event::on(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $this->afterHandler);
    }

    protected function tearDown(): void
    {
        if ($this->beforeHandler !== null) {
            Event::off(IndexingService::class, IndexingService::EVENT_BEFORE_INDEX, $this->beforeHandler);
        }
        if ($this->afterHandler !== null) {
            Event::off(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $this->afterHandler);
        }
        $this->beforeHandler = null;
        $this->afterHandler = null;
        parent::tearDown();
    }

    public function testBeforeAndAfterIndexFireForSuccessfulBatchSync(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

        $this->drainUntilEmpty($index->handle, (int) $element->id, (int) $element->siteId);

        $beforeForTarget = array_filter(
            $this->beforeEvents,
            static fn (IndexEvent $e): bool => $e->element !== null
                && (int) $e->element->id === (int) $element->id
                && (int) $e->element->siteId === (int) $element->siteId,
        );
        $afterForTarget = array_filter(
            $this->afterEvents,
            static fn (IndexEvent $e): bool => $e->element !== null
                && (int) $e->element->id === (int) $element->id
                && (int) $e->element->siteId === (int) $element->siteId
                && $e->indexHandle === $index->handle,
        );

        $this->assertCount(1, $beforeForTarget, 'EVENT_BEFORE_INDEX should fire exactly once per (element, site) per batch run.');
        $this->assertCount(1, $afterForTarget, 'EVENT_AFTER_INDEX should fire exactly once for the target (element, index).');

        /** @var IndexEvent $after */
        $after = array_values($afterForTarget)[0];
        $this->assertNotNull($after->document, 'EVENT_AFTER_INDEX must carry the transformed document data.');
        $this->assertSame($index->handle, $after->indexHandle);
    }

    public function testBeforeIndexCancelSkipsAllRowsForThatElementSite(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $targetElementId = (int) $element->id;
        $targetSiteId = (int) $element->siteId;

        // Attach a second listener that cancels indexing for our target.
        $cancelHandler = static function (IndexEvent $event) use ($targetElementId, $targetSiteId): void {
            if ($event->element === null) {
                return;
            }
            if ((int) $event->element->id === $targetElementId && (int) $event->element->siteId === $targetSiteId) {
                $event->isValid = false;
            }
        };
        Event::on(IndexingService::class, IndexingService::EVENT_BEFORE_INDEX, $cancelHandler);

        try {
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
            $this->drainUntilEmpty($index->handle, $targetElementId, $targetSiteId);

            // BEFORE fired (once, dedup'd across the batch). AFTER did NOT fire
            // because the cancellation should drop the row before batchIndex.
            $afterForTarget = array_filter(
                $this->afterEvents,
                static fn (IndexEvent $e): bool => $e->element !== null
                    && (int) $e->element->id === $targetElementId
                    && (int) $e->element->siteId === $targetSiteId
                    && $e->indexHandle === $index->handle,
            );
            $this->assertCount(
                0,
                $afterForTarget,
                'EVENT_AFTER_INDEX must NOT fire for rows cancelled by an EVENT_BEFORE_INDEX listener.',
            );

            // Row should have been marked as successfully handled (drained,
            // not retried). Empty buffer for the target = pass.
            $this->assertNull(
                $this->fetchPendingRow($index->handle, $targetElementId, $targetSiteId),
                'Cancelled row should be drained as successfully handled (no retry storm).',
            );
        } finally {
            Event::off(IndexingService::class, IndexingService::EVENT_BEFORE_INDEX, $cancelHandler);
        }
    }

    private function drainUntilEmpty(string $indexHandle, int $elementId, int $siteId, int $maxIterations = 50): void
    {
        $iterations = 0;
        while ($this->fetchPendingRow($indexHandle, $elementId, $siteId) !== null) {
            (new BatchSyncJob())->execute(Craft::$app->queue);
            $iterations++;
            $this->assertLessThanOrEqual($maxIterations, $iterations, 'BatchSyncJob did not drain the target row within the iteration cap.');
        }
    }
}
