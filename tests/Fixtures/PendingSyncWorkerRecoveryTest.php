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
use craft\queue\Queue;
use lindemannrock\base\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\PendingSyncFaultCommand;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use lindemannrock\searchmanager\tests\Support\OwnedProcessRegistry;
use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use yii\db\Exception as DbException;

/**
 * Direct-only persistent-table fixtures; never use the temporary-table TestCase.
 *
 * @since 5.57.0
 */
final class PendingSyncWorkerRecoveryTest extends TestCase
{
    private PendingSyncRepository $repository;
    private StubBackend $backend;
    private string $handle;
    private mixed $configSnapshot;
    private array $settingsSnapshot;
    private object $backendSnapshot;
    private mixed $commandSnapshot;
    private array $entryIds = [];
    private int $events = 0;
    private \Closure $listener;
    private array $executions = [];
    private string $pendingTable;
    private string $queueTable;
    private string $lockTable;
    private object $queueSnapshot;
    private OwnedProcessRegistry $processes;
    private string $barriers;

    protected function setUp(): void
    {
        $boundary = TestProjectBoundary::resolve();
        self::assertTrue($boundary->disposable, 'This fixture refuses the owner project.');
        $db = Craft::$app->getDb();
        $name = $db->createCommand('SELECT DATABASE()')->queryScalar();
        self::assertMatchesRegularExpression('/^sm_a12_3p_[a-f0-9]{16}$/', $name);
        self::assertNull($db->getTransaction());
        self::assertFalse($db->getMasterPdo()->inTransaction());
        $this->processes = new OwnedProcessRegistry();
        $this->barriers = ProcessRunOwner::createDirectory('pending-worker-barriers');
        file_put_contents($this->barriers . '/token', bin2hex(random_bytes(16)));
        $this->commandSnapshot = $db->commandClass;
        $db->commandClass = PendingSyncFaultCommand::class;
        $this->handle = 'worker-' . bin2hex(random_bytes(6));
        $suffix = bin2hex(random_bytes(8));
        $this->pendingTable = 'sm_worker_pending_' . $suffix;
        $this->queueTable = 'sm_worker_queue_' . $suffix;
        $this->lockTable = 'sm_worker_locks_' . $suffix;
        $db->createCommand('CREATE TABLE `' . $this->pendingTable . '` LIKE `searchmanager_pending_syncs`')->execute();
        $db->createCommand('CREATE TABLE `' . $this->queueTable . '` LIKE `queue`')->execute();
        $db->createCommand('CREATE TABLE `' . $this->lockTable . '` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB')->execute();
        PendingSyncFaultCommand::$pendingTable = $this->pendingTable;
        $this->queueSnapshot = Craft::$app->queue;
        Craft::$app->set('queue', new Queue(['tableName' => $this->queueTable]));
        $this->configSnapshot = (new \ReflectionProperty(ConfigFileHelper::class, '_configCache'))->getValue();
        $this->settingsSnapshot = SearchManager::$plugin->getSettings()->getAttributes();
        $this->backendSnapshot = SearchManager::$plugin->backend;
        $this->backend = new StubBackend();
        SearchManager::$plugin->set('backend', $this->backend);
        $settings = SearchManager::$plugin->getSettings();
        $settings->autoIndex = true;
        $settings->batchFlushInterval = 1;
        $settings->clearCacheOnSave = true;
        $settings->enableCache = false;
        $settings->enableAutocompleteCache = false;
        $this->repository = SearchManager::$plugin->pendingSyncs;
        $this->configureIndex();
        $this->listener = function(): void {
            $this->events++;
        };
        SearchManager::$plugin->indexing->on(IndexingService::EVENT_AFTER_INDEX, $this->listener);
    }

