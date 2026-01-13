<?php

namespace lindemannrock\searchmanager\backends;

use Algolia\AlgoliaSearch\Api\SearchClient;

/**
 * Algolia Backend
 *
 * Search backend adapter for Algolia v4 API
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

        if (empty($settings['applicationId']) || empty($settings['adminApiKey'])) {
            return false;
        }

        try {
            // Actually test the connection by listing indices
            $client = $this->getClient();
            $client->listIndices();
            return true;
        } catch (\Throwable $e) {
            $this->logError('Algolia connection test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
            $client->saveObject($fullIndexName, $data);
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
            $client->saveObjects($fullIndexName, $items);
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
            $client->deleteObject($fullIndexName, (string)$elementId);
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

            // Filter out search-manager internal options that Algolia doesn't understand
            $internalOptions = ['siteId', 'source', 'platform', 'appVersion'];
            $searchParams = array_diff_key($options, array_flip($internalOptions));
            $searchParams['query'] = $query;

            $results = $client->searchSingleIndex($fullIndexName, $searchParams);

            return ['hits' => $results['hits'] ?? [], 'total' => $results['nbHits'] ?? 0];
        } catch (\Throwable $e) {
            $this->logError('Algolia search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    /**
     * Browse an index (iterate through all objects)
     *
     * Compatible with trendyminds/algolia browse() method
     *
     * @param string $indexName Index to browse
     * @param string $query Optional query to filter results
     * @param array $browseParameters Additional browse parameters
     * @return iterable Iterator of all matching objects
     */
    public function browse(string $indexName, string $query = '', array $browseParameters = []): iterable
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Algolia v4 browseObjects returns an iterator
            $requestOptions = [];
            if (!empty($query)) {
                $requestOptions['query'] = $query;
            }
            $requestOptions = array_merge($requestOptions, $browseParameters);

            return $client->browseObjects($fullIndexName, $requestOptions);
        } catch (\Throwable $e) {
            $this->logError('Algolia browse failed', ['error' => $e->getMessage()]);
            return new \EmptyIterator();
        }
    }

    /**
     * Perform multiple queries at once
     *
     * Compatible with trendyminds/algolia multipleQueries() method
     *
     * @param array $queries Array of query objects with 'indexName', 'query', and optional 'params'
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array
    {
        try {
            $client = $this->getClient();

            // Build requests for v4 API
            $requests = [];
            foreach ($queries as $query) {
                $indexName = $this->getFullIndexName($query['indexName'] ?? '');
                $params = $query['params'] ?? [];
                $requests[] = array_merge([
                    'indexName' => $indexName,
                    'query' => $query['query'] ?? '',
                ], $params);
            }

            // v4 API uses search() with SearchMethodParams
            $results = $client->search(['requests' => $requests]);

            $this->logDebug('Multiple queries executed', ['count' => count($queries)]);
            return $results;
        } catch (\Throwable $e) {
            $this->logError('Algolia multiple queries failed', ['error' => $e->getMessage()]);
            return ['results' => []];
        }
    }

    /**
     * Parse filters array into Algolia filter string
     *
     * Compatible with trendyminds/algolia parseFilters() method
     * Converts key/value pairs into Algolia's filter syntax
     * Syntax: (key:"value1" OR key:"value2") AND (key2:"value")
     *
     * @param array $filters Key/value pairs of filters
     * @return string Algolia-compatible filter string
     */
    public function parseFilters(array $filters = []): string
    {
        $filterParts = [];

        foreach ($filters as $group => $items) {
            // Skip null or empty values
            if ($items === null || $items === '') {
                continue;
            }

            // Ensure items is an array
            if (!is_array($items)) {
                $items = [$items];
            }

            // Convert boolean values to strings
            $items = array_map(function($item) {
                if (is_bool($item)) {
                    return $item ? 'true' : 'false';
                }
                return $item;
            }, $items);

            // Build OR group for multiple values within same filter
            $orParts = array_map(function($item) use ($group) {
                return $group . ':"' . $item . '"';
            }, $items);

            $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
        }

        // Combine all filter groups with AND
        return implode(' AND ', $filterParts);
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $client->clearObjects($fullIndexName);
            $this->logInfo('Cleared Algolia index', ['index' => $fullIndexName]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Algolia index', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Try to get the object - if it exists, return true
            $client->getObject($fullIndexName, (string)$elementId);
            return true;
        } catch (\Algolia\AlgoliaSearch\Exceptions\NotFoundException $e) {
            // Object not found - this is expected
            return false;
        } catch (\Throwable $e) {
            $this->logError('Failed to check document existence in Algolia', [
                'index' => $indexName,
                'elementId' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function supportsBrowse(): bool
    {
        return true;
    }

    public function supportsMultipleQueries(): bool
    {
        return true;
    }

    /**
     * List all indices in Algolia account
     *
     * @return array Array of index information
     */
    public function listIndices(): array
    {
        try {
            $client = $this->getClient();
            $response = $client->listIndices();

            $indices = [];
            foreach ($response['items'] ?? [] as $index) {
                $indices[] = [
                    'name' => $index['name'] ?? '',
                    'entries' => $index['entries'] ?? 0,
                    'dataSize' => $index['dataSize'] ?? 0,
                    'lastBuildTimeS' => $index['lastBuildTimeS'] ?? 0,
                    'updatedAt' => $index['updatedAt'] ?? null,
                    'createdAt' => $index['createdAt'] ?? null,
                    'source' => 'algolia',
                ];
            }

            return $indices;
        } catch (\Throwable $e) {
            $this->logError('Failed to list Algolia indices', ['error' => $e->getMessage()]);
            return [];
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
