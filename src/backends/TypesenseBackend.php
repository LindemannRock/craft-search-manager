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

        if (empty($settings['host']) || empty($settings['apiKey'])) {
            return false;
        }

        try {
            // Actually test the connection with health check
            $client = $this->getClient();
            $client->health->retrieve();
            return true;
        } catch (\Throwable $e) {
            $this->logError('Typesense connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Try to retrieve the document - if it exists, return true
            $client->collections[$fullIndexName]->documents[(string)$elementId]->retrieve();
            return true;
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            // Document not found - this is expected
            return false;
        } catch (\Throwable $e) {
            $this->logError('Failed to check document existence in Typesense', [
                'index' => $indexName,
                'elementId' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // BROWSE / MULTI-QUERY / FILTER METHODS
    // =========================================================================

    /**
     * Browse all documents in an index
     *
     * @param string $indexName Index to browse
     * @param string $query Optional query to filter (not used in browse)
     * @param array $parameters Parameters like 'filter_by', 'include_fields'
     * @return iterable Array of all documents
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Typesense export returns JSONL, we need to parse it
            $exportParams = [];
            if (!empty($parameters['filter_by'])) {
                $exportParams['filter_by'] = $parameters['filter_by'];
            }
            if (!empty($parameters['include_fields'])) {
                $exportParams['include_fields'] = $parameters['include_fields'];
            }

            $exported = $client->collections[$fullIndexName]->documents->export($exportParams);

            // Parse JSONL response
            $documents = [];
            $lines = explode("\n", trim($exported));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $doc = json_decode($line, true);
                    if ($doc !== null) {
                        $documents[] = $doc;
                    }
                }
            }

            return $documents;
        } catch (\Throwable $e) {
            $this->logError('Typesense browse failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Perform multiple search queries in a single request
     *
     * @param array $queries Array of query objects with 'indexName', 'query', and optional 'params'
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array
    {
        try {
            $client = $this->getClient();

            // Build Typesense multi-search format
            $searchRequests = ['searches' => []];
            foreach ($queries as $query) {
                $indexName = $this->getFullIndexName($query['indexName'] ?? '');
                $params = $query['params'] ?? [];

                $searchRequests['searches'][] = array_merge([
                    'collection' => $indexName,
                    'q' => $query['query'] ?? '',
                    'query_by' => $params['query_by'] ?? 'title,content',
                ], $params);
            }

            $results = $client->multiSearch->perform($searchRequests, []);

            $this->logDebug('Typesense multiple queries executed', ['count' => count($queries)]);

            return ['results' => $results['results'] ?? []];
        } catch (\Throwable $e) {
            $this->logError('Typesense multiple queries failed', ['error' => $e->getMessage()]);
            return ['results' => []];
        }
    }

    /**
     * Parse filters array into Typesense filter string
     *
     * Typesense syntax: key:=value && key2:=[value1, value2]
     *
     * @param array $filters Key/value pairs of filters
     * @return string Typesense-compatible filter string
     */
    public function parseFilters(array $filters = []): string
    {
        $filterParts = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                // Multiple values - use array syntax
                $values = array_map(function($v) {
                    if (is_bool($v)) {
                        return $v ? 'true' : 'false';
                    }
                    return '`' . $v . '`';
                }, $value);
                $filterParts[] = $key . ':=[' . implode(', ', $values) . ']';
            } else {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                    $filterParts[] = $key . ':=' . $value;
                } else {
                    $filterParts[] = $key . ':=`' . $value . '`';
                }
            }
        }

        return implode(' && ', $filterParts);
    }

    public function supportsBrowse(): bool
    {
        return true;
    }

    public function supportsMultipleQueries(): bool
    {
        return true;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

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
