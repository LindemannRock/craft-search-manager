<?php

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Verifies that the periodic status-sync job has been converted to use the
 * L3 buffer: an entry whose `postDate` flipped it to `live` inside the
 * lookback window must result in `OP_UPSERT` rows in `pending_syncs`,
 * NOT a direct call to a backend.
 *
 * Before the conversion, `SyncStatusJob` called
 * `IndexingService::indexElement()` / `removeElement()` directly. That path
 * is now reserved for the deprecated `SyncElementJob`; new code goes
 * through `PendingSyncRepository::queueForElement()`.
 *
 * @since 5.45.0
 */
final class SyncStatusJobQueuesBufferTest extends TestCase
{
    /** @var array{0: int, 1: ?string} | null  [entryId, originalPostDate] */
    private ?array $postDateBackup = null;

    protected function tearDown(): void
    {
        // Restore the test entry's postDate so subsequent test runs see the
        // same fixture state.
        if ($this->postDateBackup !== null) {
            [$entryId, $original] = $this->postDateBackup;
            Craft::$app->getDb()
                ->createCommand()
                ->update('{{%entries}}', ['postDate' => $original], ['id' => $entryId])
                ->execute();
            $this->postDateBackup = null;
        }

        parent::tearDown();
    }

    public function testNewlyLiveEntryQueuesUpsertRowsInBuffer(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching live element.');

        [$index, $element] = $pair;
        $this->assertInstanceOf(Entry::class, $element);

        // Push the entry's postDate to 30 minutes ago so it falls inside
        // SyncStatusJob's default 1-hour lookback window. We backup the
        // original value and restore it in tearDown.
        $entryId = (int) $element->id;
        $originalPostDate = (new \craft\db\Query())
            ->select(['postDate'])
            ->from('{{%entries}}')
            ->where(['id' => $entryId])
            ->scalar();
        $this->postDateBackup = [$entryId, $originalPostDate ?: null];

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%entries}}',
                ['postDate' => Db::prepareDateForDb((new \DateTime())->modify('-30 minutes'))],
                ['id' => $entryId],
            )
            ->execute();

        // Run the job with no lastSyncTime → it defaults to 1 hour ago, so
        // our shifted postDate is inside the window. Don't reschedule —
        // pure unit-of-work test.
        $job = new SyncStatusJob([
            'reschedule' => false,
            'lastSyncTime' => null,
        ]);
        $job->execute(Craft::$app->queue);

        // The job should have queued at least one upsert row for our target
        // (element, site). queueForElement fans out to every applicable
        // (index, site) pair — we just need to see our specific one.
        $row = $this->fetchPendingRow($index->handle, $entryId, (int) $element->siteId);
        $this->assertNotNull(
            $row,
            'SyncStatusJob must queue a pending-sync row when an entry flips to live inside the lookback window.',
        );
        $this->assertSame(PendingSyncRepository::OP_UPSERT, $row['op']);
        $this->assertSame(PendingSyncRepository::STATUS_PENDING, $row['status']);
    }
}
