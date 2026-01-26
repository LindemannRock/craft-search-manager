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

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $data = $this->prepareDocument($data);

            $client->saveObject($fullIndexName, $data);
            $this->logDebug('Document indexed in Algolia', ['index' => $fullIndexName, 'id' => $data['objectID']]);
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

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $items = array_map(fn($item) => $this->prepareDocument($item), $items);

            $client->saveObjects($fullIndexName, $items);
            $this->logInfo('Batch indexed in Algolia', ['index' => $fullIndexName, 'count' => count($items)]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Algolia', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Prepare document for Algolia by creating composite objectID
     *
     * objectID is ALWAYS set to ensure Algolia can identify documents:
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
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Use composite key matching the objectID format
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;

            $client->deleteObject($fullIndexName, $documentId);
            $this->logDebug('Document deleted from Algolia', ['index' => $fullIndexName, 'id' => $documentId]);
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

            // Extract matched field names from _highlightResult
            $hits = array_map(function($hit) {
                if (isset($hit['_highlightResult']) && is_array($hit['_highlightResult'])) {
                    $matchedFields = [];
                    foreach ($hit['_highlightResult'] as $field => $highlight) {
                        // Check if this field has a match (matchLevel !== 'none')
                        $matchLevel = $highlight['matchLevel'] ?? ($highlight[0]['matchLevel'] ?? 'none');
                        if ($matchLevel !== 'none') {
                            $matchedFields[] = $field;
                        }
                    }
                    if (!empty($matchedFields)) {
                        $hit['matchedIn'] = $matchedFields;
                    }
                }
                return $hit;
            }, $results['hits'] ?? []);

            return ['hits' => $hits, 'total' => $results['nbHits'] ?? 0];
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

            // Use composite key matching the objectID format
            $documentId = $siteId !== null ? $elementId . '_' . $siteId : (string)$elementId;

            // Try to get the object - if it exists, return true
            $client->getObject($fullIndexName, $documentId);
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

    // =========================================================================
    // AUTOCOMPLETE SUPPORT
    // =========================================================================

    /**
     * Get autocomplete suggestions using native Algolia search
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

            // Build search options
            $searchOptions = [
                'hitsPerPage' => $limit,
                'attributesToRetrieve' => ['title', 'id', 'siteId'],
            ];

            // Apply siteId filter if specified
            if ($siteId !== null && $siteId !== '*') {
                $searchOptions['filters'] = 'siteId:' . (int)$siteId;
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

            $this->logDebug('Algolia autocomplete', [
                'index' => $indexName,
                'query' => $query,
                'suggestions' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logError('Algolia autocomplete failed', [
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

    /**
     * Cache of indices we've already configured
     */
    private array $_configuredIndices = [];

    /**
     * Ensure index has filterable attributes configured for siteId filtering
     *
     * Algolia requires attributes to be set as facets (attributesForFaceting)
     * before they can be used in filter queries.
     */
    private function ensureFilterableAttributes(string $indexName): void
    {
        // Skip if we've already configured this index
        if (isset($this->_configuredIndices[$indexName])) {
            return;
        }

        try {
            $client = $this->getClient();

            // Get current settings
            $currentSettings = $client->getSettings($indexName);
            $currentFacets = $currentSettings['attributesForFaceting'] ?? [];

            // Required filterable attributes for Search Manager
            // Using filterOnly() to allow filtering without facet counts
            $requiredFacets = ['filterOnly(siteId)', 'filterOnly(elementType)'];

            // Check if already configured (normalize for comparison)
            $normalizedCurrent = array_map(fn($f) => str_replace('filterOnly(', '', str_replace(')', '', $f)), $currentFacets);
            $missingFacets = [];
            foreach (['siteId', 'elementType'] as $attr) {
                if (!in_array($attr, $normalizedCurrent, true)) {
                    $missingFacets[] = 'filterOnly(' . $attr . ')';
                }
            }

            if (!empty($missingFacets)) {
                // Add missing facets while preserving existing ones
                $newFacets = array_unique(array_merge($currentFacets, $missingFacets));
                $client->setSettings($indexName, ['attributesForFaceting' => $newFacets]);

                $this->logInfo('Configured Algolia filterable attributes', [
                    'index' => $indexName,
                    'attributes' => $newFacets,
                ]);
            }

            $this->_configuredIndices[$indexName] = true;
        } catch (\Throwable $e) {
            // Log but don't fail - filtering just won't work
            $this->logWarning('Failed to configure Algolia filterable attributes', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
