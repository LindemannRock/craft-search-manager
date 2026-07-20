<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\elements\db\ElementQuery;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchIndexCriteriaHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
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
        $preflight = $this->preflightIndexRebuild($indexHandle, $preloadedIndex);
        $index = $preflight['index'];
        $elementType = $preflight['elementType'];
        $siteQueries = $preflight['siteQueries'];
        $sitesToIndex = array_keys($siteQueries);

        $this->logInfo('Rebuilding index', ['handle' => $indexHandle]);

        // Clear existing index
        if (!SearchManager::$plugin->backend->clearIndex($indexHandle)) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': backend clear failed.");
        }

        $totalIndexed = 0;
        $indexingFailures = [];
        $batchSize = SearchManager::$plugin->getSettings()->batchSize;

        foreach (array_values($siteQueries) as $siteIndex => $siteQuery) {
            $siteId = $sitesToIndex[$siteIndex];
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
                    if (!SearchElementAvailabilityHelper::isSearchable($element)) {
                        continue;
                    }

                    // Skip entries without URL if index is configured to do so
                    if ($index->shouldSkipElementWithoutUrl($element)) {
                        continue;
                    }

                    $elements[] = $element;
                }

                if (!empty($elements)) {
                    $batchSucceeded = SearchManager::$plugin->indexing->batchIndex($elements, $indexHandle);
                    $batchResult = SearchManager::$plugin->indexing->getLastBatchResult();
                    $totalIndexed += $batchResult['acceptedElementCount'];
                    if (!$batchSucceeded) {
                        $indexingFailures[] = SearchManager::$plugin->indexing->lastBatchIndexingFailureMessage($indexHandle);
                    }

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
        if (!$index->updateStats($totalIndexed)) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': document count metadata update failed.");
        }

        // Clear caches for this index
        SearchManager::$plugin->backend->clearSearchCache($indexHandle);
        SearchManager::$plugin->autocomplete->clearCache($indexHandle);

        if ($indexingFailures !== []) {
            throw new \RuntimeException(
                "Cannot rebuild index '{$indexHandle}': " . implode(' | ', $indexingFailures),
            );
        }

        $this->logInfo('Index rebuild completed', [
            'handle' => $indexHandle,
            'count' => $totalIndexed,
        ]);

        // Queue cache warming job if enabled
        $settings = SearchManager::$plugin->getSettings();
        if (
            SearchManager::$plugin->isPro()
            && $settings->enableCacheWarming
            && ($settings->enableCache || $settings->enableAutocompleteCache)
        ) {
            Craft::$app->getQueue()->push(new CacheWarmJob([
                'indexHandle' => $indexHandle,
            ]));

            $this->logInfo('Queued cache warming job', [
                'handle' => $indexHandle,
            ]);
        }

        $this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);
    }

    /**
     * Resolve every dependency needed for a rebuild before storage is cleared.
     *
     * @return array{index: SearchIndex, elementType: string, siteQueries: array<int, ElementQuery>}
     */
    private function preflightIndexRebuild(string $indexHandle, ?SearchIndex $preloadedIndex = null): array
    {
        $configValidation = SearchManager::$plugin->configIndexValidator->validate();
        if ($configValidation->hasErrors($indexHandle)) {
            $findings = $configValidation->getFindingsForHandle($indexHandle);
            $message = $findings[0]['message'] ?? 'config index validation failed';
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': {$message}");
        }

        $index = $preloadedIndex ?? SearchIndex::findByHandle($indexHandle);
        if (!$index) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': index model could not be resolved.");
        }

        if ($index->source === 'config') {
            $this->logInfo('Config index detected - syncing metadata', [
                'handle' => $indexHandle,
                'hasId' => $index->id ? 'YES' : 'NO',
                'name' => $index->name,
                'transformer' => $index->transformerClass,
            ]);

            if (!$index->syncMetadataFromConfig()) {
                throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': config metadata sync failed.");
            }

            $this->logInfo('Sync result: SUCCESS');
        }

        $elementType = $index->elementType;
        if (!$this->isElementTypeAvailable($elementType, 'rebuild-index-preflight')) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': element type '{$elementType}' is not available.");
        }

        $sitesToIndex = $index->getSiteIds();
        if ($sitesToIndex === null) {
            $sitesToIndex = array_map(
                static fn($site): int => (int)$site->id,
                Craft::$app->getSites()->getAllSites(),
            );
        }

        if ($sitesToIndex === []) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': site resolution produced no valid sites.");
        }

        $invalidSiteIds = [];
        foreach ($sitesToIndex as $siteId) {
            if (Craft::$app->getSites()->getSiteById($siteId) === null) {
                $invalidSiteIds[] = $siteId;
            }
        }

        if ($invalidSiteIds !== []) {
            throw new \RuntimeException(
                "Cannot rebuild index '{$indexHandle}': site resolution failed for ID(s) " . implode(', ', $invalidSiteIds) . '.',
            );
        }

        $siteQueries = [];
        foreach ($sitesToIndex as $siteId) {
            try {
                $siteQuery = $elementType::find()
                    ->siteId($siteId)
                    ->drafts(false)
                    ->revisions(false);

                if (!empty($index->criteria)) {
                    $siteQuery = SearchIndexCriteriaHelper::apply($siteQuery, $elementType, $index->criteria);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Cannot rebuild index '{$indexHandle}': element query construction failed for site {$siteId}: {$e->getMessage()}",
                    0,
                    $e,
                );
            }

            $siteQueries[$siteId] = $siteQuery;
        }

        try {
            $transformerClass = SearchManager::$plugin->transformers->resolveTransformerClassForElementType(
                $elementType,
                $index->transformerClass,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Cannot rebuild index '{$indexHandle}': transformer resolution failed: {$e->getMessage()}",
                0,
                $e,
            );
        }
        $this->assertTransformerResolvable($indexHandle, $transformerClass);

        try {
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Cannot rebuild index '{$indexHandle}': backend resolution failed: {$e->getMessage()}",
                0,
                $e,
            );
        }
        if (!$backend instanceof BackendInterface) {
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': backend could not be resolved.");
        }

        return [
            'index' => $index,
            'elementType' => $elementType,
            'siteQueries' => $siteQueries,
        ];
    }

    private function assertTransformerResolvable(string $indexHandle, ?string $transformerClass): void
    {
        if ($transformerClass === null || !class_exists($transformerClass)) {
            $label = $transformerClass ?: '(none)';
            throw new \RuntimeException("Cannot rebuild index '{$indexHandle}': transformer '{$label}' could not be resolved.");
        }

        try {
            $reflection = new \ReflectionClass($transformerClass);
            $constructor = $reflection->getConstructor();
            if (
                !$reflection->implementsInterface(TransformerInterface::class)
                || !$reflection->isInstantiable()
                || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)
            ) {
                throw new \RuntimeException('the class is not a constructible TransformerInterface implementation');
            }

            $transformer = $reflection->newInstance();
            if (!$transformer instanceof TransformerInterface) {
                throw new \RuntimeException('the constructed object does not implement TransformerInterface');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Cannot rebuild index '{$indexHandle}': transformer '{$transformerClass}' could not be resolved: {$e->getMessage()}",
                0,
                $e,
            );
        }
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

        $failures = [];
        foreach ($indices as $i => $index) {
            try {
                $this->rebuildSingleIndex(
                    $queue,
                    $index->handle,
                    $index,
                    $i / $indexCount,
                    ($i + 1) / $indexCount,
                );
            } catch (\Throwable $e) {
                $failures[$index->handle] = $e->getMessage();
                $this->logError('Index rebuild failed; continuing with remaining indices', [
                    'handle' => $index->handle,
                    'error' => $e->getMessage(),
                ]);
                $this->setRebuildProgress($queue, 1.0, $i / $indexCount, ($i + 1) / $indexCount);
            }
        }

        $this->setProgress($queue, 1.0);

        if ($failures !== []) {
            $failureSummary = implode('; ', array_map(
                static fn(string $handle, string $message): string => "{$handle}: {$message}",
                array_keys($failures),
                array_values($failures),
            ));

            throw new \RuntimeException('Rebuild all indices completed with failures: ' . $failureSummary);
        }
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
