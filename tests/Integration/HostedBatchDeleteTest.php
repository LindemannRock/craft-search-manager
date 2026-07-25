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
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\backends\RedisBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use PHPUnit\Framework\MockObject\MockObject;
use Typesense\Client as TypesenseClient;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;

/**
 * Hosted provider batch deletion regression coverage.
 *
 * @since 5.54.0
 */
final class HostedBatchDeleteTest extends TestCase
{
    public function testBaseFallbackKeepsEmptyExplicitPageAndMissingSiteBehavior(): void
    {
        $backend = new BatchDeleteFallbackBackend();

        self::assertTrue($backend->batchDelete('docs', []));
        self::assertSame([], $backend->calls);

        self::assertTrue($backend->batchDelete('docs', [
            ['backendId' => '42_7_section-a', 'elementId' => -1, 'siteId' => 'invalid'],
            ['elementId' => 43, 'siteId' => 7],
            ['elementId' => 44],
        ]));
        self::assertSame([
            ['method' => 'deleteByBackendId', 'backendId' => '42_7_section-a'],
            ['method' => 'delete', 'elementId' => 43, 'siteId' => 7],
            ['method' => 'delete', 'elementId' => 44, 'siteId' => null],
        ], $backend->calls);

        self::assertSame([
            ['backendId' => '43_7', 'elementId' => 43, 'siteId' => 7, 'explicitBackendId' => false],
            ['backendId' => '44', 'elementId' => 44, 'siteId' => null, 'explicitBackendId' => false],
            ['backendId' => '45_7_heading', 'elementId' => 45, 'siteId' => 7, 'explicitBackendId' => true],
        ], $backend->normalized([
            ['elementId' => 43, 'siteId' => 7],
            ['elementId' => 44],
            ['elementId' => 45, 'siteId' => 7, 'sectionId' => 'heading'],
        ])['items']);
    }

    public function testBaseFallbackSignalsInvalidItemsAndStillProcessesValidItems(): void
    {
        $backend = new BatchDeleteFallbackBackend();

        self::assertFalse($backend->batchDelete('docs', [
            'invalid',
            ['backendId' => ''],
            ['elementId' => 0, 'siteId' => 1],
            ['elementId' => 51, 'siteId' => 'invalid'],
            ['elementId' => 52, 'siteId' => 8],
        ]));
        self::assertSame([
            ['method' => 'delete', 'elementId' => 52, 'siteId' => 8],
        ], $backend->calls);
    }

    public function testBaseFallbackContinuesAfterFailureAndCollapsesDuplicateIdentities(): void
    {
        $backend = new BatchDeleteFallbackBackend();
        $backend->failedElementIds = [61];

        self::assertFalse($backend->batchDelete('docs', [
            ['elementId' => 61, 'siteId' => 9],
            ['elementId' => 61, 'siteId' => 9],
            ['elementId' => 62, 'siteId' => 9],
            ['backendId' => '63_9_section'],
            ['backendId' => '63_9_section'],
        ]));
        self::assertSame([
            ['method' => 'delete', 'elementId' => 61, 'siteId' => 9],
            ['method' => 'delete', 'elementId' => 62, 'siteId' => 9],
            ['method' => 'deleteByBackendId', 'backendId' => '63_9_section'],
        ], $backend->calls);
    }

    public function testBaseFallbackTreatsMissingDocumentsAsIdempotentSuccess(): void
    {
        $backend = new BatchDeleteFallbackBackend();

        self::assertTrue($backend->batchDelete('docs', [
            ['elementId' => 71, 'siteId' => 10],
            ['backendId' => '72_10_missing-section'],
        ]));
        self::assertCount(2, $backend->calls);
    }

    public function testAllLocalBackendsRetainTheSharedFallback(): void
    {
        foreach ([MySqlBackend::class, PostgreSqlBackend::class, RedisBackend::class, FileBackend::class] as $backend) {
            self::assertSame(
                BaseBackend::class,
                (new \ReflectionMethod($backend, 'batchDelete'))->getDeclaringClass()->getName(),
                $backend,
            );
        }
    }

