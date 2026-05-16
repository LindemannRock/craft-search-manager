<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Failure-mode coverage for the L3 pending-sync pipeline:
 *
 *   - A failed `backend->batchIndex()` does NOT delete the row — it transitions
 *     to `failed`, increments `attemptCount`, and schedules a future retry via
 *     `nextAttemptAt`.
 *   - After `batchMaxAttempts` failed attempts the row transitions to
 *     `abandoned`, taking it out of the eligible-claim window so a permanently
 *     broken row can't retry-storm the queue.
 *
 * This is the retry-storm defense. A regression here silently means every
 * stuck row burns workers forever — exactly the kind of thing that's
 * invisible until production capacity drops.
 *
 * @since 5.45.0
 */
final class SyncBufferFailureTest extends TestCase
{
    private int $originalMaxAttempts = 0;
    private int $originalFlushInterval = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalMaxAttempts = $settings->batchMaxAttempts;
        $this->originalFlushInterval = $settings->batchFlushInterval;

        // Drop the cap so the test doesn't have to drain 5+ times to exercise
        // the abandon path. flushInterval=1 keeps the backoff window narrow;
        // we still bypass it explicitly between drains via `resetBackoff()`.
        $settings->batchMaxAttempts = 2;
        $settings->batchFlushInterval = 1;
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->batchMaxAttempts = $this->originalMaxAttempts;
        $settings->batchFlushInterval = $this->originalFlushInterval;
        parent::tearDown();
    }

    public function testBatchIndexFailureMarksRowFailedAndIncrementsAttempt(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();
        $stub->failBatchIndex = true;

        $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
        $rowBefore = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($rowBefore);
        $this->assertSame('pending', $rowBefore['status']);
        $this->assertSame(0, (int) $rowBefore['attemptCount']);

        (new BatchSyncJob())->execute(Craft::$app->queue);

        $rowAfter = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($rowAfter, 'Failed row must remain in the buffer for retry, not be drained as success.');
        $this->assertSame('failed', $rowAfter['status']);
        $this->assertSame(1, (int) $rowAfter['attemptCount']);
        $this->assertNotEmpty($rowAfter['lastError']);

        // Backoff should push nextAttemptAt into the future so the same worker
        // can't immediately re-claim this row.
        $this->assertGreaterThan(
            (new \DateTime($rowBefore['nextAttemptAt']))->getTimestamp(),
            (new \DateTime($rowAfter['nextAttemptAt']))->getTimestamp(),
            'nextAttemptAt must be pushed forward after a failure.',
        );
    }

    public function testRowIsAbandonedAfterBatchMaxAttempts(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();
        $stub->failBatchIndex = true;

        $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

        // batchMaxAttempts = 2 (from setUp). Two failed attempts → abandoned.
        // Reset the backoff window between drains so we don't have to wait.
        // queueForElement can produce rows for multiple (index, site) pairs;
        // the truncate in setUp guarantees the buffer holds only our rows, so
        // a buffer-wide nextAttemptAt reset is safe.
        (new BatchSyncJob())->execute(Craft::$app->queue);
        $this->resetAllBackoff();
        (new BatchSyncJob())->execute(Craft::$app->queue);

        $row = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($row, 'Abandoned row must remain in the buffer (it is not drained).');
        $this->assertSame('abandoned', $row['status']);
        $this->assertSame(2, (int) $row['attemptCount']);

        // Abandoned rows must NOT be picked up by a subsequent claim, even
        // when their nextAttemptAt is in the past.
        $this->resetAllBackoff();
        $callsBefore = count($stub->callsFor('batchIndex'));
        (new BatchSyncJob())->execute(Craft::$app->queue);
        $callsAfter = count($stub->callsFor('batchIndex'));

        $this->assertSame(
            $callsBefore,
            $callsAfter,
            'Abandoned rows must not be re-claimed by future BatchSyncJob runs.',
        );
    }

    private function resetAllBackoff(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%searchmanager_pending_syncs}}',
                [
                    'nextAttemptAt' => Db::prepareDateForDb((new \DateTime())->modify('-1 second')),
                ],
                '1=1',
            )
            ->execute();
    }
}
