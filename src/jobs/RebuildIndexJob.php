<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Rebuild Index Job
 *
 * Queue job for rebuilding an entire search index
 */
class RebuildIndexJob extends BaseJob
{
    use LoggingTrait;

    public ?string $indexHandle = null;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        if ($this->indexHandle) {
            $this->rebuildSingleIndex($queue, $this->indexHandle);
        } else {
            $this->rebuildAllIndices($queue);
        }
    }

    private function rebuildSingleIndex($queue, string $indexHandle): void
    {
        $index = SearchIndex::findByHandle($indexHandle);

        if (!$index) {
            $this->logError('Index not found', ['handle' => $indexHandle]);
            return;
        }

        $this->logInfo('Rebuilding index', ['handle' => $indexHandle]);

        // Clear existing index
        SearchManager::$plugin->backend->clearIndex($indexHandle);

        // Get element type
        /** @var string $elementType */
        $elementType = $index->elementType;

        // For "All Sites" indices, we need to index each site separately
        $sitesToIndex = [];
        if ($index->siteId) {
            $sitesToIndex[] = $index->siteId;
        } else {
            // Get all site IDs
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $sitesToIndex[] = $site->id;
            }
        }

        $totalIndexed = 0;
        $batchSize = SearchManager::$plugin->getSettings()->batchSize;

        foreach ($sitesToIndex as $siteIndex => $siteId) {
            // Query elements for this specific site (exclude drafts and revisions)
            $siteQuery = $elementType::find()
                ->siteId($siteId)
                ->drafts(false)
                ->revisions(false);

            // Re-apply criteria
            if (!empty($index->criteria)) {
                if ($elementType === \craft\elements\Entry::class && !empty($index->criteria['sections'])) {
                    $siteQuery->section($index->criteria['sections']);
                }
                if ($elementType === \craft\elements\Asset::class && !empty($index->criteria['volumes'])) {
                    $siteQuery->volume($index->criteria['volumes']);
                }
                if ($elementType === \craft\elements\Category::class && !empty($index->criteria['groups'])) {
                    $siteQuery->group($index->criteria['groups']);
                }
            }

            $elementIds = $siteQuery->ids();

            $this->logInfo('Found elements to index for site', [
                'siteId' => $siteId,
                'count' => count($elementIds),
            ]);

            // Process in batches
            $batches = array_chunk($elementIds, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                // Calculate overall progress
                $siteProgress = $siteIndex / count($sitesToIndex);
                $batchProgress = ($batchIndex / count($batches)) / count($sitesToIndex);
                $this->setProgress($queue, $siteProgress + $batchProgress);

                $elements = [];
                foreach ($batch as $elementId) {
                    $element = Craft::$app->elements->getElementById(
                        $elementId,
                        $elementType,
                        $siteId
                    );

                    // Only index if element exists and is enabled for this site
                    if ($element && $element->enabled && $element->getEnabledForSite()) {
                        // For entries, also check if live (not pending/expired)
                        if ($element instanceof \craft\elements\Entry) {
                            if ($element->getStatus() === \craft\elements\Entry::STATUS_LIVE) {
                                $elements[] = $element;
                            }
                        } else {
                            $elements[] = $element;
                        }
                    }
                }

                if (!empty($elements)) {
                    SearchManager::$plugin->indexing->batchIndex($elements, $indexHandle);
                    $totalIndexed += count($elements);
                }
            }
        }

        // Update index stats
        $index->updateStats($totalIndexed);

        // Clear search cache for this index
        SearchManager::$plugin->backend->clearSearchCache($indexHandle);

        $this->logInfo('Index rebuild completed', [
            'handle' => $indexHandle,
            'count' => $totalIndexed,
        ]);
    }

    private function rebuildAllIndices($queue): void
    {
        $indices = SearchIndex::findAll();

        foreach ($indices as $i => $index) {
            if (!$index->enabled) {
                continue;
            }

            $this->setProgress($queue, $i / count($indices));
            $this->rebuildSingleIndex($queue, $index->handle);
        }
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();

        if ($this->indexHandle) {
            return Craft::t('search-manager', '{pluginName}: Rebuilding index {handle}', [
                'pluginName' => $settings->getDisplayName(),
                'handle' => $this->indexHandle,
            ]);
        }

        return Craft::t('search-manager', '{pluginName}: Rebuilding all indices', [
            'pluginName' => $settings->getDisplayName(),
        ]);
    }
}
