<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Sync Element Job
 *
 * Queue job for syncing a single element's index state for a specific site.
 * Checks if element should be indexed or removed based on current DB state.
 */
class SyncElementJob extends BaseJob
{
    use LoggingTrait;

    public int $elementId;
    public string $elementType;
    public int $siteId;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        // Get element fresh from DB for this specific site
        $element = Craft::$app->getElements()->getElementById(
            $this->elementId,
            $this->elementType,
            $this->siteId
        );

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
            SearchManager::$plugin->indexing->indexElementNow($element);
        } else {
            SearchManager::$plugin->indexing->removeElement($element);
        }
    }

    /**
     * Remove element from all indices for this site (when element not found)
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
            if ($index->siteId && $index->siteId !== $this->siteId) {
                continue;
            }

            // Check if document exists and remove
            $exists = SearchManager::$plugin->backend->documentExists(
                $index->handle,
                $this->elementId,
                $this->siteId
            );

            if ($exists) {
                SearchManager::$plugin->backend->delete($index->handle, $this->elementId, $this->siteId);
                SearchIndex::decrementDocumentCount($index->handle);
                $this->logInfo('Removed deleted element from index', [
                    'elementId' => $this->elementId,
                    'indexHandle' => $index->handle,
                ]);
            }
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
