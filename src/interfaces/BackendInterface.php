<?php

namespace lindemannrock\searchmanager\interfaces;

/**
 * Backend Interface
 *
 * All search backend adapters must implement this interface
 * Provides a consistent API regardless of the underlying search engine
 */
interface BackendInterface
{
    /**
     * Index a single document
     *
     * @param string $indexName The index name
     * @param array $data The document data
     * @return bool Success status
     */
    public function index(string $indexName, array $data): bool;

    /**
     * Index multiple documents in batch
     *
     * @param string $indexName The index name
     * @param array $items Array of documents to index
     * @return bool Success status
     */
    public function batchIndex(string $indexName, array $items): bool;

    /**
     * Delete a document from the index
     *
     * @param string $indexName The index name
     * @param int $elementId The element ID to delete
     * @param int|null $siteId The site ID (optional, uses current site if not provided)
     * @return bool Success status
     */
    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool;

    /**
     * Perform a search
     *
     * @param string $indexName The index name
     * @param string $query The search query
     * @param array $options Search options (filters, pagination, etc.)
     * @return array Search results
     */
    public function search(string $indexName, string $query, array $options = []): array;

    /**
     * Clear an entire index
     *
     * @param string $indexName The index name
     * @return bool Success status
     */
    public function clearIndex(string $indexName): bool;

    /**
     * Check if a document exists in the index
     *
     * @param string $indexName The index name
     * @param int $elementId The element ID to check
     * @param int|null $siteId The site ID (optional)
     * @return bool True if document exists
     */
    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool;

    /**
     * Check if the backend is available and configured
     *
     * @return bool Availability status
     */
    public function isAvailable(): bool;

    /**
     * Get backend configuration status
     *
     * @return array Status information
     */
    public function getStatus(): array;

    /**
     * Get the backend name/identifier
     *
     * @return string Backend name (algolia, meilisearch, mysql, typesense)
     */
    public function getName(): string;

    /**
     * Browse/iterate through all documents in an index
     *
     * @param string $indexName The index name
     * @param string $query Optional query to filter results
     * @param array $parameters Additional browse parameters
     * @return iterable Iterator or array of all matching documents
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable;

    /**
     * Perform multiple search queries in a single request
     *
     * @param array $queries Array of query objects with 'indexName', 'query', and optional 'params'
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array;

    /**
     * Parse filters array into backend-specific filter string
     *
     * @param array $filters Key/value pairs of filters
     * @return string Backend-compatible filter string
     */
    public function parseFilters(array $filters = []): string;

    /**
     * Check if this backend supports browse functionality
     *
     * @return bool
     */
    public function supportsBrowse(): bool;

    /**
     * Check if this backend supports multiple queries
     *
     * @return bool
     */
    public function supportsMultipleQueries(): bool;

    /**
     * List all indices available in the backend
     *
     * @return array Array of index information
     */
    public function listIndices(): array;

    /**
     * Set configured settings from a ConfiguredBackend
     * These settings will be used instead of global config
     *
     * @param array $settings
     * @return void
     */
    public function setConfiguredSettings(array $settings): void;

    /**
     * Set the backend handle this adapter is associated with
     *
     * @param string $handle
     * @return void
     */
    public function setBackendHandle(string $handle): void;
}
