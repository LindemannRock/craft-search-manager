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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Verifies recurring jobs push their next occurrence from the execute-time
 * reschedule path even while the current queue row still exists.
 *
 * @since 5.47.0
 */
final class RecurringJobsRescheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteSearchManagerQueueRows();
    }

    protected function tearDown(): void
    {
        $this->deleteSearchManagerQueueRows();
        parent::tearDown();
    }

    public function testCleanupJobReschedulesWhenExistingCleanupRowExists(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupAnalyticsJob'));

        $method = new ReflectionMethod(CleanupAnalyticsJob::class, 'scheduleNextCleanup');
        $method->invoke(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));

        $this->assertSame(2, $this->countQueueRows('CleanupAnalyticsJob'));
    }

    public function testSyncJobReschedulesWhenExistingSyncRowExists(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new SyncStatusJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('SyncStatusJob'));

        $method = new ReflectionMethod(SyncStatusJob::class, 'scheduleNextSync');
        $method->invoke(new SyncStatusJob([
            'reschedule' => true,
        ]), DateFormatHelper::now());

        $this->assertSame(2, $this->countQueueRows('SyncStatusJob'));
    }

    public function testBatchSyncContinuationSchedulesWhenExistingBatchRowExists(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new BatchSyncJob());
        $this->assertSame(1, $this->countQueueRows('BatchSyncJob'));

        SearchManager::$plugin->pendingSyncs->scheduleBatchJob(true);

        $this->assertSame(2, $this->countQueueRows('BatchSyncJob'));
    }

    public function testBatchSyncDebounceKeepsSingleBatchRowByDefault(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new BatchSyncJob());
        $this->assertSame(1, $this->countQueueRows('BatchSyncJob'));

        SearchManager::$plugin->pendingSyncs->scheduleBatchJob();

        $this->assertSame(1, $this->countQueueRows('BatchSyncJob'));
    }

    private function countQueueRows(string $jobClass): int
    {
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->count();
    }

    private function deleteSearchManagerQueueRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%queue}}', ['like', 'job', 'searchmanager'])
            ->execute();
    }
}
