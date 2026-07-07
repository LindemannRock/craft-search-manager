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
use craft\elements\Entry;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Delete-path coverage for the L3 pending-sync pipeline:
 *
 *   - An OP_UPSERT row whose target element no longer exists (deleted between
 *     queue and drain) flips to a backend `batchDelete` call. The row drains
 *     successfully — there is no retry storm for a structurally-impossible
 *     upsert.
 *   - An explicit OP_DELETE queued by `Elements::EVENT_AFTER_DELETE_ELEMENT`
 *     drives `batchDelete` against the backend even though there is no
 *     element to load.
 *
 * The first scenario is the buffer's equivalent of the IndexingService
 * cleanup-pass bug caught during L3 close-out — a regression here means
 * stale documents accumulate in backends with no signal in the queue.
 *
 * @since 5.45.0
 */
final class SyncBufferDeleteFanoutTest extends TestCase
{
    public function testUpsertForMissingElementFlipsToBatchDelete(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();

        // Insert an UPSERT row for a deliberately non-existent elementId so
        // PendingSyncProcessor::loadElement() returns null. We use a real
        // (indexHandle, siteId, elementType) so structural gates pass and the
        // row reaches the load-element step.
        $fakeElementId = (int) ((new \craft\db\Query())
            ->from('{{%elements}}')
            ->max('id')) + 1_000_000;

        $this->repository->upsertRows([[
            'indexHandle' => $index->handle,
            'elementType' => Entry::class,
            'elementId' => $fakeElementId,
            'siteId' => (int) $element->siteId,
            'op' => PendingSyncRepository::OP_UPSERT,
        ]]);

        $this->assertNotNull(
            $this->fetchPendingRow($index->handle, $fakeElementId, (int) $element->siteId),
            'Sanity check: row should be queued before the drain.',
        );

        (new BatchSyncJob())->execute(Craft::$app->queue);

        $this->assertNull(
            $this->fetchPendingRow($index->handle, $fakeElementId, (int) $element->siteId),
            'Row for a missing element must drain — the processor flips upsert→delete.',
        );

        $deleteCalls = $stub->callsFor('batchDelete');
        $this->assertNotEmpty($deleteCalls, 'Backend->batchDelete must be invoked for the missing-element row.');

        $matched = array_filter(
            $deleteCalls,
            static fn (array $c): bool => $c['indexName'] === $index->handle
                && !empty(array_filter(
                    $c['items'] ?? [],
                    static fn (array $item): bool => (int) ($item['elementId'] ?? 0) === $fakeElementId,
                )),
        );
        $this->assertNotEmpty(
            $matched,
            'batchDelete must target the index and elementId of the missing-element row.',
        );

        $this->assertEmpty(
            $stub->callsFor('batchIndex'),
            'No batchIndex call should fire for a row whose element does not exist.',
        );
    }

    public function testExplicitOpDeleteDrivesBatchDelete(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $element] = $pair;
        $stub = $this->installStubBackend();

        $this->repository->queueForElement($element, PendingSyncRepository::OP_DELETE);

        $row = $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId);
        $this->assertNotNull($row, 'OP_DELETE must queue a row even though no doc may exist in the backend yet.');
        $this->assertSame('delete', $row['op']);

        (new BatchSyncJob())->execute(Craft::$app->queue);

        $this->assertNull(
            $this->fetchPendingRow($index->handle, (int) $element->id, (int) $element->siteId),
            'OP_DELETE row should be drained after BatchSyncJob runs.',
        );

        $deleteCalls = $stub->callsFor('batchDelete');
        $matched = array_filter(
            $deleteCalls,
            static fn (array $c): bool => $c['indexName'] === $index->handle
                && !empty(array_filter(
                    $c['items'] ?? [],
                    static fn (array $item): bool => (int) ($item['elementId'] ?? 0) === (int) $element->id,
                )),
        );
        $this->assertNotEmpty(
            $matched,
            'batchDelete must be called for the explicitly-deleted element on its index.',
        );

        $this->assertEmpty(
            $stub->callsFor('batchIndex'),
            'OP_DELETE must not drive batchIndex.',
        );
    }
}
