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
use craft\cache\FileCache;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Support\OwnedAnalyticsTracker;
use lindemannrock\searchmanager\tests\Support\OwnedProcessRegistry;
use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\db\Connection;

/**
 * Protected-owner controls for the A12-1 isolation boundary.
 *
 * @since 5.54.0
 */
final class A12OwnerIsolationRegressionTest extends TestCase
{
    public function testOwnedClaimDoesNotTouchProtectedPendingRow(): void
    {
        $ownerDb = $this->createIndependentDatabaseConnection();
        $ownerTransaction = $ownerDb->beginTransaction();
        $protectedId = null;
        $this->registerOwnedCleanup(static function() use ($ownerDb, $ownerTransaction, &$protectedId): void {
            try {
                if ($ownerTransaction->getIsActive()) {
                    $ownerTransaction->rollBack();
                }
                if ($protectedId !== null && (int)(new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $protectedId])->count('*', $ownerDb) !== 0) {
                    throw new \RuntimeException("Protected pending-sync row {$protectedId} survived transaction rollback.");
                }
            } finally {
                $ownerDb->close();
            }
        });

        $protectedUid = StringHelper::UUID();
        $this->registerRollbackPendingRow($protectedUid);
        $protectedId = $this->insertPendingRow($ownerDb, '__sm_a12_protected__', 12_001, $protectedUid);
        $protectedBefore = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['id' => $protectedId])
            ->one($ownerDb);
        $this->repository->upsertRows([[
            'indexHandle' => '__sm_a12_owned__',
            'elementType' => Entry::class,
            'elementId' => 12_002,
            'siteId' => 1,
            'op' => PendingSyncRepository::OP_UPSERT,
        ]]);
        $ownedId = (int)(new Query())
            ->select(['id'])
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['indexHandle' => '__sm_a12_owned__', 'elementId' => 12_002, 'siteId' => 1])
            ->scalar();

        $claimed = $this->repository->claim(100, 300);

        self::assertSame(PendingSyncRepository::class, $this->repository::class);
        self::assertSame([$ownedId], array_map('intval', array_column($claimed, 'id')));
        $protected = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['id' => $protectedId])
            ->one($ownerDb);
        self::assertSame($protectedBefore, $protected);
        self::assertIsArray($protected);
        self::assertSame(PendingSyncRepository::STATUS_PENDING, $protected['status']);
        self::assertSame(0, (int)$protected['attemptCount']);
        self::assertNull($protected['claimToken']);
    }

    public function testQueueWritesStayOutOfPermanentQueue(): void
    {
        $ownerDb = $this->createIndependentDatabaseConnection();
        $this->registerOwnedCleanup(static fn() => $ownerDb->close());
        $before = $this->permanentQueueFingerprint($ownerDb);
        $id = Craft::$app->getQueue()->push(new BatchSyncJob());

        self::assertGreaterThan(0, (int)$id);
        self::assertSame(1, (int)(new Query())->from($this->queueTable())->where(['id' => $id])->count());
        self::assertSame($before, $this->permanentQueueFingerprint($ownerDb));
    }

    public function testCacheClearUsesTheIsolatedRuntimeComponent(): void
    {
        $cache = Craft::$app->getCache();
        self::assertInstanceOf(FileCache::class, $cache);
        self::assertStringStartsWith(Craft::$app->getRuntimePath(), $cache->cachePath);

        SearchManager::$plugin->backend->clearAllSearchCache();

        self::assertInstanceOf(FileCache::class, Craft::$app->getCache());
        self::assertStringStartsWith(Craft::$app->getRuntimePath(), Craft::$app->getCache()->cachePath);
    }

    public function testAnalyticsCleanupPreservesBaselineRowWithSamePrefix(): void
    {
        $prefix = '__sm_a12_owner_control_';
        $protectedId = $this->insertAnalyticsRow($prefix . 'protected');
        $tracker = OwnedAnalyticsTracker::forQueryPrefix($prefix);
        $ownedId = $this->insertAnalyticsRow($prefix . 'owned');

        $cleaned = $tracker->cleanupOwnedRows();

        self::assertContains($ownedId, $cleaned['{{%searchmanager_analytics}}']);
        self::assertSame(1, (int)(new Query())->from('{{%searchmanager_analytics}}')->where(['id' => $protectedId])->count());
        self::assertSame(0, (int)(new Query())->from('{{%searchmanager_analytics}}')->where(['id' => $ownedId])->count());
        Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_analytics}}', ['id' => $protectedId])->execute();
    }

    public function testProcessRegistryTerminatesAndReapsExceptionalPathChild(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for A12 process ownership coverage.');
        }

        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $registry = new OwnedProcessRegistry();
        $registry->register($process, $pipes);

        $started = microtime(true);
        $registry->cleanup();

        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertFalse(is_resource($process));
    }

    public function testAbruptPhpUnitParentTerminationCleansExactRunResources(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            self::markTestSkipped('proc_open and POSIX signals are required for A12 interruption coverage.');
        }

        $protectedDb = $this->createIndependentDatabaseConnection();
        $protectedTransaction = $protectedDb->beginTransaction();
        $protectedUid = StringHelper::UUID();
        $this->registerRollbackPendingRow($protectedUid);
        $protectedId = $this->insertPendingRow($protectedDb, '__sm_a12_interruption_protected__', 12_100, $protectedUid);
        $protectedBefore = (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $protectedId])->one($protectedDb);
        $protectedPath = $this->createOwnedTempDirectory('interruption-protected');
        $protectedFile = $protectedPath . '/owner.txt';
        file_put_contents($protectedFile, 'byte-identical owner state');
        $protectedFileHash = hash_file('sha256', $protectedFile);

        $observerDb = $this->createIndependentDatabaseConnection();
        $observerDb->createCommand('SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED')->execute();
        $nested = null;
        $journalDirectory = null;
        try {
            $nested = $this->startProbe('interrupt');
            $journalDirectory = $nested['startup']['journalDirectory'];
            $journal = $this->waitForProbeState($journalDirectory);
            $probe = $journal['probe'];

            self::assertTrue($probe['ready']);
            self::assertNotSame([], $probe['compiledFiles']);
            foreach ($probe['compiledFiles'] as $compiledFile) {
                self::assertFileExists($compiledFile['path']);
                self::assertSame($compiledFile['sha256'], hash_file('sha256', $compiledFile['path']));
            }
            self::assertTrue(ProcessRunOwner::processIdentity((int)$probe['worker']['pid']) === $probe['worker']);
            self::assertSame(1, (int)(new Query())
                ->from('{{%searchmanager_pending_syncs}}')
                ->where(['uid' => $probe['rollbackRow']['uid']])
                ->count('*', $observerDb));

            $supervisor = $nested['startup']['supervisor'];
            self::assertSame($supervisor, ProcessRunOwner::processIdentity((int)$supervisor['pid']));
            $interruptedAt = microtime(true);
            self::assertTrue(posix_kill((int)$supervisor['pid'], SIGKILL));
            $startup = $nested['startup'];
            $command = $nested['command'];
            $initialStderr = $nested['stderr'];
            $startedAt = $nested['startedAt'];
            $result = $this->finishOwnedProcess($nested['process'], $nested['pipes']);
            $nested = null;
            $report = $this->waitForProcessOwnerReport($journalDirectory);

            self::assertSame('supervisor-terminated', $report['reason']);
            self::assertTrue($report['success'], implode(' | ', $report['errors']));
            self::assertTrue($report['resourcesClean']);
            self::assertFalse(file_exists($startup['runRoot']));
            self::assertFalse(file_exists($startup['storageRoot']));
            self::assertFalse(file_exists($probe['fileRoot']));
            self::assertNull(ProcessRunOwner::processIdentity((int)$probe['worker']['pid']));
            self::assertNull(ProcessRunOwner::processIdentity((int)$startup['phpunit']['pid']));
            self::assertSame(0, (int)(new Query())
                ->from('{{%searchmanager_pending_syncs}}')
                ->where(['uid' => $probe['rollbackRow']['uid']])
                ->count('*', $observerDb));
            self::assertSame($protectedBefore, (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['id' => $protectedId])->one($protectedDb));
            self::assertSame($protectedFileHash, hash_file('sha256', $protectedFile));
            self::assertTrue($result['signaled'] || $result['termSignal'] === SIGKILL || $result['exitCode'] === 128 + SIGKILL);

            $evidence = [
                'command' => $command,
                'supervisorPid' => $supervisor['pid'],
                'phpunitPid' => $journal['phpunit']['pid'],
                'watchdogPid' => $journal['watchdog']['pid'],
                'workerPid' => $probe['worker']['pid'],
                'exitCode' => $result['exitCode'],
                'signaled' => $result['signaled'],
                'termSignal' => $result['termSignal'],
                'stderr' => trim($initialStderr . $result['error']),
                'elapsedSeconds' => microtime(true) - $startedAt,
                'interruptToCleanupSeconds' => microtime(true) - $interruptedAt,
                'peakMemoryKb' => $report['peakMemoryKb'],
                'runRoot' => $journal['runRoot'],
                'storageRoot' => $journal['storageRoot'],
                'report' => $report,
                'protectedPendingRowSha256' => hash('sha256', serialize($protectedBefore)),
                'protectedFileSha256' => $protectedFileHash,
            ];
            $evidencePath = $_SERVER['SM_A12_INTERRUPTION_EVIDENCE_PATH'] ?? null;
            if (is_string($evidencePath) && $evidencePath !== '') {
                file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
                chmod($evidencePath, 0600);
            }

            ProcessRunOwner::acknowledgeExternalReport($journalDirectory);
            $journalDirectory = null;
        } finally {
            if (is_array($nested)) {
                $identity = $nested['startup']['supervisor'] ?? null;
                if (is_array($identity) && ProcessRunOwner::processIdentity((int)$identity['pid']) === $identity) {
                    posix_kill((int)$identity['pid'], SIGKILL);
                }
                $this->finishOwnedProcess($nested['process'], $nested['pipes'], true);
            }
            if (is_string($journalDirectory) && is_file($journalDirectory . '/report.json')) {
                $report = ProcessRunOwner::readExternalReport($journalDirectory);
                if ($report['resourcesClean'] ?? false) {
                    ProcessRunOwner::acknowledgeExternalReport($journalDirectory);
                }
            }
            if ($protectedTransaction->getIsActive()) {
                $protectedTransaction->rollBack();
            }
            $protectedDb->close();
            $observerDb->close();
        }
    }

    public function testSupervisorSurfacesCleanupFailureWithoutHidingOriginalResult(): void
    {
        $successfulTest = $this->runProbeToCompletion('cleanup-success');
        self::assertSame(70, $successfulTest['exitCode']);
        self::assertStringContainsString('intentional process-owner cleanup failure', $successfulTest['error']);

        $failedTest = $this->runProbeToCompletion('cleanup-failure');
        self::assertSame(1, $failedTest['exitCode']);
        self::assertStringContainsString('intentional original PHPUnit failure', $failedTest['output']);
        self::assertStringContainsString('intentional process-owner cleanup failure', $failedTest['error']);
    }

    #[DataProvider('startupFailureProvider')]
    public function testStartupFailureTransactionLeavesNoOwnedResources(
        string $failurePoint,
        string $expectedRoute,
        ?string $cleanupFailure,
    ): void {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for A12 startup ownership coverage.');
        }

        $protectedRoot = $this->createOwnedTempDirectory('startup-failure-protected');
        $protectedFile = $protectedRoot . '/owner.txt';
        file_put_contents($protectedFile, 'byte-identical startup owner state');
        $protectedHash = hash_file('sha256', $protectedFile);
        $result = $this->runStartupFailureProbe($failurePoint, $cleanupFailure);

        self::assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);
        self::assertSame($expectedRoute, $payload['evidence']['route']);
        self::assertSame($payload['exception']['class'], $payload['evidence']['originalClass']);
        self::assertSame($payload['exception']['message'], $payload['evidence']['originalMessage']);
        self::assertFalse(in_array(false, $payload['evidence']['resources'], true));
        self::assertSame($payload['journalFingerprintBefore'], $payload['journalFingerprintAfter']);
        self::assertSame($protectedHash, hash_file('sha256', $protectedFile));
        if ($cleanupFailure !== null) {
            self::assertNotSame([], $payload['evidence']['cleanupErrors']);
            self::assertStringContainsString(
                "injected {$cleanupFailure} startup cleanup failure",
                strtolower($result['error']),
            );
        }
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function startupFailureProvider(): iterable
    {
        yield 'partial journal directory' => ['journal-directory', 'local', null];
        yield 'partial secret' => ['secret', 'local', null];
        yield 'partial signed journal' => ['journal', 'local', null];
        yield 'partial run root' => ['run-root', 'local', null];
        yield 'partial storage root' => ['storage-root', 'local', null];
        yield 'watchdog proc-open' => ['watchdog-proc-open', 'local', null];
        yield 'watchdog identity' => ['watchdog-identity', 'local', null];
        yield 'gate creation' => ['gate', 'watched', null];
        yield 'fork' => ['fork', 'watched', null];
        yield 'child identity' => ['child-identity', 'watched', null];
        yield 'local cleanup warning' => ['run-root', 'local', 'local'];
        yield 'watched cleanup warning' => ['gate', 'watched', 'watched'];
    }

    private function insertPendingRow(Connection $db, string $indexHandle, int $elementId, ?string $uid = null): int
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db->createCommand()->insert('{{%searchmanager_pending_syncs}}', [
            'indexHandle' => $indexHandle,
            'elementType' => Entry::class,
            'elementId' => $elementId,
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
            'uid' => $uid ?? StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }

    private function insertAnalyticsRow(string $query): int
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => '__sm_a12_owner_control__',
            'query' => $query,
            'normalizedQuery' => $query,
            'resultsCount' => 0,
            'executionTime' => 0,
            'backend' => 'test-a12-owner-control',
            'siteId' => 999_912,
            'sessionId' => null,
            'isHit' => 0,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function permanentQueueFingerprint(Connection $db): string
    {
        $rows = (new Query())->from('{{%queue}}')->orderBy(['id' => SORT_ASC])->all($db);

        return hash('sha256', serialize($rows));
    }

    /** @return array{process: resource, pipes: array<int, resource>, command: list<string>, startup: array<string, mixed>, stderr: string, startedAt: float} */
    private function startProbe(string $mode): array
    {
        $pluginRoot = dirname(__DIR__, 2);
        $command = [
            '/usr/bin/env',
            'SM_A12_PROCESS_OWNER_TRACE=1',
            'SM_A12_PROBE_MODE=' . $mode,
            PHP_BINARY,
            dirname($pluginRoot, 2) . '/vendor/bin/phpunit',
            '--configuration',
            $pluginRoot . '/phpunit.xml.dist',
            $pluginRoot . '/tests/Fixtures/ProcessInterruptionProbeTest.php',
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $pluginRoot);
        self::assertIsResource($process);
        $this->registerOwnedProcess($process, $pipes, 'nested-phpunit-' . $mode);
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        $startedAt = microtime(true);
        $startup = null;
        do {
            $stderr .= (string)stream_get_contents($pipes[2]);
            foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
                if (!str_starts_with($line, 'SM_A12_PROCESS_OWNER_START ')) {
                    continue;
                }
                $startup = json_decode(substr($line, strlen('SM_A12_PROCESS_OWNER_START ')), true, 512, JSON_THROW_ON_ERROR);
                break 2;
            }
            usleep(20000);
        } while (microtime(true) - $startedAt < 15.0);
        stream_set_blocking($pipes[2], true);
        if (!is_array($startup)) {
            $result = $this->finishOwnedProcess($process, $pipes, true);
            self::fail('Nested PHPUnit did not publish its process-owner boundary: ' . $stderr . $result['error']);
        }

        return compact('process', 'pipes', 'command', 'startup', 'stderr', 'startedAt');
    }

    /** @return array<string, mixed> */
    private function waitForProbeState(string $journalDirectory): array
    {
        $deadline = microtime(true) + 15.0;
        do {
            $journal = ProcessRunOwner::readExternalJournal($journalDirectory);
            if (is_array($journal['probe'] ?? null) && ($journal['probe']['ready'] ?? false)) {
                return $journal;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        self::fail('Nested PHPUnit did not publish its interruption probe state.');
    }

    /** @return array<string, mixed> */
    private function waitForProcessOwnerReport(string $journalDirectory): array
    {
        $deadline = microtime(true) + 15.0;
        do {
            if (is_file($journalDirectory . '/report.json')) {
                return ProcessRunOwner::readExternalReport($journalDirectory);
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        self::fail('The detached process owner did not publish its interruption report.');
    }

    /** @return array{exitCode: int, output: string, error: string, pid: int, signaled: bool, termSignal: int} */
    private function runProbeToCompletion(string $mode): array
    {
        $nested = $this->startProbe($mode);

        return $this->finishOwnedProcess($nested['process'], $nested['pipes']);
    }

    /** @return array{exitCode: int, output: string, error: string, pid: int, signaled: bool, termSignal: int} */
    private function runStartupFailureProbe(string $failurePoint, ?string $cleanupFailure): array
    {
        $pluginRoot = dirname(__DIR__, 2);
        $command = [
            '/usr/bin/env',
            'SM_A12_PROCESS_OWNER_STARTUP_PROBE=1',
            'SM_A12_PROCESS_OWNER_STARTUP_FAILURE=' . $failurePoint,
        ];
        if ($cleanupFailure !== null) {
            $command[] = 'SM_A12_PROCESS_OWNER_STARTUP_CLEANUP_FAILURE=' . $cleanupFailure;
        }
        $command[] = PHP_BINARY;
        $command[] = $pluginRoot . '/tests/Fixtures/ProcessStartupFailureProbe.php';

        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $pluginRoot);
        self::assertIsResource($process);
        $this->registerOwnedProcess($process, $pipes, 'startup-failure-' . $failurePoint);

        return $this->finishOwnedProcess($process, $pipes);
    }
}
