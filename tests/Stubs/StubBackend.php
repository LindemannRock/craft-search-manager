<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use lindemannrock\searchmanager\services\BackendService;

/**
 * Test-only backend stub. Records every call PendingSyncProcessor makes so
 * tests can assert *which* operation was driven (upsert vs delete) and lets
 * the test force a partial-failure path by returning false from batchIndex.
 *
 * Install with `TestCase::installStubBackend()` — that swaps it onto
 * `SearchManager::$plugin->backend` for the duration of one test and the base
 * class restores the original in tearDown().
 *
 * @since 5.45.0
 */
final class StubBackend extends BackendService
{
    /** @var list<array{method: string, indexName: string, items?: array<int, array<string, mixed>>}> */
    public array $calls = [];

    public bool $failBatchIndex = false;
    public bool $failBatchDelete = false;

    /** @var array<string, mixed> */
    public array $searchResponse = [
        'hits' => [],
        'total' => 0,
    ];

    /** @var array<string, mixed> */
    public array $searchMultipleResponse = [
        'hits' => [],
        'total' => 0,
        'indices' => [],
    ];

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchIndex', 'indexName' => $indexName, 'items' => $items];

        return !$this->failBatchIndex;
    }

    /**
     * @param array<int, array{elementId: int, siteId: int}> $items
     */
    public function batchDelete(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchDelete', 'indexName' => $indexName, 'items' => $items];

        return !$this->failBatchDelete;
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->calls[] = ['method' => 'clearSearchCache', 'indexName' => $indexName];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'search',
            'indexName' => $indexName,
            'items' => [
                [
                    'query' => $query,
                    'options' => $options,
                ],
            ],
        ];

        return $this->searchResponse;
    }

    /**
     * @param array<int, string> $indexNames
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function searchMultiple(array $indexNames, string $query, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'searchMultiple',
            'indexName' => implode(',', $indexNames),
            'items' => [
                [
                    'query' => $query,
                    'indices' => $indexNames,
                    'options' => $options,
                ],
            ],
        ];

        return $this->searchMultipleResponse;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method,
        ));
    }
}
