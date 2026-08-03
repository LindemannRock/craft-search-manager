<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\analytics\AnalyticsQueryTrait;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit Batch 7.
 *
 * @since 5.53.0
 */
final class SynonymAggregatePaginationTest extends TestCase
{
    public function testSynonymSearchDeduplicatesWithStableIndexMapAndKeepsHighestScore(): void
    {
        $service = new BackendService();
        $backend = new SynonymAggregateBackend([
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'title' => 'Original title', 'score' => 2.0],
                    ['elementId' => 20, 'title' => 'Second title', 'score' => 5.0],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 10, 'title' => 'Duplicate title', 'score' => 9.0],
                    ['elementId' => 30, 'title' => 'Third title', 'score' => 1.0],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(BackendService::class, '_searchWithSynonyms');
        $method->setAccessible(true);

        $results = $method->invoke($service, $backend, 'docs', ['original', 'synonym'], ['limit' => 50]);
        self::assertIsArray($results);

        self::assertSame(3, $results['total']);
        self::assertSame([10, 20, 30], array_column($results['hits'], 'elementId'));
        self::assertSame('Original title', $results['hits'][0]['title']);
        self::assertSame(9.0, $results['hits'][0]['score']);
    }

    public function testSynonymAggregateOwnsBoundedPaginationAcrossBackendStyles(): void
    {
        $responses = [
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'title' => 'Original title', 'score' => 10.0],
                    ['elementId' => 10, 'siteId' => 2, 'title' => 'Other site', 'score' => 9.5],
                    ['elementId' => 20, 'siteId' => 1, 'title' => 'Second title', 'score' => 8.0],
                    ['elementId' => 30, 'siteId' => 1, 'title' => 'Third title', 'score' => 6.0],
                ],
                'searchDebug' => [
                    'relaxedMatching' => false,
                    'resolvedTerms' => ['original' => [['term' => 'original']]],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'title' => 'Duplicate title', 'score' => 11.0],
                    ['elementId' => 40, 'siteId' => 1, 'title' => 'Fourth title', 'score' => 9.0],
                    ['elementId' => 50, 'siteId' => 1, 'title' => 'Fifth title', 'score' => 7.0],
                    ['elementId' => 60, 'siteId' => 1, 'title' => 'Sixth title', 'score' => 5.0],
                ],
                'searchDebug' => [
                    'relaxedMatching' => true,
                    'resolvedTerms' => ['synonym' => [['term' => 'synonym']]],
                ],
            ],
        ];

        foreach ([
            'local' => 'offset',
            'meilisearch' => 'offset',
            'algolia' => 'page',
            'typesense' => 'page',
        ] as $backendStyle => $paginationMode) {
            $backend = new SynonymAggregateBackend($responses, $paginationMode);
            $results = $this->invokeSynonymSearch($backend, [
                'limit' => 2,
                'offset' => 2,
                'page' => 1,
            ]);

            self::assertSame([40, 20], array_column($results['hits'], 'elementId'), $backendStyle);
            self::assertSame(7, $results['total'], $backendStyle);
            self::assertTrue($results['searchDebug']['relaxedMatching'], $backendStyle);
            self::assertSame(
                ['original', 'synonym'],
                array_keys($results['searchDebug']['resolvedTerms']),
                $backendStyle,
            );

            self::assertCount(2, $backend->searchCalls, $backendStyle);
            foreach ($backend->searchCalls as $call) {
                self::assertSame(4, $call['options']['limit'], $backendStyle);
                self::assertSame(0, $call['options']['offset'], $backendStyle);
                self::assertSame(0, $call['options']['page'], $backendStyle);
            }
        }
    }

    public function testSynonymAggregatePreservesUnboundedChildrenAndAppliesOffsetOnce(): void
    {
        $backend = new SynonymAggregateBackend([
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'score' => 10.0],
                    ['elementId' => 20, 'siteId' => 1, 'score' => 8.0],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 30, 'siteId' => 1, 'score' => 9.0],
                    ['elementId' => 40, 'siteId' => 1, 'score' => 7.0],
                ],
            ],
        ], 'offset');

        $results = $this->invokeSynonymSearch($backend, [
            'limit' => 0,
            'offset' => 2,
            'page' => 3,
        ]);

        self::assertSame([20, 40], array_column($results['hits'], 'elementId'));
        self::assertSame(4, $results['total']);
        foreach ($backend->searchCalls as $call) {
            self::assertSame(0, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    public function testSynonymAggregatePageZeroUsesExactRequestedHeadroom(): void
    {
        $backend = new SynonymAggregateBackend([
            'original' => ['hits' => [['elementId' => 10, 'siteId' => 1, 'score' => 10.0]]],
            'synonym' => ['hits' => [['elementId' => 20, 'siteId' => 1, 'score' => 9.0]]],
        ], 'page');

        $results = $this->invokeSynonymSearch($backend, [
            'limit' => 2,
            'offset' => 0,
            'page' => 0,
        ]);

        self::assertSame([10, 20], array_column($results['hits'], 'elementId'));
        foreach ($backend->searchCalls as $call) {
            self::assertSame(2, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function invokeSynonymSearch(SynonymAggregateBackend $backend, array $options): array
    {
        $method = new \ReflectionMethod(BackendService::class, '_searchWithSynonyms');
        $method->setAccessible(true);
        $results = $method->invoke(new BackendService(), $backend, 'docs', ['original', 'synonym'], $options);
        self::assertIsArray($results);

        return $results;
    }
}

/**
 * @since 5.53.0
 */
final class SynonymAggregateAnalyticsColumnProbe
{
    use AnalyticsQueryTrait {
        optionalAnalyticsColumn as public exposeOptionalAnalyticsColumn;
    }
}

/**
 * @since 5.53.0
 */
final class SynonymAggregateBackend implements BackendInterface
{
    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $searchCalls = [];

    /**
     * @param array<string, array<string, mixed>> $responsesByQuery
     */
    public function __construct(
        private array $responsesByQuery,
        private readonly string $paginationMode = 'none',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, wasCreated: bool|null}
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        return ['success' => true, 'wasCreated' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function batchDelete(string $indexName, array $items): bool
    {
        return true;
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    /**
     * @return array{success: bool, existed: bool|null}
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        return ['success' => true, 'existed' => true];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        $response = $this->responsesByQuery[$query] ?? ['hits' => []];
        $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];
        $response['total'] = $response['total'] ?? count($hits);

        $limit = (int)($options['limit'] ?? 0);
        $offset = (int)($options['offset'] ?? 0);
        if ($this->paginationMode === 'page' && $limit > 0) {
            $offset = (int)($options['page'] ?? 0) * $limit;
        }

        if ($limit > 0) {
            $response['hits'] = array_slice($hits, $offset, $limit);
        } elseif ($offset > 0) {
            $response['hits'] = array_slice($hits, $offset);
        }

        return $response;
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'batch7';
    }

    /**
     * @param array<string, mixed> $parameters
     * @return iterable<int, array<string, mixed>>
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $queries
     * @return array<string, mixed>
     */
    public function multipleQueries(array $queries = []): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function parseFilters(array $filters = []): string
    {
        return '';
    }

    public function supportsBrowse(): bool
    {
        return false;
    }

    public function supportsMultipleQueries(): bool
    {
        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndices(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setConfiguredSettings(array $settings): void
    {
    }

    public function setBackendHandle(string $handle): void
    {
    }
}
