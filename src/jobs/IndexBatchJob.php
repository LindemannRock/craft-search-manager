<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\queue\RetryableJobInterface;

/**
 * Index Batch Job
 *
 * Queue job for batch indexing multiple elements
 *
 * @since 5.0.0
 */
class IndexBatchJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    public array $elementIds = [];
    public string $elementType;
    public string $indexHandle;
    public ?int $siteId = null;

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

            $elements = $this->elementType::find()
                ->id($batch)
                ->siteId($this->siteId)
                ->status(null)
                ->all();

            // Batch index
            if (!empty($elements)) {
                SearchManager::$plugin->indexing->batchIndex($elements, $this->indexHandle);
            }

            unset($elements);
            gc_collect_cycles();
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
