<?php

namespace lindemannrock\searchmanager\backends;

use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\SearchQuery;

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

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);

            // Try to get the document - if it exists, return true
            $index->getDocument($elementId);
            return true;
        } catch (\Meilisearch\Exceptions\ApiException $e) {
            // Document not found - this is expected
            if ($e->httpStatus === 404) {
                return false;
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('Failed to check document existence in Meilisearch', [
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
     * @param string $query Optional query to filter (not used in browse, use search instead)
     * @param array $parameters Parameters like 'limit', 'offset', 'fields'
     * @return iterable Array of all documents
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $index = $client->index($fullIndexName);

            $limit = $parameters['limit'] ?? 1000;
            $offset = $parameters['offset'] ?? 0;
            $fields = $parameters['fields'] ?? null;

            $query = (new DocumentsQuery())
                ->setLimit($limit)
                ->setOffset($offset);

            if ($fields !== null) {
                $query->setFields($fields);
            }

            $results = $index->getDocuments($query);

            return $results->getResults();
        } catch (\Throwable $e) {
            $this->logError('Meilisearch browse failed', ['error' => $e->getMessage()]);
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

            // Build Meilisearch multi-search format using SearchQuery objects
            $searchQueries = array_map(function($query) {
                $indexName = $this->getFullIndexName($query['indexName'] ?? '');
                $searchQuery = new SearchQuery();
                $searchQuery->setIndexUid($indexName);
                $searchQuery->setQuery($query['query'] ?? '');

                // Apply additional params if provided
                if (isset($query['params']['limit'])) {
                    $searchQuery->setLimit($query['params']['limit']);
                }
                if (isset($query['params']['offset'])) {
                    $searchQuery->setOffset($query['params']['offset']);
                }
                if (isset($query['params']['filter'])) {
                    $searchQuery->setFilter($query['params']['filter']);
                }
                if (isset($query['params']['attributesToRetrieve'])) {
                    $searchQuery->setAttributesToRetrieve($query['params']['attributesToRetrieve']);
                }

                return $searchQuery;
            }, $queries);

            $results = $client->multiSearch($searchQueries);

            $this->logDebug('Meilisearch multiple queries executed', ['count' => count($queries)]);

            return ['results' => $results['results'] ?? []];
        } catch (\Throwable $e) {
            $this->logError('Meilisearch multiple queries failed', ['error' => $e->getMessage()]);
            return ['results' => []];
        }
    }

    /**
     * Parse filters array into Meilisearch filter string
     *
     * Meilisearch syntax: key = "value" AND (key2 = "a" OR key2 = "b")
     *
     * @param array $filters Key/value pairs of filters
     * @return string Meilisearch-compatible filter string
     */
    public function parseFilters(array $filters = []): string
    {
        $filterParts = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                // Multiple values = OR condition
                $orParts = array_map(function($v) use ($key) {
                    $v = is_bool($v) ? ($v ? 'true' : 'false') : $v;
                    return $key . ' = "' . $v . '"';
                }, $value);
                $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
            } else {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $filterParts[] = $key . ' = "' . $value . '"';
            }
        }

        return implode(' AND ', $filterParts);
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

            $this->_client = new Client(
                $this->resolveEnvVar($settings['host'] ?? null, 'http://localhost:7700'),
                $this->resolveEnvVar($settings['apiKey'] ?? null, null)
            );
        }

        return $this->_client;
    }
}
