<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use lindemannrock\base\helpers\DateFormatHelper;
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

        $nextRunTime = $this->nextRunTime;
        if ($nextRunTime === null && $this->reschedule) {
            $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                $this->calculateNextRun(),
                $settings,
                null,
                false,
                pluginHandle: 'search-manager',
            );
        }

        if ($nextRunTime) {
            $description .= " ({$nextRunTime})";
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
            : (clone DateFormatHelper::now())->modify('-1 hour');

        $now = DateFormatHelper::now();

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
            $newlyLiveEntries = Entry::find()
                ->status('live')
                ->siteId($siteId)
                ->drafts(false)
                ->revisions(false)
                ->withCustomFields(false)
                ->andWhere(['>=', 'entries.postDate', $lastSyncDb])
                ->andWhere(['<=', 'entries.postDate', $nowDb])
                ->all();

            foreach ($newlyLiveEntries as $entry) {
                $rowsQueued += $repository->queueForElement($entry, PendingSyncRepository::OP_UPSERT);
                $entriesBecameLive++;
            }

            $newlyExpiredEntries = Entry::find()
                ->status('expired')
                ->siteId($siteId)
                ->drafts(false)
                ->revisions(false)
                ->withCustomFields(false)
                ->andWhere(['>=', 'entries.expiryDate', $lastSyncDb])
                ->andWhere(['<=', 'entries.expiryDate', $nowDb])
                ->all();

            foreach ($newlyExpiredEntries as $entry) {
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

        $nextRun = $this->calculateNextRun();
        $delay = $this->calculateNextRunDelay($nextRun);

        if ($delay > 0) {
            $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                $nextRun,
                $settings,
                null,
                false,
                pluginHandle: 'search-manager',
            );

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
     * Calculate the next sync run.
     */
    private function calculateNextRun(?\DateTime $from = null): \DateTime
    {
        $settings = SearchManager::$plugin->getSettings();
        $from ??= DateFormatHelper::now();
        return (clone $from)->modify("+{$settings->statusSyncInterval} minutes");
    }

    /**
     * Calculate the delay in seconds for the next sync.
     */
    private function calculateNextRunDelay(?\DateTime $nextRun = null): int
    {
        $nextRun ??= $this->calculateNextRun();
        return max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());
    }
}
