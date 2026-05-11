<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\queue\RetryableJobInterface;

/**
 * Batch Sync Job
 *
 * Drains pending element sync rows in batches.
 *
 * @since 5.45.0
 */
class BatchSyncJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

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
        $settings = SearchManager::$plugin->getSettings();
        $repository = SearchManager::$plugin->pendingSyncs;
        $processor = SearchManager::$plugin->pendingSyncProcessor;

        $repository->purgeOld($settings->pendingMaxAge);

        $claimTtl = max(300, $settings->batchFlushInterval * 6);
        $rows = $repository->claim($settings->syncBatchSize, $claimTtl);
        if (empty($rows)) {
            return;
        }

        $started = microtime(true);
        $this->logInfo('Batch sync started', [
            'count' => count($rows),
        ]);

        $result = $processor->process($rows);

        $repository->markSucceeded($result['success']);
        foreach ($result['failures'] as $failure) {
            $repository->markRetry(
                $failure['ids'],
                $failure['error'],
                $settings->batchMaxAttempts,
                $settings->batchFlushInterval,
            );
        }

        $this->logInfo('Batch sync completed', [
            'claimed' => count($rows),
            'succeeded' => count($result['success']),
            'failureGroups' => count($result['failures']),
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
        ]);

        if ($repository->hasDueRows()) {
            $repository->scheduleBatchJob();
        }
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Processing pending search syncs', [
            'pluginName' => $settings->getDisplayName(),
        ]);
    }
}
