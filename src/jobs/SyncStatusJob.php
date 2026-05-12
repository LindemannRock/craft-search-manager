<?php

/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use yii\queue\RetryableJobInterface;

/**
 * Sync element status changes that don't fire events
 *
 * Handles entries that:
 * - Became live (postDate passed)
 * - Became expired (expiryDate passed)
 *
 * @since 5.29.0
 */
class SyncStatusJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule after completion
     */
    public bool $reschedule = false;

    /**
     * @var string|null Next run time display string
     */
    public ?string $nextRunTime = null;

    /**
     * @var string|null Last sync timestamp (ISO 8601)
     */
    public ?string $lastSyncTime = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');

        // Calculate and set next run time if not already set
        if ($this->reschedule && !$this->nextRunTime) {
            $delay = $this->calculateNextRunDelay();
            if ($delay > 0) {
                $this->nextRunTime = date('M j, g:ia', time() + $delay);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        $pluginName = $settings->getDisplayName();
        $description = Craft::t('search-manager', '{pluginName}: Syncing element status changes', ['pluginName' => $pluginName]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }

    /**
     * @inheritdoc
     *
     * Finds entries whose status flipped since the last run (became live via
     * `postDate` passing, or expired via `expiryDate` passing) and queues
     * them into the L3 pending-sync buffer. The buffer's `BatchSyncJob`
     * then drains them to the backend on its normal cadence — same pipeline
     * used by save-driven syncs. We don't talk to the backend directly here,
     * so every sync in the plugin now flows through one path.
     */
    public function execute($queue): void
    {
        $settings = SearchManager::$plugin->getSettings();

        // If sync is disabled, don't reschedule
        if ($settings->statusSyncInterval <= 0) {
            return;
        }

        // Get last sync time from the previous job instance, or use a
        // reasonable default (1 hour ago) on first run.
        $lastSync = $this->lastSyncTime
            ? new \DateTime($this->lastSyncTime)
            : new \DateTime('-1 hour');

        $now = new \DateTime();

        // Only sites covered by at least one enabled entry index are worth
        // querying — there's no point detecting a status flip on a site no
        // index targets, since `queueForElement` would queue zero rows for
        // it. Collecting the union once means we run two element queries per
        // relevant site instead of two queries per (index, site) pair.
        $relevantSiteIds = [];
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== Entry::class) {
                continue;
            }
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            foreach ($siteIds as $siteId) {
                $relevantSiteIds[(int) $siteId] = true;
            }
        }

        if (empty($relevantSiteIds)) {
            $this->logDebug('No entry indices found for status sync');
            if ($this->reschedule) {
                $this->scheduleNextSync($now);
            }
            return;
        }

        $lastSyncDb = \craft\helpers\Db::prepareDateForDb($lastSync);
        $nowDb = \craft\helpers\Db::prepareDateForDb($now);
        $repository = SearchManager::$plugin->pendingSyncs;

        $entriesBecameLive = 0;
        $entriesExpired = 0;
        $rowsQueued = 0;

        foreach (array_keys($relevantSiteIds) as $siteId) {
            $newlyLiveIds = Entry::find()
                ->status('live')
                ->siteId($siteId)
                ->andWhere(['>=', 'entries.postDate', $lastSyncDb])
                ->andWhere(['<=', 'entries.postDate', $nowDb])
                ->limit(null)
                ->ids();

            foreach ($newlyLiveIds as $entryId) {
                $entry = Entry::find()->id((int) $entryId)->siteId($siteId)->status('live')->one();
                if (!$entry) {
                    continue;
                }
                $rowsQueued += $repository->queueForElement($entry, PendingSyncRepository::OP_UPSERT);
                $entriesBecameLive++;
            }

            $newlyExpiredIds = Entry::find()
                ->status('expired')
                ->siteId($siteId)
                ->andWhere(['>=', 'entries.expiryDate', $lastSyncDb])
                ->andWhere(['<=', 'entries.expiryDate', $nowDb])
                ->limit(null)
                ->ids();

            foreach ($newlyExpiredIds as $entryId) {
                $entry = Entry::find()->id((int) $entryId)->siteId($siteId)->status(null)->one();
                if (!$entry) {
                    continue;
                }
                $rowsQueued += $repository->queueForElement($entry, PendingSyncRepository::OP_DELETE);
                $entriesExpired++;
            }
        }

        if ($entriesBecameLive > 0 || $entriesExpired > 0) {
            $this->logInfo('Status sync queued', [
                'entriesBecameLive' => $entriesBecameLive,
                'entriesExpired' => $entriesExpired,
                'rowsQueued' => $rowsQueued,
            ]);
        }

        // Reschedule if needed
        if ($this->reschedule) {
            $this->scheduleNextSync($now);
        }
    }

    /**
     * Schedule the next sync
     */
    private function scheduleNextSync(\DateTime $lastSyncTime): void
    {
        $settings = SearchManager::$plugin->getSettings();

        // Only reschedule if sync is enabled
        if ($settings->statusSyncInterval <= 0) {
            return;
        }

        // Prevent duplicate scheduling - check if another sync job already exists
        // This prevents fan-out if multiple jobs end up in the queue (manual runs, retries, etc.)
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'SyncStatusJob'])
            ->exists();

        if ($existingJob) {
            $this->logDebug('Skipping reschedule - sync job already exists');
            return;
        }

        $delay = $this->calculateNextRunDelay();

        if ($delay > 0) {
            $nextRunTime = date('M j, g:ia', time() + $delay);

            $job = new self([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
                'lastSyncTime' => $lastSyncTime->format('c'),
            ]);

            Craft::$app->getQueue()->delay($delay)->push($job);

            $this->logDebug('Scheduled next status sync', [
                'delay' => $delay,
                'nextRun' => $nextRunTime,
            ]);
        }
    }

    /**
     * Calculate the delay in seconds for the next sync
     */
    private function calculateNextRunDelay(): int
    {
        $settings = SearchManager::$plugin->getSettings();
        return $settings->statusSyncInterval * 60; // Convert minutes to seconds
    }
}
