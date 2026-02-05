<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Index Batch Job
 *
 * Queue job for batch indexing multiple elements
 *
 * @since 5.0.0
 */
class IndexBatchJob extends BaseJob
{
    use LoggingTrait;

    public array $elementIds = [];
    public string $elementType;
    public string $indexHandle;
    public ?int $siteId = null;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        $total = count($this->elementIds);

        $this->logInfo('Starting batch indexing', [
            'count' => $total,
            'indexHandle' => $this->indexHandle,
        ]);

        $batchSize = SearchManager::$plugin->getSettings()->batchSize;
        $batches = array_chunk($this->elementIds, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            // Update progress
            $this->setProgress($queue, $batchIndex / count($batches));

            // Get elements
            $elements = [];
            foreach ($batch as $elementId) {
                $element = Craft::$app->elements->getElementById(
                    $elementId,
                    $this->elementType,
                    $this->siteId
                );

                if ($element) {
                    $elements[] = $element;
                }
            }

            // Batch index
            if (!empty($elements)) {
                SearchManager::$plugin->indexing->batchIndex($elements, $this->indexHandle);
            }
        }

        $this->logInfo('Batch indexing completed', ['count' => $total]);
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Indexing {count} elements', [
            'pluginName' => $settings->getDisplayName(),
            'count' => count($this->elementIds),
        ]);
    }
}
