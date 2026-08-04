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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Happy-path coverage for the L3 pending-sync pipeline.
 *
 * @since 5.46.0
 */
final class SyncBufferHappyPathTest extends TestCase
{
    public function testQueueForElementCreatesAPendingRow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $queued = $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
        $this->assertGreaterThanOrEqual(1, $queued, 'queueForElement should report at least one row queued.');

        $row = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($row, 'A pending_syncs row should exist for the target (index, element, site).');
        $this->assertSame('upsert', $row['op']);
        $this->assertSame('pending', $row['status']);
    }

    public function testBatchSyncJobDrainsRowAndWritesDocumentToBackend(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$sourceIndex, $element] = $pair;
        $index = $this->createIsolatedDatabaseIndex($sourceIndex, (int)$element->siteId);

        try {
            $this->withOnlySearchIndices([$index], function() use ($index, $element): void {
                SearchManager::$plugin->dependencies->clearIndexCatalogue();
                $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

                // The isolated pending table contains only this test's rows,
                // but retain the cap so a processing regression cannot hang.
                $maxIterations = 50;
                $iterations = 0;
                while ($this->fetchPendingRow($index->handle, (int)$element->id, (int)$element->siteId) !== null) {
                    (new BatchSyncJob())->execute(Craft::$app->queue);
                    $iterations++;
                    $this->assertLessThanOrEqual($maxIterations, $iterations, 'BatchSyncJob did not drain the target row within the iteration cap.');
                }

                $this->assertNull(
                    $this->fetchPendingRow($index->handle, (int)$element->id, (int)$element->siteId),
                    'Pending row should be drained after BatchSyncJob runs.',
                );
                $this->assertTrue(
                    SearchManager::$plugin->backend->documentExists($index->handle, (int)$element->id, (int)$element->siteId),
                    'The production MySQL backend should persist and read back the owned document after BatchSyncJob runs.',
                );
            });
        } finally {
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
            SearchIndex::clearCache();
        }
    }

    public function testRapidQueueForElementCallsCollapseToOneRow(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        for ($i = 0; $i < 5; $i++) {
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);
        }

        $count = $this->countPendingRows([
            'indexHandle' => $index->handle,
            'elementId' => (int) $element->id,
            'siteId' => (int) $element->siteId,
        ]);
        $this->assertSame(1, $count, 'Composite UPSERT key should collapse rapid same-target queue calls into a single row.');
    }

    public function testBatchSyncJobRefreshesDocumentCountAfterCompletedDrain(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();
        $originalCount = $index->documentCount;
        try {
            $index->updateStats(0);
            $this->repository->queueForElement($element, PendingSyncRepository::OP_UPSERT);

            (new BatchSyncJob())->execute(Craft::$app->queue);

            $backendCount = $stub->getDocumentCount($index->handle);
            $refreshed = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($index->handle);
            $this->assertNotNull($backendCount);
            $this->assertNotNull($refreshed);
            $this->assertSame(
                $backendCount,
                $refreshed->documentCount,
                'Completed BatchSyncJob drains must refresh documentCount from authoritative backend state.',
            );
        } finally {
            $index->updateStats($originalCount);
        }
    }

    public function testDeferredBatchSyncJobRefreshesDocumentCountAndSchedulesContinuation(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();
        $settings = SearchManager::$plugin->getSettings();
        $originalBatchSize = $settings->syncBatchSize;
        $queueIdsBefore = $this->batchSyncQueueIds();
        $fakeElementId = (int) ((new Query())
            ->from('{{%elements}}')
            ->max('id')) + 1_000_000;

        try {
            $settings->syncBatchSize = 1;
            $index->updateStats(987_654);
            $this->repository->upsertRows([
                [
                    'indexHandle' => $index->handle,
                    'elementType' => Entry::class,
                    'elementId' => (int) $element->id,
                    'siteId' => (int) $element->siteId,
                    'op' => PendingSyncRepository::OP_UPSERT,
                ],
                [
                    'indexHandle' => $index->handle,
                    'elementType' => Entry::class,
                    'elementId' => $fakeElementId,
                    'siteId' => (int) $element->siteId,
                    'op' => PendingSyncRepository::OP_UPSERT,
                ],
            ]);

            $job = new class() extends BatchSyncJob {
                private int $budgetChecks = 0;

                protected function hasExceededTimeBudget(float $started): bool
                {
                    return ++$this->budgetChecks > 1;
                }
            };
            $job->execute(Craft::$app->queue);

            $backendCount = $stub->documentCounts[$index->handle] ?? null;
            $refreshed = SearchIndex::findByHandle($index->handle);
            self::assertNotNull($backendCount);
            self::assertNotNull($refreshed);
            self::assertSame(
                $backendCount,
                $refreshed->documentCount,
                'Deferred BatchSyncJob runs must reconcile successfully touched indices before exiting.',
            );
            self::assertNull(
                $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId),
                'The first row should be successfully processed before the run defers.',
            );
            self::assertNotNull(
                $this->fetchPendingRow($index->handle, $fakeElementId, (int) $element->siteId),
                'A due row must remain so the run takes the continuation path.',
            );
            self::assertTrue(
                $this->hasDueBatchSyncWakeup(),
                'The deferred run must leave one sufficient continuation BatchSyncJob.',
            );
        } finally {
            $settings->syncBatchSize = $originalBatchSize;
            $newQueueIds = array_diff($this->batchSyncQueueIds(), $queueIdsBefore);
            if ($newQueueIds !== []) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->delete($this->queueTable(), ['id' => $newQueueIds])
                    ->execute();
            }
        }
    }

    /**
     * @return int[]
     */
    private function batchSyncQueueIds(): array
    {
        return array_map(
            'intval',
            (new Query())
                ->select(['id'])
                ->from($this->queueTable())
                ->where(['like', 'job', 'searchmanager'])
                ->andWhere(['like', 'job', 'BatchSyncJob'])
                ->column(),
        );
    }

    private function hasDueBatchSyncWakeup(): bool
    {
        $rows = (new Query())
            ->select(['timePushed', 'delay'])
            ->from($this->queueTable())
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'BatchSyncJob'])
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->all();

        foreach ($rows as $row) {
            if ((int)$row['timePushed'] + (int)$row['delay'] <= time() + 1) {
                return true;
            }
        }

        return false;
    }

    private function createIsolatedDatabaseIndex(SearchIndex $source, int $siteId): SearchIndex
    {
        $suffix = bin2hex(random_bytes(6));
        $backendHandle = 'a12mysql' . $suffix;
        $indexHandle = 'a12sync' . $suffix;
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'A12 isolated MySQL ' . $suffix,
            'handle' => $backendHandle,
            'backendType' => 'mysql',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $db->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'A12 isolated sync ' . $suffix,
            'handle' => $indexHandle,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => '{}',
            'transformerClass' => (string)$source->transformerClass,
            'headingLevels' => $source->headingLevels === null ? null : json_encode($source->headingLevels, JSON_THROW_ON_ERROR),
            'language' => $source->language,
            'backend' => $backendHandle,
            'enabled' => 1,
            'enableAnalytics' => 0,
            'disableStopWords' => (int)$source->disableStopWords,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);

        return $index;
    }
}
