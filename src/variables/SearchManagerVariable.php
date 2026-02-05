<?php

namespace lindemannrock\searchmanager\variables;

use lindemannrock\searchmanager\SearchManager;

/**
 * Search Manager Template Variable
 *
 * Provides access to plugin functionality from Twig templates
 * Usage: {{ craft.searchManager.search('query') }}
 *
 * @since 5.0.0
 */
class SearchManagerVariable
{
    /**
     * Get plugin settings
     *
     * @since 5.0.0
     */
    public function getSettings()
    {
        return SearchManager::$plugin->getSettings();
    }

    /**
     * Get plugin instance
     *
     * @since 5.0.0
     */
    public function getPlugin()
    {
        return SearchManager::$plugin;
    }

    /**
     * Perform a search
     *
     * @since 5.0.0
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return array
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        return SearchManager::$plugin->backend->search($indexName, $query, $options);
    }

    /**
     * Search across multiple indices
     *
     * @since 5.0.0
     * @param array $indexNames Array of index handles to search
     * @param string $query Search query
     * @param array $options Search options
     * @return array Merged results with index metadata
     */
    public function searchMultiple(array $indexNames, string $query, array $options = []): array
    {
        return SearchManager::$plugin->backend->searchMultiple($indexNames, $query, $options);
    }

    /**
     * Get all configured indices
     *
     * @since 5.0.0
     */
    public function getIndices(): array
    {
        return \lindemannrock\searchmanager\models\SearchIndex::findAll();
    }

    /**
     * Highlight search terms in text
     *
     * @since 5.0.0
     * @param string $text Text to highlight
     * @param string|array $terms Search term(s) or query string
     * @param array $options Highlighting options
     * @return string Highlighted text
     */
    public function highlight(string $text, $terms, array $options = []): string
    {
        // Check if highlighting is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!($settings->enableHighlighting ?? true)) {
            return $text; // Return text unchanged if disabled
        }

        // Merge settings with options (options override settings)
        $config = [
            'tag' => $settings->highlightTag ?? 'mark',
            'class' => $settings->highlightClass ?? '',
            'snippetLength' => $settings->snippetLength ?? 200,
            'maxSnippets' => $settings->maxSnippets ?? 3,
        ];
        $config = array_merge($config, $options);

        $highlighter = new \lindemannrock\searchmanager\search\Highlighter($config);

        // If terms is a string (query), parse it
        if (is_string($terms)) {
            // Get current site's language for localized operators
            $language = \Craft::$app->getSites()->getCurrentSite()->language ?? 'en';
            $parsed = \lindemannrock\searchmanager\search\QueryParser::parse($terms, $language);
            $terms = $highlighter->extractTermsFromParsedQuery($parsed);
        }

