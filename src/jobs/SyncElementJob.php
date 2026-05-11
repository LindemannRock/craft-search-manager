<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use yii\queue\RetryableJobInterface;

/**
 * Sync Element Job
 *
 * Queue job for syncing a single element's index state for a specific site.
 * Checks if element should be indexed or removed based on current DB state.
 *
 * @deprecated 5.45.0 — Retained for one release as a transitional fallback so
 *   legacy queued jobs from pre-L3 installs can still deserialize and run.
 *   New code MUST NOT push this job. The save/delete auto-sync path now uses
 *   `SearchManager::$plugin->pendingSyncs->queueForElement()` (see
 *   `services/sync/PendingSyncRepository`) which collapses repeated work and
 *   processes via `BatchSyncJob`. Scheduled for deletion in 5.46.0.
 *
 * @since 5.21.1
 */
class SyncElementJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;
    use ElementTypeGuardTrait;

    public int $elementId;
    public string $elementType;
    public int $siteId;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /** @inheritdoc */
    public function execute($queue): void
    {
        if (!$this->isElementTypeAvailable($this->elementType, 'sync-element')) {
            $this->removeFromIndices();
            return;
        }

        // Get element fresh from DB for this specific site
        // Use status(null) to include disabled/expired elements
        /** @var \craft\elements\db\ElementQuery $query */
        $query = $this->elementType::find();
        $element = $query
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        if (!$element) {
            $this->logDebug('Element not found, may have been deleted', [
                'elementId' => $this->elementId,
                'siteId' => $this->siteId,
            ]);
            // Element doesn't exist for this site - ensure it's removed from indices
            $this->removeFromIndices();
            return;
        }

        // Check if element should be indexed for this site
        $shouldIndex = SearchManager::$plugin->indexing->shouldIndexElementForSite($element);

        $this->logInfo('Sync element state', [
            'elementId' => $this->elementId,
            'siteId' => $this->siteId,
            'enabled' => $element->enabled,
            'enabledForSite' => $element->getEnabledForSite(),
            'status' => $element->getStatus(),
            'shouldIndex' => $shouldIndex,
        ]);

        if ($shouldIndex) {
            $this->logDebug('Calling indexElementNow', [
                'elementId' => $this->elementId,
                'siteId' => $this->siteId,
            ]);
            $result = SearchManager::$plugin->indexing->indexElementNow($element);
            $this->logDebug('indexElementNow completed', [
                'elementId' => $this->elementId,
                'siteId' => $this->siteId,
                'result' => $result,
            ]);
        } else {
            $this->logDebug('Calling removeElement', [
                'elementId' => $this->elementId,
                'siteId' => $this->siteId,
            ]);
            SearchManager::$plugin->indexing->removeElement($element);
        }
    }

    /**
     * Remove element from all indices for this site (when element not found).
     *
     * Behaviour mirrors the L3 batch sync path: no documentExists pre-check,
     * no documentCount writes. Backends must treat deleting a missing document
     * as success. documentCount on indices is eventually consistent — accurate
     * values come from full rebuild or explicit count refresh, not from
     * automatic sync paths.
     */
    private function removeFromIndices(): void
    {
        $indices = SearchIndex::findAll();

        foreach ($indices as $index) {
            if (!$index->enabled) {
                continue;
            }
            if ($index->elementType !== $this->elementType) {
                continue;
            }
            if (!$index->appliesToSiteId($this->siteId)) {
                continue;
            }

            $deleted = SearchManager::$plugin->backend->delete($index->handle, $this->elementId, $this->siteId);
            if (!$deleted) {
                continue;
            }

            SearchIndex::touchLastIndexedDebounced($index->handle);
            if (SearchManager::$plugin->getSettings()->clearCacheOnSave) {
                SearchManager::$plugin->backend->clearSearchCache($index->handle);
                SearchManager::$plugin->autocomplete->clearCache($index->handle);
            }
            $this->logInfo('Removed deleted element from index', [
                'elementId' => $this->elementId,
                'indexHandle' => $index->handle,
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Sync element {id} for site {siteId}', [
            'pluginName' => $settings->getDisplayName(),
            'id' => $this->elementId,
            'siteId' => $this->siteId,
        ]);
    }
}
