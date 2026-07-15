<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\backends;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Algolia\AlgoliaSearch\Exceptions\NotFoundException;
use Craft;
use lindemannrock\searchmanager\helpers\SearchFilterExpressionHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\interfaces\AutocompleteBackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;

/**
 * Algolia Backend
 *
 * Search backend adapter for Algolia v4 API
 * Drop-in replacement for Scout + Algolia setups
 *
 * @since 5.0.0
 */
class AlgoliaBackend extends BaseBackend implements AutocompleteBackendInterface
{
    /**
     * Search Manager options that must not be forwarded to Algolia.
     */
    private const INTERNAL_SEARCH_OPTIONS = [
        'apiKeyId',
        'apiKeyPrefix',
        'apiKeyType',
        'appVersion',
        'language',
        'sessionId',
        'siteId',
        'skipAnalytics',
        'source',
        'type',
        'platform',
        'retrievableFieldsByIndex',
    ];

    private ?SearchClient $_client = null;
    private ?SearchClient $_searchClient = null;

    /** @inheritdoc */
    public function getName(): string
    {
        return 'algolia';
    }

    /** @inheritdoc */
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

    /** @inheritdoc */
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

    /** @inheritdoc */
    public function index(string $indexName, array $data): bool
    {
        return $this->indexWithResult($indexName, $data)['success'];
    }

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        $this->clearLastIndexingFailures();
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $data = $this->prepareDocument($indexName, $data);
            $objectId = (string)$data['objectID'];
            $lockName = $this->indexDocumentLockName($fullIndexName, $objectId);
            $lockAcquired = Craft::$app->getMutex()->acquire($lockName, 30);
            if (!$lockAcquired) {
                $this->logError('Failed to acquire Algolia indexing lock', [
                    'index' => $fullIndexName,
                    'id' => $objectId,
                ]);
                return [
                    'success' => false,
                    'wasCreated' => null,
                ];
            }

