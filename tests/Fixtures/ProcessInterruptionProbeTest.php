<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Fixtures;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\View;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Direct-only child fixture for process-interruption ownership regressions.
 *
 * @since 5.54.0
 */
final class ProcessInterruptionProbeTest extends TestCase
{
    public function testCreatesProcessOwnedResourcesUntilInterrupted(): void
    {
        $mode = (string)($_SERVER['SM_A12_PROBE_MODE'] ?? 'interrupt');
        if (str_starts_with($mode, 'cleanup-')) {
            ProcessRunOwner::requestSyntheticCleanupFailure('intentional process-owner cleanup failure');
            if ($mode === 'cleanup-failure') {
                self::fail('intentional original PHPUnit failure');
            }
            self::assertSame('cleanup-success', $mode);

            return;
        }

        $ownerDb = $this->createIndependentDatabaseConnection();
        $transaction = $ownerDb->beginTransaction();
        $uid = StringHelper::UUID();
        $this->registerRollbackPendingRow($uid);
        $pendingId = $this->insertPermanentPendingRow($ownerDb, $uid);

        $fileRoot = $this->createOwnedStorageDirectory('interruption-file-root');
        $scriptPath = $fileRoot . '/worker.php';
        $this->trackOwnedTempPath($scriptPath);
        file_put_contents($scriptPath, "<?php\nsleep(60);\n");
        $pipePath = $fileRoot . '/worker.pipe';
        $this->trackOwnedTempPath($pipePath);
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($pipePath, 0600);
        }

        Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_file-environment-warning',
            ['backendType' => 'file', 'margin' => 'default'],
            View::TEMPLATE_MODE_CP,
        );
        $compiled = glob(Craft::$app->getRuntimePath() . '/compiled_templates/*/*.php') ?: [];
        sort($compiled, SORT_STRING);
        self::assertNotSame([], $compiled, 'The interruption probe did not create compiled Twig runtime output.');

        $pipes = [];
        $process = proc_open([PHP_BINARY, $scriptPath], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $this->registerOwnedProcess($process, $pipes, 'interruption-probe-worker');
        $workerStatus = proc_get_status($process);
        $workerIdentity = ProcessRunOwner::processIdentity((int)($workerStatus['pid'] ?? 0));
        self::assertNotNull($workerIdentity);

        ProcessRunOwner::publishProbeState([
            'ready' => true,
            'runtimePath' => Craft::$app->getRuntimePath(),
            'compiledFiles' => array_map(static fn(string $path): array => [
                'path' => $path,
                'sha256' => hash_file('sha256', $path),
            ], $compiled),
            'fileRoot' => $fileRoot,
            'scriptPath' => $scriptPath,
            'pipePath' => $pipePath,
            'worker' => $workerIdentity,
            'rollbackRow' => [
                'table' => 'searchmanager_pending_syncs',
                'id' => $pendingId,
                'uid' => $uid,
            ],
        ]);

        try {
            while (true) {
                usleep(100000);
            }
        } finally {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $ownerDb->close();
        }
    }

    private function insertPermanentPendingRow(\yii\db\Connection $db, string $uid): int
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db->createCommand()->insert('{{%searchmanager_pending_syncs}}', [
            'indexHandle' => '__sm_a12_interruption_probe__',
            'elementType' => Entry::class,
            'elementId' => 12_101,
            'siteId' => 1,
            'op' => PendingSyncRepository::OP_UPSERT,
            'status' => PendingSyncRepository::STATUS_PENDING,
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
            'uid' => $uid,
        ])->execute();

        return (int)(new Query())
            ->select(['id'])
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['uid' => $uid])
            ->scalar($db);
    }
}
