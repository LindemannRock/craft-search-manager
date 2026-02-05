<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\ElementInterface;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\jobs\IndexElementJob;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
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

    // Event constants
    public const EVENT_BEFORE_INDEX = 'beforeIndex';
    public const EVENT_AFTER_INDEX = 'afterIndex';

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

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
     * @since 5.0.0
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
     * @since 5.0.0
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

        // Get all index handles for this element
        $indexHandles = $this->getIndexHandlesForElement($element);

        if (empty($indexHandles)) {
            $this->logDebug('No index configured for element', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
            ]);
            return true; // Not an error, just not indexed
        }

        // Index to all matching indices
        $success = true;
        foreach ($indexHandles as $indexHandle) {
            try {
                // Check if index should skip entries without URL
                $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
                if ($index && $index->skipEntriesWithoutUrl && $element->url === null) {
                    $this->logDebug('Skipping element without URL for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                    continue;
                }

                // Get transformer for this index
                $transformer = SearchManager::$plugin->transformers->getTransformer(
                    $element,
                    $index?->transformerClass
                );

                if (!$transformer) {
                    $this->logWarning('No transformer found for index', [
                        'elementId' => $element->id,
                        'elementType' => get_class($element),
                        'indexHandle' => $indexHandle,
                    ]);
                    $success = false;
                    continue;
                }

                // Transform element for this index
                try {
                    $data = $transformer->transform($element);
                } catch (\Throwable $e) {
                    $this->logError('Failed to transform element for index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                        'error' => $e->getMessage(),
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

                // Check if document already exists (for accurate count tracking)
                $isNewDocument = !SearchManager::$plugin->backend->documentExists(
                    $indexHandle,
                    $element->id,
                    $element->siteId
                );

                $result = SearchManager::$plugin->backend->index($indexHandle, $data);

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

                    // Trigger after event
                    $this->trigger(self::EVENT_AFTER_INDEX, new IndexEvent([
                        'element' => $element,
                        'data' => $data,
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

        return $success;
    }

    /**
     * Remove an element from all matching indices
     *
     * @param ElementInterface $element
     * @return bool
     * @since 5.0.0
     */
    public function removeElement(ElementInterface $element): bool
    {
        $indexHandles = $this->getIndexHandlesForElement($element);

        if (empty($indexHandles)) {
            return true; // Not indexed, nothing to remove
        }

        $success = true;

        foreach ($indexHandles as $indexHandle) {
            try {
                // Check if document actually exists in this index before removing
                $documentExists = SearchManager::$plugin->backend->documentExists(
                    $indexHandle,
                    $element->id,
                    $element->siteId
                );

                if (!$documentExists) {
                    $this->logDebug('Document not in index, skipping removal', [
                        'elementId' => $element->id,
                        'siteId' => $element->siteId,
                        'indexHandle' => $indexHandle,
                    ]);
                    continue;
                }

                $result = SearchManager::$plugin->backend->delete($indexHandle, $element->id, $element->siteId);

                if ($result) {
                    // Clear caches for this index (if enabled)
                    if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                        SearchManager::$plugin->backend->clearSearchCache($indexHandle);
                        SearchManager::$plugin->autocomplete->clearCache($indexHandle);
                    }

                    // Decrement document count (only if document actually existed)
                    SearchIndex::decrementDocumentCount($indexHandle);

                    $this->logInfo('Element removed from index', [
                        'elementId' => $element->id,
                        'siteId' => $element->siteId,
                        'indexHandle' => $indexHandle,
                    ]);
                } else {
                    $success = false;
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to remove element from index', [
                    'elementId' => $element->id,
                    'siteId' => $element->siteId,
                    'indexHandle' => $indexHandle,
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
     * @since 5.0.0
     */
    public function shouldIndexElementForSite(ElementInterface $element): bool
    {
        // Skip drafts and revisions
        if ($element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        // Must be enabled globally AND for this site
        if (!$element->enabled || !$element->getEnabledForSite()) {
            return false;
        }

        // Check status based on element type
        $status = $element->getStatus();

        // Entries: must be live (not disabled, pending, or expired)
        if ($element instanceof \craft\elements\Entry) {
            return $status === \craft\elements\Entry::STATUS_LIVE;
        }

        // Assets: must be enabled
        if ($element instanceof \craft\elements\Asset) {
            return $status === \craft\base\Element::STATUS_ENABLED;
        }

        // Categories: must be enabled
        if ($element instanceof \craft\elements\Category) {
            return $status === \craft\base\Element::STATUS_ENABLED;
        }

        // Users: must be active
        if ($element instanceof \craft\elements\User) {
            return $status === \craft\elements\User::STATUS_ACTIVE;
        }

        // Default: check if enabled status
        return $status === \craft\base\Element::STATUS_ENABLED;
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
     * @since 5.0.0
     */
    public function batchIndex(array $elements, string $indexHandle): bool
    {
        $items = [];

        // Get transformer from index config (same as indexElementNow does)
        $index = SearchIndex::findByHandle($indexHandle);
        $transformerClass = $index?->transformerClass;

        foreach ($elements as $element) {
            $transformer = SearchManager::$plugin->transformers->getTransformer($element, $transformerClass);
            if (!$transformer) {
                continue;
            }

            try {
                $data = $transformer->transform($element);

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

                $items[] = $data;
            } catch (\Throwable $e) {
                $this->logError('Failed to transform element in batch', [
                    'elementId' => $element->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

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

    // =========================================================================
    // INDEX REBUILDING
    // =========================================================================

    /**
     * Rebuild a specific index
     *
     * @param string $indexHandle
     * @return bool
     * @since 5.0.0
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
     * @since 5.0.0
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

            // Check criteria match
            if (!$this->elementMatchesCriteria($element, $index)) {
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
     * Check if an element matches an index's criteria
     */
    private function elementMatchesCriteria(ElementInterface $element, $index): bool
    {
        if (empty($index->criteria)) {
            return true; // No criteria = matches all
        }

        // Handle Closure criteria (config indices)
        if ($index->criteria instanceof \Closure) {
            return $this->elementMatchesClosureCriteria($element, $index);
        }

        // Handle array criteria (database indices)
        if (is_array($index->criteria)) {
            // Check sections filter for entries
            if ($element instanceof \craft\elements\Entry && !empty($index->criteria['sections'])) {
                $sectionHandle = $element->section->handle ?? null;
                if ($sectionHandle && !in_array($sectionHandle, $index->criteria['sections'])) {
                    return false;
                }
            }

            // Check volume filter for assets
            if ($element instanceof \craft\elements\Asset && !empty($index->criteria['volumes'])) {
                $volumeHandle = $element->volume->handle ?? null;
                if ($volumeHandle && !in_array($volumeHandle, $index->criteria['volumes'])) {
                    return false;
                }
            }

            // Check group filter for categories
            if ($element instanceof \craft\elements\Category && !empty($index->criteria['groups'])) {
                $groupHandle = $element->group->handle ?? null;
                if ($groupHandle && !in_array($groupHandle, $index->criteria['groups'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if an element matches a Closure-based criteria (config indices)
     *
     * This executes the Closure to build the query, then checks if the
     * specific element ID would be included in the results.
     *
     * Note: This only checks structural criteria (section, type, etc.)
     * Status checks are handled separately by shouldIndexElement()
     */
    private function elementMatchesClosureCriteria(ElementInterface $element, $index): bool
    {
        try {
            // Create a fresh query for the element type
            $elementType = $index->elementType;
            $query = $elementType::find();

            // Set site context - MUST match element's site for proper per-site checks
            if ($index->siteId !== null) {
                $query->siteId($element->siteId);
            }

            // Apply the Closure criteria (section, type filters, etc.)
            $closure = $index->criteria;
            $query = $closure($query);

            // Check if this specific element matches the structural criteria
            // Bypass ALL status/enabled filters - we only care about structure here
            // Status checks are done separately in shouldIndexElementForSite()
            return $query
                ->id($element->id)
                ->siteId($element->siteId) // Ensure we check the correct site version
                ->status(null) // Include all statuses
                ->exists();
        } catch (\Throwable $e) {
            $this->logError('Failed to evaluate Closure criteria', [
                'elementId' => $element->id,
                'indexHandle' => $index->handle,
                'error' => $e->getMessage(),
            ]);

            // On error, assume it doesn't match to avoid indexing incorrectly
            return false;
        }
    }

    /**
     * Get all indices (database + config)
     */
    private function getAllIndices(): array
    {
        return SearchIndex::findAll();
    }
}
