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
     */
    public function indexElementNow(ElementInterface $element): bool
    {
        $this->logDebug('Indexing element', [
            'elementId' => $element->id,
            'elementType' => get_class($element),
        ]);

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

        // Get transformer (use first index for transformer config)
        $firstIndex = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandles[0]);
        $transformer = SearchManager::$plugin->transformers->getTransformer(
            $element,
            $firstIndex?->transformerClass
        );

        if (!$transformer) {
            $this->logWarning('No transformer found for element', [
                'elementId' => $element->id,
                'elementType' => get_class($element),
            ]);
            return false;
        }

        // Transform element
        try {
            $data = $transformer->transform($element);
        } catch (\Throwable $e) {
            $this->logError('Failed to transform element', [
                'elementId' => $element->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        // Index to all matching indices
        $success = true;
        foreach ($indexHandles as $indexHandle) {
            try {
                $result = SearchManager::$plugin->backend->index($indexHandle, $data);

                if ($result) {
                    // Clear search cache for this index
                    SearchManager::$plugin->backend->clearSearchCache($indexHandle);

                    // Trigger after event
                    $this->trigger(self::EVENT_AFTER_INDEX, new IndexEvent([
                        'element' => $element,
                        'data' => $data,
                        'indexHandle' => $indexHandle,
                    ]));

                    $this->logInfo('Element indexed successfully', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                } else {
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
     * Remove an element from all indices
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
                $result = SearchManager::$plugin->backend->delete($indexHandle, $element->id, $element->siteId);

                if ($result) {
                    // Clear search cache for this index
                    SearchManager::$plugin->backend->clearSearchCache($indexHandle);

                    $this->logInfo('Element removed from index', [
                        'elementId' => $element->id,
                        'indexHandle' => $indexHandle,
                    ]);
                } else {
                    $success = false;
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to remove element from index', [
                    'elementId' => $element->id,
                    'indexHandle' => $indexHandle,
                    'error' => $e->getMessage(),
                ]);
                $success = false;
            }
        }

        return $success;
    }

    // =========================================================================
    // BATCH INDEXING
    // =========================================================================

    /**
     * Index multiple elements in batch
     */
    public function batchIndex(array $elements, string $indexHandle): bool
    {
        $items = [];

        foreach ($elements as $element) {
            $transformer = SearchManager::$plugin->transformers->getTransformer($element);
            if (!$transformer) {
                continue;
            }

            try {
                $data = $transformer->transform($element);
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
                // Clear search cache for this index
                SearchManager::$plugin->backend->clearSearchCache($indexHandle);
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

        foreach ($indices as $index) {
            if (!$index->enabled) {
                continue;
            }

            // Check element type match
            if ($index->elementType !== $elementClass) {
                continue;
            }

            // Check site match (if specified)
            if ($index->siteId && $index->siteId !== $element->siteId) {
                continue;
            }

            // Check criteria match
            if (!$this->elementMatchesCriteria($element, $index)) {
                continue;
            }

            $handles[] = $index->handle;
        }

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

        return true;
    }

    /**
     * Get all indices (database + config)
     */
    private function getAllIndices(): array
    {
        // This would normally merge database and config indices
        // For now, just get database indices
        return SearchIndex::findAll();
    }
}
