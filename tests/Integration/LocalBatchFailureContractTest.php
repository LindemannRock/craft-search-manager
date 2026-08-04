<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\backends\RedisBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Event;
use yii\mutex\Mutex;

/**
 * Regression coverage for local batch failure local batch failure truth.
 *
 * @since 5.54.0
 */
final class LocalBatchFailureContractTest extends TestCase
{
    private const FIXED_ERROR = 'Local document indexing failed.';
    private const INDEX_PREFIX = '__sm_pr159_';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeTestIndices();
        $this->deleteBatchQueueRows();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeTestIndices();
            $this->deleteBatchQueueRows();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return iterable<string, array{list<bool>, string}>
     */
    public static function mixedOrderProvider(): iterable
    {
        yield 'failure then success' => [[false, true], '101_1'];
        yield 'success then failure' => [[true, false], '202_1'];
    }

    /**
     * @param list<bool> $mutexOutcomes
     */
    #[DataProvider('mixedOrderProvider')]
    public function testMixedLocalBatchReturnsFalse(array $mutexOutcomes, string $failedBackendId): void
    {
        [$result] = $this->runLocalBatch($mutexOutcomes);

        self::assertFalse($result, "Mixed local batch with failed {$failedBackendId} must report aggregate failure.");
    }

    /**
     * @param list<bool> $mutexOutcomes
     */
    #[DataProvider('mixedOrderProvider')]
    public function testMixedLocalBatchRecordsOnlySafeFailedIdentity(array $mutexOutcomes, string $failedBackendId): void
    {
        [, $failures] = $this->runLocalBatch($mutexOutcomes);

        self::assertSame([[
            'backendId' => $failedBackendId,
            'elementId' => $failedBackendId === '101_1' ? 101 : 202,
            'title' => null,
            'error' => self::FIXED_ERROR,
        ]], $failures);
        $serialized = serialize($failures);
        foreach ([
            'PRIVATE_TITLE_SENTINEL',
            'PRIVATE_CONTENT_SENTINEL',
            'PRIVATE_ERROR_SENTINEL',
            'PRIVATE_TRACE_SENTINEL',
        ] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $serialized);
        }
    }

    public function testAllSuccessAllFailureEmptyAndConsecutiveCallsKeepExactTruth(): void
    {
        $backend = $this->localBackend();
        $items = $this->documents();

        $allSuccess = $this->withMutexOutcomes(
            [true, true],
            static fn(): bool => $backend->batchIndex('pr159-local', $items),
        );
        self::assertTrue($allSuccess);
        self::assertSame([], $backend->getLastIndexingFailures());

        $allFailed = $this->withMutexOutcomes(
            [false, false],
            static fn(): bool => $backend->batchIndex('pr159-local', $items),
        );
        self::assertFalse($allFailed);
        self::assertSame(['101_1', '202_1'], array_column($backend->getLastIndexingFailures(), 'backendId'));

        $secondSuccess = $this->withMutexOutcomes(
            [true, true],
            static fn(): bool => $backend->batchIndex('pr159-local', $items),
        );
        self::assertTrue($secondSuccess);
        self::assertSame([], $backend->getLastIndexingFailures(), 'A later batch must not inherit stale failures.');

        self::assertFalse($backend->batchIndex('pr159-local', []));
        self::assertSame([], $backend->getLastIndexingFailures());
    }

    public function testFourLocalBackendsShareContractAndHostedOverridesRemainSeparate(): void
    {
        foreach ([MySqlBackend::class, PostgreSqlBackend::class, RedisBackend::class, FileBackend::class] as $backendClass) {
            self::assertSame(
                AbstractSearchEngineBackend::class,
                (new \ReflectionMethod($backendClass, 'batchIndex'))->getDeclaringClass()->getName(),
            );
        }

        foreach ([AlgoliaBackend::class, MeilisearchBackend::class, TypesenseBackend::class] as $backendClass) {
            self::assertSame(
                $backendClass,
                (new \ReflectionMethod($backendClass, 'batchIndex'))->getDeclaringClass()->getName(),
            );
        }
    }

    public function testOrdinaryMetadataFailureContinuesSiblingsAndKeepsDiagnosticsSafe(): void
    {
        $storage = new LocalFailureThrowingMetadataStorage('pr159-metadata-failure', $this->trackedStorageAlias());
        $backend = new LocalFailureLocalBackend($storage);
        $mutex = new LocalFailureSequenceMutex([true, true]);

        $result = $this->withMutex(
            $mutex,
            fn(): bool => $backend->batchIndex('pr159-local', $this->documents()),
        );

        self::assertFalse($result);
        self::assertSame(2, $mutex->acquireCalls, 'A safe sibling must still be attempted after metadata storage fails.');
        self::assertSame(['101_1', '202_1'], array_column($backend->getLastIndexingFailures(), 'backendId'));
        $serialized = serialize($backend->getLastIndexingFailures());
        foreach ([
            'PRIVATE_TITLE_SENTINEL',
            'PRIVATE_CONTENT_SENTINEL',
            'PRIVATE_ERROR_SENTINEL',
            'PRIVATE_TRACE_SENTINEL',
        ] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $serialized);
        }
    }

    public function testAcceptedSiblingDocumentsAndElementsAreCountedWhenAggregateIsFalse(): void
    {
        [$index, $elements] = $this->workingIndexAndTwoElements();
        $backend = $this->localBackend();
        $service = new LocalFailureBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);

        $result = $this->withMutexOutcomes(
            [false, true],
            static fn(): bool => SearchManager::$plugin->indexing->batchIndex($elements, $index->handle),
        );
        $batchResult = SearchManager::$plugin->indexing->getLastBatchResult();

        self::assertFalse($result);
        self::assertSame(1, $batchResult['acceptedDocumentCount']);
        self::assertSame(1, $batchResult['acceptedElementCount']);
        self::assertSame(
            [(int)$elements[0]->id],
            array_column($batchResult['backendFailures'], 'elementId'),
        );
    }

    public function testWholeFailureGroupSkipsSuccessEffectsAndReplaysSiblingSafely(): void
    {
        [$index, $elements] = $this->workingIndexAndTwoElements();
        $backend = $this->localBackend();
        $service = new LocalFailureBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->queueExactRows($index, $elements);
        $beforeStats = $this->fetchSearchIndexStatsByHandle($index->handle);
        $afterEvents = 0;
        $handler = static function(IndexEvent $event) use (&$afterEvents, $index): void {
            if ($event->indexHandle === $index->handle) {
                $afterEvents++;
            }
        };
        Event::on(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $handler);

        try {
            $this->withMutexOutcomes(
                [false, true],
                static function(): void {
                    (new BatchSyncJob())->execute(Craft::$app->getQueue());
                },
            );
        } finally {
            Event::off(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $handler);
        }

        $rows = $this->pendingRowsFor($index->handle);
        self::assertCount(2, $rows, 'The complete contributing group must retain its retry markers.');
        self::assertSame(['failed'], array_values(array_unique(array_column($rows, 'status'))));
        self::assertSame(0, $afterEvents);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertSame(0, $service->documentCountCalls);
        self::assertSame($beforeStats, $this->fetchSearchIndexStatsByHandle($index->handle));
        self::assertCount(1, $service->batchCalls);

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_pending_syncs}}',
            ['nextAttemptAt' => Db::prepareDateForDb((new \DateTimeImmutable())->modify('-1 second'))],
            ['indexHandle' => $index->handle],
        )->execute();

        $this->withMutexOutcomes(
            [true, true],
            static function(): void {
                (new BatchSyncJob())->execute(Craft::$app->getQueue());
            },
        );

        self::assertSame([], $this->pendingRowsFor($index->handle));
        self::assertCount(2, $service->batchCalls);
        self::assertSame(
            array_column($service->batchCalls[0], 'backendId'),
            array_column($service->batchCalls[1], 'backendId'),
            'Successful siblings must be safe to replay idempotently with the whole group.',
        );
    }

    public function testSplitParentMixedFailureRetainsMarkerAndSkipsOrphanCleanup(): void
    {
        $entry = $this->splitEntry();
        $index = $this->insertSplitIndex((int)$entry->siteId);
        $storage = new FileStorage(
            'pr159-split-storage',
            $this->trackedStorageAlias(),
        );
        $service = new LocalFailureBackendService(new LocalFailureLocalBackend($storage));
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->queueExactRows($index, [$entry]);
        $mutex = new LocalFailureSequenceMutex([false]);

        $this->withMutex(
            $mutex,
            static function(): void {
                (new BatchSyncJob())->execute(Craft::$app->getQueue());
            },
        );

        self::assertGreaterThan(1, $mutex->acquireCalls, 'The split parent must produce a failed section plus safe siblings.');
        $row = $this->fetchPendingRow($index->handle, (int)$entry->id, (int)$entry->siteId);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(0, $service->orphanDeleteCalls);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertNull($this->fetchSearchIndexStatsByHandle($index->handle)['lastIndexed'] ?? null);
    }

    public function testAcceptedSplitSiblingsExcludeIncompleteParentFromCount(): void
    {
        $entry = $this->splitEntry();
        $index = $this->insertSplitIndex((int)$entry->siteId);
        $service = new LocalFailureBackendService(new LocalFailureLocalBackend(new FileStorage(
            'pr159-split-accounting',
            $this->trackedStorageAlias(),
        )));
        $this->swapPluginComponent('search-manager', 'backend', $service);

        $result = $this->withMutexOutcomes(
            [false],
            static fn(): bool => SearchManager::$plugin->indexing->batchIndex([$entry], $index->handle),
        );
        $batchResult = SearchManager::$plugin->indexing->getLastBatchResult();
        $documentCount = count($service->batchCalls[0] ?? []);

        self::assertFalse($result);
        self::assertGreaterThan(1, $documentCount);
        self::assertSame($documentCount - 1, $batchResult['acceptedDocumentCount']);
        self::assertSame(0, $batchResult['acceptedElementCount']);
        self::assertCount(1, $batchResult['backendFailures']);
    }

    /**
     * @param list<bool> $mutexOutcomes
     * @return array{bool, list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>}
     */
    private function runLocalBatch(array $mutexOutcomes): array
    {
        $backend = $this->localBackend();
        $result = $this->withMutexOutcomes(
            $mutexOutcomes,
            fn(): bool => $backend->batchIndex('pr159-local', $this->documents()),
        );

        return [$result, $backend->getLastIndexingFailures()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(): array
    {
        return [
            [
                'elementId' => 101,
                'siteId' => 1,
                'backendId' => '101_1',
                'title' => 'PRIVATE_TITLE_SENTINEL',
                'content' => 'PRIVATE_CONTENT_SENTINEL',
                'privateError' => 'PRIVATE_ERROR_SENTINEL',
                'privateTrace' => 'PRIVATE_TRACE_SENTINEL',
                'type' => 'entry',
            ],
            [
                'elementId' => 202,
                'siteId' => 1,
                'backendId' => '202_1',
                'title' => 'Safe sibling',
                'content' => 'Safe sibling content',
                'type' => 'entry',
            ],
        ];
    }

    private function localBackend(): LocalFailureLocalBackend
    {
        return new LocalFailureLocalBackend(new RecordingStorage([], [], [], 0, 0.0));
    }

    /**
     * @param list<bool> $outcomes
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withMutexOutcomes(array $outcomes, callable $callback): mixed
    {
        return $this->withMutex(new LocalFailureSequenceMutex($outcomes), $callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withMutex(LocalFailureSequenceMutex $mutex, callable $callback): mixed
    {
        $original = Craft::$app->get('mutex');
        Craft::$app->set('mutex', $mutex);

        try {
            return $callback();
        } finally {
            Craft::$app->set('mutex', $original);
        }
    }

    /**
     * @return array{SearchIndex, list<Entry>}
     */
    private function workingIndexAndTwoElements(): array
    {
        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        foreach (SearchIndex::findAll() as $index) {
            if (
                !$index->enabled
                || $index->usesSplitSections()
                || $index->elementType !== Entry::class
                || !($catalogue[$index->handle]['referenceable'] ?? false)
            ) {
                continue;
            }

            $siteId = (int)(($index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds())[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }

            $matches = [];
            foreach (Entry::find()
                ->siteId($siteId)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->andWhere(['entries.primaryOwnerId' => null])
                ->limit(50)
                ->all() as $entry) {
                if ($index->matchesElement($entry)) {
                    $matches[] = $entry;
                }
                if (count($matches) === 2) {
                    return [$index, $matches];
                }
            }
        }

        self::markTestSkipped('Requires an available non-split Entry index with two matching entries.');
    }

    /**
     * @param list<Entry> $elements
     */
    private function queueExactRows(SearchIndex $index, array $elements): void
    {
        $rows = [];
        foreach ($elements as $element) {
            $rows[] = [
                'indexHandle' => $index->handle,
                'elementType' => Entry::class,
                'elementId' => (int)$element->id,
                'siteId' => (int)$element->siteId,
                'op' => PendingSyncRepository::OP_UPSERT,
            ];
        }
        $this->repository->upsertRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingRowsFor(string $indexHandle): array
    {
        return (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['indexHandle' => $indexHandle])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function insertSplitIndex(int $siteId): SearchIndex
    {
        $handle = self::INDEX_PREFIX . 'split';
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'local batch failure Split Index',
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => json_encode([2, 3], JSON_THROW_ON_ERROR),
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 1,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);

        return $index;
    }

    private function splitEntry(): Entry
    {
        $fixture = $this->findRichTextFixtureEntry();
        if ($fixture === null) {
            self::markTestSkipped('Requires the package-owned deterministic rich-text entry fixture.');
        }

        return $fixture[0];
    }

    private function purgeTestIndices(): void
    {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => self::INDEX_PREFIX . 'split'])
            ->column();
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])
                ->execute();
        }
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_PREFIX . 'split'])
            ->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function deleteBatchQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()->delete($this->queueTable(), [
            'and',
            ['like', 'job', 'searchmanager'],
            ['like', 'job', 'BatchSyncJob'],
        ])->execute();
    }

    private function trackedStorageAlias(): string
    {
        $alias = '@storage/runtime/search-manager-pr159-' . bin2hex(random_bytes(8));
        $path = Craft::getAlias($alias);
        FileHelper::createDirectory($path);
        $this->trackTempPath($path);

        return $alias;
    }
}

