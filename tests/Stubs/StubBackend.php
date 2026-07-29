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
 * @since 5.46.0
 */
final class StubBackend extends BackendService
{
    /** @var list<array{method: string, indexName: string, items?: array<int, array<string, mixed>>}> */
    public array $calls = [];

    public bool $failBatchIndex = false;
    public bool $failBatchDelete = false;
    public bool $failIndex = false;
    public bool $failDelete = false;

    /** @var list<string> */
    public array $failBatchDeleteIndices = [];

    /** @var list<string> */
    public array $failDeleteIndices = [];

    /** @var list<string> */
    public array $throwBatchDeleteIndices = [];

    /** @var list<string> */
    public array $throwDeleteIndices = [];

    /** @var array<string, int|null> */
    public array $documentCounts = [];

    /** @var array<string, int|null> */
    public array $distinctParentCounts = [];

    /** @var array<string, bool> */
    public array $existingDocuments = [];

    /** @var array<string, array<string, mixed>> */
    public array $documentsByElementId = [];

    /** @var array<string, mixed> */
    public array $searchResponse = [
        'hits' => [],
        'total' => 0,
    ];

    /**
     * Optional per-site responses for multi-site resolver tests.
     *
     * @var array<int, array<string, mixed>>
     * @since 5.54.0
     */
    public array $searchResponsesBySiteId = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     * @since 5.54.0
     */
    public array $searchHitPoolsBySiteId = [];

    /** @var array<string, mixed> */
    public array $searchMultipleResponse = [
        'hits' => [],
        'total' => 0,
        'indices' => [],
    ];

    /**
     * @var array<int, list<array<string, mixed>>>
     * @since 5.54.0
     */
    public array $searchMultipleHitPoolsBySiteId = [];

    /**
     * @since 5.54.0
     */
    public string $searchPaginationMode = 'none';

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchIndex', 'indexName' => $indexName, 'items' => $items];

        if (!$this->failBatchIndex) {
            $this->documentCounts[$indexName] = count($items);
            $parents = [];
            foreach ($items as $item) {
                $elementId = \lindemannrock\searchmanager\helpers\SearchHitIdentityHelper::elementId($item);
                $siteId = isset($item['siteId']) ? (int)$item['siteId'] : null;
                if ($elementId !== null) {
                    $parents[$elementId . ':' . ($siteId ?? 'null')] = true;
                }
            }
            $parentCountsBySite = [];
            foreach (array_keys($parents) as $parentKey) {
                [, $siteId] = explode(':', $parentKey, 2);
                $parentCountsBySite[$siteId] = ($parentCountsBySite[$siteId] ?? 0) + 1;
            }
            foreach ($parentCountsBySite as $siteId => $parentCount) {
                $this->distinctParentCounts[$indexName . ':' . $siteId] = $parentCount;
            }
        }

        return !$this->failBatchIndex;
    }

    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int
    {
        $this->calls[] = ['method' => 'getDocumentCount', 'indexName' => $indexName];

        return $this->documentCounts[$indexName] ?? 0;
    }

    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int
    {
        $this->calls[] = ['method' => 'getDistinctParentCount', 'indexName' => $indexName];

        return $this->distinctParentCounts[$indexName . ':' . ($siteId ?? 'null')] ?? 0;
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

        if (in_array($indexName, $this->throwBatchDeleteIndices, true)) {
            throw new \RuntimeException("Synthetic batch-delete failure for {$indexName}");
        }

        return !$this->failBatchDelete && !in_array($indexName, $this->failBatchDeleteIndices, true);
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        $this->calls[] = [
            'method' => 'deleteOrphanDocuments',
            'indexName' => $indexName,
            'items' => [[
                'elementId' => $elementId,
                'siteId' => $siteId,
                'keepBackendIds' => $keepBackendIds,
            ]],
        ];

        if (in_array($indexName, $this->throwBatchDeleteIndices, true)) {
            throw new \RuntimeException("Synthetic orphan-delete failure for {$indexName}");
        }

        $failed = $this->failBatchDelete || in_array($indexName, $this->failBatchDeleteIndices, true);
        if (!$failed && $keepBackendIds === []) {
            $this->documentCounts[$indexName] = 0;
            $this->distinctParentCounts[$indexName . ':' . ($siteId ?? 'null')] = 0;
        }

        return !$failed;
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

        if (in_array($indexName, $this->throwDeleteIndices, true)) {
            throw new \RuntimeException("Synthetic delete failure for {$indexName}");
        }

        if ($this->failDelete || in_array($indexName, $this->failDeleteIndices, true)) {
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

    /**
     * @param array<int, int> $elementIds
     * @return array<int, array<string, mixed>>
     */
    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        $this->calls[] = [
            'method' => 'getDocumentsByElementIds',
            'indexName' => $indexName,
            'items' => [[
                'elementIds' => $elementIds,
                'siteId' => $siteId,
            ]],
        ];

        $documents = [];
        foreach ($elementIds as $elementId) {
            $key = $this->documentKey($indexName, (int)$elementId, $siteId);
            if ($key !== null && isset($this->documentsByElementId[$key])) {
                $documents[(int)$elementId] = $this->documentsByElementId[$key];
            }
        }

        return $documents;
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->calls[] = ['method' => 'clearSearchCache', 'indexName' => $indexName];
    }

    public function clearAllSearchCache(): void
    {
        $this->calls[] = ['method' => 'clearAllSearchCache', 'indexName' => '*'];
    }

    /**
     * @since 5.54.0
     */
    public function clearIndex(string $indexName): bool
    {
        $this->calls[] = ['method' => 'clearIndex', 'indexName' => $indexName];

        return true;
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

        $siteId = isset($options['siteId']) ? (int)$options['siteId'] : null;
        if ($siteId !== null && isset($this->searchHitPoolsBySiteId[$siteId])) {
            return $this->paginateSearchHitPool($this->searchHitPoolsBySiteId[$siteId], $options);
        }

        return $siteId !== null && isset($this->searchResponsesBySiteId[$siteId])
            ? $this->searchResponsesBySiteId[$siteId]
            : $this->searchResponse;
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

        $siteId = isset($options['siteId']) ? (int)$options['siteId'] : null;
        if ($siteId !== null && isset($this->searchMultipleHitPoolsBySiteId[$siteId])) {
            $hitPool = $this->searchMultipleHitPoolsBySiteId[$siteId];
            $response = $this->paginateSearchHitPool($hitPool, $options);
            $response['indices'] = array_fill_keys($indexNames, 0);
            foreach ($hitPool as $hit) {
                $indexHandle = $hit['_index'] ?? null;
                if (is_string($indexHandle) && array_key_exists($indexHandle, $response['indices'])) {
                    $response['indices'][$indexHandle]++;
                }
            }

            return $response;
        }

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

    /**
     * @param list<array<string, mixed>> $hits
     * @param array<string, mixed> $options
     * @return array{hits: list<array<string, mixed>>, total: int}
     */
    private function paginateSearchHitPool(array $hits, array $options): array
    {
        $total = count($hits);
        $limit = (int)($options['limit'] ?? 0);
        $offset = (int)($options['offset'] ?? 0);
        if ($this->searchPaginationMode === 'page' && $limit > 0) {
            $offset = (int)($options['page'] ?? 0) * $limit;
        }

        if ($limit > 0) {
            $hits = array_slice($hits, $offset, $limit);
        } elseif ($offset > 0) {
            $hits = array_slice($hits, $offset);
        }

        return [
            'hits' => array_values($hits),
            'total' => $total,
        ];
    }
}
