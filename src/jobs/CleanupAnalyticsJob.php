<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;
use yii\queue\RetryableJobInterface;

/**
 * Cleanup Analytics Job
 *
 * Automatically cleans up old analytics based on retention settings.
 * Respects analyticsRetention from config (e.g., 30 days dev, 60 staging, 365 prod).
 *
 * @since 5.34.0
 */
class CleanupAnalyticsJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule cleanup after completion
     */
    public bool $reschedule = false;

    /**
     * @var string|null Stable recurring schedule owner
     * @since 5.55.0
     */
    public ?string $recurringOwner = null;

    /**
     * @var string|null Next run time display string for queued jobs
     */
    public ?string $nextRunTime = null;

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
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $settings = SearchManager::$plugin->getSettings();

        // Only run if retention is enabled (> 0)
        if ($settings->analyticsRetention <= 0) {
            $this->logDebug('Analytics retention disabled, skipping cleanup');
            return;
        }

        // Clean up old analytics
        $deleted = SearchManager::$plugin->analytics->cleanupOldAnalytics();

        if ($deleted > 0) {
            $this->logInfo('Analytics cleanup completed', [
                'deleted' => $deleted,
                'retention' => $settings->analyticsRetention . ' days',
            ]);
        } else {
            $this->logDebug('Analytics cleanup: no records to delete');
        }

        // Reschedule if needed
        if ($this->reschedule) {
            $this->scheduleNextCleanup();
        }
    }

    /**
     * @inheritdoc
     */
    public function getDescription(): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        $pluginName = $settings->getDisplayName();
        $description = Craft::t('search-manager', '{pluginName}: Cleaning up old analytics', [
            'pluginName' => $pluginName,
        ]);

        $nextRunTime = $this->nextRunTime;
        if ($nextRunTime === null && $this->reschedule) {
            $nextRun = $this->calculateNextRun();
            if ($nextRun !== null) {
                $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                    $nextRun,
                    $settings,
                    null,
                    false,
                    pluginHandle: 'search-manager',
                );
            }
        }

        if ($nextRunTime) {
            $description .= " ({$nextRunTime})";
        }

        return $description;
    }

    /**
     * Schedule the next cleanup run.
     */
    private function scheduleNextCleanup(): void
    {
        $result = SearchManager::$plugin->recurringQueueScheduler->scheduleAnalyticsCleanupSuccessor();

        if ($result->hasPending()) {
            $this->logDebug('Scheduled next analytics cleanup', [
                'jobId' => $result->jobId,
            ]);
        }
    }

    /**
     * Calculate the next cleanup run.
     */
    private function calculateNextRun(): ?\DateTime
    {
        return ScheduleHelper::calculateNext('daily');
    }
}
