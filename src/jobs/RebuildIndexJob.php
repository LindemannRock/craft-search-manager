<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchIndexCriteriaHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\traits\ElementTypeGuardTrait;
use yii\queue\RetryableJobInterface;

/**
 * Rebuild Index Job
 *
 * Queue job for rebuilding an entire search index
 *
 * @since 5.0.0
 */
class RebuildIndexJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;
    use ElementTypeGuardTrait;

    public ?string $indexHandle = null;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false; // Don't auto-retry — rebuilds should be triggered manually
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
        if ($this->indexHandle) {
            $this->rebuildSingleIndex($queue, $this->indexHandle);
        } else {
            $this->rebuildAllIndices($queue);
        }
    }

    private function rebuildSingleIndex(
        $queue,
        string $indexHandle,
        ?SearchIndex $preloadedIndex = null,
        float $progressStart = 0.0,
        float $progressEnd = 1.0,
    ): void {
        $index = $preloadedIndex ?? SearchIndex::findByHandle($indexHandle);

        if (!$index) {
            $this->logError('Index not found', ['handle' => $indexHandle]);
            $this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);
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
        if (!$this->isElementTypeAvailable($elementType, 'rebuild-index')) {
            $this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);
            return;
        }

        // For "All Sites" indices, we need to index each site separately
        $sitesToIndex = $index->getSiteIds();
        if ($sitesToIndex === null) {
            // Get all site IDs
            $sitesToIndex = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $sitesToIndex[] = $site->id;
            }
        }

        if (empty($sitesToIndex)) {
            $this->logWarning('No sites to index for index', ['handle' => $index->handle]);
            $this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);
            return;
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
                $siteQuery = SearchIndexCriteriaHelper::apply($siteQuery, $elementType, $index->criteria);
            }

            $elementIds = $siteQuery->ids();

            $this->logInfo('Found elements to index for site', [
                'siteId' => $siteId,
                'count' => count($elementIds),
            ]);

            // Process in batches
            $batches = array_chunk($elementIds, $batchSize);

            $batchCount = count($batches);
            foreach ($batches as $batchIndex => $batch) {
                $elements = [];
                $batchElements = $elementType::find()
                    ->id($batch)
                    ->siteId($siteId)
                    ->status(null)
                    ->all();

                foreach ($batchElements as $element) {
                    // Only index if element exists and is enabled for this site
                    if ($element->enabled && $element->getEnabledForSite()) {
                        // Skip entries without URL if index is configured to do so
                        if ($index->shouldSkipElementWithoutUrl($element)) {
                            continue;
                        }

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
                    if (!SearchManager::$plugin->indexing->batchIndex($elements, $indexHandle)) {
                        throw new \RuntimeException(SearchManager::$plugin->indexing->lastIndexingFailureMessage($indexHandle));
                    }
                    $totalIndexed += count($elements);

                    // Free memory after each batch to prevent exhaustion
                    unset($elements, $batchElements);
                    gc_collect_cycles();
                }

                $this->setRebuildProgress(
                    $queue,
                    ($siteIndex + (($batchIndex + 1) / $batchCount)) / count($sitesToIndex),
                    $progressStart,
                    $progressEnd,
                );
            }

            if ($batchCount === 0) {
                $this->setRebuildProgress(
                    $queue,
                    ($siteIndex + 1) / count($sitesToIndex),
                    $progressStart,
                    $progressEnd,
                );
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

        $this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);
    }


    private function rebuildAllIndices($queue): void
    {
        $indices = array_values(array_filter(
            SearchIndex::findAll(),
            static fn(SearchIndex $index): bool => $index->enabled,
        ));
        $indexCount = count($indices);

        if ($indexCount === 0) {
            $this->setProgress($queue, 1.0);
            return;
        }

        foreach ($indices as $i => $index) {
            $this->rebuildSingleIndex(
                $queue,
                $index->handle,
                $index,
                $i / $indexCount,
                ($i + 1) / $indexCount,
            );
        }

        $this->setProgress($queue, 1.0);
    }

    private function setRebuildProgress($queue, float $progress, float $start = 0.0, float $end = 1.0): void
    {
        $start = max(0.0, min(1.0, $start));
        $end = max($start, min(1.0, $end));
        $progress = max(0.0, min(1.0, $progress));

        $this->setProgress($queue, $start + (($end - $start) * $progress));
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
