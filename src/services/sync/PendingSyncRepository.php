<?php

namespace lindemannrock\searchmanager\services\sync;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
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
     */
    public function upsertRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $count = 0;

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
            $count++;
        }

        return $count;
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
            $attempt = max(array_map(static fn(array $row): int => (int)$row['attemptCount'], $rows));
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

    public function scheduleBatchJob(): void
    {
        $existingJob = (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'BatchSyncJob'])
            ->andWhere(['like', 'job', 'searchmanager'])
            ->exists();

        if ($existingJob) {
            return;
        }

        $delay = max(0, SearchManager::$plugin->getSettings()->batchFlushInterval);
        Craft::$app->queue->delay($delay)->push(new BatchSyncJob());
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
