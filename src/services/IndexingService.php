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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SplitSectionDocumentHelper;
use lindemannrock\searchmanager\jobs\IndexElementJob;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
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
     * @param bool|null $queue
     * @return bool
     */
    public function indexElement(ElementInterface $element, ?bool $queue = null): bool
    {
        // Use queue setting if not specified
        if ($queue === null) {
            $queue = SearchManager::$plugin->getSettings()->queueEnabled;
        }

        // Queue the indexing operation
        if ($queue) {
            Craft::$app->getQueue()->push(new IndexElementJob([
                'elementId' => $element->id,
                'elementType' => get_class($element),
                'siteId' => $element->siteId,
            ]));

            $this->logDebug('Queued element for indexing', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
            ]);

            return true;
        }

        // Index immediately
        return $this->indexElementNow($element);
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

        // Index to all matching indices (no-op when $indexHandles is empty)
        $success = true;
        foreach ($indexHandles as $indexHandle) {
            try {
                // Check if index should skip entries without URL
                $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
                if ($index && $index->shouldSkipElementWithoutUrl($element)) {
                    $this->logDebug('Skipping element without URL for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                    continue;
                }

                // Transform element via TransformerService (fires before/after events)
                $data = SearchManager::$plugin->transformers->transform(
                    $element,
                    $indexHandle,
                    $index?->transformerClass,
                    $index?->headingLevels,
                );

                if ($data === null) {
                    $this->logDebug('Transform returned null for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
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
                if ($index?->usesSplitSections()) {
                    $result = SearchManager::$plugin->backend->batchIndex($indexHandle, $documents)
                        && SearchManager::$plugin->backend->deleteOrphanDocuments(
                            $indexHandle,
                            (int)$element->id,
                            (int)$element->siteId,
                            $this->backendIdsFromDocuments($documents),
                        );
                    $isNewDocument = false;
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

                    // Increment document count only for new documents
                    if ($isNewDocument) {
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
        $matchedHandles = array_flip($indexHandles);
        $elementClass = get_class($element);
        $siteId = (int) $element->siteId;

        foreach ($this->getAllIndices() as $index) {
            if (!$index->enabled) {
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
                    if ($deleteResult['existed'] === true) {
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
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to clean up stale document', [
                    'elementId' => $element->id,
                    'siteId' => $siteId,
                    'indexHandle' => $index->handle,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $success;
    }

    /**
     * Remove an element from all matching indices
     *
     * @param ElementInterface $element
     * @return bool
     */
    public function removeElement(ElementInterface $element): bool
    {
        // Iterate all indices matching elementType + site WITHOUT the criteria
        // filter — criteria is irrelevant for removal, and filtering by it would
        // skip deletions when the element's fields changed such that it no
        // longer qualifies (e.g. status fields, custom filters).
        $elementClass = get_class($element);
        $siteId = (int) $element->siteId;
        $success = true;

        foreach ($this->getAllIndices() as $index) {
            if (!$index->enabled) {
                continue;
            }
            if ($index->elementType !== $elementClass) {
                continue;
            }
            if (!$index->appliesToSiteId($siteId)) {
                continue;
            }

            try {
                $deleteResult = $index->usesSplitSections()
                    ? [
                        'success' => SearchManager::$plugin->backend->deleteOrphanDocuments($index->handle, (int)$element->id, $siteId, []),
                        'existed' => null,
                    ]
                    : SearchManager::$plugin->backend->deleteWithResult($index->handle, $element->id, $siteId);

                if (!$deleteResult['success']) {
                    $success = false;
                    continue;
                }

                if ($deleteResult['existed'] !== true) {
                    $this->logDebug('Document not in index, skipping removal', [
                        'elementId' => $element->id,
                        'siteId' => $siteId,
                        'indexHandle' => $index->handle,
                    ]);
                    continue;
                }

                if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                    SearchManager::$plugin->backend->clearSearchCache($index->handle);
                    SearchManager::$plugin->autocomplete->clearCache($index->handle);
                }

                SearchIndex::decrementDocumentCount($index->handle);
                SearchIndex::touchLastIndexedDebounced($index->handle);

                $this->logInfo('Element removed from index', [
                    'elementId' => $element->id,
                    'siteId' => $siteId,
                    'indexHandle' => $index->handle,
                ]);
            } catch (\Throwable $e) {
                $this->logError('Failed to remove element from index', [
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
     */
    public function batchIndex(array $elements, string $indexHandle): bool
    {
        $items = [];

        // Get index config for transformer class and heading levels
        $index = SearchIndex::findByHandle($indexHandle);

        SearchManager::$plugin->transformers->withTransformerReuse(function() use ($elements, $indexHandle, $index, &$items): void {
            foreach ($elements as $element) {
                // Transform via TransformerService (fires before/after events)
                $data = SearchManager::$plugin->transformers->transform(
                    $element,
                    $indexHandle,
                    $index?->transformerClass,
                    $index?->headingLevels,
                );

                if ($data === null) {
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

                foreach ($this->documentsForIndex($index, $element, $data) as $document) {
                    $items[] = $document;
                }
            }
        });

        if (empty($items)) {
            return true;
        }

        try {
            $result = SearchManager::$plugin->backend->batchIndex($indexHandle, $items);

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

            return $result;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index elements', [
                'count' => count($items),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
        Craft::$app->getQueue()->push(new RebuildIndexJob([
            'indexHandle' => $indexHandle,
        ]));

        $this->logInfo('Queued index rebuild', ['indexHandle' => $indexHandle]);

        return true;
    }

    /**
     * Rebuild all indices
     *
     * @return bool
     */
    public function rebuildAll(): bool
    {
        $indices = $this->getAllIndices();

        foreach ($indices as $index) {
            if ($index->enabled) {
                $this->rebuildIndex($index->handle);
            }
        }

        $this->logInfo('Queued rebuild for all indices', ['count' => count($indices)]);

        return true;
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

            // Check criteria match — delegated to the canonical implementation
            // on SearchIndex so the direct-sync (this path) and the L3 buffer
            // path (PendingSyncProcessor) can never disagree about whether an
            // element belongs in an index.
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
}
