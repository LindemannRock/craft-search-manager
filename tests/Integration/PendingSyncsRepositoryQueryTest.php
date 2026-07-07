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
use craft\helpers\Db;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Covers the repository surface that powers the CP Pending Syncs view:
 *
 *   - `search()` filters/sorts/pages as advertised and never interpolates
 *     user-supplied sort columns into ORDER BY.
 *   - `retry()` resets rows to pending and clears claim metadata, but refuses
 *     to yank a row out from under a fresh worker (status=processing with a
 *     claimedAt newer than the stale cutoff).
 *   - `deleteByIds()` honours the same fresh-processing guard.
 *   - `purgeByStatus()` removes every row at a given status and validates
 *     the status value (defence against an unsanitised CP request).
 *
 * @since 5.45.0
 */
final class PendingSyncsRepositoryQueryTest extends TestCase
{
    public function testSearchAppliesStatusAndIndexFilters(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        // Seed three rows with distinct statuses + a row on a different index
        // so we can prove the filters actually narrow the result set.
        $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'pending');
        $this->seedRow($index->handle, (int) $element->id + 1, (int) $element->siteId, 'failed');
        $this->seedRow($index->handle, (int) $element->id + 2, (int) $element->siteId, 'abandoned');
        $this->seedRow('different-index', (int) $element->id, (int) $element->siteId, 'pending');

        $byStatus = $this->repository->search(['status' => 'failed'], 'queuedAt', 'asc', 50, 0);
        $this->assertSame(1, $byStatus['total']);
        $this->assertSame('failed', $byStatus['rows'][0]['status']);

        $byIndex = $this->repository->search(['indexHandle' => $index->handle], 'queuedAt', 'asc', 50, 0);
        $this->assertSame(3, $byIndex['total'], 'Filter must isolate rows for the requested index.');
    }

    public function testSearchRejectsUnknownSortColumn(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'pending');

        // Even with a malicious-looking sort key, the query must succeed and
        // fall back to the default order — no SQL interpolation.
        $result = $this->repository->search([], 'id) OR 1=1 --', 'asc', 50, 0);
        $this->assertSame(1, $result['total']);
    }

    public function testSearchPaginates(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        for ($i = 0; $i < 12; $i++) {
            $this->seedRow($index->handle, (int) $element->id + $i, (int) $element->siteId, 'pending');
        }

        $page1 = $this->repository->search([], 'queuedAt', 'asc', 5, 0);
        $page2 = $this->repository->search([], 'queuedAt', 'asc', 5, 5);
        $page3 = $this->repository->search([], 'queuedAt', 'asc', 5, 10);

        $this->assertSame(12, $page1['total']);
        $this->assertCount(5, $page1['rows']);
        $this->assertCount(5, $page2['rows']);
        $this->assertCount(2, $page3['rows']);
    }

    public function testRetryResetsRowStateToPending(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        $id = $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'abandoned', [
            'attemptCount' => 5,
            'lastError' => 'auth failed: 401',
            'claimToken' => 'leftover-token',
            'claimedAt' => Db::prepareDateForDb((new \DateTime())->modify('-1 hour')),
            'lastProcessedAt' => Db::prepareDateForDb((new \DateTime())->modify('-1 hour')),
        ]);

        $updated = $this->repository->retry([$id]);
        $this->assertSame(1, $updated);

        $row = (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $id])->one();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attemptCount']);
        $this->assertNull($row['claimToken']);
        $this->assertNull($row['claimedAt']);
        $this->assertNull($row['lastError']);
        $this->assertNull($row['lastProcessedAt']);
    }

    public function testRetrySkipsFreshlyProcessingRow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        $id = $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'processing', [
            'claimToken' => 'live-worker',
            'claimedAt' => Db::prepareDateForDb(new \DateTime()),
        ]);

        $updated = $this->repository->retry([$id]);
        $this->assertSame(0, $updated, 'Fresh processing rows must not be reset under an active worker.');

        $row = (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $id])->one();
        $this->assertSame('processing', $row['status']);
        $this->assertSame('live-worker', $row['claimToken']);
    }

    public function testRetryIgnoresProcessingAndPendingRowsEvenIfStale(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        // Stale processing — historically retriable; new semantic says retry
        // is meaningless because the next `claim()` will re-pick it anyway.
        $staleAgo = $this->repository->getStaleCutoffSeconds() + 60;
        $staleId = $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'processing', [
            'claimToken' => 'orphaned-worker',
            'claimedAt' => Db::prepareDateForDb((new \DateTime())->modify("-{$staleAgo} seconds")),
        ]);

        // Pending — already queued; retry would be a no-op reset.
        $pendingId = $this->seedRow($index->handle, (int) $element->id + 1, (int) $element->siteId, 'pending');

        $updated = $this->repository->retry([$staleId, $pendingId]);
        $this->assertSame(0, $updated, 'Retry must only act on failed/abandoned rows. Processing (any age) and pending are ignored.');
    }

    public function testDeleteByIdsRemovesRowsButHonoursFreshProcessingGuard(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        $deletableId = $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'failed');
        $protectedId = $this->seedRow($index->handle, (int) $element->id + 1, (int) $element->siteId, 'processing', [
            'claimToken' => 'live-worker',
            'claimedAt' => Db::prepareDateForDb(new \DateTime()),
        ]);

        $deleted = $this->repository->deleteByIds([$deletableId, $protectedId]);
        $this->assertSame(1, $deleted);

        $remaining = (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $protectedId])->one();
        $this->assertNotNull($remaining, 'Fresh processing row must survive a delete attempt.');
    }

    public function testPurgeByStatusOnlyTouchesRequestedStatus(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        $this->seedRow($index->handle, (int) $element->id, (int) $element->siteId, 'abandoned');
        $this->seedRow($index->handle, (int) $element->id + 1, (int) $element->siteId, 'abandoned');
        $survivor = $this->seedRow($index->handle, (int) $element->id + 2, (int) $element->siteId, 'pending');

        $deleted = $this->repository->purgeByStatus(PendingSyncRepository::STATUS_ABANDONED);
        $this->assertSame(2, $deleted);

        $rows = (new Query())->from('{{%searchmanager_pending_syncs}}')->all();
        $this->assertCount(1, $rows);
        $this->assertSame($survivor, (int) $rows[0]['id']);
    }

    public function testPurgeByStatusRejectsUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->purgeByStatus('not-a-real-status');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(string $indexHandle, int $elementId, int $siteId, string $status, array $overrides = []): int
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $data = array_merge([
            'indexHandle' => $indexHandle,
            'elementType' => Entry::class,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'op' => PendingSyncRepository::OP_UPSERT,
            'status' => $status,
            'attemptCount' => 0,
            'queuedAt' => $now,
            'nextAttemptAt' => $now,
            'claimedAt' => null,
            'claimToken' => null,
            'lastError' => null,
            'lastProcessedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ], $overrides);

        Craft::$app->getDb()
            ->createCommand()
            ->insert('{{%searchmanager_pending_syncs}}', $data)
            ->execute();

        return (int) Craft::$app->getDb()->getLastInsertID();
    }
}
