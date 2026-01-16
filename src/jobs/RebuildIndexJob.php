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
        // Increase memory limit for rebuild jobs (handles large relational data)
        $currentLimit = ini_get('memory_limit');
        if ($currentLimit !== '-1') {
            ini_set('memory_limit', '1G');
        }

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

        // Sync config indices metadata before rebuilding
        if ($index->source === 'config') {
            $this->logInfo('Config index detected - syncing metadata', [
                'handle' => $indexHandle,
                'hasId' => $index->id ? 'YES' : 'NO',
                'name' => $index->name,
                'transformer' => $index->transformerClass,
            ]);
            $synced = $index->syncMetadataFromConfig();
            $this->logInfo('Sync result: ' . ($synced ? 'SUCCESS' : 'FAILED'));
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

            // Apply criteria
            if (!empty($index->criteria)) {
                // Config indices: criteria is a Closure to apply to query
                if ($index->criteria instanceof \Closure) {
                    $criteriaCallback = $index->criteria;
                    $siteQuery = $criteriaCallback($siteQuery);
                }
                // Database indices: criteria is an array with section/volume/group filters
                elseif (is_array($index->criteria)) {
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

                    // Free memory after each batch to prevent exhaustion
                    unset($elements);
                    gc_collect_cycles();
                }
            }

            // Free memory after processing all batches for this site
            unset($elementIds, $batches);
            gc_collect_cycles();
        }

        // Update index stats
        $index->updateStats($totalIndexed);

        // Clear caches for this index
        SearchManager::$plugin->backend->clearSearchCache($indexHandle);
        SearchManager::$plugin->autocomplete->clearCache($indexHandle);

        $this->logInfo('Index rebuild completed', [
            'handle' => $indexHandle,
            'count' => $totalIndexed,
        ]);

        // Queue cache warming job if enabled
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->enableCacheWarming && ($settings->enableCache || $settings->enableAutocompleteCache)) {
            Craft::$app->getQueue()->push(new CacheWarmJob([
                'indexHandle' => $indexHandle,
            ]));

            $this->logInfo('Queued cache warming job', [
                'handle' => $indexHandle,
            ]);
        }
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
