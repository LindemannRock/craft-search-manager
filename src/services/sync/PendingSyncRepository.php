<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services\sync;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\queue\Queue;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;
use yii\db\Expression;

/**
 * Pending Sync Repository
 *
 * Persistence layer for pending element sync rows.
 *
 * @since 5.45.0
 */
class PendingSyncRepository extends Component
{
    use LoggingTrait;

    public const OP_UPSERT = 'upsert';
    public const OP_DELETE = 'delete';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ABANDONED = 'abandoned';

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function queueForElement(ElementInterface $element, string $op): int
    {
        if (!in_array($op, [self::OP_UPSERT, self::OP_DELETE], true)) {
            throw new \InvalidArgumentException("Unsupported pending sync op: {$op}");
        }

        if (!$element->id) {
            return 0;
        }

        if ($op === self::OP_UPSERT && ($element->getIsDraft() || $element->getIsRevision())) {
            return 0;
        }

        $elementClass = get_class($element);
        $rows = [];

        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== $elementClass) {
                continue;
            }

            // Queue one row per applicable site. Structural filtering (element
            // type + site applicability) is the only check at queue time — we
            // deliberately do NOT probe the backend or evaluate criteria here.
            // The processor performs full status/criteria resolution when the
            // batch runs, and resolves the row's effective op (upsert vs
            // delete) at that point. See PendingSyncProcessor's class docblock
            // for the read-before-write rationale.
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            foreach ($siteIds as $siteId) {
                $rows[] = [
                    'indexHandle' => $index->handle,
                    'elementType' => $elementClass,
                    'elementId' => (int)$element->id,
                    'siteId' => (int)$siteId,
                    'op' => $op,
                ];
            }
        }

        $queued = $this->upsertRows($rows);
        if ($queued > 0) {
            $this->scheduleBatchJob();
        }

        return $queued;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int Number of rows submitted for upsert. This includes both new
     * inserts and updates to existing pending rows.
     */
    public function upsertRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $submitted = 0;

        foreach ($rows as $row) {
            $data = [
                'indexHandle' => (string)$row['indexHandle'],
                'elementType' => (string)$row['elementType'],
                'elementId' => (int)$row['elementId'],
                'siteId' => (int)$row['siteId'],
                'op' => (string)$row['op'],
                'status' => self::STATUS_PENDING,
                'attemptCount' => 0,
                'queuedAt' => $now,
                'nextAttemptAt' => $now,
                'claimedAt' => null,
                'claimToken' => null,
                'lastError' => null,
                'lastProcessedAt' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ];

            $db->createCommand()
                ->upsert(
                    '{{%searchmanager_pending_syncs}}',
                    $data,
                    [
                        'elementType' => $data['elementType'],
                        'op' => $data['op'],
                        'status' => self::STATUS_PENDING,
                        'attemptCount' => 0,
                        'queuedAt' => $now,
                        'nextAttemptAt' => $now,
                        'claimedAt' => null,
                        'claimToken' => null,
                        'lastError' => null,
                        'lastProcessedAt' => null,
                        'dateUpdated' => $now,
                    ]
                )
                ->execute();
            $submitted++;
        }

        return $submitted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function claim(int $limit, int $claimTtlSeconds): array
    {
        $limit = max(1, $limit);
        $now = new \DateTime();
        $nowDb = Db::prepareDateForDb($now);
        $staleCutoffDb = Db::prepareDateForDb((clone $now)->modify('-' . max(60, $claimTtlSeconds) . ' seconds'));

        $eligibleCondition = $this->eligibleClaimCondition($nowDb, $staleCutoffDb);

        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_pending_syncs}}')
            ->where($eligibleCondition)
            ->orderBy(['queuedAt' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column();

        if (empty($ids)) {
            return [];
        }

        $ids = array_map('intval', $ids);
        $claimToken = StringHelper::UUID();

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%searchmanager_pending_syncs}}',
                [
                    'status' => self::STATUS_PROCESSING,
                    'attemptCount' => new Expression('[[attemptCount]] + 1'),
                    'claimedAt' => $nowDb,
                    'claimToken' => $claimToken,
                    'dateUpdated' => $nowDb,
                ],
                [
                    'and',
                    ['id' => $ids],
                    $eligibleCondition,
                ]
            )
            ->execute();

        return (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['claimToken' => $claimToken])
            ->orderBy(['queuedAt' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
    }

    /**
     * @param int[] $ids
     */
    public function markSucceeded(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', ['id' => array_map('intval', $ids)])
            ->execute();
    }

    /**
     * @param int[] $ids
     */
    public function markRetry(array $ids, string $error, int $maxAttempts, int $flushInterval): void
    {
        if (empty($ids)) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'attemptCount'])
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['id' => array_map('intval', $ids)])
            ->all();

        $retryIds = [];
        $abandonIds = [];
        foreach ($rows as $row) {
            if ((int)$row['attemptCount'] >= $maxAttempts) {
                $abandonIds[] = (int)$row['id'];
            } else {
                $retryIds[] = (int)$row['id'];
            }
        }

        if (!empty($retryIds)) {
            $retryRows = array_filter(
                $rows,
                static fn(array $row): bool => in_array((int)$row['id'], $retryIds, true)
            );
            $attempt = max(array_map(static fn(array $row): int => (int)$row['attemptCount'], $retryRows));
            $delay = min(300, max(1, $flushInterval) * (2 ** max(0, $attempt - 1)));
            $nextAttemptAt = Db::prepareDateForDb((new \DateTime())->modify("+{$delay} seconds"));
            $nowDb = Db::prepareDateForDb(new \DateTime());

            Craft::$app->getDb()
                ->createCommand()
                ->update(
                    '{{%searchmanager_pending_syncs}}',
                    [
                        'status' => self::STATUS_FAILED,
                        'nextAttemptAt' => $nextAttemptAt,
                        'claimToken' => null,
                        'lastError' => mb_substr($error, 0, 2000),
                        'dateUpdated' => $nowDb,
                    ],
                    ['id' => $retryIds]
                )
                ->execute();
        }

        $this->markAbandoned($abandonIds, $error);
    }

    /**
     * @param int[] $ids
     */
    public function markAbandoned(array $ids, string $error): void
    {
        if (empty($ids)) {
            return;
        }

        $nowDb = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%searchmanager_pending_syncs}}',
                [
                    'status' => self::STATUS_ABANDONED,
                    'claimToken' => null,
                    'lastError' => mb_substr($error, 0, 2000),
                    'lastProcessedAt' => $nowDb,
                    'dateUpdated' => $nowDb,
                ],
                ['id' => array_map('intval', $ids)]
            )
            ->execute();
    }

    public function purgeOld(int $maxAgeSeconds): int
    {
        $cutoff = Db::prepareDateForDb((new \DateTime())->modify('-' . max(60, $maxAgeSeconds) . ' seconds'));

        return Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', [
                'and',
                ['status' => self::STATUS_ABANDONED],
                ['<', 'lastProcessedAt', $cutoff],
            ])
            ->execute();
    }

    public function hasDueRows(): bool
    {
        $nowDb = Db::prepareDateForDb(new \DateTime());

        return (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where([
                'and',
                ['status' => [self::STATUS_PENDING, self::STATUS_FAILED]],
                ['<=', 'nextAttemptAt', $nowDb],
            ])
            ->exists();
    }

    public function scheduleBatchJob(bool $force = false): void
    {
        $queue = Craft::$app->queue;

        if (!$force && $this->hasExistingDbQueueBatchJob($queue)) {
            return;
        }

        $delay = max(0, SearchManager::$plugin->getSettings()->batchFlushInterval);
        $queue->delay($delay)->push(new BatchSyncJob());
    }

    private function hasExistingDbQueueBatchJob(mixed $queue): bool
    {
        if (!$queue instanceof Queue) {
            return false;
        }

        $schema = Craft::$app->getDb()->getSchema();
        $tableSchema = $schema->getTableSchema($queue->tableName);
        if ($tableSchema === null) {
            return false;
        }

        foreach (['job', 'fail', 'timeUpdated'] as $column) {
            if (!isset($tableSchema->columns[$column])) {
                $this->logWarning('Skipping BatchSyncJob queue dedupe because the DB queue schema is not compatible', [
                    'queue' => get_class($queue),
                    'table' => $queue->tableName,
                    'missing_column' => $column,
                ]);
                return false;
            }
        }

        return (new Query())
            ->from($queue->tableName)
            ->where(['like', 'job', 'BatchSyncJob'])
            ->andWhere(['like', 'job', 'searchmanager'])
            ->andWhere(['fail' => false])
            ->andWhere(['timeUpdated' => null])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $counts = (new Query())
            ->select(['status', 'count' => 'COUNT(*)'])
            ->from('{{%searchmanager_pending_syncs}}')
            ->groupBy(['status'])
            ->all();

        return [
            'counts' => $counts,
            'oldestPending' => (new Query())
                ->select(['queuedAt'])
                ->from('{{%searchmanager_pending_syncs}}')
                ->where(['status' => [self::STATUS_PENDING, self::STATUS_FAILED]])
                ->orderBy(['queuedAt' => SORT_ASC])
                ->scalar(),
        ];
    }

    /**
     * Seconds a `processing` row's `claimedAt` must exceed before it is
     * considered stale (the row is up for re-claim by another worker, so the
     * CP can safely offer Retry/Delete without racing an active worker).
     *
     * Mirrors `BatchSyncJob::execute()`'s claim-TTL formula so all callers
     * agree on what "stale" means.
     */
    public function getStaleCutoffSeconds(): int
    {
        return max(300, SearchManager::$plugin->getSettings()->batchFlushInterval * 6);
    }

    /**
     * Page through pending-sync rows for the CP Pending Syncs view.
     *
     * Filters are all optional; an empty `$filters` returns the full table.
     * Sorting is a fixed allowlist so we never interpolate user input into
     * ORDER BY. Default sort surfaces the riskiest rows first: failed/abandoned
     * over pending/processing, then oldest queued.
     *
     * @param array{
     *     status?: string,
     *     indexHandle?: string,
     *     op?: string,
     *     siteId?: int,
     *     search?: string,
     *     stuck?: bool,
     * } $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, string $sort, string $dir, int $limit, int $offset): array
    {
        $condition = $this->buildSearchCondition($filters);

        $orderBy = $this->buildSearchOrderBy($sort, $dir);

        $query = (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where($condition);

        $total = (int) (clone $query)->count();

        $rows = $query
            ->orderBy($orderBy)
            ->limit(max(1, $limit))
            ->offset(max(0, $offset))
            ->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Reset rows back to pending: clears attemptCount, claim metadata, last
     * error, and forces nextAttemptAt = now so the next BatchSyncJob picks
     * them up immediately.
     *
     * Only `failed` and `abandoned` rows are valid retry targets:
     *   - `pending` rows are already in the queue; resetting is a no-op.
     *   - `processing` rows are in flight (or stale, in which case the next
     *     `claim()` will re-pick them anyway — retry is meaningless).
     *
     * Returns the number of rows actually updated; rows whose status is
     * outside the eligible set are silently skipped.
     *
     * @param int[] $ids
     */
    public function retry(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $nowDb = Db::prepareDateForDb(new \DateTime());

        return Craft::$app->getDb()
            ->createCommand()
            ->update(
                '{{%searchmanager_pending_syncs}}',
                [
                    'status' => self::STATUS_PENDING,
                    'attemptCount' => 0,
                    'nextAttemptAt' => $nowDb,
                    'claimedAt' => null,
                    'claimToken' => null,
                    'lastError' => null,
                    'lastProcessedAt' => null,
                    'dateUpdated' => $nowDb,
                ],
                [
                    'and',
                    ['id' => $ids],
                    ['status' => [self::STATUS_FAILED, self::STATUS_ABANDONED]],
                ],
            )
            ->execute();
    }

    /**
     * Hard-delete rows from the buffer by id. Skips `processing` rows whose
     * `claimedAt` is fresher than the stale cutoff — see `retry()`.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_map('intval', $ids);
        $staleCutoffDb = Db::prepareDateForDb(
            (new \DateTime())->modify('-' . $this->getStaleCutoffSeconds() . ' seconds'),
        );

        return Craft::$app->getDb()
            ->createCommand()
            ->delete(
                '{{%searchmanager_pending_syncs}}',
                [
                    'and',
                    ['id' => $ids],
                    [
                        'or',
                        ['!=', 'status', self::STATUS_PROCESSING],
                        ['<', 'claimedAt', $staleCutoffDb],
                    ],
                ],
            )
            ->execute();
    }

    /**
     * Delete every row at a given status.
     */
    public function purgeByStatus(string $status): int
    {
        if (!in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_FAILED,
            self::STATUS_ABANDONED,
        ], true)) {
            throw new \InvalidArgumentException("Unsupported pending sync status: {$status}");
        }

        return Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', ['status' => $status])
            ->execute();
    }

    /**
     * @param array{
     *     status?: string,
     *     indexHandle?: string,
     *     op?: string,
     *     siteId?: int,
     *     search?: string,
     *     stuck?: bool,
     * } $filters
     *
     * @return array<mixed>
     */
    private function buildSearchCondition(array $filters): array
    {
        $where = ['and'];

        if (!empty($filters['status'])) {
            $where[] = ['status' => $filters['status']];
        }
        if (!empty($filters['indexHandle'])) {
            $where[] = ['indexHandle' => $filters['indexHandle']];
        }
        if (!empty($filters['op'])) {
            $where[] = ['op' => $filters['op']];
        }
        if (!empty($filters['siteId'])) {
            $where[] = ['siteId' => (int) $filters['siteId']];
        }
        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $orParts = [
                'or',
                ['like', 'lastError', $search],
            ];
            if (ctype_digit($search)) {
                $orParts[] = ['elementId' => (int) $search];
            }
            $where[] = $orParts;
        }
        if (!empty($filters['stuck'])) {
            $nowDb = Db::prepareDateForDb(new \DateTime());
            $staleCutoffDb = Db::prepareDateForDb(
                (new \DateTime())->modify('-' . $this->getStaleCutoffSeconds() . ' seconds'),
            );
            // "Stuck" = rows where action is overdue: pending/failed that should
            // already have been retried, or processing rows past stale cutoff.
            $where[] = [
                'or',
                [
                    'and',
                    ['status' => [self::STATUS_PENDING, self::STATUS_FAILED]],
                    ['<=', 'nextAttemptAt', $nowDb],
                ],
                [
                    'and',
                    ['status' => self::STATUS_PROCESSING],
                    ['<', 'claimedAt', $staleCutoffDb],
                ],
            ];
        }

        return $where;
    }

    /**
     * @return array<string, int>
     */
    private function buildSearchOrderBy(string $sort, string $dir): array
    {
        $dirConst = strtolower($dir) === 'desc' ? SORT_DESC : SORT_ASC;
        $allowed = ['queuedAt', 'attemptCount', 'status', 'nextAttemptAt', 'indexHandle', 'op'];

        if (!in_array($sort, $allowed, true)) {
            // Default: oldest queued first — surfaces rows that have been
            // sitting longest, which are the riskiest in operator terms.
            return ['queuedAt' => SORT_ASC, 'id' => SORT_ASC];
        }

        return [$sort => $dirConst, 'id' => SORT_ASC];
    }

    /**
     * @return array<mixed>
     */
    private function eligibleClaimCondition(string $nowDb, string $staleCutoffDb): array
    {
        return [
            'or',
            [
                'and',
                ['status' => [self::STATUS_PENDING, self::STATUS_FAILED]],
                ['<=', 'nextAttemptAt', $nowDb],
            ],
            [
                'and',
                ['status' => self::STATUS_PROCESSING],
                ['<', 'claimedAt', $staleCutoffDb],
            ],
        ];
    }
}
