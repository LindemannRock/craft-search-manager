<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use GuzzleHttp\Psr7\Response;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Provider lifecycle completion regression coverage for PR1.72 and PR1.73.
 *
 * @since 5.54.0
 */
final class HostedBackendLifecycleTest extends TestCase
{
    private const INDEX_HANDLE = 'docs';

    public static function algoliaTerminalResponseProvider(): iterable
    {
        yield 'enqueued then published' => [['status' => 'published'], true];
        yield 'repeated not published then published' => [['status' => 'published'], true];
        yield 'continued not published' => [['status' => 'notPublished'], false];
        yield 'null response' => [null, false];
        yield 'malformed response' => [['unexpected' => true], false];
        yield 'unknown response' => [['status' => 'futureState'], false];
    }

    #[DataProvider('algoliaTerminalResponseProvider')]
    public function testAlgoliaAcceptsOnlyVerifiedPublishedTask(mixed $terminal, bool $expected): void
    {
        $backend = new AlgoliaBackend();
        $client = $this->createMock(SearchClient::class);
        $fullIndexName = $this->fullIndexName();

        $client->expects(self::once())
            ->method('waitForTask')
            ->with($fullIndexName, 91, [], 50, 100)
            ->willReturn($terminal);

        self::assertSame($expected, $this->invokePrivate(
            $backend,
            AlgoliaBackend::class,
            'waitForTask',
            [$client, $fullIndexName, ['taskID' => 91]],
        ));
    }

    public function testAlgoliaTaskWaitExceptionAndMalformedEnqueueReturnFalse(): void
    {
        $backend = new AlgoliaBackend();
        $client = $this->createMock(SearchClient::class);
        $client->method('waitForTask')->willThrowException(new \RuntimeException('timeout'));

        self::assertFalse($this->invokePrivate(
            $backend,
            AlgoliaBackend::class,
            'waitForTask',
            [$client, $this->fullIndexName(), ['taskID' => 92]],
        ));
        self::assertFalse($this->invokePrivate(
            $backend,
            AlgoliaBackend::class,
            'waitForTask',
            [$client, $this->fullIndexName(), []],
        ));
    }

    public static function meilisearchTerminalResponseProvider(): iterable
    {
        yield 'enqueued or processing then succeeded' => [['status' => 'succeeded'], true];
        yield 'failed' => [['status' => 'failed'], false];
        yield 'canceled' => [['status' => 'canceled'], false];
        yield 'processing exhaustion' => [['status' => 'processing'], false];
        yield 'malformed response' => [['unexpected' => true], false];
        yield 'unknown response' => [['status' => 'futureState'], false];
    }

    #[DataProvider('meilisearchTerminalResponseProvider')]
    public function testMeilisearchAcceptsOnlyTerminalSucceededTask(mixed $terminal, bool $expected): void
    {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $client->expects(self::once())
            ->method('waitForTask')
            ->with(93, 5000, 50)
            ->willReturn($terminal);

        self::assertSame($expected, $this->invokePrivate(
            $backend,
            MeilisearchBackend::class,
            'waitForTask',
            [$client, ['taskUid' => 93]],
        ));
    }

    public function testMeilisearchTaskWaitExceptionAndMalformedEnqueueReturnFalse(): void
    {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $client->method('waitForTask')->willThrowException(new \RuntimeException('timeout'));

        self::assertFalse($this->invokePrivate(
            $backend,
            MeilisearchBackend::class,
            'waitForTask',
            [$client, ['taskUid' => 94]],
        ));
        self::assertFalse($this->invokePrivate(
            $backend,
            MeilisearchBackend::class,
            'waitForTask',
            [$client, []],
        ));
    }

