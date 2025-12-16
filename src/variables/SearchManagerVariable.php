<?php

namespace lindemannrock\searchmanager\variables;

use lindemannrock\searchmanager\SearchManager;

/**
 * Search Manager Template Variable
 *
 * Provides access to plugin functionality from Twig templates
 * Usage: {{ craft.searchManager.search('query') }}
 */
class SearchManagerVariable
{
    /**
     * Get plugin settings
     */
    public function getSettings()
    {
        return SearchManager::$plugin->getSettings();
    }

    /**
     * Get plugin instance
     */
    public function getPlugin()
    {
        return SearchManager::$plugin;
    }

    /**
     * Perform a search
     *
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
     */
    public function getIndices(): array
    {
        return \lindemannrock\searchmanager\models\SearchIndex::findAll();
    }

    /**
     * Highlight search terms in text
     *
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
            $parsed = \lindemannrock\searchmanager\search\QueryParser::parse($terms);
            $terms = $highlighter->extractTermsFromParsedQuery($parsed);
        }

        return $highlighter->highlight($text, $terms, $options['stripTags'] ?? true);
    }

    /**
     * Generate snippets with highlighted terms
     *
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
            $parsed = \lindemannrock\searchmanager\search\QueryParser::parse($terms);
            $terms = $highlighter->extractTermsFromParsedQuery($parsed);
        }

        return $highlighter->generateSnippets($text, $terms, $options['stripTags'] ?? true);
    }

    /**
     * Get autocomplete suggestions for a query
     *
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
}
