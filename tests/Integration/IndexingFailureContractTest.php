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
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\events\TransformEvent;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for PR1.54-PR1.56 indexing failure truth.
 *
 * @since 5.54.0
 */
final class IndexingFailureContractTest extends TestCase
{
    private const HANDLE_PREFIX = '__sm_a5_';

    protected function setUp(): void
    {
        parent::setUp();
        A5SelectiveTransformer::$failingElementIds = [];
        $this->purgeOwnedRows();
        $this->deleteBatchQueueRows();
    }

    protected function tearDown(): void
    {
        try {
            A5SelectiveTransformer::$failingElementIds = [];
            $this->purgeOwnedRows();
            $this->deleteBatchQueueRows();
        } finally {
            parent::tearDown();
        }
    }

    public function testDirectTransformFailureReturnsFalsePreservesStaleDocumentAndIndexesSibling(): void
    {
        $element = $this->workingEntry();
        $failed = $this->insertIndex(self::HANDLE_PREFIX . 'direct_failed', $element, A5SelectiveTransformer::class);
        $healthy = $this->insertIndex(self::HANDLE_PREFIX . 'direct_healthy', $element, A5HealthyTransformer::class);
        A5SelectiveTransformer::$failingElementIds = [(int)$element->id];

        $backend = $this->installStubBackend();
        $failedKey = $failed->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$failedKey] = true;

        $result = $this->withOnlySearchIndices(
            [$failed, $healthy],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertTrue($backend->existingDocuments[$failedKey]);
        self::assertCount(0, $this->callsForIndex($backend, 'indexWithResult', $failed->handle));
        self::assertCount(1, $this->callsForIndex($backend, 'indexWithResult', $healthy->handle));
    }

