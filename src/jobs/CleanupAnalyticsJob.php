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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Cleanup Analytics Job
 *
 * Automatically cleans up old analytics based on retention settings.
 * Respects analyticsRetention from config (e.g., 30 days dev, 60 staging, 365 prod).
 *
 * @since 5.0.0
 */
class CleanupAnalyticsJob extends BaseJob
{
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule cleanup after completion
     */
    public bool $reschedule = false;

    /**
     * @var string|null Next run time display string for queued jobs
     */
    public ?string $nextRunTime = null;

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
                // Short format: "Nov 8, 12:00am"
                $this->nextRunTime = date('M j, g:ia', time() + $delay);
            }
        }
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

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }

    /**
     * Schedule the next cleanup (runs every 24 hours)
     */
    private function scheduleNextCleanup(): void
    {
        $settings = SearchManager::$plugin->getSettings();

        // Only reschedule if analytics is enabled and retention is set
        if (!$settings->enableAnalytics || $settings->analyticsRetention <= 0) {
            return;
        }

        // Prevent duplicate scheduling - check if another cleanup job already exists
        // This prevents fan-out if multiple jobs end up in the queue (manual runs, retries, etc.)
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'CleanupAnalyticsJob'])
            ->exists();

        if ($existingJob) {
            $this->logDebug('Skipping reschedule - cleanup job already exists');
            return;
        }

        $delay = $this->calculateNextRunDelay();

        if ($delay > 0) {
            // Calculate next run time for display
            $nextRunTime = date('M j, g:ia', time() + $delay);

            $job = new self([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]);

            Craft::$app->getQueue()->delay($delay)->push($job);

            $this->logDebug('Scheduled next analytics cleanup', [
                'delay' => $delay,
                'nextRun' => $nextRunTime,
            ]);
        }
    }

    /**
     * Calculate the delay in seconds for the next cleanup (24 hours)
     */
    private function calculateNextRunDelay(): int
    {
        return 86400; // 24 hours
    }
}