    protected function tearDown(): void
    {
        $this->processes->cleanup();
        PendingSyncFaultCommand::$beforeExecute = null;
        $db = Craft::$app->getDb();
        if ($db->getMasterPdo()->inTransaction()) {
            $db->getMasterPdo()->rollBack();
        }
        while (($transaction = $db->getTransaction()) !== null) {
            $transaction->rollBack();
        }
        $db->getMasterPdo()->exec('SET autocommit=1');
        $db->getMasterPdo()->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);
        $db->commandClass = $this->commandSnapshot;
        SearchManager::$plugin->getSettings()->autoIndex = false;
        SearchManager::$plugin->indexing->off(IndexingService::EVENT_AFTER_INDEX, $this->listener);
        foreach ($this->entryIds as $id) {
            Craft::$app->getElements()->deleteElementById($id, Entry::class, hardDelete: true);
        }
        $db->createCommand()->delete('{{%searchmanager_pending_syncs}}', ['indexHandle' => $this->handle])->execute();
        $db->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $this->handle])->execute();
        // The fixture owns both persistent table clones in the disposable DB.
        // Independent workers receive these exact names and never fall through
        // to the seed tables or the owner's application database.
        PendingSyncFaultCommand::$pendingTable = null;
        $db->createCommand()->dropTable($this->pendingTable)->execute();
        $db->createCommand()->dropTable($this->queueTable)->execute();
        $db->createCommand()->dropTable($this->lockTable)->execute();
        Craft::$app->set('queue', $this->queueSnapshot);
        (new \ReflectionProperty(ConfigFileHelper::class, '_configCache'))->setValue(null, $this->configSnapshot);
        SearchManager::$plugin->getSettings()->setAttributes($this->settingsSnapshot, false);
        SearchManager::$plugin->set('backend', $this->backendSnapshot);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    public function testClaimRetriesOnlyRolledBackStatementsAndCountsOnce(): void
    {
        $this->saveEntry();
        $this->failStatements('claim', 2);
        $rows = $this->repository->claim(100, 300);
        self::assertCount(1, $rows);
        self::assertSame(3, $this->executions['claim']);
        self::assertSame(1, (int)$rows[0]['attemptCount']);
        self::assertNotEmpty($rows[0]['claimToken']);
        self::assertSame([], $this->repository->claim(100, 300));
    }

    /** @return iterable<string, array{string}> */
    public static function completionStages(): iterable
    {
        yield 'dirty reset' => ['dirty'];
        yield 'clean delete' => ['complete'];
    }

    #[DataProvider('completionStages')]
    public function testCompletionRetriesWithoutReplayingBackendOrEvents(string $stage): void
    {
        $entry = $this->saveEntry();
        $clean = $this->saveEntry();
        $rows = $this->repository->claim(100, 300);
        $result = SearchManager::$plugin->pendingSyncProcessor->process($rows);
        self::assertSame([], $result['failures']);
        self::assertSame(2, $this->events);
        $entry->title = 'Newest saved title';
        $this->save($entry);
        $this->failStatements($stage, 2);
        $this->repository->markSucceeded($result['success'], $rows[0]['claimToken']);
        self::assertSame(3, $this->executions[$stage]);
        self::assertSame(1, $this->executions[$stage === 'dirty' ? 'complete' : 'dirty']);
        self::assertSame(2, $this->events);
        self::assertCount(1, $this->backend->callsFor('batchIndex'));
        $pending = $this->rows();
        self::assertCount(1, $pending);
        self::assertSame((int)$entry->id, (int)$pending[0]['elementId']);
        self::assertSame('pending', $pending[0]['status']);
        self::assertSame(0, (int)$pending[0]['attemptCount']);
        self::assertNull($pending[0]['claimToken']);
        PendingSyncFaultCommand::$beforeExecute = null;
        (new BatchSyncJob())->execute(Craft::$app->queue);
        self::assertSame([], $this->rows());
        self::assertSame(3, $this->events, 'Only the intentional newer save gets another event.');
        $calls = $this->backend->callsFor('batchIndex');
        self::assertCount(2, $calls);
        self::assertSame('Newest saved title', $calls[1]['items'][0]['title']);
        self::assertCount(2, $this->backend->callsFor('clearSearchCache'));
        self::assertCount(1, $this->backend->callsFor('getDocumentCount'));
        self::assertNotSame($entry->id, $clean->id);
    }

    public function testExhaustionPreservesExceptionAndDoesNotClaim(): void
    {
        $this->saveEntry();
        $error = $this->deadlock();
        $this->failStatements('claim', 10, $error);
        $started = microtime(true);
        try {
            $this->repository->claim(100, 300);
            self::fail('Exhaustion must fail visibly.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(3, $this->executions['claim']);
        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertSame('pending', $this->rows()[0]['status']);
        self::assertSame(0, (int)$this->rows()[0]['attemptCount']);
    }

    /** @return iterable<string, array{array}> */
    public static function nonDeadlockErrors(): iterable
    {
        yield 'lock timeout' => [['HY000', 1205, 'timeout']];
        yield 'connection loss' => [['HY000', 2013, 'lost connection']];
        yield 'duplicate key' => [['23000', 1062, 'duplicate']];
        yield 'PostgreSQL serialization' => [['40001', 7, 'serialization']];
        yield 'other SQLSTATE' => [['HY000', 1213, 'deadlock text is not authority']];
        yield 'missing structured info' => [[]];
    }

    #[DataProvider('nonDeadlockErrors')]
    public function testOtherDatabaseErrorsPropagateImmediately(array $info): void
    {
        $this->saveEntry();
        $error = new DbException('Deadlock found when trying to get lock', $info);
        $this->failStatements('claim', 10, $error);
        try {
            $this->repository->claim(100, 300);
            self::fail('The original error must propagate.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(1, $this->executions['claim']);
    }

    public function testWrappedPdoDeadlockIsRecognized(): void
    {
        $this->saveEntry();
        $pdoError = new \PDOException('controlled statement rollback');
        $pdoError->errorInfo = ['40001', 1213, 'deadlock'];
        $this->failStatements('claim', 1, new \RuntimeException('wrapped', 0, $pdoError));
        self::assertCount(1, $this->repository->claim(100, 300));
        self::assertSame(2, $this->executions['claim']);
    }

    /** @return iterable<string, array{string}> */
    public static function transactionKinds(): iterable
    {
        yield 'Craft transaction' => ['craft'];
        yield 'nested Craft savepoint' => ['nested'];
        yield 'raw PDO transaction' => ['pdo'];
        yield 'PDO autocommit disabled' => ['attribute'];
        yield 'server autocommit disabled' => ['server'];
    }

    #[DataProvider('transactionKinds')]
    public function testCallerTransactionNeverReceivesStatementOnlyRecovery(string $kind): void
    {
        $entry = $this->saveEntry();
        $originalTitle = $entry->title;
        $db = Craft::$app->getDb();
        $pdo = $db->getMasterPdo();
        if ($kind === 'craft' || $kind === 'nested') {
            $db->beginTransaction();
            if ($kind === 'nested') {
                $db->beginTransaction();
            }
            $entry->title = 'Preceding caller-owned write';
            $this->save($entry);
        } elseif ($kind === 'pdo') {
            $pdo->beginTransaction();
            // A preceding owned write proves whole-transaction rollback, not
            // merely the absence of an extra claim increment.
            $db->createCommand()->update('{{%searchmanager_indices}}', ['documentCount' => 123], ['handle' => $this->handle])->execute();
        } elseif ($kind === 'attribute') {
            $pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, false);
        } else {
            $pdo->exec('SET autocommit=0');
        }
        $error = $this->deadlock();
        $calls = 0;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use (&$calls, $pdo, $error): void {
            if ($this->stage($command->getRawSql()) !== 'claim') {
                return;
            }
            $calls++;
            // Model MySQL's transaction-wide rollback BEFORE surfacing 1213.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        };
        try {
            $this->repository->claim(100, 300);
            self::fail('Caller-owned transactions must fail immediately.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(1, $calls);
        self::assertSame(0, (int)$this->rows()[0]['attemptCount']);
        if ($kind === 'craft' || $kind === 'nested') {
            self::assertNotNull($db->getTransaction(), 'Yii metadata can survive the server rollback.');
            self::assertSame($originalTitle, Entry::find()->id($entry->id)->siteId($entry->siteId)->one()->title);
        }
        if ($kind === 'pdo') {
            self::assertSame(0, (int)(new Query())->select('documentCount')->from('{{%searchmanager_indices}}')->where(['handle' => $this->handle])->scalar());
        }
    }

    public function testCompetingClaimWinsDuringRetryWithoutDuplicateOwnership(): void
    {
        $this->saveEntry();
        $winner = [];
        $attempts = 0;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use (&$winner, &$attempts): void {
            if ($this->stage($command->getRawSql()) !== 'claim') {
                return;
            }
            if (++$attempts === 1) {
                $hook = PendingSyncFaultCommand::$beforeExecute;
                PendingSyncFaultCommand::$beforeExecute = null;
                $winner = $this->repository->claim(100, 300);
                PendingSyncFaultCommand::$beforeExecute = $hook;
                throw $this->deadlock();
            }
        };
        self::assertSame([], $this->repository->claim(100, 300), 'Zero affected rows is a successful lost race.');
        self::assertSame(2, $attempts);
        self::assertCount(1, $winner);
        self::assertSame($winner[0]['claimToken'], $this->rows()[0]['claimToken']);
        self::assertSame(1, (int)$this->rows()[0]['attemptCount']);
    }

    public function testClaimReadFailureDoesNotReplaySuccessfulClaimUpdate(): void
    {
        $this->saveEntry();
        $error = $this->deadlock();
        $updates = 0;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use (&$updates, $error): void {
            $stage = $this->stage($command->getRawSql());
            if ($stage === 'claim') {
                $updates++;
            } elseif ($stage === 'read' && $updates > 0) {
                throw $error;
            }
        };
        try {
            $this->repository->claim(100, 300);
            self::fail('A token-read failure must propagate.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
        PendingSyncFaultCommand::$beforeExecute = null;
        self::assertSame(1, $updates);
        self::assertSame(1, (int)$this->rows()[0]['attemptCount']);
        self::assertSame('processing', $this->rows()[0]['status']);
    }

    public function testCommittedDirtyResetIsNotReplayedAfterNewDeleteAndClaim(): void
    {
        $entry = $this->saveEntry();
        $rows = $this->repository->claim(100, 300);
        $this->save($entry);
        $newClaim = [];
        $deletes = 0;
        $dirtyResets = 0;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use ($entry, &$newClaim, &$deletes, &$dirtyResets): void {
            $stage = $this->stage($command->getRawSql());
            if ($stage === 'dirty') {
                $dirtyResets++;
            }
            if ($stage === 'complete' && ++$deletes === 1) {
                $hook = PendingSyncFaultCommand::$beforeExecute;
                PendingSyncFaultCommand::$beforeExecute = null;
                $this->pinIndex();
                self::assertTrue(Craft::$app->getElements()->deleteElement($entry));
                $newClaim = $this->repository->claim(100, 300);
                PendingSyncFaultCommand::$beforeExecute = $hook;
                throw $this->deadlock();
            }
        };
        $this->repository->markSucceeded(array_column($rows, 'id'), $rows[0]['claimToken']);
        self::assertSame(1, $dirtyResets);
        self::assertSame(2, $deletes);
        self::assertCount(1, $newClaim);
        self::assertSame('delete', $this->rows()[0]['op']);
        self::assertSame($newClaim[0]['claimToken'], $this->rows()[0]['claimToken']);
        $this->repository->markSucceeded(array_column($rows, 'id'), $rows[0]['claimToken']);
        self::assertCount(1, $this->rows(), 'The previous token cannot retire the newer claim.');
        PendingSyncFaultCommand::$beforeExecute = null;
        $outcome = SearchManager::$plugin->pendingSyncProcessor->process($newClaim);
        self::assertSame([], $outcome['failures']);
        $this->repository->markSucceeded($outcome['success'], $newClaim[0]['claimToken']);
        self::assertSame([], $this->rows());
        self::assertCount(1, $this->backend->callsFor('batchDelete'));
    }

    public function testDirtyBackendFailuresRetainBackoffAndAbandonAccounting(): void
    {
        $entry = $this->saveEntry();
        $this->backend->failBatchIndex = true;
        $rows = $this->repository->claim(100, 300);
        $this->save($entry);
        $outcome = SearchManager::$plugin->pendingSyncProcessor->process($rows);
        self::assertCount(1, $outcome['failures']);
        $failure = $outcome['failures'][0];
        $this->failStatements('retry', 2);
        $before = time();
        $this->repository->markRetry($failure['ids'], $failure['error'], 2, 1, $rows[0]['claimToken']);
        self::assertSame(3, $this->executions['retry']);
        $failed = $this->rows()[0];
        self::assertSame('failed', $failed['status']);
        self::assertSame(1, (int)$failed['attemptCount']);
        self::assertNull($failed['dirtyAt']);
        self::assertGreaterThanOrEqual($before + 1, strtotime($failed['nextAttemptAt'] . ' UTC'));
        PendingSyncFaultCommand::$beforeExecute = null;
        // Let all application clocks advance naturally; do not fabricate due rows.
        sleep(2);
        $rows = $this->repository->claim(100, 300);
        self::assertCount(1, $rows);
        $this->save($entry);
        $this->failStatements('abandon', 1);
        $this->repository->markRetry(array_column($rows, 'id'), $failure['error'], 2, 1, $rows[0]['claimToken']);
        self::assertSame(2, $this->executions['abandon']);
        self::assertSame('abandoned', $this->rows()[0]['status']);
        self::assertSame(2, (int)$this->rows()[0]['attemptCount']);
        self::assertNull($this->rows()[0]['dirtyAt']);
        $this->failStatements('purge', 2);
        self::assertSame(0, $this->repository->purgeOld(60), 'Young abandoned rows remain retained.');
        self::assertSame(3, $this->executions['purge']);
    }

    public function testEnqueueUpsertDoesNotReplayCapturedPayload(): void
    {
        $entry = $this->saveEntry();
        $this->failStatements('enqueue', 1);
        try {
            $this->repository->queueForElement($entry, PendingSyncRepository::OP_DELETE);
            self::fail('Producer writes remain outside worker retries.');
        } catch (DbException) {
            self::assertSame(1, $this->executions['enqueue']);
        }
        self::assertSame('upsert', $this->rows()[0]['op']);
    }

    public function testIndependentWorkersArbitrateOverlappingClaimCandidates(): void
    {
        $this->saveEntry();
        $this->saveEntry();
        $first = $this->startWorker('first', 'claim');
        $second = $this->startWorker('second', 'claim');
        $this->waitFor('first-ready');
        $this->waitFor('second-ready');
        file_put_contents($this->barriers . '/go', '1');
        $one = $this->finishWorker($first);
        $two = $this->finishWorker($second);
        self::assertNotSame($one['connectionId'], $two['connectionId']);
        $firstIds = array_column($one['rows'], 'id');
        $secondIds = array_column($two['rows'], 'id');
        self::assertSame([], array_intersect($firstIds, $secondIds));
        self::assertCount(2, array_merge($firstIds, $secondIds));
        foreach ($this->rows() as $row) {
            self::assertSame(1, (int)$row['attemptCount']);
        }
        foreach ([$one['rows'], $two['rows']] as $rows) {
            if ($rows !== []) {
                $outcome = SearchManager::$plugin->pendingSyncProcessor->process($rows);
                self::assertSame([], $outcome['failures']);
                $this->repository->markSucceeded($outcome['success'], $rows[0]['claimToken']);
            }
        }
        self::assertSame([], $this->rows());
        self::assertSame(2, $this->events);
    }

    public function testConcurrentSupportedSaveSurvivesCompletionLockWait(): void
    {
        $entry = $this->saveEntry();
        $rows = $this->repository->claim(100, 300);
        $outcome = SearchManager::$plugin->pendingSyncProcessor->process($rows);
        self::assertSame([], $outcome['failures']);
        $producer = $this->startWorker('producer', 'save', ['firstId' => (int)$entry->id]);
        $this->waitFor('producer-ready');
        $worker = $this->startWorker('completion', 'complete', [
            'ids' => $outcome['success'], 'claimToken' => $rows[0]['claimToken'],
        ]);
        // Producer holds the real upsert lock. Completion's dirty UPDATE waits
        // until commit; this interleaving need not produce a deadlock.
        $this->waitFor('completion-ready');
        usleep(200000);
        file_put_contents($this->barriers . '/go', '1');
        $saved = $this->finishWorker($producer);
        $completed = $this->finishWorker($worker);
        self::assertNotSame($saved['connectionId'], $completed['connectionId']);
        self::assertCount(1, $this->rows());
        self::assertSame('pending', $this->rows()[0]['status']);
        (new BatchSyncJob())->execute(Craft::$app->queue);
        $calls = $this->backend->callsFor('batchIndex');
        self::assertCount(2, $calls);
        self::assertSame('Concurrent newest title', $calls[1]['items'][0]['title']);
        self::assertSame(2, $this->events);
        self::assertSame([], $this->rows());
    }

    public function testRealDeadlockRollsBackWholeCallerTransactionWithoutLocalRetry(): void
    {
        $first = $this->saveEntry();
        $originalTitle = $first->title;
        $db = Craft::$app->getDb();
        $db->createCommand()->batchInsert($this->lockTable, ['id', 'value'], array_map(static fn(int $id): array => [$id, 0], range(1, 102)))->execute();
        $transaction = $db->beginTransaction();
        $first->title = 'Uncommitted caller title';
        $this->save($first);
        $db->createCommand('UPDATE `' . $this->lockTable . '` SET value = 1 WHERE id = 1')->execute();
        $other = $this->startWorker('competitor', 'transaction', ['lockTable' => $this->lockTable]);
        $this->waitFor('competitor-ready');
        file_put_contents($this->barriers . '/go', '1');
        $this->waitFor('competitor-enter');
        usleep(100000);
        $attempts = 0;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use (&$attempts): void {
            if ($this->stage($command->getRawSql()) === 'claim') {
                $attempts++;
                // Obtain an actual engine rollback at the execution boundary.
                // This controlled two-row cycle proves transaction semantics;
                // it is explicitly not claimed to be the production cycle.
                $command->db->getMasterPdo()->exec('UPDATE `' . $this->lockTable . '` SET value = 2 WHERE id = 2');
            }
        };
        try {
            $this->repository->claim(100, 300);
            self::fail('The controlled lower-weight transaction should be the actual MySQL deadlock victim.');
        } catch (\PDOException $error) {
            self::assertSame('40001', $error->errorInfo[0]);
            self::assertSame(1213, (int)$error->errorInfo[1]);
        } finally {
            PendingSyncFaultCommand::$beforeExecute = null;
            $transaction->rollBack();
        }
        $this->finishWorker($other);
        self::assertSame(1, $attempts);
        self::assertSame($originalTitle, Entry::find()->id($first->id)->siteId($first->siteId)->one()->title);
        foreach ($this->rows() as $row) {
            self::assertSame('pending', $row['status']);
            self::assertSame(0, (int)$row['attemptCount']);
        }
    }

    #[Group('elapsed-worker-recovery')]
    public function testExhaustedCompletionRecoversOnlyAfterLeaseExpiryAndPurgesOldAbandonedWork(): void
    {
        $this->saveEntry();
        $abandoned = $this->repository->claim(100, 300);
        $this->backend->failBatchIndex = true;
        $failure = SearchManager::$plugin->pendingSyncProcessor->process($abandoned)['failures'][0];
        $this->repository->markRetry($failure['ids'], $failure['error'], 1, 1, $abandoned[0]['claimToken']);
        $this->backend->failBatchIndex = false;
        $this->clearOwnedQueue();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame([], $this->queueRows(), 'Exclusively abandoned work receives no successor.');

        $entry = $this->saveEntry();
        $this->clearOwnedQueue();
        $job = new BatchSyncJob();
        $queue = Craft::$app->queue;
        self::assertInstanceOf(Queue::class, $queue);
        self::assertFalse($job->canRetry(1, $this->deadlock()));
        $jobId = $queue->push($job);
        $this->failStatements('complete', 10);
        self::assertTrue($queue->executeJob((string)$jobId));
        self::assertSame(3, $this->executions['complete']);
        PendingSyncFaultCommand::$beforeExecute = null;
        $failedJob = (new Query())->from($this->queueTable)->where(['id' => $jobId])->one();
        self::assertNotFalse($failedJob);
        self::assertSame(1, (int)$failedJob['fail']);
        self::assertStringContainsString('Controlled rolled-back statement', $failedJob['error']);
        $processing = array_values(array_filter($this->rows(), static fn(array $row): bool => $row['status'] === 'processing'));
        self::assertCount(1, $processing);
        self::assertSame((int)$entry->id, (int)$processing[0]['elementId']);
        self::assertSame([], $this->repository->claim(100, 300), 'Fresh claims cannot be stolen.');
        $eligibleAt = strtotime($processing[0]['claimedAt'] . ' UTC') + 301;
        $successors = array_values(array_filter($this->queueRows(), static fn(array $row): bool => !(bool)$row['fail']));
        self::assertCount(1, $successors);
        self::assertSame($eligibleAt, (int)$successors[0]['timePushed'] + (int)$successors[0]['delay']);
        self::assertSame(1, $this->events);

        // Intentional elapsed-time proof: PHP time(), DateTime, the queue and
        // server clocks all advance normally. No manual timestamp/row rewrite.
        while (time() < $eligibleAt) {
            sleep(min(10, $eligibleAt - time()));
        }
        $this->failStatements('purge', 2);
        self::assertSame(1, $this->repository->purgeOld(60));
        self::assertSame(3, $this->executions['purge']);
        PendingSyncFaultCommand::$beforeExecute = null;
        self::assertTrue($queue->executeJob((string)$successors[0]['id']));
        self::assertSame([], $this->rows());
        self::assertSame(2, $this->events, 'Exhaustion retains existing stale-recovery replay semantics.');
        $calls = $this->backend->callsFor('batchIndex');
        self::assertSame($entry->title, $calls[count($calls) - 1]['items'][0]['title']);
        self::assertSame(1, (int)(new Query())->from($this->queueTable)->where(['id' => $jobId])->count(), 'The original failed job remains visible.');
        $this->clearOwnedQueue();
        $this->repository->scheduleNextEligibleBatchJob();
        self::assertSame([], $this->queueRows(), 'Empty work receives no successor.');
    }

    public function testExhaustedClaimSchedulesDueSuccessorAndPreservesOriginalErrorWhenSchedulingFails(): void
    {
        $this->saveEntry();
        $this->clearOwnedQueue();
        $error = $this->deadlock();
        $this->failStatements('claim', 10, $error);
        try {
            (new BatchSyncJob())->execute(Craft::$app->queue);
            self::fail('Exhaustion must propagate.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertCount(1, $this->queueRows());
        self::assertSame(0, (int)$this->queueRows()[0]['delay']);
        $this->clearOwnedQueue();
        $hook = PendingSyncFaultCommand::$beforeExecute;
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use ($hook): void {
            if (str_starts_with($command->getRawSql(), 'INSERT') && str_contains($command->getRawSql(), $this->queueTable)) {
                throw new \RuntimeException('Controlled secondary scheduling failure');
            }
            $hook($command);
        };
        try {
            (new BatchSyncJob())->execute(Craft::$app->queue);
            self::fail('Original execution error must propagate.');
        } catch (DbException $caught) {
            self::assertSame($error, $caught);
        }
    }

    private function queueRows(): array
    {
        return (new Query())->from($this->queueTable)->orderBy('id')->all();
    }

    private function clearOwnedQueue(): void
    {
        $ids = array_column($this->queueRows(), 'id');
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()->delete($this->queueTable, ['id' => $ids])->execute();
        }
    }

    /** @return array{process: resource, pipes: array<int, resource>} */
    private function startWorker(string $label, string $mode, array $extra = []): array
    {
        $boundary = TestProjectBoundary::resolve();
        $process = proc_open([PHP_BINARY, __DIR__ . '/PendingSyncConcurrentWorker.php'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $boundary->packageRoot);
        self::assertIsResource($process);
        $this->processes->register($process, $pipes, 'pending-' . $label);
        fwrite($pipes[0], json_encode($extra + [
            'label' => $label, 'mode' => $mode, 'directory' => $this->barriers,
            'token' => file_get_contents($this->barriers . '/token'),
            'database' => Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar(),
            'pendingTable' => $this->pendingTable, 'queueTable' => $this->queueTable,
            'handle' => $this->handle,
        ], JSON_THROW_ON_ERROR) . "\n");
        return ['process' => $process, 'pipes' => $pipes];
    }

    private function finishWorker(array $worker): array
    {
        $result = $this->processes->finish($worker['process'], $worker['pipes']);
        self::assertSame(0, $result['exitCode'], $result['error'] . $result['output']);
        fwrite(STDERR, 'PENDING_WORKER_EVIDENCE ' . $result['output'] . "\n");
        return json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitFor(string $name): void
    {
        $deadline = microtime(true) + 15;
        while (!is_file($this->barriers . '/' . $name)) {
            if (microtime(true) >= $deadline) {
                self::fail('Worker barrier timed out: ' . $name);
            }
            usleep(10000);
        }
    }

    private function configureIndex(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $cache = is_array($this->configSnapshot) ? $this->configSnapshot : [];
        $cache['search-manager'] = ['indices' => [$this->handle => [
            'name' => 'Owned worker index',
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'backend' => 'fixtureMysql',
            'enabled' => true,
        ]]];
        (new \ReflectionProperty(ConfigFileHelper::class, '_configCache'))->setValue(null, $cache);
        SearchIndex::clearCache();
        $index = SearchIndex::findByHandle($this->handle);
        self::assertNotNull($index);
        self::assertTrue($index->syncMetadataFromConfig());
        (new \ReflectionProperty(SearchIndex::class, 'allCache'))->setValue(null, [$index]);
        (new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt'))->setValue(null, microtime(true) + 3600);
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function saveEntry(): Entry
    {
        $template = Entry::find()->sectionId(Craft::$app->getEntries()->getAllSectionIds())->status(null)->one();
        self::assertInstanceOf(Entry::class, $template);
        $entry = new Entry([
            'sectionId' => $template->sectionId,
            'typeId' => $template->typeId,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'authorId' => $template->authorId,
            'title' => 'Worker ' . bin2hex(random_bytes(6)),
            'slug' => 'worker-' . bin2hex(random_bytes(6)),
            'enabled' => true,
        ]);
        try {
            $this->save($entry);
        } finally {
            if ($entry->id) {
                $this->entryIds[] = (int)$entry->id;
            }
        }
        self::assertNotEmpty($this->rows());
        return $entry;
    }

    private function save(Entry $entry): void
    {
        $this->pinIndex();
        self::assertTrue(Craft::$app->getElements()->saveElement($entry, false, true, false), json_encode($entry->getErrors()));
    }

    private function pinIndex(): void
    {
        $index = SearchIndex::findByHandle($this->handle);
        self::assertNotNull($index);
        (new \ReflectionProperty(SearchIndex::class, 'allCache'))->setValue(null, [$index]);
        (new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt'))->setValue(null, microtime(true) + 3600);
    }

    private function rows(): array
    {
        return (new Query())->from('{{%searchmanager_pending_syncs}}')->where(['indexHandle' => $this->handle])->orderBy('id')->all();
    }

    private function deadlock(): DbException
    {
        return new DbException('Controlled rolled-back statement', ['40001', 1213, 'deadlock']);
    }

    private function failStatements(string $stage, int $failures, ?\Throwable $error = null): void
    {
        $error ??= $this->deadlock();
        $this->executions = [];
        PendingSyncFaultCommand::$beforeExecute = function(PendingSyncFaultCommand $command) use ($stage, $failures, $error): void {
            $sql = $command->getRawSql();
            $current = $this->stage($sql);
            if ($current === null) {
                return;
            }
            $this->executions[$current] = ($this->executions[$current] ?? 0) + 1;
            if ($current === $stage && $this->executions[$current] <= $failures) {
                // Failure is before execute: no successful committed mutation is replayed.
                throw $error;
            }
        };
    }

    private function stage(string $sql): ?string
    {
        if (!str_contains($sql, $this->pendingTable)) {
            return null;
        }
        if (str_starts_with($sql, 'INSERT')) {
            return 'enqueue';
        }
        if (str_starts_with($sql, 'DELETE')) {
            return str_contains($sql, 'lastProcessedAt') ? 'purge' : 'complete';
        }
        if (!str_starts_with($sql, 'UPDATE')) {
            return 'read';
        }
        foreach (['processing' => 'claim', 'pending' => 'dirty', 'failed' => 'retry', 'abandoned' => 'abandon'] as $status => $stage) {
            if (str_contains($sql, "'{$status}'")) {
                return $stage;
            }
        }
        return null;
    }
}
