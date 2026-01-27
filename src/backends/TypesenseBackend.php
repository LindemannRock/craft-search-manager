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

            // Ensure collection exists
            $this->ensureCollectionExists($fullIndexName);

            // Create composite id for multi-site uniqueness
            $data = $this->prepareDocument($data);

            $client->collections[$fullIndexName]->documents->upsert($data);
            $this->logDebug('Document indexed in Typesense', ['index' => $fullIndexName, 'id' => $data['id']]);
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

            // Ensure collection exists
            $this->ensureCollectionExists($fullIndexName);

            // Create composite id for multi-site uniqueness
            $items = array_map(fn($item) => $this->prepareDocument($item), $items);

            $client->collections[$fullIndexName]->documents->import($items, ['action' => 'upsert']);
            $this->logInfo('Batch indexed in Typesense', ['index' => $fullIndexName, 'count' => count($items)]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Typesense', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Prepare document for Typesense by creating composite id
     *
     * Note: Typesense uses 'id' as primary key (not 'objectID' like Algolia/Meilisearch)
     *
     * id is ALWAYS set to ensure Typesense can identify documents:
     * - With siteId: "123_1" (multi-site safe, prevents collisions)
     * - Without siteId: "123" (single-site or custom transformers)
     */
    private function prepareDocument(array $data): array
    {
        $elementId = $data['id'] ?? $data['objectID'] ?? null;
        $siteId = $data['siteId'] ?? null;

        if ($elementId === null) {
            throw new \InvalidArgumentException('Document must have either "id" or "objectID" field');
        }

        // Always set id - use composite key for multi-site, simple key otherwise
        // Store original element ID for Craft lookups when using composite key
        if ($siteId !== null) {
            $data['elementId'] = $elementId;
            $data['id'] = $elementId . '_' . $siteId;
        } else {
            $data['id'] = (string)$elementId;
        }

        return $data;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Use composite key matching the id format
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;

            $client->collections[$fullIndexName]->documents[$documentId]->delete();
            $this->logDebug('Document deleted from Typesense', ['index' => $fullIndexName, 'id' => $documentId]);
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

            // Filter out internal options that Typesense doesn't understand
            $internalOptions = ['siteId', 'source', 'platform', 'appVersion'];
            $searchParams = array_diff_key($options, array_flip($internalOptions));
            $searchParams['q'] = $query;
            // Search all common string fields for consistency with other backends
            $searchParams['query_by'] = $searchParams['query_by'] ?? 'title,content,url';

            $results = $client->collections[$fullIndexName]->documents->search($searchParams);

            // Unwrap documents - Typesense wraps each hit in a 'document' key
            // Also add score from text_match for consistency with other backends
            $hits = array_map(function($hit) {
                $doc = $hit['document'] ?? [];
                // Add score from Typesense's text_match (normalize to 0-1 range)
                if (isset($hit['text_match'])) {
                    $doc['score'] = $hit['text_match'] / 1000000000000000; // Typesense uses large integers
                }
                // Extract matched field names from highlights
                if (isset($hit['highlights']) && is_array($hit['highlights'])) {
                    $matchedFields = [];
                    foreach ($hit['highlights'] as $highlight) {
                        if (isset($highlight['field'])) {
                            $matchedFields[] = $highlight['field'];
                        }
                    }
                    if (!empty($matchedFields)) {
                        $doc['matchedIn'] = array_unique($matchedFields);
                    }
                }
                return $doc;
            }, $results['hits'] ?? []);

            return ['hits' => $hits, 'total' => $results['found'] ?? 0];
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

            // Delete the entire collection (cleanest way to clear all documents)
            // It will be auto-recreated on next index operation
            $client->collections[$fullIndexName]->delete();

            // Clear from our cache so it gets recreated
            unset($this->_existingCollections[$fullIndexName]);

            $this->logInfo('Cleared Typesense index (deleted collection)', ['index' => $fullIndexName]);
            return true;
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            // Collection doesn't exist - that's fine, it's already "clear"
            $this->logDebug('Typesense collection not found (already clear)', ['index' => $fullIndexName]);
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

            // Use composite key matching the id format
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;

            // Try to retrieve the document - if it exists, return true
            $client->collections[$fullIndexName]->documents[$documentId]->retrieve();
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

    /**
     * List all collections (indices) in Typesense
     *
     * @return array Array of index information
     */
    public function listIndices(): array
    {
        try {
            $client = $this->getClient();
            $collections = $client->collections->retrieve();

            $indices = [];
            foreach ($collections as $collection) {
                $indices[] = [
                    'name' => $collection['name'] ?? '',
                    'entries' => $collection['num_documents'] ?? 0,
                    'entriesAvailable' => true,
                    'fields' => count($collection['fields'] ?? []),
                    'createdAt' => $collection['created_at'] ?? null,
                    'source' => 'typesense',
                ];
            }

            return $indices;
        } catch (\Throwable $e) {
            $this->logError('Failed to list Typesense collections', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // =========================================================================
    // AUTOCOMPLETE SUPPORT
    // =========================================================================

    /**
     * Get autocomplete suggestions using native Typesense search
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

            // Build search options for Typesense
            $searchOptions = [
                'per_page' => $limit,
                'query_by' => 'title,content',
                'include_fields' => 'title,elementId,siteId',
            ];

            // Apply siteId filter if specified
            if ($siteId !== null && $siteId !== '*') {
                $searchOptions['filter_by'] = 'siteId:=' . (int)$siteId;
            }

            $results = $this->search($indexName, $query, $searchOptions);

            // Extract unique titles from results
            $suggestions = [];
            foreach ($results['hits'] ?? [] as $hit) {
                // Typesense wraps document in 'document' key
                $doc = $hit['document'] ?? $hit;
                $title = $doc['title'] ?? null;
                if ($title && !in_array($title, $suggestions, true)) {
                    $suggestions[] = $title;
                }
            }

            $this->logDebug('Typesense autocomplete', [
                'index' => $indexName,
                'query' => $query,
                'suggestions' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logError('Typesense autocomplete failed', [
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

    /**
     * Cache of collections we've already verified exist
     */
    private array $_existingCollections = [];

    /**
     * Ensure collection exists, create with auto-schema if not
     *
     * Typesense requires collections to have a schema before indexing.
     * We use auto-schema detection which infers types from the first document.
     */
    private function ensureCollectionExists(string $collectionName): void
    {
        // Skip if we've already verified this collection
        if (isset($this->_existingCollections[$collectionName])) {
            return;
        }

        try {
            $client = $this->getClient();

            // Try to retrieve collection info
            $client->collections[$collectionName]->retrieve();
            $this->_existingCollections[$collectionName] = true;
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            // Collection doesn't exist - create it with auto-schema
            $this->createCollection($collectionName);
            $this->_existingCollections[$collectionName] = true;
        }
    }

    /**
     * Create a collection with a flexible schema for Search Manager documents
     */
    private function createCollection(string $collectionName): void
    {
        $client = $this->getClient();

        // Define schema with common Search Manager fields
        // Using 'auto' type where possible for flexibility
        $schema = [
            'name' => $collectionName,
            'fields' => [
                // Required ID field (string for composite IDs like "5_1")
                ['name' => 'id', 'type' => 'string'],

                // Core fields
                ['name' => 'objectID', 'type' => 'int32', 'optional' => true],
                ['name' => 'elementId', 'type' => 'int32', 'optional' => true],
                ['name' => 'siteId', 'type' => 'int32', 'optional' => true, 'facet' => true],
                ['name' => 'title', 'type' => 'string', 'optional' => true],
                ['name' => 'url', 'type' => 'string', 'optional' => true],
                ['name' => 'content', 'type' => 'string', 'optional' => true],

                // Dates (stored as timestamps)
                ['name' => 'dateCreated', 'type' => 'int64', 'optional' => true],
                ['name' => 'dateUpdated', 'type' => 'int64', 'optional' => true],

                // Allow any additional fields with auto-detection
                ['name' => '.*', 'type' => 'auto', 'optional' => true],
            ],
            'enable_nested_fields' => true,
        ];

        $client->collections->create($schema);

        $this->logInfo('Created Typesense collection', ['name' => $collectionName]);
    }
}
