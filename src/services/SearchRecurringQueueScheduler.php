<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\queue\BaseJob;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\RecurringQueueResult;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\searchmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\searchmanager\jobs\SyncStatusJob;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;
use yii\db\Expression;

/**
 * Owns Search Manager's portable recurring queue schedules.
 *
 * @since 5.55.0
 */
class SearchRecurringQueueScheduler extends Component
{
    public const PLUGIN_TOKEN = 'searchmanager';
    public const ANALYTICS_OWNER = 'search-manager:analytics-cleanup:recurring';
    public const STATUS_SYNC_OWNER = 'search-manager:status-sync:recurring';
    public const ANALYTICS_FAMILY_MUTEX = 'search-manager:analytics-cleanup:schedule';
    public const STATUS_SYNC_FAMILY_MUTEX = 'search-manager:status-sync:schedule';
    public const ANALYTICS_PORTABLE_MUTEX = 'search-manager:analytics-cleanup:portable';
    public const STATUS_SYNC_PORTABLE_MUTEX = 'search-manager:status-sync:portable';

    public int $mutexTimeout = 5;

    public function ensureAnalyticsCleanup(): RecurringQueueResult
    {
        if (SearchManager::$plugin->getSettings()->analyticsRetention <= 0) {
            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
        }

        $target = ScheduleHelper::calculateNext('daily');
        if ($target === null) {
            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
        }

        return $this->withFamilyLock(
            self::ANALYTICS_FAMILY_MUTEX,
            fn(): RecurringQueueResult => $this->withPortableLock(
                self::ANALYTICS_PORTABLE_MUTEX,
                fn(): RecurringQueueResult => $this->ensureFamilyAt(
                    CleanupAnalyticsJob::class,
                    self::ANALYTICS_OWNER,
                    self::ANALYTICS_PORTABLE_MUTEX,
                    $target->getTimestamp(),
                    $this->analyticsJob($target),
                ),
            ),
        );
    }

    public function ensureStatusSync(): RecurringQueueResult
    {
        if (SearchManager::$plugin->getSettings()->statusSyncInterval <= 0) {
            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
        }

        $targetTimestamp = DateTimeHelper::currentTimeStamp() + 300;
        $target = new \DateTimeImmutable("@$targetTimestamp");

        return $this->withFamilyLock(
            self::STATUS_SYNC_FAMILY_MUTEX,
            fn(): RecurringQueueResult => $this->withPortableLock(
                self::STATUS_SYNC_PORTABLE_MUTEX,
                fn(): RecurringQueueResult => $this->ensureFamilyAt(
                    SyncStatusJob::class,
                    self::STATUS_SYNC_OWNER,
                    self::STATUS_SYNC_PORTABLE_MUTEX,
                    $targetTimestamp,
                    $this->statusSyncJob($target),
                ),
            ),
        );
    }

    public function scheduleAnalyticsCleanupSuccessor(): RecurringQueueResult
    {
        return $this->ensureAnalyticsCleanup();
    }

    public function scheduleStatusSyncSuccessor(\DateTimeInterface $executionReference): RecurringQueueResult
    {
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->statusSyncInterval <= 0) {
            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
        }

        $target = \DateTimeImmutable::createFromInterface($executionReference)
            ->modify("+{$settings->statusSyncInterval} minutes");