/**
 * Shared-local test adapter that exercises the production abstract batch implementation.
 *
 * @since 5.54.0
 */
final class LocalFailureLocalBackend extends AbstractSearchEngineBackend
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return $this->storage;
    }

    protected function getBackendLabel(): string
    {
        return 'local batch failure local test';
    }

    public function getName(): string
    {
        return 'pr159-local';
    }

    public function index(string $indexName, array $data): bool
    {
        return false;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }
}

/**
 * Backend service recorder that preserves the production BaseBackend failure channel.
 *
 * @since 5.54.0
 */
final class LocalFailureBackendService extends BackendService
{
    /** @var list<list<array<string, mixed>>> */
    public array $batchCalls = [];
    public int $clearSearchCacheCalls = 0;
    public int $documentCountCalls = 0;
    public int $orphanDeleteCalls = 0;

    public function __construct(private readonly LocalFailureLocalBackend $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        $this->batchCalls[] = $items;

        return parent::batchIndex($indexName, $items);
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->clearSearchCacheCalls++;
    }

    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int
    {
        $this->documentCountCalls++;

        return count($this->batchCalls[array_key_last($this->batchCalls)] ?? []);
    }

    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int
    {
        return $this->getDocumentCount($indexName, $siteId);
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        $this->orphanDeleteCalls++;

        return parent::deleteOrphanDocuments($indexName, $elementId, $siteId, $keepBackendIds);
    }
}

/**
 * Supported local metadata-storage failure boundary with a private exception marker.
 *
 * @since 5.54.0
 */
final class LocalFailureThrowingMetadataStorage extends FileStorage
{
    public function storeElementByKey(
        int $siteId,
        int $elementId,
        string $documentKey,
        string $title,
        string $elementType,
        ?string $documentData = null,
    ): void {
        throw new \RuntimeException('PRIVATE_ERROR_SENTINEL PRIVATE_TRACE_SENTINEL');
    }
}

/**
 * Deterministic ordinary mutex-acquisition outcomes for local indexing.
 *
 * @since 5.54.0
 */
final class LocalFailureSequenceMutex extends Mutex
{
    public int $acquireCalls = 0;

    /**
     * @param list<bool> $outcomes
     */
    public function __construct(private array $outcomes)
    {
        parent::__construct(['autoRelease' => false]);
    }

    protected function acquireLock($name, $timeout = 0): bool
    {
        $this->acquireCalls++;

        return array_shift($this->outcomes) ?? true;
    }

    protected function releaseLock($name): bool
    {
        return true;
    }
}
