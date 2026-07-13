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
 * per-element indexing/removal APIs directly. All sync
 * paths now flow through `PendingSyncRepository::queueForElement()` so the
 * `BatchSyncJob` drains a single buffer.
 *
 * @since 5.45.0
 */
final class SyncStatusJobQueuesBufferTest extends TestCase
{
    /** @var array<int, ?string> entryId => originalPostDate */
    private array $postDateBackups = [];

    protected function tearDown(): void
    {
        // Restore test entry postDates so subsequent test runs see the
        // same fixture state.
        foreach ($this->postDateBackups as $entryId => $original) {
            Craft::$app->getDb()
                ->createCommand()
                ->update('{{%entries}}', ['postDate' => $original], ['id' => $entryId])
                ->execute();
        }
        $this->postDateBackups = [];

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
        $this->shiftPostDateInsideStatusWindow($entryId);

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

    public function testStatusQueriesAvoidCustomFieldHydration(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/jobs/SyncStatusJob.php');
        $this->assertIsString($source);

        $queueBody = $this->methodBody($source, 'queueStatusEntries');

        $this->assertSame(1, substr_count($source, '->withCustomFields(false)'));
        $this->assertSame(1, substr_count($source, '->drafts(false)'));
        $this->assertSame(1, substr_count($source, '->revisions(false)'));
        $this->assertStringNotContainsString('->limit(null)', $source);
        $this->assertStringNotContainsString('Entry::find()->siteId($siteId)->all()', $source);
        $this->assertStringContainsString('->limit($batchSize)', $queueBody);
        $this->assertStringContainsString("->andWhere(['>', 'elements.id', \$lastElementId])", $queueBody);
        $this->assertStringContainsString("->orderBy(['elements.id' => SORT_ASC])", $queueBody);
        $this->assertStringContainsString('while (count($entries) === $batchSize)', $queueBody);

        $this->assertStringContainsString("PendingSyncRepository::OP_UPSERT", $source);
        $this->assertStringContainsString("PendingSyncRepository::OP_DELETE", $source);
        $this->assertStringContainsString('$repository->queueForElement($entry, $operation)', $queueBody);
    }

    public function testStatusSyncProcessesMultipleBoundedBatches(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with matching live elements.');

        [$index, $element] = $pair;
        $siteId = (int) $element->siteId;
        $entries = $this->findMatchingLiveEntries((int) $siteId, 2);

        $this->assertCount(
            2,
            $entries,
            'Test install must have two live entries matching the same enabled Entry index to prove multi-batch status sync.',
        );

        foreach ($entries as $entry) {
            $this->shiftPostDateInsideStatusWindow((int) $entry->id);
        }

        $job = new class([
            'reschedule' => false,
            'lastSyncTime' => null,
        ]) extends SyncStatusJob {
            protected function statusSyncBatchSize(): int
            {
                return 1;
            }
        };
        $job->execute(Craft::$app->queue);

        foreach ($entries as $entry) {
            $row = $this->fetchPendingRow($index->handle, (int) $entry->id, $siteId);
            $this->assertNotNull($row);
            $this->assertSame(PendingSyncRepository::OP_UPSERT, $row['op']);
            $this->assertSame(PendingSyncRepository::STATUS_PENDING, $row['status']);
        }
    }

    /**
     * @return list<Entry>
     */
    private function findMatchingLiveEntries(int $siteId, int $limit): array
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index] = $pair;

        $entries = Entry::find()
            ->siteId($siteId)
            ->status('live')
            ->drafts(false)
            ->revisions(false)
            ->limit(200)
            ->all();

        $matches = [];
        foreach ($entries as $entry) {
            if (!$index->matchesElement($entry)) {
                continue;
            }

            $matches[] = $entry;
            if (count($matches) === $limit) {
                break;
            }
        }

        return $matches;
    }

    private function shiftPostDateInsideStatusWindow(int $entryId): void
    {
        if (!array_key_exists($entryId, $this->postDateBackups)) {
            $originalPostDate = (new \craft\db\Query())
                ->select(['postDate'])
                ->from('{{%entries}}')
                ->where(['id' => $entryId])
                ->scalar();
            $this->postDateBackups[$entryId] = $originalPostDate ?: null;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%entries}}',
                ['postDate' => Db::prepareDateForDb((new \DateTime())->modify('-30 minutes'))],
                ['id' => $entryId],
            )
            ->execute();
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/(?:public|protected|private) function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