        return $highlighter->highlight($text, $terms, $options['stripTags'] ?? true);
    }

    /**
     * Generate snippets with highlighted terms
     *
     * @since 5.0.0
     * @param string $text Text to generate snippets from
     * @param string|array $terms Search term(s) or query string
     * @param array $options Snippet options
     * @return array Array of snippet strings
     */
    public function snippets(string $text, $terms, array $options = []): array
    {
        // Check if highlighting is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!($settings->enableHighlighting ?? true)) {
            return []; // Return empty array if disabled
        }

        // Merge settings with options (options override settings)
        $config = [
            'tag' => $settings->highlightTag ?? 'mark',
            'class' => $settings->highlightClass ?? '',
            'snippetLength' => $settings->snippetLength ?? 200,
            'maxSnippets' => $settings->maxSnippets ?? 3,
        ];
        $config = array_merge($config, $options);

        $highlighter = new \lindemannrock\searchmanager\search\Highlighter($config);

        // If terms is a string (query), parse it
        if (is_string($terms)) {
            // Get current site's language for localized operators
            $language = \Craft::$app->getSites()->getCurrentSite()->language ?? 'en';
            $parsed = \lindemannrock\searchmanager\search\QueryParser::parse($terms, $language);
            $terms = $highlighter->extractTermsFromParsedQuery($parsed);
        }

        return $highlighter->generateSnippets($text, $terms, $options['stripTags'] ?? true);
    }

    /**
     * Get autocomplete suggestions for a query
     *
     * @since 5.0.0
     * @param string $query Partial search query
     * @param string $indexHandle Index to search (default: 'all-sites')
     * @param array $options Suggestion options
     * @return array Array of suggestion strings
     */
    public function suggest(string $query, string $indexHandle = 'all-sites', array $options = []): array
    {
        // Check if autocomplete is enabled
        $settings = SearchManager::$plugin->getSettings();
        if (!($settings->enableAutocomplete ?? true)) {
            return []; // Return empty array if disabled
        }

        return SearchManager::$plugin->autocomplete->suggest($query, $indexHandle, $options);
    }

    /**
     * Get analytics for a specific query rule
     *
     * @since 5.10.0
     * @param int $ruleId The query rule ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     */
    public function getRuleAnalytics(int $ruleId, string $dateRange = 'last7days'): array
    {
        return SearchManager::$plugin->analytics->getRuleAnalytics($ruleId, $dateRange);
    }

    /**
     * Get analytics for a specific promotion
     *
     * @since 5.10.0
     * @param int $promotionId The promotion ID
     * @param string $dateRange Date range filter
     * @return array Analytics data
     */
    public function getPromotionAnalytics(int $promotionId, string $dateRange = 'last7days'): array
    {
        return SearchManager::$plugin->analytics->getPromotionAnalytics($promotionId, $dateRange);
    }

    // =========================================================================
    // CROSS-BACKEND METHODS (Algolia, Meilisearch, Typesense)
    // =========================================================================

    /**
     * Browse an index (iterate through all objects)
     *
     * Works with Algolia, Meilisearch, and Typesense backends.
     * Compatible with trendyminds/algolia craft.algolia.browse()
     *
     * Usage: {% for item in craft.searchManager.browse({index: 'myIndex', query: '', params: {}}) %}
     *
     * @since 5.0.0
     * @param array $options Options array with 'index', 'query', and optional 'params'
     * @return iterable Iterator or array of all matching objects
     */
    public function browse(array $options = []): iterable
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();

        if ($backend === null) {
            \Craft::warning('No active backend configured for browse()', 'search-manager');
            return [];
        }

        if (!$backend->supportsBrowse()) {
            \Craft::warning('browse() is not supported by ' . $backend->getName() . ' backend', 'search-manager');
            return [];
        }

        $index = $options['index'] ?? '';
        $query = $options['query'] ?? '';
        $params = $options['params'] ?? [];

        return $backend->browse($index, $query, $params);
    }

    /**
     * Perform multiple queries at once
     *
     * Works with Algolia, Meilisearch, and Typesense backends.
     * Other backends fall back to sequential queries.
     * Compatible with trendyminds/algolia craft.algolia.multipleQueries()
     *
     * Usage: {{ craft.searchManager.multipleQueries([{indexName: 'index1', query: 'test'}, ...]) }}
     *
     * @since 5.0.0
     * @param array $queries Array of query objects
     * @return array Results from all queries
     */
    public function multipleQueries(array $queries = []): array
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();

        if ($backend === null) {
            \Craft::warning('No active backend configured for multipleQueries()', 'search-manager');
            return ['results' => []];
        }

        return $backend->multipleQueries($queries);
    }

    /**
     * Parse filters array into backend-specific filter string
     *
     * Automatically generates the correct filter syntax for the active backend:
     * - Algolia: (key:"value1" OR key:"value2") AND (key2:"value")
     * - Meilisearch: key = "value1" OR key = "value2" AND key2 = "value"
     * - Typesense: key:=[`value1`, `value2`] && key2:=`value`
     *
     * Usage: {{ craft.searchManager.parseFilters({category: ['news', 'blog'], active: true}) }}
     *
     * @since 5.0.0
     * @param array $filters Key/value pairs of filters
     * @return string Backend-compatible filter string
     */
    public function parseFilters(array $filters = []): string
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();

        if ($backend === null) {
            \Craft::warning('No active backend configured for parseFilters()', 'search-manager');
            return '';
        }

        return $backend->parseFilters($filters);
    }

    /**
     * Check if the active backend supports browse functionality
     *
     * @since 5.0.0
     * @return bool
     */
    public function supportsBrowse(): bool
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();
        return $backend !== null && $backend->supportsBrowse();
    }

    /**
     * Check if the active backend supports native multiple queries
     *
     * @since 5.0.0
     * @return bool
     */
    public function supportsMultipleQueries(): bool
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();
        return $backend !== null && $backend->supportsMultipleQueries();
    }

    /**
     * List all indices available in the backend
     *
     * For Algolia/Meilisearch/Typesense: returns indices from the service
     * For local backends: returns configured indices from search-manager
     *
     * Usage: {% for index in craft.searchManager.listIndices() %}
     *
     * @since 5.0.0
     * @return array Array of index information
     */
    public function listIndices(): array
    {
        $backend = SearchManager::$plugin->backend->getActiveBackend();

        if ($backend === null) {
            return [];
        }

        return $backend->listIndices();
    }

    /**
     * Get a proxy for a specific configured backend
     *
     * This allows templates to use a specific backend regardless of the default.
     * Useful for testing or when you need to query multiple backends.
     *
     * Usage:
     *     {% set algolia = craft.searchManager.withBackend('production-algolia') %}
     *     {% set indices = algolia.listIndices() %}
     *     {% set results = algolia.search('my-index', 'query') %}
     *
     * @since 5.28.0
     * @param string $backendHandle Handle of a configured backend
     * @return BackendVariableProxy|null Proxy object or null if backend not found
     */
    public function withBackend(string $backendHandle): ?BackendVariableProxy
    {
        // Look up the configured backend by handle
        $configuredBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($backendHandle);

        if (!$configuredBackend || !$configuredBackend->enabled) {
            \Craft::warning("Backend '{$backendHandle}' not found or disabled", 'search-manager');
            return null;
        }

        // Create the backend instance with its configured settings
        $backend = SearchManager::$plugin->backend->createBackendFromConfig($configuredBackend);

        if (!$backend) {
            \Craft::warning("Failed to create backend instance for '{$backendHandle}'", 'search-manager');
            return null;
        }

        return new BackendVariableProxy($backend, $backendHandle);
    }
}
