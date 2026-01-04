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
}