        return $this->withFamilyLock(
            self::STATUS_SYNC_FAMILY_MUTEX,
            fn(): RecurringQueueResult => $this->withPortableLock(
                self::STATUS_SYNC_PORTABLE_MUTEX,
                fn(): RecurringQueueResult => $this->ensureFamilyAt(
                    SyncStatusJob::class,
                    self::STATUS_SYNC_OWNER,
                    self::STATUS_SYNC_PORTABLE_MUTEX,
                    $target->getTimestamp(),
                    $this->statusSyncJob($target, $executionReference),
                ),
            ),
        );
    }

    public function replaceAnalyticsCleanup(?int $analyticsRetention = null): RecurringQueueResult
    {
        $analyticsRetention ??= SearchManager::$plugin->getSettings()->analyticsRetention;

        return $this->withFamilyLock(
            self::ANALYTICS_FAMILY_MUTEX,
            function() use ($analyticsRetention): RecurringQueueResult {
                return $this->withPortableLock(
                    self::ANALYTICS_PORTABLE_MUTEX,
                    function() use ($analyticsRetention): RecurringQueueResult {
                        $this->cancelFamilyRows(CleanupAnalyticsJob::class, self::ANALYTICS_OWNER);

                        if ($analyticsRetention <= 0) {
                            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
                        }

                        $target = ScheduleHelper::calculateNext('daily');
                        if ($target === null) {
                            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
                        }

                        return $this->ensureFamilyAt(
                            CleanupAnalyticsJob::class,
                            self::ANALYTICS_OWNER,
                            self::ANALYTICS_PORTABLE_MUTEX,
                            $target->getTimestamp(),
                            $this->analyticsJob($target),
                        );
                    },
                );
            },
        );
    }

    public function replaceStatusSync(?int $statusSyncInterval = null): RecurringQueueResult
    {
        $statusSyncInterval ??= SearchManager::$plugin->getSettings()->statusSyncInterval;

        return $this->withFamilyLock(
            self::STATUS_SYNC_FAMILY_MUTEX,
            function() use ($statusSyncInterval): RecurringQueueResult {
                return $this->withPortableLock(
                    self::STATUS_SYNC_PORTABLE_MUTEX,
                    function() use ($statusSyncInterval): RecurringQueueResult {
                        $this->cancelFamilyRows(SyncStatusJob::class, self::STATUS_SYNC_OWNER);

                        if ($statusSyncInterval <= 0) {
                            return new RecurringQueueResult(RecurringQueueResult::STATUS_SKIPPED);
                        }

                        $targetTimestamp = DateTimeHelper::currentTimeStamp() + 300;
                        $target = new \DateTimeImmutable("@$targetTimestamp");

                        return $this->ensureFamilyAt(
                            SyncStatusJob::class,
                            self::STATUS_SYNC_OWNER,
                            self::STATUS_SYNC_PORTABLE_MUTEX,
                            $targetTimestamp,
                            $this->statusSyncJob($target),
                        );
                    },
                );
            },
        );
    }

    public function cancelAnalyticsCleanup(): int
    {
        return $this->withFamilyLock(
            self::ANALYTICS_FAMILY_MUTEX,
            fn(): int => $this->withPortableLock(
                self::ANALYTICS_PORTABLE_MUTEX,
                fn(): int => $this->cancelFamilyRows(CleanupAnalyticsJob::class, self::ANALYTICS_OWNER),
            ),
        );
    }

    public function cancelStatusSync(): int
    {
        return $this->withFamilyLock(
            self::STATUS_SYNC_FAMILY_MUTEX,
            fn(): int => $this->withPortableLock(
                self::STATUS_SYNC_PORTABLE_MUTEX,
                fn(): int => $this->cancelFamilyRows(SyncStatusJob::class, self::STATUS_SYNC_OWNER),
            ),
        );
    }

    public function hasAnalyticsCleanup(): bool
    {
        return $this->hasPendingFamily(CleanupAnalyticsJob::class, self::ANALYTICS_OWNER);
    }

    public function hasStatusSync(): bool
    {
        return $this->hasPendingFamily(SyncStatusJob::class, self::STATUS_SYNC_OWNER);
    }

    private function analyticsJob(\DateTimeInterface $target): CleanupAnalyticsJob
    {
        return new CleanupAnalyticsJob([
            'reschedule' => true,
            'recurringOwner' => self::ANALYTICS_OWNER,
            'nextRunTime' => $this->formatTarget($target),
        ]);
    }

    private function statusSyncJob(
        \DateTimeInterface $target,
        ?\DateTimeInterface $lastSyncTime = null,
    ): SyncStatusJob {
        return new SyncStatusJob([
            'reschedule' => true,
            'recurringOwner' => self::STATUS_SYNC_OWNER,
            'nextRunTime' => $this->formatTarget($target),
            'lastSyncTime' => $lastSyncTime?->format('c'),
        ]);
    }

    private function formatTarget(\DateTimeInterface $target): string
    {
        return DateFormatHelper::formatCompactDatetimeFromSettings(
            \DateTime::createFromInterface($target),
            SearchManager::$plugin->getSettings(),
            null,
            false,
            pluginHandle: 'search-manager',
        );
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function ensureFamilyAt(
        string $jobClass,
        string $ownerToken,
        string $portableMutex,
        int $targetTimestamp,
        BaseJob $job,
    ): RecurringQueueResult {
        $portableRows = $this->portableRows($jobClass, $ownerToken, true);
        $legacyRows = $this->legacyRows($jobClass, $ownerToken, true);

        if ($portableRows !== []) {
            $keptId = (string)$portableRows[0]['id'];
            $duplicates = array_merge(array_slice($portableRows, 1), $legacyRows);

            return new RecurringQueueResult(
                RecurringQueueResult::STATUS_EXISTING,
                $keptId,
                $this->deleteRows($duplicates),
            );
        }

        if ($legacyRows !== []) {
            return new RecurringQueueResult(
                RecurringQueueResult::STATUS_EXISTING,
                (string)$legacyRows[0]['id'],
                $this->deleteRows(array_slice($legacyRows, 1)),
            );
        }

        $jobId = PortableQueueScheduler::pushAt(
            job: $job,
            targetTimestamp: $targetTimestamp,
            identityTokens: [self::PLUGIN_TOKEN, $jobClass, $ownerToken],
            mutexName: $portableMutex,
            mutexTimeout: $this->mutexTimeout,
        );

        if ($jobId === null) {
            throw new \RuntimeException('Portable recurring queue scheduling did not create a queue row.');
        }

        return new RecurringQueueResult(RecurringQueueResult::STATUS_CREATED, $jobId);
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function hasPendingFamily(string $jobClass, string $ownerToken): bool
    {
        return $this->portableRows($jobClass, $ownerToken, true) !== []
            || $this->legacyRows($jobClass, $ownerToken, true) !== [];
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function cancelFamilyRows(string $jobClass, string $ownerToken): int
    {
        return $this->deleteRows(array_merge(
            $this->portableRows($jobClass, $ownerToken, false),
            $this->legacyRows($jobClass, $ownerToken, false),
        ));
    }

    /**
     * @param class-string<BaseJob> $jobClass
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function portableRows(string $jobClass, string $ownerToken, bool $pendingOnly): array
    {
        $query = $this->familyQuery($jobClass)
            ->andWhere(['like', 'job', $ownerToken]);

        return $this->queueRows($query, $pendingOnly);
    }

    /**
     * @param class-string<BaseJob> $jobClass
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function legacyRows(string $jobClass, string $ownerToken, bool $pendingOnly): array
    {
        $rows = $this->queueRows(
            $this->familyQuery($jobClass)->andWhere(['not like', 'job', $ownerToken]),
            $pendingOnly,
        );

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => $this->isLegacyRecurringPayload((string)$row['job'], $jobClass, $ownerToken),
        ));
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function familyQuery(string $jobClass): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', self::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', $this->jobClassToken($jobClass)]);
    }

    /** @param class-string<BaseJob> $jobClass */
    private function jobClassToken(string $jobClass): string
    {
        $parts = explode('\\', $jobClass);

        return end($parts) ?: $jobClass;
    }

    /**
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}>
     */
    private function queueRows(Query $query, bool $pendingOnly): array
    {
        if ($pendingOnly) {
            $query
                ->andWhere(['fail' => false])
                ->andWhere(['timeUpdated' => null]);
        }

        /** @var list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string}> $rows */
        $rows = $query
            ->select(['id', 'job', 'timePushed', 'delay', 'priority'])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $rows;
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function isLegacyRecurringPayload(string $payload, string $jobClass, string $ownerToken): bool
    {
        if (str_contains($payload, $ownerToken)) {
            return false;
        }

        $json = json_decode($payload, true);
        if (is_array($json)) {
            return ($json['class'] ?? null) === $jobClass
                && ($json['reschedule'] ?? null) === true;
        }

        return preg_match('/^O:\\d+:"' . preg_quote($jobClass, '/') . '":\\d+:\\{/', $payload) === 1
            && preg_match('/s:10:"reschedule";b:1;/', $payload) === 1;
    }

    /**
     * @param list<array{id: int|string}> $rows
     */
    private function deleteRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', [
                'id' => array_map(static fn(array $row): string => (string)$row['id'], $rows),
            ])
            ->execute();
    }

    private function withFamilyLock(string $mutexName, callable $callback): mixed
    {
        return $this->withLock($mutexName, 'recurring schedule', $callback);
    }

    private function withPortableLock(string $mutexName, callable $callback): mixed
    {
        return $this->withLock($mutexName, 'portable recurring schedule', $callback);
    }

    private function withLock(string $mutexName, string $label, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($mutexName, $this->mutexTimeout)) {
            throw new \RuntimeException("Unable to acquire the {$label} lock.");
        }

        try {
            return $callback();
        } finally {
            $mutex->release($mutexName);
        }
    }
}