    public function testAlgoliaUsesOneNativeBulkTaskForCanonicalIds(): void
    {
        $backend = new AlgoliaBackend();
        $client = $this->createMock(SearchClient::class);
        $fullIndexName = $this->fullIndexName('docs');

        $client->expects(self::once())
            ->method('deleteObjects')
            ->with($fullIndexName, ['101_2', '102_2_section', '103_2_heading'])
            ->willReturn([['taskID' => 123]]);
        $client->expects(self::never())->method('deleteObject');
        $client->expects(self::never())->method('getObject');
        $this->setPrivateProperty($backend, AlgoliaBackend::class, '_client', $client);

        self::assertFalse($backend->batchDelete('docs', $this->mixedDeleteItems()));
    }

    public function testMeilisearchUsesOneNativeBulkTaskForCanonicalIds(): void
    {
        $backend = new MeilisearchBackend();
        $client = $this->createMock(MeilisearchClient::class);
        $index = $this->createMock(Indexes::class);
        $fullIndexName = $this->fullIndexName('docs');

        $client->expects(self::once())->method('index')->with($fullIndexName)->willReturn($index);
        $index->expects(self::once())
            ->method('deleteDocuments')
            ->with(['101_2', '102_2_section', '103_2_heading'])
            ->willReturn(['taskUid' => 456]);
        $index->expects(self::never())->method('deleteDocument');
        $index->expects(self::never())->method('getDocument');
        $this->setPrivateProperty($backend, MeilisearchBackend::class, '_adminClient', $client);

        self::assertFalse($backend->batchDelete('docs', $this->mixedDeleteItems()));
    }

    public function testTypesenseUsesOneExactIdFilterDeleteForCanonicalIds(): void
    {
        $backend = new TypesenseBackend();
        [$client, $collections, $documents] = $this->typesenseMocks();
        $fullIndexName = $this->fullIndexName('docs');

        $collections->expects(self::once())->method('offsetGet')->with($fullIndexName);
        $documents->expects(self::once())
            ->method('delete')
            ->with(['filter_by' => 'id:=[`101_2`, `102_2_section`, `103_2_heading`]'])
            ->willReturn(['num_deleted' => 2]);
        $documents->expects(self::never())->method('offsetGet');
        $this->setPrivateProperty($backend, TypesenseBackend::class, '_client', $client);

        self::assertFalse($backend->batchDelete('docs', $this->mixedDeleteItems()));
    }

    public function testHostedBackendsMakeNoRequestForEmptyInput(): void
    {
        $algolia = new AlgoliaBackend();
        $algoliaClient = $this->createMock(SearchClient::class);
        $algoliaClient->expects(self::never())->method('deleteObjects');
        $this->setPrivateProperty($algolia, AlgoliaBackend::class, '_client', $algoliaClient);

        $meilisearch = new MeilisearchBackend();
        $meilisearchClient = $this->createMock(MeilisearchClient::class);
        $meilisearchClient->expects(self::never())->method('index');
        $this->setPrivateProperty($meilisearch, MeilisearchBackend::class, '_adminClient', $meilisearchClient);

        $typesense = new TypesenseBackend();
        [$typesenseClient, $collections] = $this->typesenseMocks();
        $collections->expects(self::never())->method('offsetGet');
        $this->setPrivateProperty($typesense, TypesenseBackend::class, '_client', $typesenseClient);

        self::assertTrue($algolia->batchDelete('docs', []));
        self::assertTrue($meilisearch->batchDelete('docs', []));
        self::assertTrue($typesense->batchDelete('docs', []));
    }

    public function testAlgoliaExceptionAndInvalidResponseReturnFalse(): void
    {
        $throwing = new AlgoliaBackend();
        $throwingClient = $this->createMock(SearchClient::class);
        $throwingClient->method('deleteObjects')->willThrowException(new \RuntimeException('provider unavailable'));
        $this->setPrivateProperty($throwing, AlgoliaBackend::class, '_client', $throwingClient);
        self::assertFalse($throwing->batchDelete('docs', [['backendId' => '201_2']]));

        $invalid = new AlgoliaBackend();
        $invalidClient = $this->createMock(SearchClient::class);
        $invalidClient->method('deleteObjects')->willReturn([]);
        $this->setPrivateProperty($invalid, AlgoliaBackend::class, '_client', $invalidClient);
        self::assertFalse($invalid->batchDelete('docs', [['backendId' => '201_2']]));
    }

