<?php
/**
 * Search Manager plugin for Craft CMS 5.x
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
    public bool $failIndex = false;
    public bool $failDelete = false;

    /** @var array<string, bool> */
    public array $existingDocuments = [];

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
     * @param array<string, mixed> $data
     * @return array{success: bool, wasCreated: bool|null}
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        $elementId = \lindemannrock\searchmanager\helpers\SearchHitIdentityHelper::elementId($data);
        $siteId = isset($data['siteId']) ? (int)$data['siteId'] : null;
        $key = $this->documentKey($indexName, $elementId, $siteId);
        $existed = $key !== null && ($this->existingDocuments[$key] ?? false);

        $this->calls[] = ['method' => 'indexWithResult', 'indexName' => $indexName, 'items' => [$data]];

        if ($this->failIndex) {
            return [
                'success' => false,
                'wasCreated' => null,
            ];
        }

        if ($key !== null) {
            $this->existingDocuments[$key] = true;
        }

        return [
            'success' => true,
            'wasCreated' => !$existed,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function index(string $indexName, array $data): bool
    {
        return $this->indexWithResult($indexName, $data)['success'];
    }

    /**
     * @param array<int, array{elementId: int, siteId: int}> $items
     */
    public function batchDelete(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchDelete', 'indexName' => $indexName, 'items' => $items];

        return !$this->failBatchDelete;
    }

    /**
     * @return array{success: bool, existed: bool|null}
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        $key = $this->documentKey($indexName, $elementId, $siteId);
        $existed = $key !== null && ($this->existingDocuments[$key] ?? false);

        $this->calls[] = [
            'method' => 'deleteWithResult',
            'indexName' => $indexName,
            'items' => [
                [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'existed' => $existed,
                ],
            ],
        ];

        if ($this->failDelete) {
            return [
                'success' => false,
                'existed' => null,
            ];
        }

        if ($key !== null) {
            unset($this->existingDocuments[$key]);
        }

        return [
            'success' => true,
            'existed' => $existed,
        ];
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return $this->deleteWithResult($indexName, $elementId, $siteId)['success'];
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        $key = $this->documentKey($indexName, $elementId, $siteId);

        return $key !== null && ($this->existingDocuments[$key] ?? false);
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

    private function documentKey(string $indexName, ?int $elementId, ?int $siteId): ?string
    {
        if ($elementId === null || $elementId <= 0) {
            return null;
        }

        return $indexName . ':' . $elementId . ':' . ($siteId ?? 'null');
    }
}
