<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\interfaces\IndexCountBackendInterface;
use lindemannrock\searchmanager\models\BulkMutationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Owns destructive Index storage, metadata, count, and cache maintenance.
 *
 * @since 5.54.0
 */
class IndexMaintenanceService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Clear one Index while retaining its definition.
     *
     * @return array<string, mixed>
     */
    public function clearIndex(SearchIndex $index): array
    {
        $authoritativeIndex = $this->resolveAuthoritativeIndex($index);
        if ($authoritativeIndex === null) {
            return $this->missingIndexResult($index->id, 'clear');
        }

        $index = $authoritativeIndex;
        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $index->handle,
            DependencyService::ACTION_CLEAR_DATA,
        );
        if (!$capability['allowed']) {
            return $this->capabilityFailureResult($index, 'clear', $capability);
        }

        $result = $this->clearStorageAndReconcile($index, 'clear');
        if ($result['status'] !== 'success') {
            return $result;
        }

        $result['message'] = Craft::t('search-manager', 'Index data cleared');
        return $result;
    }

    /**
     * Clear every supplied Index sequentially.
     *
     * Safe pre-mutation failures do not block later indices. An irreversible
     * partial result stops the batch and leaves later indices unattempted.
     *
     * @param list<SearchIndex> $indices
     * @return array<string, mixed>
     */
    public function clearIndices(array $indices): array
    {
        $planned = [];
        foreach ($indices as $index) {
            $planned[] = [
                'index' => $this->resolveAuthoritativeIndex($index),
                'id' => $index->id,
            ];
        }

        $results = [];
        $stop = false;

        foreach ($planned as $item) {
            $index = $item['index'];
            if ($stop) {
                $results[] = $index === null
                    ? $this->unattemptedIdentityResult($item['id'], 'clear')
                    : $this->unattemptedResult($index, 'clear');
                continue;
            }

            if ($index === null) {
                $results[] = $this->missingIndexResult($item['id'], 'clear');
                continue;
            }

            $result = $this->clearIndex($index);
            $results[] = $result;
            if ($result['status'] === 'partial') {
                $stop = true;
            }
        }

        return $this->summarize($results);
    }

    /**
     * Delete one editable, unused Index.
     *
     * @return array<string, mixed>
     */
    public function deleteIndex(SearchIndex $index): array
    {
        $authoritativeIndex = $this->resolveAuthoritativeIndex($index);
        if ($authoritativeIndex === null) {
            return $this->missingIndexResult($index->id, 'delete');
        }

        $index = $authoritativeIndex;
        $preflightError = $this->preflightAuthoritativeDelete($index);
        if ($preflightError !== null) {
            $capability = $this->deleteCapability($index);
            return !$capability['allowed']
                ? $this->capabilityFailureResult($index, 'delete', $capability)
                : $this->failureResult($index, 'delete', $preflightError);
        }

        $result = $this->clearStorageAndReconcile($index, 'delete');
        if ($result['status'] !== 'success') {
            return $result;
        }

        try {
            $this->deleteIndexMetadata($index);
        } catch (\Throwable $e) {
            $this->logError('Index metadata deletion failed after storage was cleared', [
                'index' => $index->handle,
                'error' => $e->getMessage(),
            ]);

            return array_merge($result, [
                'status' => 'partial',
                'success' => false,
                'metadataDeleted' => false,
                'error' => Craft::t('search-manager', 'Could not delete index'),
                'recovery' => [
                    'rebuildIndex' => true,
                    'retryDelete' => true,
                ],
            ]);
        }

        return array_merge($result, [
            'metadataDeleted' => true,
            'message' => Craft::t('search-manager', 'Index deleted'),
            'recovery' => [],
        ]);
    }

    /**
     * Normalize, preflight, and delete selected Index records.
     *
     * @return array<string, mixed>
     */
    public function deleteIndices(mixed $rawIdentifiers): array
    {
        $normalized = BulkMutationResult::fromIdentifiers($rawIdentifiers);
        if (!$normalized->canMutate()) {
            return array_merge($normalized->toArray(), [
                'changed' => false,
                'results' => [],
            ]);
        }

        $planned = [];
        $resultsById = [];
        $skipped = 0;

        // Complete every preflight before the first destructive write.
        foreach ($normalized->identifiers() as $id) {
            $index = $this->findIndexById($id);
            if ($index === null) {
                $skipped++;
                continue;
            }

            $preflightError = $this->preflightAuthoritativeDelete($index);
            if ($preflightError !== null) {
                $capability = $this->deleteCapability($index);
                $resultsById[$id] = !$capability['allowed']
                    ? $this->capabilityFailureResult($index, 'delete', $capability)
                    : $this->failureResult($index, 'delete', $preflightError);
                continue;
            }

            $planned[$id] = $index;
        }

        $stop = false;
        foreach ($planned as $id => $index) {
            if ($stop) {
                $resultsById[$id] = $this->unattemptedResult($index, 'delete');
                continue;
            }

            $result = $this->deleteIndex($index);
            $resultsById[$id] = $result;
            if ($result['status'] === 'partial') {
                $stop = true;
            }
        }

        $orderedResults = [];
        foreach ($normalized->identifiers() as $id) {
            if (isset($resultsById[$id])) {
                $orderedResults[] = $resultsById[$id];
            }
        }

        return $this->summarize($orderedResults, $skipped);
    }

    /**
     * Return the current deletion guard result without mutating state.
     */
    public function preflightDelete(SearchIndex $index): ?string
    {
        $authoritativeIndex = $this->resolveAuthoritativeIndex($index);
        if ($authoritativeIndex === null) {
            return $this->failureMessage('delete');
        }

        return $this->preflightAuthoritativeDelete($authoritativeIndex);
    }

    /**
     * Check deletion guards against an already-authoritative Index.
     */
    protected function preflightAuthoritativeDelete(SearchIndex $index): ?string
    {
        $capability = $this->deleteCapability($index);

        return $capability['allowed'] ? null : $capability['reason'];
    }

    /** @return array{allowed: bool, reasonCode: string|null, reason: string|null} */
    private function deleteCapability(SearchIndex $index): array
    {
        return SearchManager::$plugin->dependencies->getIndexActionCapability(
            $index->handle,
            DependencyService::ACTION_DELETE,
        );
    }

    /**
     * Clear the recoverable caches scoped to one authoritative index handle.
     *
     * @return array<string, mixed>
     * @since 5.54.0
     */
    public function clearIndexCache(SearchIndex $index): array
    {
        $authoritativeIndex = $this->resolveAuthoritativeIndex($index);
        if ($authoritativeIndex === null) {
            return $this->missingIndexResult($index->id, 'clear-cache');
        }

        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $authoritativeIndex->handle,
            DependencyService::ACTION_CLEAR_CACHE,
        );
        if (!$capability['allowed']) {
            return $this->capabilityFailureResult($authoritativeIndex, 'clear-cache', $capability);
        }

        $errors = $this->invalidateIndexCaches($authoritativeIndex);
        if ($errors !== []) {
            return $this->failureResult(
                $authoritativeIndex,
                'clear-cache',
                Craft::t('search-manager', 'Failed to clear cache'),
            );
        }

        return array_merge($this->baseResult($authoritativeIndex, 'clear-cache'), [
            'status' => 'success',
            'success' => true,
            'cachesInvalidated' => true,
            'message' => Craft::t('search-manager', 'Cache cleared for "{name}"', [
                'name' => $authoritativeIndex->name,
            ]),
        ]);
    }

    /**
     * Synchronize document-count metadata through a strict external backend target.
     *
     * @return array<string, mixed>
     * @since 5.54.0
     */
    public function syncIndexCount(SearchIndex $index): array
    {
        $authoritativeIndex = $this->resolveAuthoritativeIndex($index);
        if ($authoritativeIndex === null) {
            return $this->missingIndexResult($index->id, 'sync-count');
        }

        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $authoritativeIndex->handle,
            DependencyService::ACTION_SYNC_COUNT,
        );
        if (!$capability['allowed']) {
            return $this->capabilityFailureResult($authoritativeIndex, 'sync-count', $capability);
        }

        $target = SearchManager::$plugin->dependencies->getStrictBackendTarget(
            $authoritativeIndex->handle,
            DependencyService::ACTION_SYNC_COUNT,
        );
        if ($target === null || !$target['backend'] instanceof IndexCountBackendInterface) {
            return $this->capabilityFailureResult($authoritativeIndex, 'sync-count', [
                'reasonCode' => 'backend-count-unsupported',
                'reason' => Craft::t('search-manager', 'This backend does not support syncing the document count.'),
            ]);
        }

        try {
            $count = $target['backend']->getDocumentCount($authoritativeIndex->handle);
            if ($count === null) {
                return $this->failureResult(
                    $authoritativeIndex,
                    'sync-count',
                    Craft::t('search-manager', 'Failed to sync count'),
                );
            }
            if (!$authoritativeIndex->updateStats($count)) {
                return $this->failureResult(
                    $authoritativeIndex,
                    'sync-count',
                    Craft::t('search-manager', 'Failed to update index stats'),
                );
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to sync count from backend', [
                'index' => $authoritativeIndex->handle,
                'error' => $e->getMessage(),
            ]);

            return $this->failureResult(
                $authoritativeIndex,
                'sync-count',
                Craft::t('search-manager', 'Failed to sync count'),
            );
        }

        return array_merge($this->baseResult($authoritativeIndex, 'sync-count'), [
            'status' => 'success',
            'success' => true,
            'count' => $count,
            'message' => Craft::t('search-manager', 'Count synced for "{name}": {count} documents', [
                'name' => $authoritativeIndex->name,
                'count' => number_format($count),
            ]),
        ]);
    }

    /**
     * Reload models so caller-mutable properties never select the storage
     * target, guards, count row, cache identity, or metadata row.
     */
    protected function resolveAuthoritativeIndex(SearchIndex $index): ?SearchIndex
    {
        if ($index->id !== null) {
            return $this->findIndexById($index->id);
        }

        if ($index->source !== 'config' || $index->handle === '') {
            return null;
        }

        $authoritativeIndex = $this->findIndexByHandle($index->handle);
        return $authoritativeIndex?->source === 'config'
            ? $authoritativeIndex
            : null;
    }

    protected function findIndexById(int $id): ?SearchIndex
    {
        return SearchIndex::findById($id);
    }

    protected function findIndexByHandle(string $handle): ?SearchIndex
    {
        return SearchIndex::findByHandle($handle);
    }

    /**
     * Clear external/local storage, then reconcile metadata and caches.
     *
     * @return array<string, mixed>
     */
    protected function clearStorageAndReconcile(SearchIndex $index, string $operation): array
    {
        try {
            if (!$this->clearBackendStorage($index, $operation)) {
                return $this->failureResult(
                    $index,
                    $operation,
                    Craft::t('search-manager', 'Failed to clear index data'),
                );
            }
        } catch (\Throwable $e) {
            $this->logError('Index storage clear failed', [
                'index' => $index->handle,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);

            return $this->failureResult(
                $index,
                $operation,
                Craft::t('search-manager', 'Failed to clear index data'),
            );
        }

        $countReconciled = $this->updateDocumentCount($index);
        $cacheErrors = $this->invalidateIndexCaches($index);

        $result = $this->baseResult($index, $operation);
        $result['storageCleared'] = true;
        $result['countReconciled'] = $countReconciled;
        $result['cachesInvalidated'] = $cacheErrors === [];

        if (!$countReconciled || $cacheErrors !== []) {
            $result['status'] = 'partial';
            $result['success'] = false;
            $result['error'] = $this->failureMessage($operation);
            $result['recovery'] = [
                'rebuildIndex' => true,
                'retryDelete' => $operation === 'delete',
            ];

            return $result;
        }

        $result['status'] = 'success';
        $result['success'] = true;
        return $result;
    }

    protected function clearBackendStorage(SearchIndex $index, string $operation = 'clear'): bool
    {
        if ($operation !== 'delete') {
            return SearchManager::$plugin->backend->clearIndex($index->handle);
        }

        $target = SearchManager::$plugin->dependencies->getStrictBackendTarget(
            $index->handle,
            DependencyService::ACTION_DELETE,
        );

        return $target !== null && $target['backend']->clearIndex($index->handle);
    }

    protected function updateDocumentCount(SearchIndex $index): bool
    {
        return $index->updateStats(0);
    }

    /**
     * @return list<string>
     */
    protected function invalidateIndexCaches(SearchIndex $index): array
    {
        $errors = [];

        try {
            SearchManager::$plugin->backend->clearSearchCache($index->handle);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            $this->logError('Failed to clear search cache after index storage maintenance', [
                'index' => $index->handle,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            SearchManager::$plugin->autocomplete->clearCache($index->handle);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            $this->logError('Failed to clear autocomplete cache after index storage maintenance', [
                'index' => $index->handle,
                'error' => $e->getMessage(),
            ]);
        }

        return $errors;
    }

    /**
     * Delete the definition and site mappings in one database transaction.
     */
    protected function deleteIndexMetadata(SearchIndex $index): void
    {
        if (!$index->id) {
            throw new \RuntimeException('Cannot delete Index metadata without an ID.');
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $index->id])
                ->execute();

            $deleted = $db->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $index->id])
                ->execute();
            if ($deleted !== 1) {
                throw new \RuntimeException('Index definition was not deleted.');
            }

            $transaction->commit();
            SearchIndex::clearCache();
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseResult(SearchIndex $index, string $operation): array
    {
        return $this->baseIdentityResult($index->id, $index->handle, $index->name, $operation);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseIdentityResult(?int $id, string $handle, string $name, string $operation): array
    {
        return [
            'status' => 'failure',
            'success' => false,
            'operation' => $operation,
            'id' => $id,
            'handle' => $handle,
            'name' => $name,
            'storageCleared' => false,
            'countReconciled' => false,
            'cachesInvalidated' => false,
            'metadataDeleted' => false,
            'error' => null,
            'reasonCode' => null,
            'message' => null,
            'recovery' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingIndexResult(?int $id, string $operation): array
    {
        return array_merge($this->baseIdentityResult($id, '', '', $operation), [
            'error' => $this->failureMessage($operation),
        ]);
    }

    private function failureMessage(string $operation): string
    {
        return match ($operation) {
            'delete' => Craft::t('search-manager', 'Could not delete index'),
            'clear-cache' => Craft::t('search-manager', 'Failed to clear cache'),
            'sync-count' => Craft::t('search-manager', 'Failed to sync count'),
            default => Craft::t('search-manager', 'Failed to clear index data'),
        };
    }

    /**
     * @param array{reasonCode: string|null, reason: string|null} $capability
     * @return array<string, mixed>
     */
    private function capabilityFailureResult(SearchIndex $index, string $operation, array $capability): array
    {
        return array_merge($this->baseResult($index, $operation), [
            'reasonCode' => $capability['reasonCode'],
            'error' => $capability['reason'] ?? $this->failureMessage($operation),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureResult(SearchIndex $index, string $operation, string $error): array
    {
        return array_merge($this->baseResult($index, $operation), [
            'error' => $error,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unattemptedResult(SearchIndex $index, string $operation): array
    {
        return array_merge($this->baseResult($index, $operation), [
            'status' => 'unattempted',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function unattemptedIdentityResult(?int $id, string $operation): array
    {
        return array_merge($this->baseIdentityResult($id, '', '', $operation), [
            'status' => 'unattempted',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>
     */
    private function summarize(array $results, int $skipped = 0): array
    {
        $count = 0;
        $changed = false;
        $errors = [];
        $hasPartial = false;

        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                $count++;
            }
            if (($result['storageCleared'] ?? false) === true || ($result['metadataDeleted'] ?? false) === true) {
                $changed = true;
            }
            if ($result['status'] === 'partial') {
                $hasPartial = true;
            }
            if (is_string($result['error'] ?? null) && $result['error'] !== '') {
                $errors[] = $result['error'];
            }
        }

        $hasNonSuccess = count($results) !== $count;
        $status = match (true) {
            $hasPartial || ($count > 0 && $hasNonSuccess) => 'partial',
            $count > 0 && !$hasNonSuccess => 'success',
            default => 'failure',
        };

        return [
            'status' => $status,
            'success' => $status === 'success',
            'count' => $count,
            'skipped' => $skipped,
            'changed' => $changed,
            'errors' => $errors,
            'results' => $results,
        ];
    }
}