    public function testAlgoliaFirstWriteBootstrapsAndWaitsForSettingsBeforeDocument(): void
    {
        $backend = new AlgoliaBackend();
        $client = $this->createMock(SearchClient::class);
        $fullIndexName = $this->fullIndexName();
        $sequence = [];

        $client->expects(self::once())
            ->method('getSettings')
            ->with($fullIndexName)
            ->willThrowException(new NotFoundException('missing'));
        $client->expects(self::once())
            ->method('setSettings')
            ->with($fullIndexName, [
                'attributesForFaceting' => ['filterOnly(siteId)', 'filterOnly(elementId)', 'filterOnly(type)'],
                'searchableAttributes' => SearchRecordProjectionHelper::providerSearchableAttributes(),
                'attributeForDistinct' => 'elementId',
            ])
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'settings-enqueued';
                return ['taskID' => 101];
            });
        $client->method('waitForTask')
            ->willReturnCallback(static function(string $index, int $taskId) use (&$sequence): array {
                $sequence[] = 'task-' . $taskId . '-published';
                return ['status' => 'published'];
            });
        $client->expects(self::once())
            ->method('getObject')
            ->willThrowException(new NotFoundException('missing'));
        $client->expects(self::once())
            ->method('saveObject')
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'document-enqueued';
                return ['taskID' => 102];
            });
        $this->setPrivateProperty($backend, AlgoliaBackend::class, '_client', $client);

        self::assertSame(['success' => true, 'wasCreated' => true], $backend->indexWithResult(
            self::INDEX_HANDLE,
            $this->document(101),
        ));
        self::assertSame([
            'settings-enqueued',
            'task-101-published',
            'document-enqueued',
            'task-102-published',
        ], $sequence);
    }

    public function testMeilisearchFirstWriteCreatesThenConfiguresBeforeDocument(): void
    {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $index = $this->createMock(Indexes::class);
        $fullIndexName = $this->fullIndexName();
        $sequence = [];

        $client->expects(self::once())
            ->method('getIndex')
            ->with($fullIndexName)
            ->willThrowException($this->meilisearchNotFound());
        $client->expects(self::once())
            ->method('createIndex')
            ->with($fullIndexName, ['primaryKey' => 'objectID'])
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'create-enqueued';
                return ['taskUid' => 201];
            });
        $client->expects(self::exactly(2))->method('index')->with($fullIndexName)->willReturn($index);
        $client->method('waitForTask')
            ->willReturnCallback(static function(int $taskUid) use (&$sequence): array {
                $sequence[] = 'task-' . $taskUid . '-succeeded';
                return ['status' => 'succeeded'];
            });
        $index->expects(self::never())->method('getFilterableAttributes');
        $index->expects(self::never())->method('getSearchableAttributes');
        $index->expects(self::once())
            ->method('updateFilterableAttributes')
            ->with(['siteId', 'elementId', 'type'])
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'filterable-enqueued';
                return ['taskUid' => 202];
            });
        $index->expects(self::once())
            ->method('updateSearchableAttributes')
            ->with(SearchRecordProjectionHelper::providerSearchableAttributes())
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'searchable-enqueued';
                return ['taskUid' => 203];
            });
        $index->expects(self::once())
            ->method('getDocument')
            ->willThrowException($this->meilisearchNotFound());
        $index->expects(self::once())
            ->method('addDocuments')
            ->willReturnCallback(static function() use (&$sequence): array {
                $sequence[] = 'document-enqueued';
                return ['taskUid' => 204];
            });
        $this->setPrivateProperty($backend, MeilisearchBackend::class, '_adminClient', $client);

        self::assertSame(['success' => true, 'wasCreated' => true], $backend->indexWithResult(
            self::INDEX_HANDLE,
            $this->document(201),
        ));
        self::assertSame([
            'create-enqueued',
            'task-201-succeeded',
            'filterable-enqueued',
            'task-202-succeeded',
            'searchable-enqueued',
            'task-203-succeeded',
            'document-enqueued',
            'task-204-succeeded',
        ], $sequence);
    }

    public function testAlgoliaExistingSettingsRetainCompatibleFacets(): void
    {
        $backend = new AlgoliaBackend();
        $client = $this->createMock(SearchClient::class);
        $fullIndexName = $this->fullIndexName();

        $client->method('getSettings')->willReturn([
            'attributesForFaceting' => ['filterOnly(customFacet)', 'filterOnly(siteId)'],
            'searchableAttributes' => SearchRecordProjectionHelper::providerSearchableAttributes(),
            'attributeForDistinct' => 'elementId',
        ]);
        $client->expects(self::once())
            ->method('setSettings')
            ->with($fullIndexName, [
                'attributesForFaceting' => [
                    'filterOnly(customFacet)',
                    'filterOnly(siteId)',
                    'filterOnly(elementId)',
                    'filterOnly(type)',
                ],
            ])
            ->willReturn(['taskID' => 251]);
        $client->expects(self::once())->method('saveObjects')->willReturn([['taskID' => 252]]);
        $client->expects(self::exactly(2))->method('waitForTask')->willReturn(['status' => 'published']);
        $this->setPrivateProperty($backend, AlgoliaBackend::class, '_client', $client);

        self::assertTrue($backend->batchIndex(self::INDEX_HANDLE, [$this->document(251)]));
    }

    public function testMeilisearchExistingSettingsPreserveFilterableButReplaceSearchableProjection(): void
    {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $index = $this->createMock(Indexes::class);
        $advancedFilter = ['attributePatterns' => ['category.*']];
        $requiredSearchable = SearchRecordProjectionHelper::providerSearchableAttributes();

        $client->method('getIndex')->willReturn($index);
        $client->method('index')->willReturn($index);
        $client->method('waitForTask')->willReturn(['status' => 'succeeded']);
        $index->method('getFilterableAttributes')->willReturn(['customFacet', $advancedFilter, 'elementType', 'siteId']);
        $index->expects(self::once())
            ->method('updateFilterableAttributes')
            ->with(['customFacet', $advancedFilter, 'siteId', 'elementId', 'type'])
            ->willReturn(['taskUid' => 301]);
        $index->method('getSearchableAttributes')->willReturn(['customSearch', 'elementType']);
        $index->expects(self::once())
            ->method('updateSearchableAttributes')
            ->with($requiredSearchable)
            ->willReturn(['taskUid' => 302]);
        $index->expects(self::once())->method('addDocuments')->willReturn(['taskUid' => 303]);
        $this->setPrivateProperty($backend, MeilisearchBackend::class, '_adminClient', $client);

        self::assertTrue($backend->batchIndex(self::INDEX_HANDLE, [$this->document(301)]));
    }

    public static function meilisearchNonCanonicalSearchableProvider(): iterable
    {
        $required = SearchRecordProjectionHelper::providerSearchableAttributes();

        yield 'provider wildcard default' => [['*']];
        yield 'required fields in wrong ranking order' => [array_reverse($required)];
    }

    /** @param list<string> $currentSearchable */
    #[DataProvider('meilisearchNonCanonicalSearchableProvider')]
    public function testMeilisearchNonCanonicalSearchableSettingsConvergeToExactOrderedProjection(
        array $currentSearchable,
    ): void {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $index = $this->createMock(Indexes::class);
        $required = SearchRecordProjectionHelper::providerSearchableAttributes();

        $client->method('getIndex')->willReturn($index);
        $client->method('index')->willReturn($index);
        $client->expects(self::exactly(2))->method('waitForTask')->willReturn(['status' => 'succeeded']);
        $index->method('getFilterableAttributes')->willReturn(['siteId', 'elementId', 'type']);
        $index->expects(self::never())->method('updateFilterableAttributes');
        $index->method('getSearchableAttributes')->willReturn($currentSearchable);
        $index->expects(self::once())
            ->method('updateSearchableAttributes')
            ->with($required)
            ->willReturn(['taskUid' => 351]);
        $index->expects(self::once())->method('addDocuments')->willReturn(['taskUid' => 352]);
        $this->setPrivateProperty($backend, MeilisearchBackend::class, '_adminClient', $client);

        self::assertTrue($backend->batchIndex(self::INDEX_HANDLE, [$this->document(351)]));
    }

    public function testFailedNativeBatchesRecordOnlyIdentityAndNeverProbePerItem(): void
    {
        $documents = [$this->document(401), $this->document(402, 'PRIVATE_DOCUMENT_SENTINEL')];

        $algolia = new AlgoliaBackend();
        $algoliaClient = $this->createMock(SearchClient::class);
        $algoliaClient->method('getSettings')->willReturn($this->algoliaConfiguredSettings());
        $algoliaClient->expects(self::once())->method('saveObjects')->willReturn([['taskID' => 401]]);
        $algoliaClient->expects(self::never())->method('saveObject');
        $algoliaClient->method('waitForTask')->willReturn(['status' => 'notPublished']);
        $this->setPrivateProperty($algolia, AlgoliaBackend::class, '_client', $algoliaClient);

        self::assertFalse($algolia->batchIndex(self::INDEX_HANDLE, $documents));
        $this->assertSafeBatchFailures($algolia->getLastIndexingFailures(), 'Algolia batch task did not complete.');

        $meilisearch = new MeilisearchBackend();
        $meiliClient = $this->createMock(MeilisearchClient::class);
        $meiliIndex = $this->createMock(Indexes::class);
        $meiliClient->method('getIndex')->willReturn($meiliIndex);
        $meiliClient->method('index')->willReturn($meiliIndex);
        $meiliClient->method('waitForTask')->willReturn(['status' => 'failed']);
        $meiliIndex->method('getFilterableAttributes')->willReturn(['siteId', 'elementId', 'type']);
        $meiliIndex->method('getSearchableAttributes')->willReturn(SearchRecordProjectionHelper::providerSearchableAttributes());
        $meiliIndex->expects(self::never())->method('updateFilterableAttributes');
        $meiliIndex->expects(self::never())->method('updateSearchableAttributes');
        $meiliIndex->expects(self::once())->method('addDocuments')->willReturn(['taskUid' => 402]);
        $this->setPrivateProperty($meilisearch, MeilisearchBackend::class, '_adminClient', $meiliClient);

        self::assertFalse($meilisearch->batchIndex(self::INDEX_HANDLE, $documents));
        $this->assertSafeBatchFailures($meilisearch->getLastIndexingFailures(), 'Meilisearch batch task did not complete.');
    }

    public function testMissingMeilisearchClearIsIdempotentAndAlgoliaClearWaits(): void
    {
        $meilisearch = new MeilisearchBackend();
        $meiliClient = $this->createMock(MeilisearchClient::class);
        $meiliIndex = $this->createMock(Indexes::class);
        $meiliClient->method('index')->willReturn($meiliIndex);
        $meiliClient->expects(self::never())->method('createIndex');
        $meiliClient->expects(self::never())->method('waitForTask');
        $meiliIndex->method('deleteAllDocuments')->willThrowException($this->meilisearchNotFound());
        $this->setPrivateProperty($meilisearch, MeilisearchBackend::class, '_adminClient', $meiliClient);
        self::assertTrue($meilisearch->clearIndex(self::INDEX_HANDLE));

        $algolia = new AlgoliaBackend();
        $algoliaClient = $this->createMock(SearchClient::class);
        $algoliaClient->expects(self::once())->method('clearObjects')->willReturn(['taskID' => 501]);
        $algoliaClient->expects(self::once())
            ->method('waitForTask')
            ->with($this->fullIndexName(), 501, [], 50, 100)
            ->willReturn(['status' => 'published']);
        $this->setPrivateProperty($algolia, AlgoliaBackend::class, '_client', $algoliaClient);
        self::assertTrue($algolia->clearIndex(self::INDEX_HANDLE));
    }

    public function testOrdinarySearchAndCountNeverUseAdminSettingsLifecycle(): void
    {
        $algolia = new AlgoliaBackend();
        $algoliaSearch = $this->createMock(SearchClient::class);
        $algoliaSearch->expects(self::never())->method('getSettings');
        $algoliaSearch->expects(self::never())->method('setSettings');
        $algoliaSearch->expects(self::exactly(2))
            ->method('searchSingleIndex')
            ->willReturn(['hits' => [], 'nbHits' => 7, 'exhaustiveNbHits' => true]);
        $this->setPrivateProperty($algolia, AlgoliaBackend::class, '_searchClient', $algoliaSearch);
        self::assertSame(['hits' => [], 'total' => 7], $algolia->search(self::INDEX_HANDLE, 'needle'));
        self::assertSame(7, $algolia->getDocumentCount(self::INDEX_HANDLE));

        $meilisearch = new MeilisearchBackend();
        $meiliSearchClient = $this->createMock(MeilisearchClient::class);
        $searchIndex = $this->createMock(Indexes::class);
        $adminClient = $this->createMock(MeilisearchClient::class);
        $adminIndex = $this->createMock(Indexes::class);
        $meiliSearchClient->method('index')->willReturn($searchIndex);
        $searchIndex->expects(self::never())->method('getFilterableAttributes');
        $searchIndex->expects(self::never())->method('getSearchableAttributes');
        $searchIndex->method('search')->willReturn(new SearchResult([
            'hits' => [],
            'offset' => 0,
            'limit' => 20,
            'estimatedTotalHits' => 0,
            'processingTimeMs' => 1,
            'query' => 'needle',
        ]));
        $adminClient->method('index')->willReturn($adminIndex);
        $adminClient->expects(self::never())->method('getIndex');
        $adminClient->expects(self::never())->method('createIndex');
        $adminIndex->expects(self::never())->method('getFilterableAttributes');
        $adminIndex->expects(self::never())->method('getSearchableAttributes');
        $adminIndex->method('stats')->willReturn(['numberOfDocuments' => 8]);
        $this->setPrivateProperty($meilisearch, MeilisearchBackend::class, '_searchClient', $meiliSearchClient);
        $this->setPrivateProperty($meilisearch, MeilisearchBackend::class, '_adminClient', $adminClient);

        self::assertSame(['hits' => [], 'total' => 0, 'processingTime' => 1], $meilisearch->search(self::INDEX_HANDLE, 'needle'));
        self::assertSame(8, $meilisearch->getDocumentCount(self::INDEX_HANDLE));
    }

    public function testEveryHostedMutationFamilyIsTaskGatedAndReadPathsStayReadOnly(): void
    {
        foreach (['indexWithResult', 'deleteWithResult', 'deleteByBackendId', 'clearIndex', 'ensureFilterableAttributes'] as $method) {
            self::assertStringContainsString('waitForTask(', $this->methodSource(AlgoliaBackend::class, $method), $method);
        }
        foreach (['batchIndex', 'batchDelete'] as $method) {
            self::assertStringContainsString('waitForTasks(', $this->methodSource(AlgoliaBackend::class, $method), $method);
        }
        foreach (['indexWithResult', 'batchIndex', 'batchDelete', 'deleteWithResult', 'deleteByBackendId', 'clearIndex', 'ensureFilterableAttributes'] as $method) {
            self::assertStringContainsString('waitForTask(', $this->methodSource(MeilisearchBackend::class, $method), $method);
        }

        foreach (['search', 'countRecords'] as $method) {
            self::assertStringNotContainsString('ensureFilterableAttributes(', $this->methodSource(AlgoliaBackend::class, $method), $method);
        }
        foreach (['search', 'getDocumentCount', 'countSearchResults'] as $method) {
            self::assertStringNotContainsString('ensureFilterableAttributes(', $this->methodSource(MeilisearchBackend::class, $method), $method);
        }

        self::assertStringNotContainsString('saveObject(', $this->methodSource(AlgoliaBackend::class, 'recordAlgoliaBatchFailures'));
        self::assertStringNotContainsString('addDocuments(', $this->methodSource(MeilisearchBackend::class, 'recordMeilisearchBatchFailures'));
    }

    private function fullIndexName(): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName(self::INDEX_HANDLE);
    }

    /** @return array<string, mixed> */
    private function document(int $elementId, string $title = 'Title'): array
    {
        return [
            'elementId' => $elementId,
            'siteId' => 1,
            'title' => $title,
            'content' => 'PRIVATE_CONTENT_SENTINEL',
        ];
    }

    /** @return array<string, mixed> */
    private function algoliaConfiguredSettings(): array
    {
        return [
            'attributesForFaceting' => ['filterOnly(siteId)', 'filterOnly(elementId)', 'filterOnly(type)'],
            'searchableAttributes' => SearchRecordProjectionHelper::providerSearchableAttributes(),
            'attributeForDistinct' => 'elementId',
        ];
    }

    private function meilisearchNotFound(): ApiException
    {
        return new ApiException(new Response(404), ['message' => 'missing']);
    }

    /**
     * @param list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}> $failures
     */
    private function assertSafeBatchFailures(array $failures, string $classification): void
    {
        self::assertSame(['401_1', '402_1'], array_column($failures, 'backendId'));
        self::assertSame([401, 402], array_column($failures, 'elementId'));
        self::assertSame([null, null], array_column($failures, 'title'));
        self::assertSame([$classification, $classification], array_column($failures, 'error'));
        self::assertStringNotContainsString('PRIVATE_', serialize($failures));
    }

    private function setPrivateProperty(object $target, string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue($target, $value);
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(object $target, string $class, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($class, $method))->invokeArgs($target, $arguments);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return implode("\n", array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