    public function testDirectConstructorFailureReturnsFalseWithoutReplacingStaleDocument(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'constructor', $element, A5ConstructorFailingTransformer::class);
        $backend = $this->installStubBackend();
        $key = $index->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$key] = true;

        $result = $this->withOnlySearchIndices(
            [$index],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertTrue($backend->existingDocuments[$key]);
        self::assertSame([], $backend->callsFor('indexWithResult'));
    }

    public function testDirectAfterTransformFailureAndNullDocumentReturnFalse(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'after_transform', $element, A5SelectiveTransformer::class);
        $backend = $this->installStubBackend();

        $throwing = static function(TransformEvent $event) use ($index): void {
            if ($event->indexName === $index->handle) {
                throw new \RuntimeException('Synthetic after-transform listener failure');
            }
        };
        SearchManager::$plugin->transformers->on(TransformerService::EVENT_AFTER_TRANSFORM, $throwing);
        try {
            self::assertFalse($this->withOnlySearchIndices(
                [$index],
                static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
            ));
        } finally {
            SearchManager::$plugin->transformers->off(TransformerService::EVENT_AFTER_TRANSFORM, $throwing);
        }

        $nulling = static function(TransformEvent $event) use ($index): void {
            if ($event->indexName === $index->handle) {
                $event->document = null;
            }
        };
        SearchManager::$plugin->transformers->on(TransformerService::EVENT_AFTER_TRANSFORM, $nulling);
        try {
            self::assertFalse($this->withOnlySearchIndices(
                [$index],
                static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
            ));
        } finally {
            SearchManager::$plugin->transformers->off(TransformerService::EVENT_AFTER_TRANSFORM, $nulling);
        }

        self::assertSame([], $backend->callsFor('indexWithResult'));
    }

    public function testIntentionalTransformSkipIsSuccessfulNoOpForDirectAndPendingConsumers(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'intentional_skip', $element, A5SelectiveTransformer::class);
        $backend = $this->installStubBackend();
        $handler = static function(TransformEvent $event) use ($index): void {
            if ($event->indexName === $index->handle) {
                $event->handled = true;
            }
        };
        SearchManager::$plugin->transformers->on(TransformerService::EVENT_BEFORE_TRANSFORM, $handler);

        try {
            self::assertTrue($this->withOnlySearchIndices(
                [$index],
                static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
            ));

            $this->repository->upsertRows([$this->pendingRow($index, $element)]);
            (new BatchSyncJob())->execute(Craft::$app->queue);
            self::assertNull($this->fetchPendingRow($index->handle, (int)$element->id, (int)$element->siteId));
        } finally {
            SearchManager::$plugin->transformers->off(TransformerService::EVENT_BEFORE_TRANSFORM, $handler);
        }

        self::assertSame([], $backend->callsFor('indexWithResult'));
        self::assertSame([], $backend->callsFor('batchIndex'));
    }

    public function testPendingTransformFailureRetriesThenAbandonsWithoutReplacingStaleDocument(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'pending_failed', $element, A5SelectiveTransformer::class);
        A5SelectiveTransformer::$failingElementIds = [(int)$element->id];
        $backend = $this->installStubBackend();
        $key = $index->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$key] = true;
        SearchManager::$plugin->getSettings()->batchMaxAttempts = 2;
        SearchManager::$plugin->getSettings()->batchFlushInterval = 1;
        $this->repository->upsertRows([$this->pendingRow($index, $element)]);

        (new BatchSyncJob())->execute(Craft::$app->queue);
        $failed = $this->fetchPendingRow($index->handle, (int)$element->id, (int)$element->siteId);
        self::assertNotNull($failed);
        self::assertSame('failed', $failed['status']);
        self::assertSame(1, (int)$failed['attemptCount']);
        self::assertNotEmpty($failed['lastError']);
        self::assertSame(1, $this->pendingBatchQueueCount());

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_pending_syncs}}',
            ['nextAttemptAt' => Db::prepareDateForDb((new \DateTimeImmutable())->modify('-1 second'))],
            ['id' => (int)$failed['id']],
        )->execute();
        (new BatchSyncJob())->execute(Craft::$app->queue);

        $abandoned = $this->fetchPendingRow($index->handle, (int)$element->id, (int)$element->siteId);
        self::assertNotNull($abandoned);
        self::assertSame('abandoned', $abandoned['status']);
        self::assertSame(2, (int)$abandoned['attemptCount']);
        self::assertTrue($backend->existingDocuments[$key]);
        self::assertSame([], $backend->callsFor('batchIndex'));
    }

    public function testPendingTransformFailureDoesNotBlockHealthySiblingIndex(): void
    {
        $element = $this->workingEntry();
        $failed = $this->insertIndex(self::HANDLE_PREFIX . 'pending_sibling_failed', $element, A5SelectiveTransformer::class);
        $healthy = $this->insertIndex(self::HANDLE_PREFIX . 'pending_sibling_healthy', $element, A5HealthyTransformer::class);
        A5SelectiveTransformer::$failingElementIds = [(int)$element->id];
        $backend = $this->installStubBackend();
        $this->repository->upsertRows([
            $this->pendingRow($failed, $element),
            $this->pendingRow($healthy, $element),
        ]);

        (new BatchSyncJob())->execute(Craft::$app->queue);

        $failedRow = $this->fetchPendingRow($failed->handle, (int)$element->id, (int)$element->siteId);
        self::assertNotNull($failedRow);
        self::assertSame('failed', $failedRow['status']);
        self::assertNull($this->fetchPendingRow($healthy->handle, (int)$element->id, (int)$element->siteId));
        self::assertCount(0, $this->callsForIndex($backend, 'batchIndex', $failed->handle));
        self::assertCount(1, $this->callsForIndex($backend, 'batchIndex', $healthy->handle));
    }

    public function testPageCleanupFalseReturnsFalseRetainsStaleDocumentAndSkipsReconciliation(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'cleanup_page', $element, A5SelectiveTransformer::class, false, 7);
        $index->criteria = static fn($query) => $query->andWhere('1=0');
        $backend = $this->installStubBackend();
        $backend->failDeleteIndices = [$index->handle];
        $key = $index->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$key] = true;

        $result = $this->withOnlySearchIndices(
            [$index],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertTrue($backend->existingDocuments[$key]);
        self::assertSame(7, SearchIndex::findByHandle($index->handle)?->documentCount);
        self::assertCount(0, $this->callsForIndex($backend, 'clearSearchCache', $index->handle));
    }

    public function testUrlMismatchCleanupFalseReturnsFalseAndRetainsStaleDocument(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'cleanup_url', $element, A5SelectiveTransformer::class, false, 5);
        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['skipEntriesWithoutUrl' => 1],
            ['id' => $index->id],
        )->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        $index = SearchIndex::findByHandle($index->handle);
        self::assertNotNull($index);

        $element->uri = null;
        self::assertTrue($index->shouldSkipElementWithoutUrl($element));

        $backend = $this->installStubBackend();
        $backend->failDeleteIndices = [$index->handle];
        $key = $index->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$key] = true;

        $result = $this->withOnlySearchIndices(
            [$index],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertTrue($backend->existingDocuments[$key]);
        self::assertSame(5, SearchIndex::findByHandle($index->handle)?->documentCount);
        self::assertCount(1, $this->callsForIndex($backend, 'deleteWithResult', $index->handle));
        self::assertCount(0, $this->callsForIndex($backend, 'clearSearchCache', $index->handle));
    }

    public function testSplitCleanupFalseReturnsFalseAndPreservesExistingCount(): void
    {
        $element = $this->workingEntry();
        $index = $this->insertIndex(self::HANDLE_PREFIX . 'cleanup_split', $element, '', true, 9);
        $index->criteria = static fn($query) => $query->andWhere('1=0');
        $backend = $this->installStubBackend();
        $backend->failBatchDeleteIndices = [$index->handle];
        $backend->documentCounts[$index->handle] = 9;

        $result = $this->withOnlySearchIndices(
            [$index],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertSame(9, $backend->documentCounts[$index->handle]);
        self::assertSame(9, SearchIndex::findByHandle($index->handle)?->documentCount);
        self::assertCount(0, $this->callsForIndex($backend, 'clearSearchCache', $index->handle));
    }

    public function testThrownCleanupReturnsFalseAndSuccessfulSiblingStillReconciles(): void
    {
        $element = $this->workingEntry();
        $throwing = $this->insertIndex(self::HANDLE_PREFIX . 'cleanup_throw', $element, A5SelectiveTransformer::class, false, 4);
        $healthy = $this->insertIndex(self::HANDLE_PREFIX . 'cleanup_healthy', $element, A5SelectiveTransformer::class, false, 3);
        $throwing->criteria = static fn($query) => $query->andWhere('1=0');
        $healthy->criteria = static fn($query) => $query->andWhere('1=0');
        $backend = $this->installStubBackend();
        $backend->throwDeleteIndices = [$throwing->handle];
        $throwingKey = $throwing->handle . ':' . $element->id . ':' . $element->siteId;
        $healthyKey = $healthy->handle . ':' . $element->id . ':' . $element->siteId;
        $backend->existingDocuments[$throwingKey] = true;
        $backend->existingDocuments[$healthyKey] = true;

        $result = $this->withOnlySearchIndices(
            [$throwing, $healthy],
            static fn(): bool => SearchManager::$plugin->indexing->indexElementNow($element),
        );

        self::assertFalse($result);
        self::assertTrue($backend->existingDocuments[$throwingKey]);
        self::assertArrayNotHasKey($healthyKey, $backend->existingDocuments);
        self::assertSame(4, SearchIndex::findByHandle($throwing->handle)?->documentCount);
        self::assertSame(2, SearchIndex::findByHandle($healthy->handle)?->documentCount);
        self::assertCount(1, $this->callsForIndex($backend, 'clearSearchCache', $healthy->handle));
    }

    public function testBatchIndexIsInternalRebuildPlumbingWithOneRuntimeCallerAndNoPublicExample(): void
    {
        $serviceSource = $this->readPluginFile('src/services/IndexingService.php');
        $jobSource = $this->readPluginFile('src/jobs/RebuildIndexJob.php');
        $apiDocs = $this->readPluginFile('docs/developers/api-reference.md');
        $eventsDocs = $this->readPluginFile('docs/developers/events.md');

        self::assertMatchesRegularExpression(
            '/\\/\\*\\*.*?@internal.*?\\*\\/\\s+public function batchIndex\\(/s',
            $serviceSource,
        );
        self::assertSame(1, substr_count($jobSource, '->batchIndex('));

        $runtimeCallers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__, 2) . '/src',
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (is_string($source) && str_contains($source, 'indexing->batchIndex(')) {
                $runtimeCallers[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
            }
        }
        sort($runtimeCallers);
        self::assertSame(['src/jobs/RebuildIndexJob.php'], $runtimeCallers);
        self::assertStringNotContainsString('batchIndex(', $apiDocs);
        self::assertStringContainsString('internal rebuild', strtolower($eventsDocs));
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRow(SearchIndex $index, ElementInterface $element): array
    {
        return [
            'indexHandle' => $index->handle,
            'elementType' => $index->elementType,
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'op' => PendingSyncRepository::OP_UPSERT,
        ];
    }

    private function workingEntry(): Entry
    {
        $pair = $this->findWorkingIndexAndElement();
        self::assertNotNull($pair, 'Test install must have an enabled Entry index with a matching element.');
        self::assertInstanceOf(Entry::class, $pair[1]);

        return $pair[1];
    }

    private function insertIndex(
        string $handle,
        Entry $element,
        string $transformerClass,
        bool $splitSections = false,
        int $documentCount = 0,
    ): SearchIndex {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => (int)$element->siteId,
            'criteria' => null,
            'transformerClass' => $transformerClass,
            'headingLevels' => $splitSections ? json_encode([2], JSON_THROW_ON_ERROR) : null,
            'language' => null,
            'backend' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => $splitSections ? 1 : 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => $documentCount,
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

    /**
     * @return list<array<string, mixed>>
     */
    private function callsForIndex(StubBackend $backend, string $method, string $indexHandle): array
    {
        return array_values(array_filter(
            $backend->callsFor($method),
            static fn(array $call): bool => $call['indexName'] === $indexHandle,
        ));
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    private function purgeOwnedRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::HANDLE_PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::HANDLE_PREFIX])
            ->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function deleteBatchQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), [
                'and',
                ['like', 'job', 'searchmanager'],
                ['like', 'job', 'BatchSyncJob'],
            ])
            ->execute();
    }

    private function pendingBatchQueueCount(): int
    {
        return (int)(new \craft\db\Query())
            ->from($this->queueTable())
            ->where(['like', 'job', 'searchmanager'])
            ->andWhere(['like', 'job', 'BatchSyncJob'])
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->count();
    }
}

/**
 * Deterministic transformer that can fail selected elements.
 *
 * @since 5.54.0
 */
final class A5SelectiveTransformer implements TransformerInterface
{
    /** @var list<int> */
    public static array $failingElementIds = [];

    public function transform(ElementInterface $element): array
    {
        if (in_array((int)$element->id, self::$failingElementIds, true)) {
            throw new \RuntimeException('Synthetic A5 transformation failure');
        }

        return [
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'title' => (string)$element,
            'content' => (string)$element,
            'type' => 'entry',
        ];
    }

    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry;
    }
}

/**
 * Deterministic successful transformer for sibling-continuation coverage.
 *
 * @since 5.54.0
 */
final class A5HealthyTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'title' => (string)$element,
            'content' => (string)$element,
            'type' => 'entry',
        ];
    }

    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry;
    }
}

/**
 * Transformer whose construction fails at runtime.
 *
 * @since 5.54.0
 */
final class A5ConstructorFailingTransformer implements TransformerInterface
{
    public function __construct()
    {
        throw new \RuntimeException('Synthetic A5 transformer construction failure');
    }

    public function transform(ElementInterface $element): array
    {
        return [];
    }

    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry;
    }
}
