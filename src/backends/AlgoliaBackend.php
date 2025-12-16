<?php

namespace lindemannrock\searchmanager\backends;

use Algolia\AlgoliaSearch\SearchClient;

/**
 * Algolia Backend
 *
 * Search backend adapter for Algolia
 * Drop-in replacement for Scout + Algolia setups
 */
class AlgoliaBackend extends BaseBackend
{
    private ?SearchClient $_client = null;

    public function getName(): string
    {
        return 'algolia';
    }

    public function isAvailable(): bool
    {
        $settings = $this->getBackendSettings();
        return !empty($settings['applicationId']) &&
               !empty($settings['adminApiKey']);
    }

    public function getStatus(): array
    {
        $settings = $this->getBackendSettings();
        return [
            'name' => 'Algolia',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => !empty($settings['applicationId']) && !empty($settings['adminApiKey']),
            'available' => $this->isAvailable(),
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->initIndex($fullIndexName);
            $index->saveObject($data);
            $this->logDebug('Document indexed in Algolia', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in Algolia', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->initIndex($fullIndexName);
            $index->saveObjects($items);
            $this->logInfo('Batch indexed in Algolia', ['index' => $fullIndexName, 'count' => count($items)]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Algolia', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->initIndex($fullIndexName);
            $index->deleteObject($elementId);
            $this->logDebug('Document deleted from Algolia', ['index' => $fullIndexName, 'id' => $elementId]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from Algolia', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->initIndex($fullIndexName);
            $results = $index->search($query, $options);
            return ['hits' => $results['hits'], 'total' => $results['nbHits']];
        } catch (\Throwable $e) {
            $this->logError('Algolia search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->initIndex($fullIndexName);
            $index->clearObjects();
            $this->logInfo('Cleared Algolia index', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Algolia index', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getClient(): SearchClient
    {
        if ($this->_client === null) {
            $settings = $this->getBackendSettings();
            $this->_client = SearchClient::create(
                $this->resolveEnvVar($settings['applicationId'] ?? null, ''),
                $this->resolveEnvVar($settings['adminApiKey'] ?? null, '')
            );
        }
        return $this->_client;
    }
}
