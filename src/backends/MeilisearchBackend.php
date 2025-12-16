<?php

namespace lindemannrock\searchmanager\backends;

use MeiliSearch\Client;

/**
 * Meilisearch Backend
 *
 * Search backend adapter for Meilisearch
 * Cost-effective, self-hosted alternative to Algolia
 */
class MeilisearchBackend extends BaseBackend
{
    private ?Client $_client = null;

    // =========================================================================
    // BACKEND INTERFACE IMPLEMENTATION
    // =========================================================================

    public function getName(): string
    {
        return 'meilisearch';
    }

    public function isAvailable(): bool
    {
        $settings = $this->getBackendSettings();

        if (empty($settings['host'])) {
            return false;
        }

        try {
            $client = $this->getClient();
            $client->health();
            return true;
        } catch (\Throwable $e) {
            $this->logError('Meilisearch health check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getStatus(): array
    {
        $settings = $this->getBackendSettings();

        return [
            'name' => 'Meilisearch',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => !empty($settings['host']),
            'available' => $this->isAvailable(),
            'host' => $settings['host'] ?? null,
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);
            $index->addDocuments([$data], 'objectID');

            $this->logDebug('Document indexed in Meilisearch', [
                'index' => $fullIndexName,
                'id' => $data['objectID'] ?? $data['id'] ?? 'unknown',
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to index document in Meilisearch', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);
            $index->addDocuments($items, 'objectID');

            $this->logInfo('Batch indexed in Meilisearch', [
                'index' => $fullIndexName,
                'count' => count($items),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Meilisearch', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);
            $index->deleteDocument($elementId);

            $this->logDebug('Document deleted from Meilisearch', [
                'index' => $fullIndexName,
                'id' => $elementId,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete document from Meilisearch', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);
            $results = $index->search($query, $options);

            return [
                'hits' => $results->getHits(),
                'total' => $results->getEstimatedTotalHits(),
                'processingTime' => $results->getProcessingTimeMs(),
            ];
        } catch (\Throwable $e) {
            $this->logError('Meilisearch search failed', [
                'error' => $e->getMessage(),
            ]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);
            $index->deleteAllDocuments();

            $this->logInfo('Cleared Meilisearch index', ['index' => $fullIndexName]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Meilisearch index', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function getClient(): Client
    {
        if ($this->_client === null) {
            $settings = $this->getBackendSettings();

            $this->_client = new Client(
                $this->resolveEnvVar($settings['host'] ?? null, 'http://localhost:7700'),
                $this->resolveEnvVar($settings['apiKey'] ?? null, null),
                (int)$this->resolveEnvVar($settings['timeout'] ?? null, 5)
            );
        }

        return $this->_client;
    }
}
