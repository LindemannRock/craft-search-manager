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
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use yii\log\Logger;
use yii\queue\Queue;

/**
 * Regression coverage for PR1.58 pending-sync queue wake-ups.
 *
 * @since 5.54.0
 */
final class Pr158PendingSyncWakeupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteBatchQueueRows();
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteBatchQueueRows();
        } finally {
            parent::tearDown();
        }
    }

    public function testEarliestEligibilityControlsOneDbQueueWakeup(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pendingAt = $now->modify('+60 seconds');
        $failedAt = $now->modify('+120 seconds');
        $claimedAt = $now;

        $pendingId = $this->seedRow(PendingSyncRepository::STATUS_PENDING, [
            'elementId' => 158001,
            'nextAttemptAt' => Db::prepareDateForDb($pendingAt),
        ]);
        $failedId = $this->seedRow(PendingSyncRepository::STATUS_FAILED, [
            'elementId' => 158002,
            'nextAttemptAt' => Db::prepareDateForDb($failedAt),
        ]);
        $processingId = $this->seedRow(PendingSyncRepository::STATUS_PROCESSING, [
            'elementId' => 158003,
            'claimedAt' => Db::prepareDateForDb($claimedAt),
            'claimToken' => 'pr158-active-claim',
        ]);

        self::assertSame([], $this->repository->claim(10, $this->repository->getStaleCutoffSeconds()));

        $this->repository->scheduleNextEligibleBatchJob();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame([$pendingAt->getTimestamp()], $this->pendingBatchRunTimes());

        $this->setStatus($pendingId, PendingSyncRepository::STATUS_ABANDONED);
        $this->deleteBatchQueueRows();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame([$failedAt->getTimestamp()], $this->pendingBatchRunTimes());

        $this->setStatus($failedId, PendingSyncRepository::STATUS_ABANDONED);
        $this->deleteBatchQueueRows();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame(
            [$claimedAt->getTimestamp() + $this->repository->getStaleCutoffSeconds() + 1],
            $this->pendingBatchRunTimes(),
        );

        $this->setStatus($processingId, PendingSyncRepository::STATUS_ABANDONED);
        $this->deleteBatchQueueRows();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame([], $this->pendingBatchRunTimes(), 'Abandoned rows must not schedule automatic retries.');
    }

    public function testAlreadyStaleProcessingRowQueuesImmediateWakeup(): void
    {
        $staleSeconds = $this->repository->getStaleCutoffSeconds() + 2;
        $this->seedRow(PendingSyncRepository::STATUS_PROCESSING, [
            'elementId' => 158004,
            'claimedAt' => Db::prepareDateForDb(
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$staleSeconds} seconds"),
            ),
            'claimToken' => 'pr158-stale-claim',
        ]);

        $this->repository->scheduleNextEligibleBatchJob();

        $runTimes = $this->pendingBatchRunTimes();
        self::assertCount(1, $runTimes);
        self::assertLessThanOrEqual(time() + 1, $runTimes[0]);
    }

    public function testSingleAndBulkRetryOnlyQueueChangedRows(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new BatchSyncJob());
        $singleId = $this->seedRow(PendingSyncRepository::STATUS_ABANDONED, ['elementId' => 158005]);
        $bulkIdA = $this->seedRow(PendingSyncRepository::STATUS_FAILED, ['elementId' => 158006]);
        $bulkIdB = $this->seedRow(PendingSyncRepository::STATUS_ABANDONED, ['elementId' => 158007]);
        $pendingId = $this->seedRow(PendingSyncRepository::STATUS_PENDING, ['elementId' => 158008]);

        self::assertSame(1, $this->repository->retry([$singleId]));
        self::assertCount(2, $this->pendingBatchRunTimes());
        self::assertLessThanOrEqual(time() + 1, min($this->pendingBatchRunTimes()));

        self::assertSame(2, $this->repository->retry([$bulkIdA, $bulkIdB, $pendingId]));
        self::assertCount(2, $this->pendingBatchRunTimes(), 'Bulk retry must reuse the sufficient immediate wake-up.');

        self::assertSame(0, $this->repository->retry([$singleId, $pendingId]));
        self::assertCount(2, $this->pendingBatchRunTimes(), 'No-op retries must not queue work.');
    }

    public function testFailedAndCompletedDbQueueRowsDoNotBlockRequiredWork(): void
    {
        $failedId = Craft::$app->getQueue()->delay(0)->push(new BatchSyncJob());
        Craft::$app->getDb()->createCommand()
            ->update('{{%queue}}', ['fail' => true], ['id' => $failedId])
            ->execute();

        $completedId = Craft::$app->getQueue()->delay(0)->push(new BatchSyncJob());
        Craft::$app->getDb()->createCommand()
            ->update('{{%queue}}', ['timeUpdated' => time()], ['id' => $completedId])
            ->execute();

        $this->seedRow(PendingSyncRepository::STATUS_PENDING, ['elementId' => 158009]);
        $this->repository->scheduleNextEligibleBatchJob();

        self::assertCount(1, $this->pendingBatchRunTimes());
    }

    public function testUnexpectedExceptionIsPreservedAndSchedulesStaleClaimRecovery(): void
    {
        $id = $this->seedRow(PendingSyncRepository::STATUS_PENDING, ['elementId' => 158010]);
        $processor = new class extends PendingSyncProcessor {
            public function process(array $rows): array
            {
                throw new \RuntimeException('Synthetic PR1.58 processor failure');
            }
        };
        $this->swapPluginComponent('search-manager', 'pendingSyncProcessor', $processor);

        try {
            (new BatchSyncJob())->execute(Craft::$app->queue);
            self::fail('The original processor exception must escape the queue job.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic PR1.58 processor failure', $e->getMessage());
        }

        $row = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['id' => $id])
            ->one();
        self::assertIsArray($row);
        self::assertSame(PendingSyncRepository::STATUS_PROCESSING, $row['status']);
        self::assertNotEmpty($row['claimToken']);

        $expectedRunAt = $this->dbTimestamp((string)$row['claimedAt'])
            + $this->repository->getStaleCutoffSeconds()
            + 1;
        self::assertSame([$expectedRunAt], $this->pendingBatchRunTimes());
    }

    public function testOriginalExceptionEscapesWhenRecoverySchedulingAlsoFails(): void
    {
        $this->seedRow(PendingSyncRepository::STATUS_PENDING, ['elementId' => 158012]);
        $processor = new class extends PendingSyncProcessor {
            public function process(array $rows): array
            {
                throw new \RuntimeException('Synthetic original processing failure: private-marker');
            }
        };
        $repository = new class extends PendingSyncRepository {
            public function scheduleNextEligibleBatchJob(): void
            {
                throw new \RuntimeException('Synthetic recovery scheduling failure');
            }
        };
        $this->swapPluginComponent('search-manager', 'pendingSyncProcessor', $processor);
        $this->swapPluginComponent('search-manager', 'pendingSyncs', $repository);

        $logger = Craft::getLogger();
        $messagesBefore = count($logger->messages);

        try {
            (new BatchSyncJob())->execute(Craft::$app->queue);
            self::fail('The original processor exception must escape the queue job.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic original processing failure: private-marker', $e->getMessage());
        }

        $recoveryErrors = array_values(array_filter(
            array_slice($logger->messages, $messagesBefore),
            static fn(array $message): bool => ($message[1] ?? null) === Logger::LEVEL_ERROR
                && ($message[2] ?? null) === 'search-manager'
                && str_contains((string)($message[0] ?? ''), 'Unable to schedule pending-sync recovery after job failure'),
        ));
        self::assertCount(1, $recoveryErrors);

        $loggedMessage = (string)$recoveryErrors[0][0];
        self::assertStringContainsString('Synthetic recovery scheduling failure', $loggedMessage);
        self::assertStringContainsString(\RuntimeException::class, $loggedMessage);
        self::assertStringNotContainsString('private-marker', $loggedMessage);
        self::assertStringNotContainsString('claimToken', $loggedMessage);
        self::assertStringNotContainsString('trace', $loggedMessage);
    }

    public function testRecordingNonDbQueueUsesNoDbTableAssumptionAndAllowsSafeDuplicates(): void
    {
        $this->seedRow(PendingSyncRepository::STATUS_PENDING, ['elementId' => 158011]);
        $originalQueue = Craft::$app->getQueue();
        $recordingQueue = new Pr158RecordingQueue();
        Craft::$app->set('queue', $recordingQueue);

        try {
            $this->repository->scheduleNextEligibleBatchJob();
            $this->repository->scheduleNextEligibleBatchJob();
        } finally {
            Craft::$app->set('queue', $originalQueue);
        }

        self::assertSame([0, 0], $recordingQueue->delays);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(string $status, array $overrides = []): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $data = array_merge([
            'indexHandle' => '__sm_pr158',
            'elementType' => Entry::class,
            'elementId' => 158000,
            'siteId' => 1,
            'op' => PendingSyncRepository::OP_UPSERT,
            'status' => $status,
            'attemptCount' => 0,
            'queuedAt' => $now,
            'nextAttemptAt' => $now,
            'claimedAt' => null,
            'claimToken' => null,
            'dirtyAt' => null,
            'lastError' => null,
            'lastProcessedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], $overrides);

        Craft::$app->getDb()
            ->createCommand()
            ->insert('{{%searchmanager_pending_syncs}}', $data)
            ->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function setStatus(int $id, string $status): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_pending_syncs}}', ['status' => $status], ['id' => $id])
            ->execute();
    }

    /**
     * @return list<int>
     */
    private function pendingBatchRunTimes(): array
    {
        $rows = (new Query())
            ->select(['timePushed', 'delay'])
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'BatchSyncJob'])
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return array_map(
            static fn(array $row): int => (int)$row['timePushed'] + (int)$row['delay'],
            $rows,
        );
    }

    private function deleteBatchQueueRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%queue}}', [
                'and',
                ['like', 'job', 'searchmanager'],
                ['like', 'job', 'BatchSyncJob'],
            ])
            ->execute();
    }

    private function dbTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
    }
}

/**
 * Recording non-DB queue for PR1.58 portability coverage.
 *
 * @since 5.54.0
 */
final class Pr158RecordingQueue extends Queue
{
    /** @var list<int> */
    public array $delays = [];

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    /** @inheritdoc */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $this->delays[] = (int)$delay;

        return 'pr158-' . count($this->delays);
    }
}
