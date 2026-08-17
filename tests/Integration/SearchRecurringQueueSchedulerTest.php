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
use craft\errors\MissingComponentException;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\DeferredQueueJob;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\SearchRecurringQueueScheduler;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use yii\queue\Queue as BaseQueue;
use yii\queue\serializers\JsonSerializer;
use yii\queue\sqs\Queue as SqsQueue;

/**
 * Pins Search Manager's recurring queue ownership and portable handoffs.
 *
 * @since 5.55.0
 */
#[CoversClass(SearchRecurringQueueScheduler::class)]
final class SearchRecurringQueueSchedulerTest extends TestCase
{
    private const START_TIMESTAMP = 1_800_000_000;

    private SearchRecurringQueueScheduler $scheduler;
    private ?Queue $schedulerQueue = null;
    private ?RecordingSearchSqsQueue $sqsProxy = null;
    private ?RecordingUnknownProxyQueue $unknownProxy = null;
    private bool $timePaused = false;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new SearchRecurringQueueScheduler();
        $this->swapPluginComponent('search-manager', 'recurringQueueScheduler', $this->scheduler);
        $this->deleteFamilyRows();
    }

    protected function tearDown(): void
    {
        $this->deleteFamilyRows();

        if ($this->timePaused) {
            DateTimeHelper::resume();
            $this->timePaused = false;
        }

        if ($this->schedulerQueue !== null) {
            Craft::$app->set('queue', $this->schedulerQueue);
            $this->schedulerQueue = null;
        }

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        parent::tearDown();
    }

    public function testApprovedBaseClassesLoadFromTheLocalCheckout(): void
    {
        foreach ([
            RecurringQueueHelper::class,
            PortableQueueScheduler::class,
            DeferredQueueJob::class,
        ] as $class) {
            $filename = (new ReflectionClass($class))->getFileName();
            self::assertIsString($filename);
            self::assertStringContainsString('/plugins/base/src/', str_replace('\\', '/', $filename));
        }
    }

    public function testFamiliesExposeSeparateStableIdentitiesAndMutexes(): void
    {
        self::assertSame('search-manager:analytics-cleanup:recurring', SearchRecurringQueueScheduler::ANALYTICS_OWNER);
        self::assertSame('search-manager:status-sync:recurring', SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        self::assertNotSame(SearchRecurringQueueScheduler::ANALYTICS_OWNER, SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        self::assertNotSame(SearchRecurringQueueScheduler::ANALYTICS_FAMILY_MUTEX, SearchRecurringQueueScheduler::STATUS_SYNC_FAMILY_MUTEX);
        self::assertNotSame(SearchRecurringQueueScheduler::ANALYTICS_PORTABLE_MUTEX, SearchRecurringQueueScheduler::STATUS_SYNC_PORTABLE_MUTEX);
        self::assertNotSame(SearchRecurringQueueScheduler::ANALYTICS_FAMILY_MUTEX, SearchRecurringQueueScheduler::ANALYTICS_PORTABLE_MUTEX);
        self::assertNotSame(SearchRecurringQueueScheduler::STATUS_SYNC_FAMILY_MUTEX, SearchRecurringQueueScheduler::STATUS_SYNC_PORTABLE_MUTEX);
    }

    public function testInitialStatusSyncKeepsTheExactFiveMinuteDelayAndDescription(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 60;

        $result = $this->scheduler->ensureStatusSync();
        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        $job = $this->unserializeRow($row);
        $target = new \DateTime('@' . (self::START_TIMESTAMP + 300));

        self::assertTrue($result->wasCreated());
        self::assertSame(300, (int)$row['delay']);
        self::assertSame(1024, (int)$row['priority']);
        self::assertSame(1800, (int)$row['ttr']);
        self::assertInstanceOf(SyncStatusJob::class, $job);
        self::assertSame(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER, $job->recurringOwner);
        self::assertNull($job->lastSyncTime);
        self::assertSame($this->formatted($target), $job->nextRunTime);
        self::assertStringContainsString($this->formatted($target), (string)$row['description']);
    }

    public function testDailyCleanupUsesTheCanonicalAbsoluteTargetWithoutAnalyticsCollection(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = false;
        $settings->analyticsRetention = 30;
        $target = ScheduleHelper::calculateNext('daily');
        self::assertNotNull($target);

        $this->scheduler->ensureAnalyticsCleanup();
        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::ANALYTICS_OWNER);
        $job = $this->unserializeRow($row);

        self::assertInstanceOf(CleanupAnalyticsJob::class, $job);
        self::assertSame(SearchRecurringQueueScheduler::ANALYTICS_OWNER, $job->recurringOwner);
        self::assertSame($target->getTimestamp(), (int)$row['timePushed'] + (int)$row['delay']);
        self::assertSame($this->formatted($target), $job->nextRunTime);
        self::assertStringContainsString($this->formatted($target), (string)$row['description']);
    }

    #[DataProvider('statusIntervalProvider')]
    public function testStatusSuccessorKeepsIntervalReferenceDescriptionAndDefaults(int $minutes): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = $minutes;
        $reference = new \DateTime('@' . self::START_TIMESTAMP);
        $target = (clone $reference)->modify("+$minutes minutes");

        $this->scheduler->scheduleStatusSyncSuccessor($reference);
        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        $job = $this->unserializeRow($row);

        self::assertInstanceOf(SyncStatusJob::class, $job);
        self::assertSame($minutes * 60, (int)$row['delay']);
        self::assertSame($reference->format('c'), $job->lastSyncTime);
        self::assertSame($this->formatted($target), $job->nextRunTime);
        self::assertSame(1024, (int)$row['priority']);
        self::assertSame(1800, (int)$row['ttr']);
        self::assertStringContainsString($this->formatted($target), (string)$row['description']);
    }

    /** @return iterable<string, array{int}> */
    public static function statusIntervalProvider(): iterable
    {
        yield 'inclusive SQS boundary' => [15];
        yield 'above SQS boundary' => [16];
        yield 'maximum supported interval' => [1440];
    }

    #[DataProvider('portableBoundaryProvider')]
    public function testSearchConsumerUsesTheInclusiveSqsDelayBoundary(int $delay, string $expectedClass): void
    {
        $this->installQueue(new RecordingSearchSqsQueue());
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: new CleanupAnalyticsJob([
                'reschedule' => true,
                'recurringOwner' => SearchRecurringQueueScheduler::ANALYTICS_OWNER,
            ]),
            delay: $delay,
            identityTokens: [
                SearchRecurringQueueScheduler::PLUGIN_TOKEN,
                CleanupAnalyticsJob::class,
                SearchRecurringQueueScheduler::ANALYTICS_OWNER,
            ],
            mutexName: SearchRecurringQueueScheduler::ANALYTICS_PORTABLE_MUTEX,
        );

        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::ANALYTICS_OWNER);
        self::assertInstanceOf($expectedClass, $this->unserializeRow($row));
        self::assertSame([min(900, $delay)], $this->sqsDelays());
    }

    /** @return iterable<string, array{int, class-string}> */
    public static function portableBoundaryProvider(): iterable
    {
        yield '900 seconds is direct' => [900, CleanupAnalyticsJob::class];
        yield '901 seconds starts a handoff' => [901, DeferredQueueJob::class];
    }

    public function testBoundedQueueUsesMultipleAbsoluteHandoffsWithoutEarlyConsumerDispatch(): void
    {
        $queue = $this->installQueue(new RecordingSearchSqsQueue());
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 60;
        $reference = new \DateTime('@' . self::START_TIMESTAMP);

        $this->scheduler->scheduleStatusSyncSuccessor($reference);
        $target = self::START_TIMESTAMP + 3600;

        for ($hop = 1; $hop <= 2; $hop++) {
            $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
            $handoff = $this->unserializeRow($row);
            self::assertInstanceOf(DeferredQueueJob::class, $handoff);
            self::assertSame($target, $handoff->targetTimestamp);
            self::assertInstanceOf(SyncStatusJob::class, $handoff->job);

            $this->pauseAt(self::START_TIMESTAMP + ($hop * 900));
            self::assertTrue($queue->executeJob((string)$row['id']));
        }

        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeRow($row));

        $this->pauseAt($target - 300);
        self::assertTrue($queue->executeJob((string)$row['id']));
        $consumerRow = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        self::assertInstanceOf(SyncStatusJob::class, $this->unserializeRow($consumerRow));
        self::assertSame(300, (int)$consumerRow['delay']);
        self::assertSame([900, 900, 900, 300], $this->sqsDelays());
        self::assertLessThanOrEqual(900, max($this->sqsDelays()));
    }

    public function testLateHandoffQueuesTheFinalConsumerImmediately(): void
    {
        $queue = $this->installQueue(new RecordingSearchSqsQueue());
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 16;

        $this->scheduler->scheduleStatusSyncSuccessor(new \DateTime('@' . self::START_TIMESTAMP));
        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeRow($row));

        $this->pauseAt(self::START_TIMESTAMP + 1000);
        self::assertTrue($queue->executeJob((string)$row['id']));
        $consumerRow = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);

        self::assertInstanceOf(SyncStatusJob::class, $this->unserializeRow($consumerRow));
        self::assertSame(0, (int)$consumerRow['delay']);
        self::assertSame([900, 0], $this->sqsDelays());
    }

    public function testUnknownNonSqsProxyRetainsTheFullNativeDelay(): void
    {
        $this->installQueue(new RecordingUnknownProxyQueue());
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 16;

        $this->scheduler->scheduleStatusSyncSuccessor(new \DateTime('@' . self::START_TIMESTAMP));
        $row = $this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER);

        self::assertInstanceOf(SyncStatusJob::class, $this->unserializeRow($row));
        self::assertSame(960, (int)$row['delay']);
        self::assertSame([960], $this->unknownDelays());
    }

    public function testRepeatedBootstrapAndReplayKeepOnePendingChain(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 60;

        $first = $this->scheduler->ensureStatusSync();
        $second = $this->scheduler->ensureStatusSync();
        $replay = $this->scheduler->scheduleStatusSyncSuccessor(new \DateTime('@' . self::START_TIMESTAMP));

        self::assertTrue($first->wasCreated());
        self::assertSame($first->jobId, $second->jobId);
        self::assertSame($first->jobId, $replay->jobId);
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
    }

    public function testOuterLockContentionIsObservableAndLeavesNoPartialChain(): void
    {
        $this->scheduler->mutexTimeout = 0;
        SearchManager::$plugin->getSettings()->statusSyncInterval = 15;
        $mutex = Craft::$app->getMutex();
        self::assertTrue($mutex->acquire(SearchRecurringQueueScheduler::STATUS_SYNC_FAMILY_MUTEX, 0));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to acquire the recurring schedule lock.');
            $this->scheduler->ensureStatusSync();
        } finally {
            self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
            $mutex->release(SearchRecurringQueueScheduler::STATUS_SYNC_FAMILY_MUTEX);
        }
    }

    public function testProxyQueueFailurePropagatesAndLeavesAnInspectableOwnedRow(): void
    {
        $proxy = new RecordingSearchSqsQueue();
        $proxy->failPushes = true;
        $this->installQueue($proxy);
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 16;

        try {
            $this->scheduler->scheduleStatusSyncSuccessor(new \DateTime('@' . self::START_TIMESTAMP));
            self::fail('The proxy queue failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced Search recurring proxy failure', $exception->getMessage());
        }

        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
        self::assertInstanceOf(
            DeferredQueueJob::class,
            $this->unserializeRow($this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER)),
        );
    }

    #[DataProvider('legacyFamilyProvider')]
    public function testPhpAndJsonLegacyRowsBlockDuplicatesAndReportPending(
        string $jobClass,
        string $ownerToken,
        string $ensureMethod,
        string $statusMethod,
    ): void {
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;
        SearchManager::$plugin->getSettings()->statusSyncInterval = 15;
        $this->pushLegacy($jobClass, true, false);
        $this->pushLegacy($jobClass, true, true);

        self::assertTrue($this->scheduler->{$statusMethod}());
        $result = $this->scheduler->{$ensureMethod}();

        self::assertFalse($result->wasCreated());
        self::assertSame(1, $result->duplicatesDeleted);
        self::assertSame(1, $this->countClassRows($jobClass));
        self::assertSame(0, $this->countFamilyRows($ownerToken));
    }

    /** @return iterable<string, array{class-string, string, string, string}> */
    public static function legacyFamilyProvider(): iterable
    {
        yield 'analytics cleanup' => [
            CleanupAnalyticsJob::class,
            SearchRecurringQueueScheduler::ANALYTICS_OWNER,
            'ensureAnalyticsCleanup',
            'hasAnalyticsCleanup',
        ];
        yield 'status sync' => [
            SyncStatusJob::class,
            SearchRecurringQueueScheduler::STATUS_SYNC_OWNER,
            'ensureStatusSync',
            'hasStatusSync',
        ];
    }

    public function testFailedLegacyDoesNotBlockRecoveryAndRetainedLegacyUpgradesNaturally(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        SearchManager::$plugin->getSettings()->statusSyncInterval = 16;
        $failedId = $this->pushLegacy(SyncStatusJob::class, true, false);
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['fail' => true], ['id' => $failedId])->execute();

        $recovery = $this->scheduler->ensureStatusSync();
        self::assertTrue($recovery->wasCreated());
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));

        $this->deleteFamilyRows();
        $legacyId = $this->pushLegacy(SyncStatusJob::class, true, false);
        $this->markExecuting($legacyId);
        $method = new \ReflectionMethod(SyncStatusJob::class, 'scheduleNextSync');
        $method->invoke(new SyncStatusJob(['reschedule' => true]), new \DateTime('@' . self::START_TIMESTAMP));

        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
        self::assertSame(2, $this->countClassRows(SyncStatusJob::class));
    }

    public function testFalseRecurringMarkerAndOtherQueueFamiliesDoNotBlockOrGetCancelled(): void
    {
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;
        $manualId = $this->pushLegacy(CleanupAnalyticsJob::class, false, false);
        $batchId = (string)Craft::$app->getQueue()->push(new BatchSyncJob());
        $statusId = (string)Craft::$app->getQueue()->push(new SyncStatusJob(['reschedule' => false]));

        $this->scheduler->ensureAnalyticsCleanup();
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));
        $this->scheduler->cancelAnalyticsCleanup();

        self::assertTrue($this->rowExists($manualId));
        self::assertTrue($this->rowExists($batchId));
        self::assertTrue($this->rowExists($statusId));
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));
    }

    public function testCancellationRemovesEveryPortableAndLegacyStateButPreservesManualRows(): void
    {
        $this->installQueue(new RecordingSearchSqsQueue());
        $this->pauseAt(time());
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;

        $failedHandoff = $this->scheduler->ensureAnalyticsCleanup()->jobId;
        self::assertNotNull($failedHandoff);
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['fail' => true], ['id' => $failedHandoff])->execute();

        $reservedHandoff = $this->scheduler->ensureAnalyticsCleanup()->jobId;
        self::assertNotNull($reservedHandoff);
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['timeUpdated' => self::START_TIMESTAMP], ['id' => $reservedHandoff])->execute();

        $pendingHandoff = $this->scheduler->ensureAnalyticsCleanup()->jobId;
        self::assertNotNull($pendingHandoff);

        $failedFinal = (string)Craft::$app->getQueue()->push($this->ownedCleanupJob());
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['fail' => true], ['id' => $failedFinal])->execute();
        $reservedFinal = (string)Craft::$app->getQueue()->push($this->ownedCleanupJob());
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['timeUpdated' => self::START_TIMESTAMP], ['id' => $reservedFinal])->execute();
        $pendingFinal = (string)Craft::$app->getQueue()->push($this->ownedCleanupJob());
        $legacyFailed = $this->pushLegacy(CleanupAnalyticsJob::class, true, false);
        Craft::$app->getDb()->createCommand()->update($this->queueTable(), ['fail' => true], ['id' => $legacyFailed])->execute();
        $manualId = $this->pushLegacy(CleanupAnalyticsJob::class, false, false);

        self::assertGreaterThanOrEqual(7, $this->countClassRows(CleanupAnalyticsJob::class));
        self::assertGreaterThanOrEqual(7, $this->scheduler->cancelAnalyticsCleanup());
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));
        self::assertTrue($this->rowExists($manualId));
        self::assertFalse($this->rowExists($legacyFailed));
    }

    public function testDisableReenableAndIntervalReplacementLeaveExactlyOneChain(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        $settings = SearchManager::$plugin->getSettings();
        $settings->statusSyncInterval = 15;
        $this->scheduler->ensureStatusSync();

        $settings->statusSyncInterval = 0;
        $disabled = $this->scheduler->replaceStatusSync();
        self::assertTrue($disabled->wasSkipped());
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));

        $settings->statusSyncInterval = 30;
        $enabled = $this->scheduler->replaceStatusSync();
        self::assertTrue($enabled->wasCreated());
        self::assertSame(300, (int)$this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER)['delay']);

        $settings->statusSyncInterval = 60;
        $replaced = $this->scheduler->replaceStatusSync();
        self::assertTrue($replaced->wasCreated());
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
    }

    public function testAnalyticsDisableAndReenableLeaveExactlyOneDailyChain(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->analyticsRetention = 30;
        $this->scheduler->ensureAnalyticsCleanup();

        $settings->analyticsRetention = 0;
        self::assertTrue($this->scheduler->replaceAnalyticsCleanup()->wasSkipped());
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));

        $settings->analyticsRetention = 90;
        self::assertTrue($this->scheduler->replaceAnalyticsCleanup()->wasCreated());
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));
    }

    public function testSettingsSaveReplacesStatusScheduleAfterPersistence(): void
    {
        $this->withSettingsPost('cache', ['statusSyncInterval' => 47]);
        $user = $this->createTestUser('__sm_recurring_settings_', ['admin' => true]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);

        try {
            (new SettingsController('settings', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        self::assertSame(47, Settings::loadFromDatabase()->statusSyncInterval);
        self::assertSame(1, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));
        self::assertSame(300, (int)$this->onlyFamilyRow(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER)['delay']);
    }

    public function testSettingsSavesDisableBothPersistedRecurringFamilies(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->statusSyncInterval = 15;
        $settings->analyticsRetention = 30;
        $this->scheduler->ensureStatusSync();
        $this->scheduler->ensureAnalyticsCleanup();
        $user = $this->createTestUser('__sm_recurring_disable_', ['admin' => true]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);

        $this->withSettingsPost('cache', ['statusSyncInterval' => 0]);
        try {
            (new SettingsController('settings', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException) {
        }
        self::assertSame(0, Settings::loadFromDatabase()->statusSyncInterval);
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::STATUS_SYNC_OWNER));

        $this->restoreSettingsRequest();
        $this->withSettingsPost('analytics', ['analyticsRetention' => 0]);
        try {
            (new SettingsController('settings', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException) {
        }
        self::assertSame(0, Settings::loadFromDatabase()->analyticsRetention);
        self::assertSame(0, $this->countFamilyRows(SearchRecurringQueueScheduler::ANALYTICS_OWNER));
    }

    public function testPostPersistenceReplacementFailurePropagatesWithoutAFalseSuccess(): void
    {
        $failing = new FailingSearchRecurringQueueScheduler();
        $this->swapPluginComponent('search-manager', 'recurringQueueScheduler', $failing);
        $this->withSettingsPost('cache', ['statusSyncInterval' => 53]);
        $user = $this->createTestUser('__sm_recurring_failure_', ['admin' => true]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);

        try {
            (new SettingsController('settings', SearchManager::$plugin))->actionSave();
            self::fail('The queue replacement failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced recurring replacement failure', $exception->getMessage());
        }

        self::assertSame(53, Settings::loadFromDatabase()->statusSyncInterval);
        self::assertSame(1, $failing->statusReplacementAttempts);
    }

    public function testRuntimeHasNoCraftCloudDependencyOrPrivateInfrastructureInspection(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/SearchRecurringQueueScheduler.php');
        $composer = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertIsString($source);
        self::assertIsString($composer);

        self::assertStringNotContainsString('craft\\cloud', $source);
        self::assertStringNotContainsString('craftcms/cloud', $composer);
        self::assertStringNotContainsString('Cloud', $source);
        self::assertStringNotContainsString('proxyQueue', $source);
    }

    private function ownedCleanupJob(): CleanupAnalyticsJob
    {
        return new CleanupAnalyticsJob([
            'reschedule' => true,
            'recurringOwner' => SearchRecurringQueueScheduler::ANALYTICS_OWNER,
        ]);
    }

    private function installQueue(BaseQueue $proxy): Queue
    {
        if ($this->schedulerQueue === null) {
            $current = Craft::$app->getQueue();
            self::assertInstanceOf(Queue::class, $current);
            $this->schedulerQueue = $current;
        }

        $this->sqsProxy = $proxy instanceof RecordingSearchSqsQueue ? $proxy : null;
        $this->unknownProxy = $proxy instanceof RecordingUnknownProxyQueue ? $proxy : null;
        $queue = new Queue([
            'db' => Craft::$app->getDb(),
            'mutex' => Craft::$app->getMutex(),
            'tableName' => $this->queueTable(),
            'channel' => $this->schedulerQueue->channel,
            'mutexTimeout' => $this->schedulerQueue->mutexTimeout,
            'proxyQueue' => $proxy,
        ]);
        Craft::$app->set('queue', $queue);

        return $queue;
    }

    private function pauseAt(int $timestamp): void
    {
        if ($this->timePaused) {
            DateTimeHelper::resume();
        }

        DateTimeHelper::pause(new \DateTime("@$timestamp"));
        $this->timePaused = true;
    }

    private function formatted(\DateTimeInterface $target): string
    {
        return DateFormatHelper::formatCompactDatetimeFromSettings(
            \DateTime::createFromInterface($target),
            SearchManager::$plugin->getSettings(),
            null,
            false,
            pluginHandle: 'search-manager',
        );
    }

    /** @return array<string, mixed> */
    private function onlyFamilyRow(string $ownerToken): array
    {
        $rows = $this->familyQuery($ownerToken)->orderBy(['id' => SORT_ASC])->all();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function countFamilyRows(string $ownerToken): int
    {
        return (int)$this->familyQuery($ownerToken)->count();
    }

    private function familyQuery(string $ownerToken): Query
    {
        return (new Query())
            ->from($this->queueTable())
            ->where(['like', 'job', $ownerToken]);
    }

    private function countClassRows(string $jobClass): int
    {
        return (int)(new Query())
            ->from($this->queueTable())
            ->where(['like', 'job', $jobClass])
            ->count();
    }

    /** @param array<string, mixed> $row */
    private function unserializeRow(array $row): object
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $job = $queue->serializer->unserialize((string)$row['job']);
        self::assertIsObject($job);

        return $job;
    }

    /** @param class-string<CleanupAnalyticsJob|SyncStatusJob> $jobClass */
    private function pushLegacy(string $jobClass, bool $reschedule, bool $json): string
    {
        $id = Craft::$app->getQueue()->delay(300)->push(new $jobClass([
            'reschedule' => $reschedule,
        ]));
        self::assertNotNull($id);

        if ($json) {
            $payload = (new JsonSerializer())->serialize(new $jobClass([
                'reschedule' => $reschedule,
            ]));
            Craft::$app->getDb()->createCommand()
                ->update($this->queueTable(), ['job' => $payload], ['id' => $id])
                ->execute();
        }

        return $id;
    }

    private function markExecuting(string $jobId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update($this->queueTable(), ['timeUpdated' => DateTimeHelper::currentTimeStamp()], ['id' => $jobId])
            ->execute();

        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $property = new \ReflectionProperty(Queue::class, '_executingJobId');
        $property->setValue($queue, $jobId);
    }

    private function rowExists(string $id): bool
    {
        return (new Query())->from($this->queueTable())->where(['id' => $id])->exists();
    }

    /** @return list<int> */
    private function sqsDelays(): array
    {
        return $this->sqsProxy === null ? [] : array_column($this->sqsProxy->pushes, 'delay');
    }

    /** @return list<int> */
    private function unknownDelays(): array
    {
        return $this->unknownProxy === null ? [] : array_column($this->unknownProxy->pushes, 'delay');
    }

    private function deleteFamilyRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), [
                'or',
                ['like', 'job', CleanupAnalyticsJob::class],
                ['like', 'job', SyncStatusJob::class],
                ['like', 'job', BatchSyncJob::class],
            ])
            ->execute();
    }

    /** @param array<string, mixed> $settings */
    private function withSettingsPost(string $section, array $settings): void
    {
        $this->originalRequest ??= Craft::$app->getRequest();
        $this->originalResponse ??= Craft::$app->getResponse();
        $this->originalRequestMethod ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'bodyParams' => [
                'section' => $section,
                'settings' => $settings,
            ],
        ]);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function restoreSettingsRequest(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
    }
}

final class RecordingSearchSqsQueue extends SqsQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];

    public bool $failPushes = false;

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->failPushes) {
            throw new RuntimeException('forced Search recurring proxy failure');
        }

        $this->pushes[] = [
            'delay' => (int)$delay,
            'priority' => $priority,
            'ttr' => (int)$ttr,
        ];

        return 'search-recurring-sqs-' . count($this->pushes);
    }
}

final class RecordingUnknownProxyQueue extends BaseQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $this->pushes[] = [
            'delay' => (int)$delay,
            'priority' => $priority,
            'ttr' => (int)$ttr,
        ];

        return 'search-recurring-unknown-' . count($this->pushes);
    }
}

final class FailingSearchRecurringQueueScheduler extends SearchRecurringQueueScheduler
{
    public int $statusReplacementAttempts = 0;

    public function replaceStatusSync(?int $statusSyncInterval = null): \lindemannrock\base\helpers\RecurringQueueResult
    {
        $this->statusReplacementAttempts++;
        throw new RuntimeException('forced recurring replacement failure');
    }
}
