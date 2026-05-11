<?php

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
