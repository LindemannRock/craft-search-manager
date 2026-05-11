<?php

namespace lindemannrock\searchmanager\services\sync;

use craft\base\ElementInterface;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use yii\base\Component;

/**
 * Pending Sync Processor
 *
 * Converts pending sync rows into search backend batch operations.
 *
 * @since 5.45.0
 */
class PendingSyncProcessor extends Component
{
    use LoggingTrait;
    use ElementTypeGuardTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{success: int[], failures: array<int, array{ids: int[], error: string}>}
     */
    public function process(array $rows): array
    {
        $successIds = [];
        $failures = [];

        // Shared cache of EVENT_BEFORE_INDEX results across all indices in this
        // batch. Keyed by "elementId:siteId". Pre-L3 fired BEFORE_INDEX once per
        // (element, site) per indexElementNow() call — this matches that
        // contract: a listener that cancels via $event->isValid = false skips
        // ALL rows for that (element, site) pair across every index in the
        // batch, with no duplicate listener invocations per index.
        $beforeIndexResults = [];

        foreach ($this->groupByIndex($rows) as $indexHandle => $indexRows) {
            try {
                $result = $this->processIndexRows($indexHandle, $indexRows, $beforeIndexResults);
                $successIds = array_merge($successIds, $result['success']);
                $failures = array_merge($failures, $result['failures']);
            } catch (\Throwable $e) {
                $failures[] = [
                    'ids' => $this->rowIds($indexRows),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => array_values(array_unique($successIds)),
            'failures' => $failures,
        ];
    }

    /**
     * @param array<int, array<string, mixed>>       $rows
     * @param array<string, bool>                    $beforeIndexResults Cache of
     *     EVENT_BEFORE_INDEX outcomes, keyed by "elementId:siteId". Shared across
     *     all indices in a single process() run so the event fires at most once
     *     per (element, site) pair — matching the pre-L3 indexElementNow contract.
     * @return array{success: int[], failures: array<int, array{ids: int[], error: string}>}
     */
    private function processIndexRows(string $indexHandle, array $rows, array &$beforeIndexResults): array
    {
        $index = SearchIndex::findByHandle($indexHandle);
        if (!$index || !$index->enabled) {
            return [
                'success' => $this->rowIds($rows),
                'failures' => [],
            ];
        }

        // Batch auto-sync deliberately does NOT issue per-row documentExists
        // probes. The whole point of L3 is to collapse N save events into one
        // backend write — re-introducing a read-before-write for each row would
        // restore the API amplification we set out to eliminate. As a result:
        //
        //   - `documentCount` on indices is eventually consistent. It is not
        //     incremented/decremented from this path. Accurate values come from
        //     full rebuild or an explicit refresh action.
        //   - Deletes are sent unconditionally to the backend, which must treat
        //     a missing-document delete as success (idempotent). All shipped
        //     backends do; TypesenseBackend::delete() catches ObjectNotFound
        //     explicitly to preserve this invariant.
        //
        // The upsert→delete flip below still happens when an element no longer
        // matches the index's criteria — that uses local element/criteria
        // checks, NOT a backend read.
        //
        // EVENT_BEFORE_INDEX / EVENT_AFTER_INDEX preserve the pre-L3 contract:
        // BEFORE fires once per (element, site) per BatchSyncJob run (deduped
        // via $beforeIndexResults), AFTER fires once per (element, indexHandle)
        // after a successful batchIndex. Cancellation via $event->isValid = false
        // skips ALL rows for that (element, site) pair across every index in
        // the batch; cancelled rows are marked successfully handled so they
        // drain from the buffer (no retry storm for an intentionally-skipped
        // element).
        $docs = [];
        $docRows = [];
        $docElements = [];
        $deleteItems = [];
        $deleteRows = [];
        $successIds = [];
        $failures = [];

        $indexing = SearchManager::$plugin->indexing;

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            $elementId = (int)$row['elementId'];
            $siteId = (int)$row['siteId'];
            $elementType = (string)$row['elementType'];
            $op = (string)$row['op'];

            if ($index->elementType !== $elementType || !$index->appliesToSiteId($siteId)) {
                $successIds[] = $rowId;
                continue;
            }

            if ($op === PendingSyncRepository::OP_DELETE || !$this->isElementTypeAvailable($elementType, 'batch-sync')) {
                $this->queueDelete($elementId, $siteId, $row, $deleteItems, $deleteRows);
                continue;
            }

            $element = $this->loadElement($elementType, $elementId, $siteId);
            if (!$element || !$indexing->shouldIndexElementForSite($element)) {
                $this->queueDelete($elementId, $siteId, $row, $deleteItems, $deleteRows);
                continue;
            }

            if (!$index->matchesElement($element)) {
                $this->queueDelete($elementId, $siteId, $row, $deleteItems, $deleteRows);
                continue;
            }

            if ($index->skipEntriesWithoutUrl && $element->url === null) {
                $this->queueDelete($elementId, $siteId, $row, $deleteItems, $deleteRows);
                continue;
            }

            // EVENT_BEFORE_INDEX — fired once per (element, site) per batch
            // run. Result cached so the listener sees one callback even when
            // the element matches multiple indices in this batch.
            $beforeKey = $elementId . ':' . $siteId;
            if (!isset($beforeIndexResults[$beforeKey])) {
                $beforeEvent = new IndexEvent(['element' => $element]);
                $indexing->trigger(IndexingService::EVENT_BEFORE_INDEX, $beforeEvent);
                $beforeIndexResults[$beforeKey] = $beforeEvent->isValid;
            }
            if (!$beforeIndexResults[$beforeKey]) {
                // Listener cancelled — drain the row without indexing.
                $this->logDebug('Pending sync cancelled by EVENT_BEFORE_INDEX listener', [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'indexHandle' => $indexHandle,
                ]);
                $successIds[] = $rowId;
                continue;
            }

            $data = SearchManager::$plugin->transformers->transform(
                $element,
                $indexHandle,
                $index->transformerClass,
                $index->headingLevels,
            );

            if ($data === null) {
                $successIds[] = $rowId;
                continue;
            }

            if (!isset($data['siteId'])) {
                $data['siteId'] = $siteId;
            } elseif ((int)$data['siteId'] !== $siteId) {
                $this->logWarning('Transformer siteId mismatch in pending sync; overriding', [
                    'elementId' => $elementId,
                    'elementSiteId' => $siteId,
                    'transformerSiteId' => $data['siteId'],
                ]);
                $data['siteId'] = $siteId;
            }

            $docs[] = $data;
            $docRows[] = $row;
            $docElements[] = $element;
        }

        if (!empty($docs)) {
            if (SearchManager::$plugin->backend->batchIndex($indexHandle, $docs)) {
                SearchIndex::touchLastIndexedDebounced($indexHandle);

                // EVENT_AFTER_INDEX — fired once per successfully-indexed row,
                // matching pre-L3 behaviour where indexElementNow fired AFTER
                // per matching index after the backend write succeeded.
                foreach ($docRows as $i => $docRow) {
                    $indexing->trigger(IndexingService::EVENT_AFTER_INDEX, new IndexEvent([
                        'element' => $docElements[$i],
                        'document' => $docs[$i],
                        'indexHandle' => $indexHandle,
                    ]));
                }

                $successIds = array_merge($successIds, $this->rowIds($docRows));
            } else {
                $failures[] = [
                    'ids' => $this->rowIds($docRows),
                    'error' => "Batch index failed for {$indexHandle}.",
                ];
            }
        }

        if (!empty($deleteItems)) {
            if (SearchManager::$plugin->backend->batchDelete($indexHandle, $deleteItems)) {
                SearchIndex::touchLastIndexedDebounced($indexHandle);
                if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                    SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                    SearchManager::$plugin->autocomplete->clearCache($indexHandle);
                }
                $successIds = array_merge($successIds, $this->rowIds($deleteRows));
            } else {
                $failures[] = [
                    'ids' => $this->rowIds($deleteRows),
                    'error' => "Batch delete failed for {$indexHandle}.",
                ];
            }
        }

        return [
            'success' => $successIds,
            'failures' => $failures,
        ];
    }

    /**
     * @param class-string<ElementInterface> $elementType
     */
    private function loadElement(string $elementType, int $elementId, int $siteId): ?ElementInterface
    {
        /** @var \craft\elements\db\ElementQuery $query */
        $query = $elementType::find();
        $element = $query
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        return $element instanceof ElementInterface ? $element : null;
    }

    /**
     * Queue a delete operation for a single row, unconditionally.
     *
     * No documentExists check — backends are required to treat deleting a
     * missing document as success. See class docblock and the comment in
     * processIndexRows() for the rationale.
     *
     * @param array<string, mixed> $row
     * @param array<int, array{elementId: int, siteId: int}> $deleteItems
     * @param array<int, array<string, mixed>> $deleteRows
     */
    private function queueDelete(
        int $elementId,
        int $siteId,
        array $row,
        array &$deleteItems,
        array &$deleteRows,
    ): void {
        $deleteItems[] = [
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];
        $deleteRows[] = $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByIndex(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $indexHandle = (string)$row['indexHandle'];
            $grouped[$indexHandle][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function rowIds(array $rows): array
    {
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }
}
