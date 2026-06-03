<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
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

    /**
     * Time budget per BatchSyncJob run, in seconds. We claim+process buffer
     * rows in `syncBatchSize` chunks within a single job, looping until the
     * buffer is empty OR this budget is exhausted (whichever comes first).
     *
     * Set well under Craft's default queue TTR (~30s) so the job finishes
     * cleanly before the queue starts considering it stuck. Operators with
     * huge backlogs (Feed Me bulk imports, 10k+ rows) will exhaust the budget
     * on the first pass, drop a continuation job on the queue, and resume.
     *
     * @since 5.45.0
     */
    private const TIME_BUDGET_SECONDS = 25;

    /** @inheritdoc */
    public function execute($queue): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $repository = SearchManager::$plugin->pendingSyncs;
        $processor = SearchManager::$plugin->pendingSyncProcessor;

        $repository->purgeOld($settings->pendingMaxAge);

        $started = microtime(true);
        $claimTtl = max(300, $settings->batchFlushInterval * 6);
        $totalClaimed = 0;
        $totalSucceeded = 0;
        $totalFailureGroups = 0;
        $passes = 0;
        $syncedIndexHandles = [];
        $deferred = false;

        // Drain in `syncBatchSize` chunks within a single job until the buffer
        // is empty or our time budget runs out. The chunk size still bounds
        // the SQL UPDATE...WHERE id IN (...) cardinality, but we no longer
        // require N separate job invocations to drain N×chunk-size rows.
        while (true) {
            if ((microtime(true) - $started) > self::TIME_BUDGET_SECONDS) {
                if ($repository->hasDueRows()) {
                    $deferred = true;
                    $repository->scheduleBatchJob(true);
                }
                break;
            }

            $rows = $repository->claim($settings->syncBatchSize, $claimTtl);
            if (empty($rows)) {
                break;
            }

            $passes++;
            $totalClaimed += count($rows);

            $result = $processor->process($rows);
            $repository->markSucceeded($result['success']);
            foreach ($result['syncedIndexHandles'] as $indexHandle) {
                $syncedIndexHandles[$indexHandle] = true;
            }
            $totalSucceeded += count($result['success']);

            foreach ($result['failures'] as $failure) {
                $repository->markRetry(
                    $failure['ids'],
                    $failure['error'],
                    $settings->batchMaxAttempts,
                    $settings->batchFlushInterval,
                );
                $totalFailureGroups++;
            }
        }

        if ($totalClaimed > 0) {
            if (!$deferred && !empty($syncedIndexHandles)) {
                $this->refreshSyncedIndexCounts(array_keys($syncedIndexHandles));
            }

            $this->logInfo('Batch sync run complete', [
                'passes' => $passes,
                'claimed' => $totalClaimed,
                'succeeded' => $totalSucceeded,
                'failureGroups' => $totalFailureGroups,
                'durationMs' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
    }

    protected function defaultDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return Craft::t('search-manager', '{pluginName}: Processing pending search syncs', [
            'pluginName' => $settings->getDisplayName(),
        ]);
    }

    /**
     * @param string[] $indexHandles
     */
    private function refreshSyncedIndexCounts(array $indexHandles): void
    {
        foreach ($indexHandles as $indexHandle) {
            $index = SearchIndex::findByHandle($indexHandle);
            if (!$index) {
                continue;
            }

            $index->updateStats($index->getExpectedCount());
        }
    }
}
