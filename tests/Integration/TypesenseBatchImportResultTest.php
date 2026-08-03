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
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use yii\base\Event;
use yii\log\Logger;

/**
 * Regression coverage for Typesense import Typesense import-result truth.
 *
 * @since 5.54.0
 */
final class TypesenseBatchImportResultTest extends TestCase
{
    private const FIXED_ERROR = 'Typesense import failed.';
    private const INDEX_HANDLE = '__sm_pr168_typesense';
    private const SPLIT_INDEX_HANDLE = '__sm_pr168_typesense_split';
    private const SENTINELS = [
        'PRIVATE_TITLE_SENTINEL',
        'PRIVATE_FIELD_SENTINEL',
        'PRIVATE_CONTENT_SENTINEL',
        'PRIVATE_PROVIDER_ERROR_SENTINEL',
        'PRIVATE_PROVIDER_DETAIL_SENTINEL',
        'PRIVATE_PROVIDER_TRACE_SENTINEL',
        'PRIVATE_PROVIDER_STACK_SENTINEL',
        'PRIVATE_API_KEY_SENTINEL',
        'PRIVATE_CREDENTIAL_SENTINEL',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeSplitIndex();
        $this->deleteBatchQueueRows();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeSplitIndex();
            $this->deleteBatchQueueRows();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return iterable<string, array{list<bool>, string, int}>
     */
    public static function mixedOrderProvider(): iterable
    {
        yield 'failure then success' => [[false, true], '101_1', 101];
        yield 'success then failure' => [[true, false], '202_1', 202];
    }

    /**
     * @param list<bool> $outcomes
     */
    #[DataProvider('mixedOrderProvider')]
    public function testOrderedArrayMapsFailureBySubmittedOrderWithoutSensitiveDiagnostics(
        array $outcomes,
        string $failedBackendId,
        int $failedElementId,
    ): void {
        [$backend] = $this->typesenseBackend($this->responseRows($outcomes));
        [$result, $loggedMessages] = $this->captureLoggerMessages(
            fn(): bool => $backend->batchIndex(self::INDEX_HANDLE, $this->documents()),
        );

        self::assertFalse($result);
        self::assertSame([[
            'backendId' => $failedBackendId,
            'elementId' => $failedElementId,
            'title' => null,
            'error' => self::FIXED_ERROR,
        ]], $backend->getLastIndexingFailures());
        $this->assertNoSensitiveSentinels(serialize($backend->getLastIndexingFailures()));
        $this->assertImportFailureLogIsAggregateAndSafe($loggedMessages);
    }

    public function testAllSuccessAllFailedEmptyAndConsecutiveCallsKeepExactTruth(): void
    {
        [$backend, $documents] = $this->typesenseBackend($this->responseRows([false, true]));

        self::assertFalse($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertCount(1, $backend->getLastIndexingFailures());

        $documents->response = $this->responseRows([true, true]);
        self::assertTrue($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertSame([], $backend->getLastIndexingFailures(), 'A later success must clear stale import failures.');

        $documents->response = $this->responseRows([false, false]);
        self::assertFalse($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertSame(['101_1', '202_1'], array_column($backend->getLastIndexingFailures(), 'backendId'));
        self::assertSame([null, null], array_column($backend->getLastIndexingFailures(), 'title'));
        self::assertSame([self::FIXED_ERROR, self::FIXED_ERROR], array_column($backend->getLastIndexingFailures(), 'error'));

        $documents->response = [];
        self::assertFalse($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertSame(['101_1', '202_1'], array_column($backend->getLastIndexingFailures(), 'backendId'));

        self::assertFalse($backend->batchIndex(self::INDEX_HANDLE, []));
        self::assertSame([], $backend->getLastIndexingFailures());
    }

    public function testCurrentlySupportedJsonlResponseRemainsCompatible(): void
    {
        $jsonl = implode("\r\n", array_map(
            static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $this->responseRows([true, false]),
        ));
        [$backend, $documents] = $this->typesenseBackend($jsonl);

        self::assertFalse($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertSame([[
            'backendId' => '202_1',
            'elementId' => 202,
            'title' => null,
            'error' => self::FIXED_ERROR,
        ]], $backend->getLastIndexingFailures());

        $documents->response = "{\"success\":true}\r\n{\"success\":true}";
        self::assertTrue($backend->batchIndex(self::INDEX_HANDLE, $this->documents()));
        self::assertSame([], $backend->getLastIndexingFailures());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function structurallyInvalidResponseProvider(): iterable
    {
        yield 'empty array' => [[]];
        yield 'empty string' => [''];
        yield 'non-response type' => [null];
        yield 'non-list array' => [[
            1 => ['success' => true],
            2 => ['success' => false, 'error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL'],
        ]];
        yield 'truncated list' => [[['success' => true]]];
        yield 'extra row' => [[
            ['success' => true],
            ['success' => false, 'error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL'],
            ['success' => true],
        ]];
        yield 'non-array row' => [[
            ['success' => true],
            'PRIVATE_PROVIDER_ERROR_SENTINEL',
        ]];
        yield 'missing success' => [[
            ['success' => true],
            ['error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL'],
        ]];
        yield 'non-boolean success' => [[
            ['success' => true],
            ['success' => 'false', 'error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL'],
        ]];
        yield 'invalid JSONL' => ["{\"success\":true}\nPRIVATE_PROVIDER_ERROR_SENTINEL"];
        yield 'blank JSONL row' => ["{\"success\":true}\n\n{\"success\":false}"];
    }

    #[DataProvider('structurallyInvalidResponseProvider')]
    public function testStructurallyInvalidResponseFailsEverySubmittedIdentity(mixed $response): void
    {
        [$backend] = $this->typesenseBackend($response);
        [$result, $loggedMessages] = $this->captureLoggerMessages(
            fn(): bool => $backend->batchIndex(self::INDEX_HANDLE, $this->documents()),
        );

        self::assertFalse($result);
        self::assertSame([
            [
                'backendId' => '101_1',
                'elementId' => 101,
                'title' => null,
                'error' => self::FIXED_ERROR,
            ],
            [
                'backendId' => '202_1',
                'elementId' => 202,
                'title' => null,
                'error' => self::FIXED_ERROR,
            ],
        ], $backend->getLastIndexingFailures());
        $this->assertNoSensitiveSentinels(serialize($backend->getLastIndexingFailures()));
        $this->assertImportFailureLogIsAggregateAndSafe($loggedMessages);
    }

    public function testLoggerCaptureIsolatesThresholdAndRestoresExactState(): void
    {
        $logger = Craft::getLogger();
        $initialMessages = $logger->messages;
        $initialFlushInterval = $logger->flushInterval;

        try {
            $logger->messages = [];
            $logger->flushInterval = 3;
            $logger->log('Typesense logger capture before one', Logger::LEVEL_INFO, 'pr168-test');
            $logger->log('Typesense logger capture before two', Logger::LEVEL_INFO, 'pr168-test');
            $adjacentThresholdMessages = $logger->messages;

            [$result, $captured] = $this->captureLoggerMessages(static function() use ($logger): string {
                $logger->log('Typesense batch import reported document failures', Logger::LEVEL_ERROR, 'pr168-test');

                return 'captured';
            });

            self::assertSame('captured', $result);
            self::assertCount(1, $captured);
            self::assertSame('Typesense batch import reported document failures', $captured[0][0] ?? null);
            self::assertSame($adjacentThresholdMessages, $logger->messages);
            self::assertSame(3, $logger->flushInterval);

            $expected = new \RuntimeException('Typesense logger capture capture exception');
            try {
                $this->captureLoggerMessages(static function() use ($logger, $expected): never {
                    $logger->log('Typesense logger capture exceptional capture', Logger::LEVEL_ERROR, 'pr168-test');
                    throw $expected;
                });
                self::fail('The capture callback exception was not rethrown.');
            } catch (\RuntimeException $caught) {
                self::assertSame($expected, $caught);
            }

            self::assertSame($adjacentThresholdMessages, $logger->messages);
            self::assertSame(3, $logger->flushInterval);
        } finally {
            $logger->messages = $initialMessages;
            $logger->flushInterval = $initialFlushInterval;
        }

        self::assertSame($initialMessages, $logger->messages);
        self::assertSame($initialFlushInterval, $logger->flushInterval);
    }

    public function testAcceptedTypesenseSiblingDocumentsAndElementsAreCounted(): void
    {
        [$index, $elements] = $this->workingIndexAndTwoElements();
        [$backend] = $this->typesenseBackend($this->firstDocumentFails(...));
        $service = new TypesenseImportBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);

        $result = SearchManager::$plugin->indexing->batchIndex($elements, $index->handle);
        $batchResult = SearchManager::$plugin->indexing->getLastBatchResult();

        self::assertFalse($result);
        self::assertSame(1, $batchResult['acceptedDocumentCount']);
        self::assertSame(1, $batchResult['acceptedElementCount']);
        self::assertCount(1, $batchResult['backendFailures']);
        self::assertSame(self::FIXED_ERROR, $batchResult['backendFailures'][0]['error']);
        self::assertNull($batchResult['backendFailures'][0]['title']);
    }

    public function testWholeTypesenseFailureGroupSuppressesSuccessEffects(): void
    {
        [$index, $elements] = $this->workingIndexAndTwoElements();
        [$backend] = $this->typesenseBackend($this->firstDocumentFails(...));
        $service = new TypesenseImportBackendService($backend);
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
            (new BatchSyncJob())->execute(Craft::$app->getQueue());
        } finally {
            Event::off(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $handler);
        }

        $rows = $this->pendingRowsFor($index->handle);
        self::assertCount(2, $rows);
        self::assertSame(['failed'], array_values(array_unique(array_column($rows, 'status'))));
        self::assertSame(0, $afterEvents);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertSame(0, $service->documentCountCalls);
        self::assertSame(0, $service->orphanDeleteCalls);
        self::assertSame($beforeStats, $this->fetchSearchIndexStatsByHandle($index->handle));
    }

    public function testSplitTypesenseFailurePreservesParentAndRebuildAccounting(): void
    {
        $entry = $this->splitEntry();
        $index = $this->insertSplitIndex((int)$entry->siteId);
        [$backend] = $this->typesenseBackend($this->firstDocumentFails(...));
        $service = new TypesenseImportBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);

        $result = SearchManager::$plugin->indexing->batchIndex([$entry], $index->handle);
        $batchResult = SearchManager::$plugin->indexing->getLastBatchResult();
        $documentCount = count($service->batchCalls[0] ?? []);

        self::assertFalse($result);
        self::assertGreaterThan(1, $documentCount);
        self::assertSame($documentCount - 1, $batchResult['acceptedDocumentCount']);
        self::assertSame(0, $batchResult['acceptedElementCount']);
        self::assertCount(1, $batchResult['backendFailures']);

        $this->queueExactRows($index, [$entry]);
        (new BatchSyncJob())->execute(Craft::$app->getQueue());

        $row = $this->fetchPendingRow($index->handle, (int)$entry->id, (int)$entry->siteId);
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame(0, $service->orphanDeleteCalls);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertNull($this->fetchSearchIndexStatsByHandle($index->handle)['lastIndexed'] ?? null);
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
                '_fields' => [
                    'privateField' => 'PRIVATE_FIELD_SENTINEL',
                    'apiKey' => 'PRIVATE_API_KEY_SENTINEL',
                ],
                'credential' => 'PRIVATE_CREDENTIAL_SENTINEL',
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

    /**
     * @param list<bool> $outcomes
     * @return list<array<string, mixed>>
     */
    private function responseRows(array $outcomes): array
    {
        return array_map(static fn(bool $success): array => $success
            ? ['success' => true]
            : [
                'success' => false,
                'error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL',
                'detail' => 'PRIVATE_PROVIDER_DETAIL_SENTINEL',
                'trace' => 'PRIVATE_PROVIDER_TRACE_SENTINEL',
                'stack' => 'PRIVATE_PROVIDER_STACK_SENTINEL',
                'document' => ['title' => 'PRIVATE_TITLE_SENTINEL'],
                'apiKey' => 'PRIVATE_API_KEY_SENTINEL',
                'credential' => 'PRIVATE_CREDENTIAL_SENTINEL',
            ], $outcomes);
    }

    /**
     * @param list<array<string, mixed>> $submitted
     * @return list<array{success: bool, error?: string}>
     */
    private function firstDocumentFails(array $submitted): array
    {
        return array_map(
            static fn(int $i): array => $i === 0
                ? ['success' => false, 'error' => 'PRIVATE_PROVIDER_ERROR_SENTINEL']
                : ['success' => true],
            array_keys($submitted),
        );
    }

    /**
     * @return array{TypesenseBackend, TypesenseImportDocuments}
     */
    private function typesenseBackend(mixed $response): array
    {
        $documents = new TypesenseImportDocuments($response);
        $collection = new TypesenseImportCollection($documents);
        $collections = new TypesenseImportCollections($collection);

        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $client->collections = $collections;

        $backend = new TypesenseBackend();
        (new \ReflectionProperty(TypesenseBackend::class, '_client'))->setValue($backend, $client);

        return [$backend, $documents];
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return array{T, array<mixed>}
     */
    private function captureLoggerMessages(callable $operation): array
    {
        $logger = Craft::getLogger();
        $messages = $logger->messages;
        $flushInterval = $logger->flushInterval;
        $logger->messages = [];
        $logger->flushInterval = 0;

        try {
            $result = $operation();

            return [$result, $logger->messages];
        } finally {
            $logger->messages = $messages;
            $logger->flushInterval = $flushInterval;
        }
    }

    /**
     * @param array<mixed> $loggedMessages
     */
    private function assertImportFailureLogIsAggregateAndSafe(array $loggedMessages): void
    {
        $logged = serialize($loggedMessages);
        self::assertStringContainsString('Typesense batch import reported document failures', $logged);
        $this->assertNoSensitiveSentinels($logged);
    }

    private function assertNoSensitiveSentinels(string $serialized): void
    {
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsString($sentinel, $serialized);
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
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Typesense import Typesense Split Index',
            'handle' => self::SPLIT_INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => json_encode([2, 3], JSON_THROW_ON_ERROR),
            'language' => null,
            'backend' => 'typesense',
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

        $index = SearchIndex::findByHandle(self::SPLIT_INDEX_HANDLE);
        self::assertNotNull($index);

        return $index;
    }

    private function splitEntry(): Entry
    {
        $entry = Entry::find()
            ->id(1087)
            ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
            ->status(null)
            ->one();
        if (!$entry instanceof Entry || !$entry->getFieldLayout()?->getFieldByHandle('richText')) {
            self::markTestSkipped('Requires lorem-ipsum entry 1087 with a richText field.');
        }

        return $entry;
    }

    private function purgeSplitIndex(): void
    {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => self::SPLIT_INDEX_HANDLE])
            ->column();
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])
                ->execute();
        }
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::SPLIT_INDEX_HANDLE])
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
}

