<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SplitSectionDocumentHelper;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use yii\base\Component;

/**
 * Indexing Service
 *
 * Handles all indexing operations (single, batch, rebuild)
 *
 * @since 5.0.0
 */
class IndexingService extends Component
{
    use LoggingTrait;
    use ElementTypeGuardTrait;

    // Event constants
    public const EVENT_BEFORE_INDEX = 'beforeIndex';
    public const EVENT_AFTER_INDEX = 'afterIndex';

    /**
     * @var array{
     *     indexHandle: string,
     *     acceptedElementCount: int,
     *     acceptedDocumentCount: int,
     *     transformationFailures: list<array{elementId: int|null, error: string}>,
     *     backendFailures: list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>
     * }
     */
    private array $lastBatchResult = [
        'indexHandle' => '',
        'acceptedElementCount' => 0,
        'acceptedDocumentCount' => 0,
        'transformationFailures' => [],
        'backendFailures' => [],
    ];

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // SINGLE ELEMENT INDEXING
    // =========================================================================

    /**
     * Index a single element
     *
     * @param ElementInterface $element
     * @return bool
     */
    public function indexElement(ElementInterface $element): bool
    {
        $queued = SearchManager::$plugin->pendingSyncs->queueForElement($element, PendingSyncRepository::OP_UPSERT);

        $this->logDebug('Queued element for pending sync', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
            'rows' => $queued,
        ]);

