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
 *
 * @since 5.0.0
 */
class MeilisearchBackend extends BaseBackend
{
    private ?Client $_adminClient = null;
    private ?Client $_searchClient = null;

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
            $client = $this->getAdminClient();
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
            $client = $this->getAdminClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $data = $this->prepareDocument($data);

            $index = $client->index($fullIndexName);
            $index->addDocuments([$data], 'objectID');

            $this->logDebug('Document indexed in Meilisearch', [
                'index' => $fullIndexName,
                'id' => $data['objectID'],
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
            $client = $this->getAdminClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $items = array_map(fn($item) => $this->prepareDocument($item), $items);

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

    /**
     * Prepare document for Meilisearch by creating composite objectID
     *
     * objectID is ALWAYS set to ensure Meilisearch can identify documents:
     * - With siteId: "123_1" (multi-site safe, prevents collisions)
     * - Without siteId: "123" (single-site or custom transformers)
     */
    private function prepareDocument(array $data): array
    {
        $elementId = $data['objectID'] ?? $data['id'] ?? null;
        $siteId = $data['siteId'] ?? null;

        if ($elementId === null) {
            throw new \InvalidArgumentException('Document must have either "objectID" or "id" field');
        }

        // Always set objectID - use composite key for multi-site, simple key otherwise
        if ($siteId !== null) {
            $data['objectID'] = $elementId . '_' . $siteId;
        } else {
            $data['objectID'] = (string)$elementId;
        }

        return $data;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getAdminClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);

            // Use composite key matching the objectID format from transformer
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;
            $index->deleteDocument($documentId);

            $this->logDebug('Document deleted from Meilisearch', [
                'index' => $fullIndexName,
                'id' => $documentId,
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
            $client = $this->getSearchClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Extract siteId for filtering (internal option, not Meilisearch param)
            $siteId = $options['siteId'] ?? null;

            // Build Meilisearch-compatible search options
            $searchParams = [
                'showRankingScore' => true, // Include ranking scores in results
                'showMatchesPosition' => true, // Include which fields matched
            ];

            // Map supported options
            if (isset($options['limit'])) {
                $searchParams['limit'] = $options['limit'];
            }
            if (isset($options['offset'])) {
                $searchParams['offset'] = $options['offset'];
            }
            if (isset($options['attributesToRetrieve'])) {
                $searchParams['attributesToRetrieve'] = $options['attributesToRetrieve'];
            }

            // Apply siteId filter if specified (not '*' or null for all sites)
            if ($siteId !== null && $siteId !== '*') {
                $searchParams['filter'] = 'siteId = ' . (int)$siteId;
            }

            $index = $client->index($fullIndexName);
            $results = $index->search($query, $searchParams);

            // Map Meilisearch _rankingScore to standard score field
            $rawHits = $results->getHits();
            $this->logDebug('Meilisearch search raw hits', [
                'count' => count($rawHits),
                'firstHitKeys' => !empty($rawHits) ? array_keys($rawHits[0]) : [],
                'hasRankingScore' => !empty($rawHits) && isset($rawHits[0]['_rankingScore']),
            ]);

            $hits = array_map(function($hit) {
                if (isset($hit['_rankingScore'])) {
                    $hit['score'] = $hit['_rankingScore'];
                }
                // Extract matched field names from _matchesPosition
                if (isset($hit['_matchesPosition']) && is_array($hit['_matchesPosition'])) {
                    $hit['matchedIn'] = array_keys($hit['_matchesPosition']);
                }
                return $hit;
            }, $rawHits);

            return [
                'hits' => $hits,
                'total' => $results->getEstimatedTotalHits(),
                'processingTime' => $results->getProcessingTimeMs(),
            ];
        } catch (\Throwable $e) {
            $this->logError('Meilisearch search failed', [
                'error' => $e->getMessage(),
                'index' => $indexName,
                'query' => $query,
            ]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            $client = $this->getAdminClient();
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
            $client = $this->getAdminClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $index = $client->index($fullIndexName);

            // Use composite key matching the objectID format from transformer
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;

            // Try to get the document - if it exists, return true
            $index->getDocument($documentId);
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
            $client = $this->getSearchClient();
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
            $client = $this->getSearchClient();

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
                    $v = is_bool($v) ? ($v ? 'true' : 'false') : str_replace('"', '\\"', (string) $v);
                    return $key . ' = "' . $v . '"';
                }, $value);
                $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
            } else {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : str_replace('"', '\\"', (string) $value);
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

    /**
     * List all indices in Meilisearch
     *
     * @return array Array of index information
     */
    public function listIndices(): array
    {
        try {
            $client = $this->getAdminClient();
            $indexesResult = $client->getIndexes();

            $indices = [];
            foreach ($indexesResult->getResults() as $index) {
                // Try to get stats for document count (may fail due to API key permissions)
                $entries = 0;
                $entriesAvailable = true;
                try {
                    $stats = $index->stats();
                    $entries = $stats['numberOfDocuments'] ?? 0;
                } catch (\Throwable $e) {
                    // Stats endpoint may require different permissions - mark as unavailable
                    $entriesAvailable = false;
                }

                $indices[] = [
                    'name' => $index->getUid(),
                    'uid' => $index->getUid(),
                    'entries' => $entries,
                    'entriesAvailable' => $entriesAvailable,
                    'primaryKey' => $index->getPrimaryKey(),
                    'createdAt' => $index->getCreatedAt()?->format('c'),
                    'updatedAt' => $index->getUpdatedAt()?->format('c'),
                    'source' => 'meilisearch',
                ];
            }

            return $indices;
        } catch (\Throwable $e) {
            $this->logError('Failed to list Meilisearch indices', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // =========================================================================
    // AUTOCOMPLETE SUPPORT
    // =========================================================================

    /**
     * Get autocomplete suggestions using native Meilisearch search
     *
     * Meilisearch is designed for instant search / search-as-you-type,
     * so we simply use the search endpoint with the partial query.
     *
     * @param string $indexName Index to search
     * @param string $query Partial search query
     * @param array $options Options like limit, siteId
     * @return array Array of suggestion strings (titles)
     */
    public function autocomplete(string $indexName, string $query, array $options = []): array
    {
        try {
            $limit = $options['limit'] ?? 10;
            $siteId = $options['siteId'] ?? null;

            // Use native search - Meilisearch handles prefix matching automatically
            $searchOptions = [
                'limit' => $limit,
                'attributesToRetrieve' => ['title', 'id', 'siteId'],
            ];

            if ($siteId !== null && $siteId !== '*') {
                $searchOptions['siteId'] = $siteId;
            }

            $results = $this->search($indexName, $query, $searchOptions);

            // Extract unique titles from results
            $suggestions = [];
            foreach ($results['hits'] ?? [] as $hit) {
                $title = $hit['title'] ?? null;
                if ($title && !in_array($title, $suggestions, true)) {
                    $suggestions[] = $title;
                }
            }

            $this->logDebug('Meilisearch autocomplete', [
                'index' => $indexName,
                'query' => $query,
                'suggestions' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logError('Meilisearch autocomplete failed', [
                'error' => $e->getMessage(),
                'index' => $indexName,
                'query' => $query,
            ]);
            return [];
        }
    }

    /**
     * Check if this backend supports autocomplete
     */
    public function supportsAutocomplete(): bool
    {
        return true;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Get admin client for write operations (indexing, deleting)
     */
    private function getAdminClient(): Client
    {
        if ($this->_adminClient === null) {
            $settings = $this->getBackendSettings();

            // Support both old 'apiKey' and new 'adminApiKey' for backward compatibility
            $adminKey = $settings['adminApiKey'] ?? $settings['apiKey'] ?? null;

            $this->_adminClient = new Client(
                $this->resolveEnvVar($settings['host'] ?? null, 'http://localhost:7700'),
                $this->resolveEnvVar($adminKey, null)
            );
        }

        return $this->_adminClient;
    }

    /**
     * Get search client for read operations (searching, autocomplete)
     * Uses searchApiKey if configured, otherwise falls back to admin key
     */
    private function getSearchClient(): Client
    {
        if ($this->_searchClient === null) {
            $settings = $this->getBackendSettings();

            // Use search key if set, otherwise fall back to admin key
            $searchKey = $settings['searchApiKey'] ?? $settings['adminApiKey'] ?? $settings['apiKey'] ?? null;

            $this->_searchClient = new Client(
                $this->resolveEnvVar($settings['host'] ?? null, 'http://localhost:7700'),
                $this->resolveEnvVar($searchKey, null)
            );
        }

        return $this->_searchClient;
    }

    /**
     * Cache of indices we've already configured
     */
    private array $_configuredIndices = [];

    /**
     * Ensure index has filterable attributes configured for siteId filtering
     *
     * Meilisearch requires attributes to be explicitly set as filterable
     * before they can be used in filter queries.
     */
    private function ensureFilterableAttributes(string $indexName): void
    {
        // Skip if we've already configured this index
        if (isset($this->_configuredIndices[$indexName])) {
            return;
        }

        try {
            $client = $this->getAdminClient();
            $index = $client->index($indexName);

            // Get current filterable attributes
            $currentFilterable = $index->getFilterableAttributes();

            // Required filterable attributes for Search Manager
            $requiredFilterable = ['siteId', 'elementType'];

            // Check if already configured
            $missingAttributes = array_diff($requiredFilterable, $currentFilterable);

            if (!empty($missingAttributes)) {
                // Add missing attributes while preserving existing ones
                $newFilterable = array_unique(array_merge($currentFilterable, $requiredFilterable));
                $index->updateFilterableAttributes($newFilterable);

                $this->logInfo('Configured Meilisearch filterable attributes', [
                    'index' => $indexName,
                    'attributes' => $newFilterable,
                ]);
            }

            $this->_configuredIndices[$indexName] = true;
        } catch (\Throwable $e) {
            // Log but don't fail - filtering just won't work
            $this->logWarning('Failed to configure Meilisearch filterable attributes', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
