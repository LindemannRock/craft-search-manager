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
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit finding #306.
 *
 * @since 5.53.0
 */
final class AuditItem306RegressionTest extends TestCase
{
    private const MARKER_PREFIX = '__sm_audit306_';

    public function testResaveMidFlightThenMarkSucceededDoesNotDropRequeuedSync(): void
    {
        $id = $this->seedProcessingRow(self::MARKER_PREFIX . 'resave', 306001, 1, 'claim-a');

        $submitted = $this->repository->upsertRows([
            [
                'indexHandle' => self::MARKER_PREFIX . 'resave',
                'elementType' => Entry::class,
                'elementId' => 306001,
                'siteId' => 1,
                'op' => PendingSyncRepository::OP_UPSERT,
            ],
        ]);

        self::assertSame(1, $submitted);

        $dirtyRow = $this->fetchRowById($id);
        self::assertNotNull($dirtyRow);
        self::assertSame(PendingSyncRepository::STATUS_PROCESSING, $dirtyRow['status']);
        self::assertSame('claim-a', $dirtyRow['claimToken']);
        self::assertNotEmpty($dirtyRow['dirtyAt']);

        $this->repository->markSucceeded([$id], 'different-claim');
        $mismatchedRow = $this->fetchRowById($id);
        self::assertNotNull($mismatchedRow, 'A completion from another claim must not touch the row.');
        self::assertSame(PendingSyncRepository::STATUS_PROCESSING, $mismatchedRow['status']);
        self::assertSame('claim-a', $mismatchedRow['claimToken']);
        self::assertNotEmpty($mismatchedRow['dirtyAt']);

        $this->repository->markSucceeded([$id], 'claim-a');

        $requeuedRow = $this->fetchRowById($id);
        self::assertNotNull($requeuedRow, 'Dirty processing rows must be re-queued, not deleted, when the active claim completes.');
        self::assertSame(PendingSyncRepository::STATUS_PENDING, $requeuedRow['status']);
        self::assertSame(0, (int)$requeuedRow['attemptCount']);
        self::assertNull($requeuedRow['claimToken']);
        self::assertNull($requeuedRow['claimedAt']);
        self::assertNull($requeuedRow['dirtyAt']);
    }

    public function testDirtyProcessingRowIsNotReclaimableUntilCompletion(): void
    {
        $id = $this->seedProcessingRow(self::MARKER_PREFIX . 'claim', 306002, 1, 'claim-b');

        $this->repository->upsertRows([
            [
                'indexHandle' => self::MARKER_PREFIX . 'claim',
                'elementType' => Entry::class,
                'elementId' => 306002,
                'siteId' => 1,
                'op' => PendingSyncRepository::OP_UPSERT,
            ],
        ]);

        $claimedWhileDirty = $this->repository->claim(10, 300);
        self::assertSame([], $claimedWhileDirty, 'A fresh dirty processing row must stay owned by its active claim.');

        $this->repository->markSucceeded([$id], 'claim-b');

        $claimedAfterCompletion = $this->repository->claim(10, 300);
        self::assertCount(1, $claimedAfterCompletion);
        self::assertSame($id, (int)$claimedAfterCompletion[0]['id']);
        self::assertSame(PendingSyncRepository::STATUS_PROCESSING, $claimedAfterCompletion[0]['status']);
        self::assertNotSame('claim-b', $claimedAfterCompletion[0]['claimToken']);
    }

    public function testDirtyProcessingRowRetryPreservesAttemptCountAndBacksOff(): void
    {
        $id = $this->seedProcessingRow(
            self::MARKER_PREFIX . 'retry',
            306003,
            1,
            'claim-c',
            2,
            true,
        );

        $before = $this->fetchRowById($id);
        self::assertNotNull($before);
        self::assertNotEmpty($before['dirtyAt']);

        $this->repository->markRetry([$id], 'backend failed', 5, 10, 'claim-c');

        $row = $this->fetchRowById($id);
        self::assertNotNull($row);
        self::assertSame(PendingSyncRepository::STATUS_FAILED, $row['status']);
        self::assertSame(2, (int)$row['attemptCount']);
        self::assertNull($row['claimedAt']);
        self::assertNull($row['claimToken']);
        self::assertNull($row['dirtyAt']);
        self::assertSame('backend failed', $row['lastError']);
        self::assertGreaterThan(
            (new \DateTime($before['nextAttemptAt']))->getTimestamp(),
            (new \DateTime($row['nextAttemptAt']))->getTimestamp(),
            'Dirty failed rows must honor retry backoff instead of resetting to immediate pending work.',
        );
    }

    public function testDirtyProcessingRowAtMaxAttemptsIsAbandoned(): void
    {
        $id = $this->seedProcessingRow(
            self::MARKER_PREFIX . 'abandon',
            306004,
            1,
            'claim-d',
            3,
            true,
        );

        $this->repository->markRetry([$id], 'backend still failed', 3, 10, 'claim-d');

        $row = $this->fetchRowById($id);
        self::assertNotNull($row);
        self::assertSame(PendingSyncRepository::STATUS_ABANDONED, $row['status']);
        self::assertSame(3, (int)$row['attemptCount']);
        self::assertNull($row['claimToken']);
        self::assertNull($row['dirtyAt']);
        self::assertSame('backend still failed', $row['lastError']);
        self::assertNotEmpty($row['lastProcessedAt']);
    }

    private function seedProcessingRow(
        string $indexHandle,
        int $elementId,
        int $siteId,
        string $claimToken,
        int $attemptCount = 1,
        bool $dirty = false,
    ): int
    {
        $now = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()
            ->createCommand()
            ->insert('{{%searchmanager_pending_syncs}}', [
                'indexHandle' => $indexHandle,
                'elementType' => Entry::class,
                'elementId' => $elementId,
                'siteId' => $siteId,
                'op' => PendingSyncRepository::OP_UPSERT,
                'status' => PendingSyncRepository::STATUS_PROCESSING,
                'attemptCount' => $attemptCount,
                'queuedAt' => $now,
                'nextAttemptAt' => $now,
                'claimedAt' => $now,
                'claimToken' => $claimToken,
                'dirtyAt' => $dirty ? $now : null,
                'lastError' => null,
                'lastProcessedAt' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])
            ->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRowById(int $id): ?array
    {
        $row = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['id' => $id])
            ->one();

        return $row === false ? null : $row;
    }
}