/**
 * In-memory Typesense SDK documents resource.
 *
 * @since 5.54.0
 */
final class TypesenseImportDocuments extends Documents
{
    /** @var list<list<array<string, mixed>>> */
    public array $submissions = [];

    public function __construct(public mixed $response)
    {
    }

    public function import($documents, array $options = [])
    {
        /** @var list<array<string, mixed>> $documents */
        $this->submissions[] = $documents;

        return is_callable($this->response)
            ? ($this->response)($documents)
            : $this->response;
    }
}

/**
 * In-memory Typesense SDK collection resource.
 *
 * @since 5.54.0
 */
final class TypesenseImportCollection extends Collection
{
    public function __construct(Documents $documents)
    {
        $this->documents = $documents;
    }

    public function retrieve(): array
    {
        return ['name' => 'pr168-test'];
    }
}

/**
 * In-memory Typesense SDK collections resource.
 *
 * @since 5.54.0
 */
final class TypesenseImportCollections extends Collections
{
    public function __construct(private Collection $collection)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): Collection
    {
        return $this->collection;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value instanceof Collection) {
            $this->collection = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
    }
}

/**
 * Backend service recorder preserving the production Typesense failure channel.
 *
 * @since 5.54.0
 */
final class TypesenseImportBackendService extends BackendService
{
    /** @var list<list<array<string, mixed>>> */
    public array $batchCalls = [];
    public int $clearSearchCacheCalls = 0;
    public int $documentCountCalls = 0;
    public int $orphanDeleteCalls = 0;

    public function __construct(private readonly TypesenseBackend $backend)
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

    public function deleteOrphanDocuments(
        string $indexName,
        int $elementId,
        ?int $siteId,
        array $keepBackendIds,
    ): bool {
        $this->orphanDeleteCalls++;

        return true;
    }
}
