<?php

namespace lindemannrock\searchmanager\variables;

use lindemannrock\searchmanager\interfaces\BackendInterface;

/**
 * Backend Variable Proxy
 *
 * Provides the same API as SearchManagerVariable but uses a specific backend instance.
 * This allows templates to work with a specific configured backend regardless of the default.
 *
 * Usage:
 *     {% set algolia = craft.searchManager.withBackend('production-algolia') %}
 *     {% set indices = algolia.listIndices() %}
 *     {% set results = algolia.search('my-index', 'query') %}
 */
class BackendVariableProxy
{
    private BackendInterface $backend;

    private string $backendHandle;

    public function __construct(BackendInterface $backend, string $backendHandle)
    {
        $this->backend = $backend;
        $this->backendHandle = $backendHandle;
    }

    /**
     * Get the backend handle this proxy is using
     */
    public function getBackendHandle(): string
    {
        return $this->backendHandle;
    }

    /**
     * Get the backend instance
     */
    public function getBackend(): BackendInterface
    {
        return $this->backend;
    }

    /**
     * Perform a search using this backend
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return array
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        return $this->backend->search($indexName, $query, $options);
    }

    /**
     * Browse an index (iterate through all objects)
     *
     * @param array $options Options array with 'index', 'query', and optional 'params'
     * @return iterable Iterator or array of all matching objects
     */
    public function browse(array $options = []): iterable
    {
        if (!$this->backend->supportsBrowse()) {
            \Craft::warning('browse() is not supported by ' . $this->backend->getName() . ' backend', 'search-manager');
            return [];
        }

        $index = $options['index'] ?? '';
        $query = $options['query'] ?? '';
        $params = $options['params'] ?? [];

        return $this->backend->browse($index, $query, $params);
    }

    /**
     * Perform multiple queries at once
     *
     * @param array $queries Array of query objects
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array
    {
        return $this->backend->multipleQueries($queries);
    }

    /**
     * Parse filters array into backend-specific filter string
     *
     * @param array $filters Key/value pairs of filters
     * @return string Backend-compatible filter string
     */
    public function parseFilters(array $filters = []): string
    {
        return $this->backend->parseFilters($filters);
    }

    /**
     * Check if this backend supports browse functionality
     *
     * @return bool
     */
    public function supportsBrowse(): bool
    {
        return $this->backend->supportsBrowse();
    }

    /**
     * Check if this backend supports native multiple queries
     *
     * @return bool
     */
    public function supportsMultipleQueries(): bool
    {
        return $this->backend->supportsMultipleQueries();
    }

    /**
     * List all indices available in this backend
     *
     * @return array Array of index information
     */
    public function listIndices(): array
    {
        return $this->backend->listIndices();
    }

    /**
     * Get backend name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->backend->getName();
    }

    /**
     * Check if backend is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->backend->isAvailable();
    }

    /**
     * Get backend status
     *
     * @return array
     */
    public function getStatus(): array
    {
        return $this->backend->getStatus();
    }
}
