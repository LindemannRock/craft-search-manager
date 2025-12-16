<?php

namespace lindemannrock\searchmanager\backends;

use Typesense\Client;

/**
 * Typesense Backend
 *
 * Search backend adapter for Typesense
 * Open-source alternative to Algolia/Meilisearch
 */
class TypesenseBackend extends BaseBackend
{
    private ?Client $_client = null;

    public function getName(): string
    {
        return 'typesense';
    }

    public function isAvailable(): bool
    {
        $settings = $this->getBackendSettings();
        return !empty($settings['host']) && !empty($settings['apiKey']);
    }

    public function getStatus(): array
    {
        $settings = $this->getBackendSettings();
        return [
            'name' => 'Typesense',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => !empty($settings['host']) && !empty($settings['apiKey']),
            'available' => $this->isAvailable(),
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $client->collections[$fullIndexName]->documents->upsert($data);
            $this->logDebug('Document indexed in Typesense', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in Typesense', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $client->collections[$fullIndexName]->documents->import($items, ['action' => 'upsert']);
            $this->logInfo('Batch indexed in Typesense', ['index' => $fullIndexName, 'count' => count($items)]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Typesense', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $client->collections[$fullIndexName]->documents[(string)$elementId]->delete();
            $this->logDebug('Document deleted from Typesense', ['index' => $fullIndexName, 'id' => $elementId]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from Typesense', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $searchParams = array_merge(['q' => $query, 'query_by' => 'title,content'], $options);
            $results = $client->collections[$fullIndexName]->documents->search($searchParams);
            return ['hits' => $results['hits'] ?? [], 'total' => $results['found'] ?? 0];
        } catch (\Throwable $e) {
            $this->logError('Typesense search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $client->collections[$fullIndexName]->documents->delete(['filter_by' => 'id:>0']);
            $this->logInfo('Cleared Typesense index', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Typesense index', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function getClient(): Client
    {
        if ($this->_client === null) {
            $settings = $this->getBackendSettings();
            $this->_client = new Client([
                'nodes' => [[
                    'host' => $this->resolveEnvVar($settings['host'] ?? null, 'localhost'),
                    'port' => $this->resolveEnvVar($settings['port'] ?? null, '8108'),
                    'protocol' => $this->resolveEnvVar($settings['protocol'] ?? null, 'http'),
                ]],
                'api_key' => $this->resolveEnvVar($settings['apiKey'] ?? null, ''),
                'connection_timeout_seconds' => (int)$this->resolveEnvVar($settings['connectionTimeout'] ?? null, 5),
            ]);
        }
        return $this->_client;
    }
}