    public function testMeilisearchExceptionAndInvalidResponseReturnFalse(): void
    {
        $throwing = new MeilisearchBackend();
        $throwingClient = $this->createMock(MeilisearchClient::class);
        $throwingIndex = $this->createMock(Indexes::class);
        $throwingClient->method('index')->willReturn($throwingIndex);
        $throwingIndex->method('deleteDocuments')->willThrowException(new \RuntimeException('provider unavailable'));
        $this->setPrivateProperty($throwing, MeilisearchBackend::class, '_adminClient', $throwingClient);
        self::assertFalse($throwing->batchDelete('docs', [['backendId' => '202_2']]));

        $invalid = new MeilisearchBackend();
        $invalidClient = $this->createMock(MeilisearchClient::class);
        $invalidIndex = $this->createMock(Indexes::class);
        $invalidClient->method('index')->willReturn($invalidIndex);
        $invalidIndex->method('deleteDocuments')->willReturn([]);
        $this->setPrivateProperty($invalid, MeilisearchBackend::class, '_adminClient', $invalidClient);
        self::assertFalse($invalid->batchDelete('docs', [['backendId' => '202_2']]));
    }

    public function testTypesenseExceptionAndInvalidResponseReturnFalse(): void
    {
        $throwing = new TypesenseBackend();
        [$throwingClient, , $throwingDocuments] = $this->typesenseMocks();
        $throwingDocuments->method('delete')->willThrowException(new \RuntimeException('provider unavailable'));
        $this->setPrivateProperty($throwing, TypesenseBackend::class, '_client', $throwingClient);
        self::assertFalse($throwing->batchDelete('docs', [['backendId' => '203_2']]));

        $invalid = new TypesenseBackend();
        [$invalidClient, , $invalidDocuments] = $this->typesenseMocks();
        $invalidDocuments->method('delete')->willReturn([]);
        $this->setPrivateProperty($invalid, TypesenseBackend::class, '_client', $invalidClient);
        self::assertFalse($invalid->batchDelete('docs', [['backendId' => '203_2']]));
    }

    /**
     * @return list<mixed>
     */
    private function mixedDeleteItems(): array
    {
        return [
            ['elementId' => 101, 'siteId' => 2],
            ['elementId' => 101, 'siteId' => 2],
            ['backendId' => '102_2_section'],
            ['elementId' => 103, 'siteId' => 2, 'sectionId' => 'heading'],
            ['elementId' => 0, 'siteId' => 2],
        ];
    }

    private function fullIndexName(string $indexName): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($indexName);
    }

    private function setPrivateProperty(object $target, string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue($target, $value);
    }

    /**
     * @return array{
     *     TypesenseClient&MockObject,
     *     Collections&MockObject,
     *     Documents&MockObject,
     * }
     */
    private function typesenseMocks(): array
    {
        $client = $this->createMock(TypesenseClient::class);
        $collections = $this->createMock(Collections::class);
        $collection = $this->createMock(Collection::class);
        $documents = $this->createMock(Documents::class);

        $client->collections = $collections;
        $collection->documents = $documents;
        $collections->method('offsetGet')->willReturn($collection);

        return [$client, $collections, $documents];
    }
}

/**
 * Test-only custom backend that exercises BaseBackend's fallback contract.
 *
 * @since 5.54.0
 */
final class BatchDeleteFallbackBackend extends BaseBackend
{
    /** @var list<array{method: string, backendId?: string, elementId?: int, siteId?: int|null}> */
    public array $calls = [];

    /** @var list<int> */
    public array $failedElementIds = [];

    /**
     * @param array<int, mixed> $items
     * @return array{
     *     items: list<array{
     *         backendId: string,
     *         elementId: int,
     *         siteId: int|null,
     *         explicitBackendId: bool,
     *     }>,
     *     valid: bool,
     * }
     */
    public function normalized(array $items): array
    {
        return $this->normalizeBatchDeleteItems($items);
    }

    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        $this->calls[] = [
            'method' => 'delete',
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];

        return !in_array($elementId, $this->failedElementIds, true);
    }

    protected function deleteByBackendId(string $indexName, string $backendId): bool
    {
        $this->calls[] = [
            'method' => 'deleteByBackendId',
            'backendId' => $backendId,
        ];

        return true;
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        return ['hits' => [], 'total' => 0];
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
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

    public function getName(): string
    {
        return 'batch-delete-fallback';
    }

    public function listIndices(): array
    {
        return [];
    }
}