        return true;
    }

    /**
     * Index an element immediately (no queue)
     *
     * @param ElementInterface $element
     * @return bool
     */
    public function indexElementNow(ElementInterface $element): bool
    {
        $this->logDebug('Indexing element', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
        ]);

        // Skip elements that shouldn't be indexed (drafts, revisions, disabled for site)
        if (!$this->shouldIndexElementForSite($element)) {
            $this->logDebug('Element should not be indexed, skipping', [
                'elementId' => $element->id,
                'siteId' => $element->siteId,
                'enabled' => $element->enabled,
                'enabledForSite' => $element->getEnabledForSite(),
                'status' => $element->getStatus(),
            ]);
            return true; // Not an error, just shouldn't be indexed
        }

        // Trigger before event
        $event = new IndexEvent([
            'element' => $element,
        ]);
        $this->trigger(self::EVENT_BEFORE_INDEX, $event);

        if (!$event->isValid) {
            $this->logInfo('Element indexing cancelled by event handler', [
                'elementId' => $element->id,
            ]);
            return false;
        }

        // Get all index handles for this element. An empty array is valid
        // here — the element may have fallen out of every index's criteria
        // (e.g. custom status flipped from available → sold). We must still
        // fall through to the cleanup pass below so stale documents get
        // purged from any index that previously held them.
        $indexHandles = $this->getIndexHandlesForElement($element);

        if (empty($indexHandles)) {
            $this->logDebug('No matching indices — will run cleanup pass only', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
            ]);
        }

        $matchedHandles = array_flip($indexHandles);

        // Index to all matching indices (no-op when $indexHandles is empty)
        $success = true;
        foreach ($indexHandles as $indexHandle) {
            try {
                // Check if index should skip entries without URL
                $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
                if ($index && $index->shouldSkipElementWithoutUrl($element)) {
                    unset($matchedHandles[$indexHandle]);
                    $this->logDebug('Skipping element without URL for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                    continue;
                }

                // Transform element via TransformerService (fires before/after events)
                $transformResult = SearchManager::$plugin->transformers->transformWithResult(
                    $element,
                    $indexHandle,
                    $index->transformerClass,
                    $index->headingLevels,
                );

                if ($transformResult['status'] === 'skipped') {
                    $this->logDebug('Transform intentionally skipped for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                    continue;
                }

                if ($transformResult['status'] === 'failed') {
                    $this->logWarning('Transformer failed for inline indexing', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                        'error' => $transformResult['error'] ?? 'Unknown transformation failure.',
                    ]);
                    $success = false;
                    continue;
                }

                $data = $transformResult['data'];
                if ($data === null) {
                    $this->logWarning('Transformer reported success without document data', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                    $success = false;
                    continue;
                }

                // Always ensure siteId is set from element (source of truth)
                // This guarantees backends receive correct siteId for objectID generation
                if (!isset($data['siteId'])) {
                    $data['siteId'] = $element->siteId;
                } elseif ((int)$data['siteId'] !== (int)$element->siteId) {
                    $this->logWarning('Transformer siteId mismatch; overriding', [
                        'elementId' => $element->id,
                        'elementSiteId' => $element->siteId,
                        'transformerSiteId' => $data['siteId'],
                    ]);
                    $data['siteId'] = $element->siteId;
                }

                // Get the backend that will be used for this index
                $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);
                $backendName = $backend ? $backend->getName() : 'none';

                $this->logDebug('Indexing to backend', [
                    'elementId' => $element->id,
                    'elementSiteId' => $element->siteId,
                    'indexHandle' => $indexHandle,
                    'backendName' => $backendName,
                ]);

                $documents = $this->documentsForIndex($index, $element, $data);
                $usesSplitSections = $index?->usesSplitSections() === true;
                $isNewDocument = false;
                if ($usesSplitSections) {
                    $result = SearchManager::$plugin->backend->batchIndex($indexHandle, $documents)
                        && SearchManager::$plugin->backend->deleteOrphanDocuments(
                            $indexHandle,
                            (int)$element->id,
                            (int)$element->siteId,
                            $this->backendIdsFromDocuments($documents),
                        );
                } else {
                    $indexResult = SearchManager::$plugin->backend->indexWithResult($indexHandle, $data);
                    $result = $indexResult['success'];
                    $isNewDocument = $indexResult['wasCreated'] === true;
                }

                if ($result) {
                    // Clear caches for this index (if enabled)
                    if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                        SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                        SearchManager::$plugin->autocomplete->clearCache($indexHandle);
                    }

                    if ($usesSplitSections) {
                        $index->refreshDocumentCount();
                    } elseif ($isNewDocument) {
                        SearchIndex::incrementDocumentCount($indexHandle);
                    }
                    SearchIndex::touchLastIndexedDebounced($indexHandle);

                    // Trigger after event
                    $this->trigger(self::EVENT_AFTER_INDEX, new IndexEvent([
                        'element' => $element,
                        'document' => $index?->usesSplitSections() ? $documents : $data,
                        'indexHandle' => $indexHandle,
                    ]));

                    $this->logInfo('Element indexed successfully', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                        'backendName' => $backendName,
                        'isNew' => $isNewDocument,
                    ]);
                } else {
                    $this->logWarning('Backend index() returned false', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                        'backendName' => $backendName,
                        'failures' => SearchManager::$plugin->backend->getLastIndexingFailures($indexHandle),
                    ]);
                    $success = false;
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to index element', [
                    'elementId' => $element->id,
                    'indexHandle' => $indexHandle,
                    'error' => $e->getMessage(),
                ]);
                $success = false;
            }
        }

        // Cleanup pass: the element passed shouldIndexElementForSite but may have
        // fallen out of some indices' criteria (e.g. custom status flipped). Scan
        // same-type-and-site indices the element did NOT match and purge any
        // stale documents — otherwise they linger until a full rebuild.
        $elementClass = get_class($element);
        $siteId = (int) $element->siteId;

        foreach ($this->getAllIndices() as $index) {
            if (!$index->enabled) {
                continue;
            }
            if (!SearchManager::$plugin->dependencies->isIndexAvailable($index->handle)) {
                continue;
            }
            if ($index->elementType !== $elementClass) {
                continue;
            }
            if (!$index->appliesToSiteId($siteId)) {
                continue;
            }
            if (isset($matchedHandles[$index->handle])) {
                continue;
            }

            try {
                $deleteResult = $index->usesSplitSections()
                    ? [
                        'success' => SearchManager::$plugin->backend->deleteOrphanDocuments($index->handle, (int)$element->id, $siteId, []),
                        'existed' => null,
                    ]
                    : SearchManager::$plugin->backend->deleteWithResult($index->handle, $element->id, $siteId);
                if ($deleteResult['success']) {
                    if ($index->usesSplitSections()) {
                        $index->refreshDocumentCount();
                    } elseif ($deleteResult['existed'] === true) {
                        SearchIndex::decrementDocumentCount($index->handle);
                    }
                    SearchIndex::touchLastIndexedDebounced($index->handle);
                    if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                        SearchManager::$plugin->backend->clearSearchCache($index->handle);
                        SearchManager::$plugin->autocomplete->clearCache($index->handle);
                    }
                    $this->logInfo('Removed stale document from non-matching index', [
                        'elementId' => $element->id,
                        'siteId' => $siteId,
                        'indexHandle' => $index->handle,
                        'reason' => 'criteria no longer matches',
                    ]);
                } else {
                    $this->logWarning('Backend cleanup returned false', [
                        'elementId' => $element->id,
                        'siteId' => $siteId,
                        'indexHandle' => $index->handle,
                    ]);
                    $success = false;
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to clean up stale document', [
                    'elementId' => $element->id,
                    'siteId' => $siteId,
                    'indexHandle' => $index->handle,
                    'error' => $e->getMessage(),
                ]);
                $success = false;
            }
        }

        return $success;
    }

    // =========================================================================
    // MULTI-SITE SYNC
    // =========================================================================

    /**
     * Check if an element should be indexed for its specific site
     *
     * Checks: not draft/revision, enabled globally, enabled for site, proper status
     *
     * @param ElementInterface $element
     * @return bool
     */
    public function shouldIndexElementForSite(ElementInterface $element): bool
    {
        return SearchElementAvailabilityHelper::isSearchable($element);
    }

    // =========================================================================
    // BATCH INDEXING
    // =========================================================================

    /**
     * Index multiple elements in batch
     *
     * @param ElementInterface[] $elements
     * @param string $indexHandle
     * @return bool
     * @internal RebuildIndexJob is the sole supported runtime caller.
     */
    public function batchIndex(array $elements, string $indexHandle): bool
    {
        $items = [];
        $elementDocumentIndexes = [];
        $transformationFailures = [];
        $this->lastBatchResult = [
            'indexHandle' => $indexHandle,
            'acceptedElementCount' => 0,
            'acceptedDocumentCount' => 0,
            'transformationFailures' => [],
            'backendFailures' => [],
        ];

        // Get index config for transformer class and heading levels
        $index = SearchIndex::findByHandle($indexHandle);
        if ($index === null) {
            foreach (SearchIndex::findAll() as $candidate) {
                if ($candidate->handle === $indexHandle) {
                    $index = $candidate;
                    break;
                }
            }
        }
        if (!$index || !SearchManager::$plugin->dependencies->isIndexAvailable($indexHandle)) {
            return false;
        }

        SearchManager::$plugin->transformers->withTransformerReuse(function() use (
            $elements,
            $indexHandle,
            $index,
            &$items,
            &$elementDocumentIndexes,
            &$transformationFailures,
        ): void {
            foreach ($elements as $element) {
                // Transform via TransformerService (fires before/after events)
                $transformResult = SearchManager::$plugin->transformers->transformWithResult(
                    $element,
                    $indexHandle,
                    $index->transformerClass,
                    $index->headingLevels,
                );

                if ($transformResult['status'] === 'skipped') {
                    continue;
                }

                if ($transformResult['status'] === 'failed') {
                    $transformationFailures[] = [
                        'elementId' => $element->id !== null ? (int)$element->id : null,
                        'error' => $transformResult['error'] ?? 'Unknown transformation failure.',
                    ];
                    continue;
                }

                $data = $transformResult['data'];
                if ($data === null) {
                    $transformationFailures[] = [
                        'elementId' => $element->id !== null ? (int)$element->id : null,
                        'error' => 'Transformer reported success without document data.',
                    ];
                    continue;
                }

                // Always ensure siteId is set from element (source of truth)
                // This guarantees backends receive correct siteId for objectID generation
                if (!isset($data['siteId'])) {
                    $data['siteId'] = $element->siteId;
                } elseif ((int)$data['siteId'] !== (int)$element->siteId) {
                    $this->logWarning('Transformer siteId mismatch in batch; overriding', [
                        'elementId' => $element->id,
                        'elementSiteId' => $element->siteId,
                        'transformerSiteId' => $data['siteId'],
                    ]);
                    $data['siteId'] = $element->siteId;
                }

                $elementKey = get_class($element) . ':' . (string)$element->id . ':' . (string)$element->siteId;
                $documents = $this->documentsForIndex($index, $element, $data);
                if ($documents === []) {
                    $transformationFailures[] = [
                        'elementId' => $element->id !== null ? (int)$element->id : null,
                        'error' => 'Transformer produced no indexable documents.',
                    ];
                    continue;
                }

                foreach ($documents as $document) {
                    $documentIndex = count($items);
                    $items[] = $document;
                    $elementDocumentIndexes[$elementKey][] = $documentIndex;
                }
            }
        });

        if (empty($items)) {
            $this->lastBatchResult['transformationFailures'] = $transformationFailures;

            return $transformationFailures === [];
        }

        try {
            $result = SearchManager::$plugin->backend->batchIndex($indexHandle, $items);
            $backendFailures = $result
                ? []
                : SearchManager::$plugin->backend->getLastIndexingFailures($indexHandle);
            $acceptedDocumentIndexes = $result
                ? array_keys($items)
                : $this->acceptedDocumentIndexes($items, $backendFailures);
            $acceptedDocumentLookup = array_fill_keys($acceptedDocumentIndexes, true);
            $acceptedElementCount = 0;
            foreach ($elementDocumentIndexes as $documentIndexes) {
                $allDocumentsAccepted = true;
                foreach ($documentIndexes as $documentIndex) {
                    if (!isset($acceptedDocumentLookup[$documentIndex])) {
                        $allDocumentsAccepted = false;
                        break;
                    }
                }
                if ($allDocumentsAccepted) {
                    $acceptedElementCount++;
                }
            }

            $this->lastBatchResult = [
                'indexHandle' => $indexHandle,
                'acceptedElementCount' => $acceptedElementCount,
                'acceptedDocumentCount' => count($acceptedDocumentIndexes),
                'transformationFailures' => $transformationFailures,
                'backendFailures' => $backendFailures,
            ];

            if ($result) {
                // Clear caches for this index (if enabled)
                if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                    SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                    SearchManager::$plugin->autocomplete->clearCache($indexHandle);
                }
            } else {
                $this->logWarning($this->lastIndexingFailureMessage($indexHandle), [
                    'indexHandle' => $indexHandle,
                    'failures' => SearchManager::$plugin->backend->getLastIndexingFailures($indexHandle),
                ]);
            }

            if ($transformationFailures !== []) {
                $this->logWarning($this->lastBatchIndexingFailureMessage($indexHandle), [
                    'indexHandle' => $indexHandle,
                    'failures' => $transformationFailures,
                ]);
            }

            return $result && $transformationFailures === [];
        } catch (\Throwable $e) {
            $this->lastBatchResult['transformationFailures'] = $transformationFailures;
            $this->lastBatchResult['backendFailures'] = [[
                'backendId' => null,
                'elementId' => null,
                'title' => null,
                'error' => $e->getMessage(),
            ]];
            $this->logError('Failed to batch index elements', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Return accounting for the most recent batch call.
     *
     * `acceptedElementCount` counts source elements whose complete generated
     * document set was accepted. Intentional transform-event skips are omitted
     * without being reported as failures.
     *
     * @return array{
     *     indexHandle: string,
     *     acceptedElementCount: int,
     *     acceptedDocumentCount: int,
     *     transformationFailures: list<array{elementId: int|null, error: string}>,
     *     backendFailures: list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>
     * }
     * @since 5.54.0
     */
    public function getLastBatchResult(): array
    {
        return $this->lastBatchResult;
    }

    /**
     * Return the most recent transform/backend batch failures in a job-visible form.
     *
     * @since 5.54.0
     */
    public function lastBatchIndexingFailureMessage(string $indexHandle): string
    {
        $messages = [];
        if ($this->lastBatchResult['indexHandle'] === $indexHandle) {
            foreach ($this->lastBatchResult['transformationFailures'] as $failure) {
                $label = $failure['elementId'] !== null ? 'element ' . $failure['elementId'] : 'unknown element';
                $messages[] = $label . ': ' . $failure['error'];
            }
        }

        if ($messages !== []) {
            $message = "Element transformation failed for {$indexHandle}: " . implode('; ', array_slice($messages, 0, 5))
                . (count($messages) > 5 ? ' +' . (count($messages) - 5) . ' more' : '');

            if ($this->lastBatchResult['backendFailures'] !== []) {
                $message .= ' | ' . $this->lastIndexingFailureMessage($indexHandle);
            }

            return $message;
        }

        return $this->lastIndexingFailureMessage($indexHandle);
    }

    /**
     * Return the most recent backend indexing failures in a CP/job-visible form.
     *
     * @since 5.53.0
     */
    public function lastIndexingFailureMessage(string $indexHandle): string
    {
        $failures = SearchManager::$plugin->backend->getLastIndexingFailures($indexHandle);
        if ($failures === []) {
            return "Batch index failed for {$indexHandle}.";
        }

        $summary = array_slice(array_map(static function(array $failure): string {
            $label = $failure['backendId'] ?? ($failure['elementId'] ?? 'unknown document');
            $title = isset($failure['title']) && $failure['title'] !== ''
                ? ' "' . $failure['title'] . '"'
                : '';

            return (string)$label . $title . ': ' . $failure['error'];
        }, $failures), 0, 5);

        $suffix = count($failures) > 5 ? ' +' . (count($failures) - 5) . ' more' : '';

        return "Batch index failed for {$indexHandle}: " . implode('; ', $summary) . $suffix;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}> $failures
     * @return list<int>
     */
    private function acceptedDocumentIndexes(array $items, array $failures): array
    {
        if ($failures === []) {
            return [];
        }

        $acceptedIndexes = [];
        foreach ($items as $index => $item) {
            $documentId = SearchHitIdentityHelper::documentId($item);
            $elementId = SearchHitIdentityHelper::elementId($item);
            $failed = false;
            foreach ($failures as $failure) {
                if ($failure['backendId'] !== null && $failure['backendId'] === $documentId) {
                    $failed = true;
                    break;
                }
                if (
                    $failure['backendId'] === null
                    && $failure['elementId'] !== null
                    && $failure['elementId'] === $elementId
                ) {
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $acceptedIndexes[] = $index;
            }
        }

        return $acceptedIndexes;
    }

    // =========================================================================
    // INDEX REBUILDING
    // =========================================================================

    /**
     * Rebuild a specific index
     *
     * @param string $indexHandle
     * @return bool
     */
    public function rebuildIndex(string $indexHandle): bool
    {
        return $this->rebuildIndexResult($indexHandle)['queued'];
    }

    /**
     * Queue a targeted rebuild through the canonical action capability.
     *
     * @return array{queued: bool, reasonCode: string|null, reason: string|null}
     * @since 5.54.0
     */
    public function rebuildIndexResult(string $indexHandle): array
    {
        return $this->queueSingleIndexRebuild(
            $indexHandle,
            DependencyService::ACTION_TARGETED_REBUILD,
            'Targeted index rebuild denied',
            'Queued index rebuild',
        );
    }

    /**
     * @return array{queued: bool, reasonCode: string|null, reason: string|null}
     */
    private function queueSingleIndexRebuild(
        string $indexHandle,
        string $capabilityAction,
        string $deniedLogMessage,
        string $queuedLogMessage,
    ): array {
        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $indexHandle,
            $capabilityAction,
        );
        if (!$capability['allowed']) {
            $this->logWarning($deniedLogMessage, [
                'indexHandle' => $indexHandle,
                'reasonCode' => $capability['reasonCode'],
            ]);

            return [
                'queued' => false,
                'reasonCode' => $capability['reasonCode'],
                'reason' => $capability['reason'],
            ];
        }

        $jobId = $this->pushIndexRebuildJob(new RebuildIndexJob([
            'indexHandle' => $indexHandle,
            'capabilityAction' => $capabilityAction,
        ]));
        $queued = $jobId !== null;
        if ($queued) {
            $this->logInfo($queuedLogMessage, ['indexHandle' => $indexHandle]);
        }

        return [
            'queued' => $queued,
            'reasonCode' => $queued ? null : 'queue-rejected',
            'reason' => $queued ? null : Craft::t('search-manager', 'Failed to queue index rebuild'),
        ];
    }

    protected function pushIndexRebuildJob(RebuildIndexJob $job): string|int|null
    {
        return Craft::$app->getQueue()->push($job);
    }

    /**
     * Rebuild all indices
     *
     * @return bool
     */
    public function rebuildAll(): bool
    {
        return $this->rebuildAllResult()['queued'];
    }

    /**
     * Queue the shared rebuild-all participant plan.
     *
     * @return array<string, mixed>
     * @since 5.54.0
     */
    public function rebuildAllResult(): array
    {
        $plan = SearchManager::$plugin->dependencies->getRebuildAllPlan();
        if (!$plan['allowed']) {
            return array_merge($plan, ['queued' => false]);
        }

        $jobId = Craft::$app->getQueue()->push(new RebuildIndexJob([
            'indexHandles' => $plan['participants'],
            'structuralSkips' => $plan['skips'],
        ]));
        $queued = $jobId !== null;
        if ($queued) {
            $this->logInfo('Queued rebuild for all eligible indices', [
                'participants' => $plan['participants'],
                'structuralSkips' => array_column($plan['skips'], 'reasonCode', 'handle'),
            ]);
        }

        return array_merge($plan, [
            'queued' => $queued,
            'reasonCode' => $queued ? null : 'queue-rejected',
            'reason' => $queued ? null : Craft::t('search-manager', 'Failed to queue index rebuild'),
        ]);
    }

    /**
     * Queue a configuration-change rebuild only when automatic participation is allowed.
     *
     * @since 5.54.0
     */
    public function rebuildIndexAutomatically(string $indexHandle): bool
    {
        return $this->queueSingleIndexRebuild(
            $indexHandle,
            DependencyService::ACTION_AUTOMATIC_REBUILD,
            'Automatic index rebuild skipped',
            'Queued automatic index rebuild',
        )['queued'];
    }

    /**
     * Queue existing full rebuild jobs for an affected set, coalesced per handle.
     *
     * @param iterable<SearchIndex> $indices
     * @return list<string> Handles queued by this call.
     * @since 5.54.0
     */
    public function scheduleAffectedIndexRebuilds(iterable $indices, string $reason): array
    {
        $queued = [];
        foreach ($indices as $index) {
            if (!$this->isAffectedIndexAvailable($index->handle)) {
                continue;
            }

            try {
                $queuedNow = $this->withAffectedRebuildLock(
                    $index->handle,
                    function() use ($index): bool {
                        $state = $this->getAffectedRebuildState($index->handle);
                        if ($state !== null) {
                            $this->setAffectedRebuildState($index->handle, true);

                            return false;
                        }

                        $this->setAffectedRebuildState($index->handle, false);
                        try {
                            $this->pushAffectedSchedulerJob($index->handle);
                        } catch (\Throwable $e) {
                            $this->deleteAffectedRebuildState($index->handle);
                            throw $e;
                        }

                        return true;
                    },
                );
            } catch (\Throwable $e) {
                $this->logError('Unable to queue affected index rebuild', [
                    'indexHandle' => $index->handle,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$queuedNow) {
                $this->logDebug('Coalesced affected index rebuild event', [
                    'indexHandle' => $index->handle,
                    'reason' => $reason,
                ]);
                continue;
            }

            $queued[] = $index->handle;
            $this->logInfo('Queued affected index rebuild', [
                'indexHandle' => $index->handle,
                'reason' => $reason,
            ]);
        }

        return $queued;
    }

    /**
     * Complete an affected rebuild and either queue one coalesced follow-up or release ownership.
     *
     * @since 5.54.0
     */
    public function completeAffectedIndexRebuild(string $indexHandle): void
    {
        try {
            $queuedFollowUp = $this->withAffectedRebuildLock(
                $indexHandle,
                function() use ($indexHandle): bool {
                    $state = $this->getAffectedRebuildState($indexHandle);
                    if ($state === null) {
                        return false;
                    }

                    if (!$state['dirty']) {
                        $this->deleteAffectedRebuildState($indexHandle);

                        return false;
                    }

                    $this->setAffectedRebuildState($indexHandle, false);
                    try {
                        $this->pushAffectedSchedulerJob($indexHandle);
                    } catch (\Throwable $e) {
                        $this->deleteAffectedRebuildState($indexHandle);
                        throw $e;
                    }

                    return true;
                },
            );
        } catch (\Throwable $e) {
            $this->logError('Unable to advance affected index rebuild scheduling', [
                'indexHandle' => $indexHandle,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($queuedFollowUp) {
            $this->logInfo('Queued coalesced affected index rebuild follow-up', [
                'indexHandle' => $indexHandle,
            ]);
        } else {
            $this->logDebug('Released affected index rebuild scheduling', [
                'indexHandle' => $indexHandle,
            ]);
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get all index handles that contain an element
     */
    private function getIndexHandlesForElement(ElementInterface $element): array
    {
        $indices = $this->getAllIndices();
        $elementClass = get_class($element);
        $handles = [];

        $this->logDebug('Finding indices for element', [
            'elementId' => $element->id,
            'elementType' => $elementClass,
            'elementSiteId' => $element->siteId,
            'totalIndices' => count($indices),
        ]);

        foreach ($indices as $index) {
            if (!$index->enabled) {
                $this->logDebug('Index skipped (disabled)', [
                    'indexHandle' => $index->handle,
                ]);
                continue;
            }
            if (!SearchManager::$plugin->dependencies->isIndexAvailable($index->handle)) {
                $this->logDebug('Index skipped (dependency unavailable)', [
                    'indexHandle' => $index->handle,
                ]);
                continue;
            }

            // Check element type match
            if ($index->elementType !== $elementClass) {
                $this->logDebug('Index skipped (element type mismatch)', [
                    'indexHandle' => $index->handle,
                    'indexElementType' => $index->elementType,
                    'elementType' => $elementClass,
                ]);
                continue;
            }

            // Check site match (if specified)
            // For all-sites indices (siteId = null), this check passes
            // Use explicit int casting to ensure type-safe comparison
            if (!$index->appliesToSiteId((int)$element->siteId)) {
                $this->logDebug('Index skipped (site mismatch)', [
                    'indexHandle' => $index->handle,
                    'indexSiteId' => $index->siteId,
                    'elementSiteId' => $element->siteId,
                ]);
                continue;
            }

            // Check criteria through the singular SearchIndex gate. The L3
            // buffer evaluates the same criteria in batches through
            // SearchIndex::matchesCriteriaBatch().
            if (!$index->matchesCriteria($element)) {
                $this->logDebug('Index skipped (criteria mismatch)', [
                    'indexHandle' => $index->handle,
                    'criteria' => is_array($index->criteria) ? $index->criteria : 'Closure',
                ]);
                continue;
            }

            $this->logDebug('Index matched', [
                'indexHandle' => $index->handle,
                'indexSiteId' => $index->siteId,
                'isAllSites' => $index->siteId === null,
            ]);

            $handles[] = $index->handle;
        }

        $this->logDebug('Indices matched for element', [
            'elementId' => $element->id,
            'matchedCount' => count($handles),
            'handles' => $handles,
        ]);

        return $handles;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function documentsForIndex(?SearchIndex $index, ElementInterface $element, array $data): array
    {
        return SplitSectionDocumentHelper::documentsForIndex($index, $element, $data);
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return list<string>
     */
    private function backendIdsFromDocuments(array $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            $documentId = SearchHitIdentityHelper::documentId($document);
            if ($documentId !== null) {
                $ids[] = $documentId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Get all indices (database + config)
     */
    private function getAllIndices(): array
    {
        return SearchIndex::findAll();
    }

    private function scheduledRebuildCacheKey(string $indexHandle): string
    {
        return PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'affected-index-rebuild')
            . $indexHandle;
    }

    private function scheduledRebuildMutexName(string $indexHandle): string
    {
        return PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'affected-index-rebuild-mutex')
            . $indexHandle;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function withAffectedRebuildLock(string $indexHandle, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        $lockName = $this->scheduledRebuildMutexName($indexHandle);
        if (!$mutex->acquire($lockName, 30)) {
            throw new \RuntimeException("Unable to acquire affected rebuild scheduler lock for '{$indexHandle}'.");
        }

        try {
            return $callback();
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * @return array{active: true, dirty: bool}|null
     */
    protected function getAffectedRebuildState(string $indexHandle): ?array
    {
        $state = Craft::$app->getCache()->get($this->scheduledRebuildCacheKey($indexHandle));
        if ($state === true) {
            return ['active' => true, 'dirty' => false];
        }
        if (!is_array($state) || ($state['active'] ?? null) !== true || !is_bool($state['dirty'] ?? null)) {
            return null;
        }

        return [
            'active' => true,
            'dirty' => $state['dirty'],
        ];
    }

    protected function setAffectedRebuildState(string $indexHandle, bool $dirty): void
    {
        $stored = Craft::$app->getCache()->set(
            $this->scheduledRebuildCacheKey($indexHandle),
            ['active' => true, 'dirty' => $dirty],
            0,
        );
        if (!$stored) {
            throw new \RuntimeException("Unable to persist affected rebuild scheduler state for '{$indexHandle}'.");
        }
    }

    protected function deleteAffectedRebuildState(string $indexHandle): void
    {
        $cache = Craft::$app->getCache();
        $cacheKey = $this->scheduledRebuildCacheKey($indexHandle);
        $released = $cache->set($cacheKey, ['active' => false, 'dirty' => false], 60);
        if (!$released) {
            throw new \RuntimeException("Unable to release affected rebuild scheduler state for '{$indexHandle}'.");
        }

        $cache->delete($cacheKey);
    }

    private function pushAffectedSchedulerJob(string $indexHandle): void
    {
        $jobId = $this->pushAffectedRebuildJob(new RebuildIndexJob([
            'indexHandle' => $indexHandle,
            'capabilityAction' => DependencyService::ACTION_AUTOMATIC_REBUILD,
            'releaseAffectedSchedule' => true,
        ]));
        if ($jobId === null) {
            throw new \RuntimeException('Queue did not accept the rebuild job.');
        }
    }

    protected function pushAffectedRebuildJob(RebuildIndexJob $job): string|int|null
    {
        return Craft::$app->getQueue()->push($job);
    }

    protected function isAffectedIndexAvailable(string $indexHandle): bool
    {
        return SearchManager::$plugin->dependencies->getIndexActionCapability(
            $indexHandle,
            DependencyService::ACTION_AUTOMATIC_REBUILD,
        )['allowed'];
    }
}
