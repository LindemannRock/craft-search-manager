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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Sync element status changes that don't fire events
 *
 * Handles entries that:
 * - Became live (postDate passed)
 * - Became expired (expiryDate passed)
 *
 * @since 5.0.0
 */
class SyncStatusJob extends BaseJob
{
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
     */
    public function execute($queue): void
    {
        $settings = SearchManager::$plugin->getSettings();

        // If sync is disabled, don't reschedule
        if ($settings->statusSyncInterval <= 0) {
            return;
        }

        // Get last sync time from cache, or use a reasonable default (1 hour ago)
        $lastSync = $this->lastSyncTime
            ? new \DateTime($this->lastSyncTime)
            : new \DateTime('-1 hour');

        $now = new \DateTime();

        // Get all enabled indices that track Entry elements
        $indices = SearchIndex::findAll();
        $entryIndices = array_filter($indices, function($index) {
            return $index->enabled && $index->elementType === Entry::class;
        });

        if (empty($entryIndices)) {
            $this->logDebug('No entry indices found for status sync');
            if ($this->reschedule) {
                $this->scheduleNextSync($now);
            }
            return;
        }

        $totalAdded = 0;
        $totalRemoved = 0;
        $batchSize = $settings->batchSize;

        foreach ($entryIndices as $index) {
            $lastSyncDb = \craft\helpers\Db::prepareDateForDb($lastSync);
            $nowDb = \craft\helpers\Db::prepareDateForDb($now);

            // Determine which sites to process
            $sitesToProcess = $index->getSiteIds();
            if ($sitesToProcess === null) {
                $sitesToProcess = Craft::$app->getSites()->getAllSiteIds();
            }

            if (empty($sitesToProcess)) {
                $this->logWarning('No sites to process for status sync', ['index' => $index->handle]);
                continue;
            }

            foreach ($sitesToProcess as $siteId) {
                // Find entries that became live since last sync (postDate between lastSync and now)
                $newlyLive = Entry::find()
                    ->status('live')
                    ->siteId($siteId)
                    ->andWhere(['>=', 'entries.postDate', $lastSyncDb])
                    ->andWhere(['<=', 'entries.postDate', $nowDb])
                    ->limit(null)
                    ->ids();

                // Find entries that expired since last sync (expiryDate between lastSync and now)
                $newlyExpired = Entry::find()
                    ->status('expired')
                    ->siteId($siteId)
                    ->andWhere(['>=', 'entries.expiryDate', $lastSyncDb])
                    ->andWhere(['<=', 'entries.expiryDate', $nowDb])
                    ->limit(null)
                    ->ids();

                $this->logDebug('Status sync for index site', [
                    'index' => $index->handle,
                    'siteId' => $siteId,
                    'newlyLive' => count($newlyLive),
                    'newlyExpired' => count($newlyExpired),
                ]);

                // Index newly live entries in batches
                if (!empty($newlyLive)) {
                    $chunks = array_chunk($newlyLive, $batchSize);
                    foreach ($chunks as $i => $chunk) {
                        $this->setProgress($queue, ($i + 1) / count($chunks) * 0.5, Craft::t('search-manager', 'Adding newly live entries to {index}', ['index' => $index->name]));

                        foreach ($chunk as $entryId) {
                            $entry = Entry::find()->id($entryId)->siteId($siteId)->status('live')->one();
                            if ($entry) {
                                SearchManager::$plugin->indexing->indexElement($entry);
                                $totalAdded++;
                            }
                        }
                    }
                }

                // Remove expired entries in batches
                if (!empty($newlyExpired)) {
                    $chunks = array_chunk($newlyExpired, $batchSize);
                    foreach ($chunks as $i => $chunk) {
                        $this->setProgress($queue, 0.5 + ($i + 1) / count($chunks) * 0.5, Craft::t('search-manager', 'Removing expired entries from {index}', ['index' => $index->name]));

                        foreach ($chunk as $entryId) {
                            $entry = Entry::find()->id($entryId)->siteId($siteId)->status(null)->one();
                            if ($entry) {
                                SearchManager::$plugin->indexing->removeElement($entry);
                                $totalRemoved++;
                            }
                        }
                    }
                }
            }
        }

        if ($totalAdded > 0 || $totalRemoved > 0) {
            $this->logInfo('Status sync completed', [
                'added' => $totalAdded,
                'removed' => $totalRemoved,
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
