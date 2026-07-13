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
use craft\events\ElementEvent;
use craft\services\Elements;
use lindemannrock\searchmanager\adapters\CraftSearchAdapter;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Event;

/**
 * Verifies the auto-indexing wiring at the Craft event-system level:
 *
 *   - Class-level listeners are attached on `Elements::EVENT_AFTER_SAVE_ELEMENT`
 *     and `Elements::EVENT_AFTER_DELETE_ELEMENT`.
 *   - When `autoIndex` is enabled, saving an element via the normal Craft event
 *     queues a pending-sync row for every matching (index, site) pair.
 *   - When `autoIndex` is disabled in the same booted process, the listener
 *     returns before queueing work.
 *
 * @since 5.45.0
 */
final class SyncBufferAutoIndexTest extends TestCase
{
    public function testAutoIndexRegistersSaveAndDeleteListeners(): void
    {
        $this->assertTrue(
            Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT),
            'Search Manager must attach a class-level handler on Elements::EVENT_AFTER_SAVE_ELEMENT.',
        );
        $this->assertTrue(
            Event::hasHandlers(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT),
            'Search Manager must attach a class-level handler on Elements::EVENT_AFTER_DELETE_ELEMENT.',
        );
    }

    public function testElementSaveEventQueuesARowWhenAutoIndexIsEnabled(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        $this->assertSame(
            0,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'Buffer must be empty before the save event fires (setUp truncates).',
        );

        $this->withAutoIndex(true, function() use ($element): void {
            // Fire the listener directly. We are asserting that the autoIndex
            // wiring → queueForElement → buffer pipeline is intact. Going through
            // `Craft::$app->getElements()->saveElement()` adds field-validation
            // work (Link field touches $request->getIsPost() which doesn't exist
            // on console requests) that is unrelated to what this test verifies.
            Event::trigger(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                new ElementEvent(['element' => $element]),
            );
        });

        $this->assertGreaterThanOrEqual(
            1,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'EVENT_AFTER_SAVE_ELEMENT listener must queue a row for the saved (element, site).',
        );
    }

    public function testElementSaveEventDoesNotQueueWhenAutoIndexIsDisabled(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;

        $this->withAutoIndex(false, function() use ($element): void {
            Event::trigger(
                Elements::class,
                Elements::EVENT_AFTER_SAVE_ELEMENT,
                new ElementEvent(['element' => $element]),
            );
        });

        $this->assertSame(
            0,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'EVENT_AFTER_SAVE_ELEMENT listener must not queue rows while autoIndex is disabled.',
        );
    }

    public function testNativeSearchSavePathQueuesOnePendingRowWhenAutoIndexIsDisabled(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $backend = $this->installStubBackend();
        $adapter = new CraftSearchAdapter();
        $existingBatchJobs = $this->countQueueRows('BatchSyncJob');

        $this->withAutoIndex(false, function() use ($adapter, $element): void {
            $this->assertTrue($adapter->indexElementAttributes($element));
            $this->assertTrue($adapter->indexElementAttributes($element));
        });

        $this->assertSame(
            1,
            $this->countPendingRows([
                'indexHandle' => $index->handle,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ]),
            'Two rapid native-search save callbacks must collapse into one pending-sync row.',
        );
        $this->assertLessThanOrEqual(
            1,
            $this->countQueueRows('BatchSyncJob') - $existingBatchJobs,
            'Two rapid native-search save callbacks must not enqueue two BatchSyncJob rows.',
        );

        (new BatchSyncJob())->execute(Craft::$app->queue);

        $this->assertNull(
            $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId),
            'BatchSyncJob must drain the pending row from the native-search save path.',
        );
        $this->assertNotEmpty(
            array_filter(
                $backend->calls,
                static fn(array $call): bool => $call['method'] === 'batchIndex' && $call['indexName'] === $index->handle,
            ),
            'BatchSyncJob must process the queued row through the backend batch writer.',
        );
    }

    /**
     * @param callable(): void $callback
     */
    private function withAutoIndex(bool $enabled, callable $callback): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $original = $settings->autoIndex;
        $settings->autoIndex = $enabled;

        try {
            $callback();
        } finally {
            $settings->autoIndex = $original;
        }
    }

    private function countQueueRows(string $jobClass): int
    {
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->andWhere(['fail' => false])
            ->andWhere(['timeUpdated' => null])
            ->count();
    }
}