            try {
                $existed = $this->algoliaObjectExists($fullIndexName, $objectId);

                $client->saveObject($fullIndexName, $data);
                $this->logDebug('Document indexed in Algolia', ['index' => $fullIndexName, 'id' => $objectId]);
                return [
                    'success' => true,
                    'wasCreated' => !$existed,
                ];
            } finally {
                Craft::$app->getMutex()->release($lockName);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to index in Algolia', ['error' => $e->getMessage()]);
            $this->recordIndexingFailure($data, $e->getMessage());
            return [
                'success' => false,
                'wasCreated' => null,
            ];
        }
    }

    /** @inheritdoc */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->clearLastIndexingFailures();
        $fullIndexName = $this->getFullIndexName($indexName);
        try {
            $client = $this->getClient();

            // Ensure index has filterable attributes configured
            $this->ensureFilterableAttributes($fullIndexName);

            // Create composite objectID for multi-site uniqueness
            $items = array_map(fn($item) => $this->prepareDocument($indexName, $item), $items);

            $client->saveObjects($fullIndexName, $items);
            $this->logInfo('Batch indexed in Algolia', ['index' => $fullIndexName, 'count' => count($items)]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Algolia', ['error' => $e->getMessage()]);
            $this->recordAlgoliaBatchFailures($fullIndexName, $items, $e->getMessage());
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function recordAlgoliaBatchFailures(string $fullIndexName, array $items, string $batchError): void
    {
        try {
            $client = $this->getClient();
            foreach ($items as $item) {
                try {
                    $client->saveObject($fullIndexName, $item);
                } catch (\Throwable $e) {
                    $this->recordIndexingFailure($item, $e->getMessage());
                }
            }
        } catch (\Throwable) {
            // Fall through to the batch-level failure records below.
        }

        if ($this->lastIndexingFailures === []) {
            foreach ($items as $item) {
                $this->recordIndexingFailure($item, $batchError);
            }
        }
    }

    /**
     * Prepare document for Algolia by creating composite objectID
     *
     * objectID is ALWAYS set to ensure Algolia can identify documents:
     * - With siteId: "123_1" (multi-site safe, prevents collisions)
     * - Without siteId: "123" (single-site or custom transformers)
     */
    private function prepareDocument(string $indexName, array $data): array
    {
        return SearchRecordProjectionHelper::externalRecord(
            $indexName,
            SearchHitIdentityHelper::prepareObjectIdDocument($data),
        );
    }

    private function indexDocumentLockName(string $fullIndexName, string $objectId): string
    {
        return sprintf('search-manager:index-document:%s:%s', $fullIndexName, $objectId);
    }

    /** @inheritdoc */
    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return $this->deleteWithResult($indexName, $elementId, $siteId)['success'];
    }

    /**
     * @inheritdoc
     * @since 5.53.0
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        $lockName = null;
        $lockAcquired = false;

        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            $documentId = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
            $lockName = $this->indexDocumentLockName($fullIndexName, $documentId);
            $lockAcquired = Craft::$app->getMutex()->acquire($lockName, 30);
            if (!$lockAcquired) {
                $this->logError('Failed to acquire Algolia deletion lock', [
                    'index' => $fullIndexName,
                    'id' => $documentId,
                ]);
                return [
                    'success' => false,
                    'existed' => null,
                ];
            }

            if (!$this->algoliaObjectExists($fullIndexName, $documentId)) {
                return [
                    'success' => true,
                    'existed' => false,
                ];
            }

            $client->deleteObject($fullIndexName, $documentId);
            $this->logDebug('Document deleted from Algolia', ['index' => $fullIndexName, 'id' => $documentId]);
            return [
                'success' => true,
                'existed' => true,
            ];
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from Algolia', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'existed' => null,
            ];
        } finally {
            if ($lockAcquired && $lockName !== null) {
                Craft::$app->getMutex()->release($lockName);
            }
        }
    }

    private function algoliaObjectExists(string $fullIndexName, string $documentId): bool
    {
        try {
            $this->getClient()->getObject($fullIndexName, $documentId, ['objectID']);

            return true;
        } catch (NotFoundException) {
            return false;
        }
    }

    protected function deleteByBackendId(string $indexName, string $backendId): bool
    {
        try {
            $this->getClient()->deleteObject($this->getFullIndexName($indexName), $backendId);

            return true;
        } catch (NotFoundException) {
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete Algolia object by backend ID', [
                'index' => $indexName,
                'backendId' => $backendId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the Algolia `filters` expression that scopes results by site,
     * merging with any caller-supplied filter rather than replacing it.
     *
     * - All-sites (`*` / null): returns `$existing` unchanged (no site filter).
     * - Single site, no existing filter: `siteId:N`.
     * - Multiple sites, no existing filter: `(siteId:N OR siteId:M)`.
     * - Site scope + existing filter: `({existing}) AND {site filter}`.
     *
     * @since 5.47.0
     */
    public static function siteIdFilter(int|string|array|null $siteId, ?string $existing = null): ?string
    {
        $existing = ($existing === null || $existing === '') ? null : $existing;
        $siteScope = SearchSiteScopeHelper::normalize($siteId);

        if ($siteScope === SearchSiteScopeHelper::ALL_SITES) {
            return SearchFilterExpressionHelper::normalizeExpression($existing);
        }

        if (is_array($siteScope)) {
            $site = '(' . implode(' OR ', array_map(static fn(int $id): string => 'siteId:' . $id, $siteScope)) . ')';
        } else {
            $site = 'siteId:' . $siteScope;
        }

        return SearchFilterExpressionHelper::mergeWithRequiredFilter($existing, $site, 'AND');
    }

    /**
     * Build the portable Search Manager type filter as an Algolia filter.
     */
    private function typeFilter(mixed $type, ?string $existing = null): ?string
    {
        $existing = ($existing === null || $existing === '') ? null : $existing;

        if ($type === null || $type === '') {
            return SearchFilterExpressionHelper::normalizeExpression($existing);
        }

        $types = is_array($type) ? $type : explode(',', (string) $type);
        $types = array_values(array_filter(array_map('trim', $types), static fn(string $value): bool => $value !== ''));

        if ($types === []) {
            return SearchFilterExpressionHelper::normalizeExpression($existing);
        }

        $typeFilter = $this->parseFilters(['type' => $types]);

        return SearchFilterExpressionHelper::mergeWithRequiredFilter($existing, $typeFilter, 'AND');
    }

    /** @inheritdoc */
    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            $client = $this->getSearchClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $this->ensureFilterableAttributes($fullIndexName);

            // Filter out Search Manager internal options that Algolia doesn't understand.
            $searchParams = array_diff_key($options, array_flip(self::INTERNAL_SEARCH_OPTIONS));
            $searchParams['query'] = $query;
            $searchParams['attributesToRetrieve'] = $searchParams['attributesToRetrieve']
                ?? SearchRecordProjectionHelper::searchProjectionFields($this->retrievableFieldsForIndex($indexName, $options));

            $existingFilters = SearchFilterExpressionHelper::normalizeExpression(
                isset($searchParams['filters']) && is_string($searchParams['filters']) ? $searchParams['filters'] : null,
            );
            if ($existingFilters === null) {
                unset($searchParams['filters']);
            } else {
                $searchParams['filters'] = $existingFilters;
            }
            $typeFilter = $this->typeFilter($options['type'] ?? null, $existingFilters);
            if ($typeFilter !== null) {
                $searchParams['filters'] = $typeFilter;
            }

            // Scope to a single site when requested (main search previously
            // dropped siteId). Merge with any caller-supplied filters instead of
            // overwriting them.
            $existingFilters = isset($searchParams['filters']) && is_string($searchParams['filters'])
                ? $searchParams['filters']
                : null;
            $siteFilter = self::siteIdFilter($options['siteId'] ?? null, $existingFilters);
            if ($siteFilter !== null) {
                $searchParams['filters'] = $siteFilter;
            }

            $limit = $options['limit'] ?? null;
            $offset = $options['offset'] ?? null;
            $page = $options['page'] ?? null;

            if ($limit !== null && (int) $limit > 0) {
                $searchParams['hitsPerPage'] = (int) $limit;
            }
            if ($page !== null) {
                $searchParams['page'] = (int) $page;
            } elseif ($offset !== null && $limit !== null && (int) $limit > 0) {
                $searchParams['page'] = (int) floor(((int) $offset) / (int) $limit);
            }

            unset($searchParams['limit'], $searchParams['offset'], $searchParams['page']);

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
                return SearchHitIdentityHelper::normalizeHit($hit);
            }, $results['hits'] ?? []);

            return ['hits' => $hits, 'total' => $results['nbHits'] ?? 0];
        } catch (\Throwable $e) {
            $this->logError('Algolia search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0, '_failed' => true];
        }
    }

    /**
     * @inheritdoc
     * @since 5.56.0
     */
    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        $elementIds = array_values(array_unique(array_filter(
            array_map('intval', $elementIds),
            static fn(int $id): bool => $id > 0,
        )));
        if ($elementIds === []) {
            return [];
        }

        try {
            $client = $this->getSearchClient();
            $fullIndexName = $this->getFullIndexName($indexName);
            $this->ensureFilterableAttributes($fullIndexName);

            if ($siteId !== null && !(SearchIndex::findByHandle($indexName)?->usesSplitSections() ?? false)) {
                $documents = [];
                foreach ($elementIds as $elementId) {
                    try {
                        $documents[] = $client->getObject($fullIndexName, SearchHitIdentityHelper::pageDocumentId($elementId, $siteId));
                    } catch (NotFoundException) {
                        continue;
                    }
                }

                return $this->bestDocumentsByElementId($documents, $elementIds, $siteId);
            }

            $filters = $this->elementIdFilter($elementIds);
            $filters = self::siteIdFilter($siteId, $filters);
            $documents = [];
            foreach ($this->browse($indexName, '', ['filters' => $filters]) as $document) {
                if (is_array($document)) {
                    $documents[] = $document;
                }
            }

            return $this->bestDocumentsByElementId($documents, $elementIds, $siteId);
        } catch (\Throwable $e) {
            $this->logWarning('Failed to fetch indexed Algolia documents by element ID', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Browse an index (iterate through all objects)
     *
     * Compatible with trendyminds/algolia browse() method
     *
     * @inheritdoc
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

    protected function browseDocumentsForElement(string $indexName, int $elementId, ?int $siteId): iterable
    {
        $filters = $this->elementIdFilter([$elementId]);
        $filters = self::siteIdFilter($siteId, $filters);

        return $this->browse($indexName, '', ['filters' => $filters]);
    }

    /**
     * Perform multiple queries at once
     *
     * Compatible with trendyminds/algolia multipleQueries() method
     *
     * @inheritdoc
     * @param array $queries Array of query objects with 'indexName', 'query', and optional 'params'
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array
    {
        try {
            $client = $this->getSearchClient();

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
     * @inheritdoc
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
                $item = SearchFilterExpressionHelper::escapeDelimitedValue($item, '"');
                return $group . ':"' . $item . '"';
            }, $items);

            $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
        }

        // Combine all filter groups with AND
        return implode(' AND ', $filterParts);
    }

    /** @inheritdoc */
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

    /** @inheritdoc */
    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            $client = $this->getClient();
            $fullIndexName = $this->getFullIndexName($indexName);

            $documentId = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);

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

    /** @inheritdoc */
    public function supportsBrowse(): bool
    {
        return true;
    }

    /** @inheritdoc */
    public function supportsMultipleQueries(): bool
    {
        return true;
    }

    /**
     * List all indices in Algolia account
     *
     * @inheritdoc
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
                    'entriesAvailable' => true,
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

            $siteFilter = self::siteIdFilter($siteId);
            if ($siteFilter !== null) {
                $searchOptions['filters'] = $siteFilter;
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

    private function getSearchClient(): SearchClient
    {
        if ($this->_searchClient === null) {
            $settings = $this->getBackendSettings();
            $searchKey = $settings['searchApiKey'] ?? $settings['adminApiKey'] ?? null;
            $this->_searchClient = SearchClient::create(
                $this->resolveEnvVar($settings['applicationId'] ?? null, ''),
                $this->resolveEnvVar($searchKey, '')
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
            $cleanedFacets = array_values(array_filter(
                $currentFacets,
                static fn($facet): bool => $facet !== 'filterOnly(elementType)',
            ));

            // Required filterable attributes for Search Manager
            // Using filterOnly() to allow filtering without facet counts
            $requiredSearchable = SearchRecordProjectionHelper::providerSearchableAttributes();

            // Check if already configured (normalize for comparison)
            $normalizedCurrent = array_map(fn($f) => str_replace('filterOnly(', '', str_replace(')', '', $f)), $cleanedFacets);
            $missingFacets = [];
            foreach (['siteId', 'elementId', 'type'] as $attr) {
                if (!in_array($attr, $normalizedCurrent, true)) {
                    $missingFacets[] = 'filterOnly(' . $attr . ')';
                }
            }

            $settingsUpdate = [];
            if (!empty($missingFacets) || $cleanedFacets !== $currentFacets) {
                // Add missing facets while preserving existing ones
                $newFacets = array_values(array_unique(array_merge($cleanedFacets, $missingFacets)));
                $settingsUpdate['attributesForFaceting'] = $newFacets;

                $this->logInfo('Configured Algolia filterable attributes', [
                    'index' => $indexName,
                    'attributes' => $newFacets,
                ]);
            }

            if (($currentSettings['searchableAttributes'] ?? []) !== $requiredSearchable) {
                $settingsUpdate['searchableAttributes'] = $requiredSearchable;
                $this->logInfo('Configured Algolia searchable attributes', [
                    'index' => $indexName,
                    'attributes' => $requiredSearchable,
                ]);
            }

            if ($settingsUpdate !== []) {
                $client->setSettings($indexName, $settingsUpdate);
            }

            $this->_configuredIndices[$indexName] = true;
        } catch (\Throwable $e) {
            $this->logError('Failed to configure Algolia index attributes', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<int, int> $elementIds
     */
    private function elementIdFilter(array $elementIds): string
    {
        $elementIds = array_values(array_unique(array_map('intval', $elementIds)));

        return count($elementIds) === 1
            ? 'elementId:' . $elementIds[0]
            : '(' . implode(' OR ', array_map(static fn(int $id): string => 'elementId:' . $id, $elementIds)) . ')';
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>|null
     */
    private function retrievableFieldsForIndex(string $indexName, array $options): ?array
    {
        $byIndex = $options['retrievableFieldsByIndex'] ?? null;

        return is_array($byIndex) && is_array($byIndex[$indexName] ?? null)
            ? SearchIndex::normalizeRetrievableFields($byIndex[$indexName])
            : null;
    }
}
