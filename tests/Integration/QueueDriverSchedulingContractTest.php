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
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use yii\queue\Queue;

/**
 * Regression coverage for audit Batch 5 findings.
 *
 * @since 5.53.0
 */
final class QueueDriverSchedulingContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', 'audit-batch-5-'])
            ->execute();

        parent::tearDown();
    }

    public function testBatchSchedulingSkipsDbQueueDedupeForCustomQueueDrivers(): void
    {
        $repository = new PendingSyncRepository();
        $method = new \ReflectionMethod(PendingSyncRepository::class, 'hasExistingDbQueueBatchJob');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($repository, new QueueDriverSchedulingStub()));
    }
}

final class QueueDriverSchedulingStub extends Queue
{
    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        return 'audit-batch-5';
    }
}
