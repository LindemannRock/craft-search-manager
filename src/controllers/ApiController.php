<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * API Controller
 *
 * Provides AJAX endpoints for frontend search features
 *
 * @since 5.0.0
 */
class ApiController extends Controller
{
    /**
     * Maximum query length to prevent resource exhaustion
     */
    private const MAX_QUERY_LENGTH = 256;
    /**
     * Maximum number of indices allowed per request
     */
    private const MAX_INDICES_COUNT = 5;

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = true;

    /**
     * Get autocomplete suggestions and/or element results
     *
     * GET /actions/search-manager/api/autocomplete?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - index: Single index handle (legacy)
     * - hitsPerPage: Max results (default: 10)
     * - only: Return only 'suggestions' or 'results' (optional, default returns both)
     * - type: Filter results by element type (optional, e.g., 'product', 'category')
     *
     * Response formats:
     * - Default: {suggestions: ["term1", ...], results: [{text, type, id}, ...]}
     * - only=suggestions: ["term1", "term2", ...]
     * - only=results: [{text: "Product Name", type: "product", id: 123}, ...]
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionAutocomplete(): Response
    {
        $query = Craft::$app->getRequest()->getParam('q', '');

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
                'error' => 'Query too long',
            ]);
        }

        $limit = (int) Craft::$app->getRequest()->getParam('hitsPerPage', 10);
        // Clamp limit to prevent expensive queries (max 100, 0 or negative = use default)
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min(100, $limit);
        $only = Craft::$app->getRequest()->getParam('only', null);
        $typeFilter = Craft::$app->getRequest()->getParam('type', null);
        $siteId = Craft::$app->getRequest()->getParam('siteId');
        $siteId = $siteId ? (int)$siteId : null;
        // Support both 'language' and 'lang' parameters
        $language = Craft::$app->getRequest()->getParam('language') ?? Craft::$app->getRequest()->getParam('lang');

        if (empty($query)) {
            if ($only === 'suggestions') {
                return $this->asJson([]);
            }
            if ($only === 'results') {
                return $this->asJson([]);
            }
            return $this->asJson([
                'suggestions' => [],
                'results' => [],
            ]);
        }

        $autocomplete = SearchManager::$plugin->autocomplete;

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
            Craft::$app->getRequest()->getParam('indices', ''),
            Craft::$app->getRequest()->getParam('index', ''),
            self::MAX_INDICES_COUNT,
        );

        // If indices were explicitly provided but none are valid/enabled, return empty
        // Don't fall back to "all enabled" - that would expose unintended results
        if ($indicesProvided && empty($indexHandles)) {
            if ($only === 'suggestions') {
                return $this->asJson([]);
            }
            if ($only === 'results') {
                return $this->asJson([]);
            }
            return $this->asJson(['suggestions' => [], 'results' => []]);
        }

        // Build options array
        $options = ['limit' => $limit];
        if ($siteId !== null) {
            $options['siteId'] = $siteId;
        }
        if ($language !== null) {
            $options['language'] = $language;
        }

        // Only suggestions: return plain strings
        if ($only === 'suggestions') {
            if (count($indexHandles) === 1) {
                return $this->asJson($autocomplete->suggest($query, $indexHandles[0], $options));
            }
            if (count($indexHandles) > 1) {
                $allSuggestions = [];
                foreach ($indexHandles as $handle) {
                    $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
                }
                return $this->asJson(array_values(array_unique($allSuggestions)));
            }
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );
            $allSuggestions = [];
            foreach ($allIndexHandles as $handle) {
                $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
            }
            return $this->asJson(array_values(array_unique($allSuggestions)));
        }

        // Only results: return element objects with type info
        if ($only === 'results') {
            $options['type'] = $typeFilter;
            if (count($indexHandles) === 1) {
                return $this->asJson($autocomplete->suggestElements($query, $indexHandles[0], $options));
            }
            if (count($indexHandles) > 1) {
                $allResults = [];
                foreach ($indexHandles as $handle) {
                    $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, $options));
                }
                return $this->asJson($allResults);
            }
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );
            $allResults = [];
            foreach ($allIndexHandles as $handle) {
                $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, $options));
            }
            return $this->asJson($allResults);
        }

        // Default: return both
        if (count($indexHandles) === 1) {
            return $this->asJson([
                'suggestions' => $autocomplete->suggest($query, $indexHandles[0], $options),
                'results' => $autocomplete->suggestElements($query, $indexHandles[0], array_merge($options, ['type' => $typeFilter])),
            ]);
        }
        if (count($indexHandles) > 1) {
            $allSuggestions = [];
            $allResults = [];
            foreach ($indexHandles as $handle) {
                $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
                $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, array_merge($options, ['type' => $typeFilter])));
            }
            return $this->asJson([
                'suggestions' => array_values(array_unique($allSuggestions)),
                'results' => $allResults,
            ]);
        }

        $allIndices = SearchIndex::findAll();
        $allIndexHandles = array_map(
            fn($idx) => $idx->handle,
            array_filter($allIndices, fn($idx) => $idx->enabled)
        );
        $allSuggestions = [];
        $allResults = [];
        foreach ($allIndexHandles as $handle) {
            $allSuggestions = array_merge($allSuggestions, $autocomplete->suggest($query, $handle, $options));
            $allResults = array_merge($allResults, $autocomplete->suggestElements($query, $handle, array_merge($options, ['type' => $typeFilter])));
        }
        return $this->asJson([
            'suggestions' => array_values(array_unique($allSuggestions)),
            'results' => $allResults,
        ]);
    }

    /**
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - indices: Comma-separated index handles (optional)
     * - index: Single index handle (legacy)
     * - hitsPerPage: Max results per page (default: 20, min: 1, max: 200)
     * - page: Page number (0-based, default: 0)
     * - type: Filter by element type (optional, e.g., 'product', 'category', 'product,category')
     * - language: Language code for localized operators (optional, e.g., 'de', 'fr', 'es', 'ar')
     *             Supports: AND/OR/NOT in English, UND/ODER/NICHT (German), ET/OU/SAUF (French),
     *             Y/O/NO (Spanish), و/أو/ليس (Arabic). Defaults to site language.
     * - source: Analytics source identifier (optional, e.g., 'ios-app', 'android-app')
     * - platform: Platform info (optional, e.g., 'iOS 17.2', 'Android 14')
     * - appVersion: App version (optional, e.g., '2.1.0')
     * - enrich: Enable result enrichment (default: 0). When enabled, results include
     *           snippets, heading expansion, thumbnails, debug meta, and promoted/boosted flags.
     *           Response uses 'hits' key with enriched result objects.
     * - skipAnalytics: Skip analytics tracking for this search (default: 0)
     *
     * Enrichment parameters (only used when enrich=1):
     * - snippetMode: Snippet positioning mode: 'early'|'balanced'|'deep' (default: 'balanced')
     * - snippetLength: Max snippet length in chars (default: 150, min: 50, max: 1000)
     * - allowCodeSnippets: Include code block snippets (default: 0)
     * - parseMarkdownSnippets: Parse markdown before generating snippets (default: 0)
     * - hideResultsWithoutUrl: Exclude results that have no URL (default: 0)
     * - debug: Include debug metadata in response (default: devMode setting)
     *
     * Response format:
     * - Raw (default): {hits: [{objectID, id, score, type}, ...], total, page, hitsPerPage, totalPages}
     * - Enriched (enrich=1): {hits: [{id, title, url, description, section, type, score, ...}, ...],
     *   total, page, hitsPerPage, totalPages, query}
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');
        $enrich = (bool) $request->getParam('enrich', false);

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
                'query' => $query,
                'error' => 'Query too long (max ' . self::MAX_QUERY_LENGTH . ' characters)',
            ]);
        }

        // hitsPerPage: min 1, default 20, max 200
        $limit = (int) $request->getParam('hitsPerPage', 20);
        if ($limit < 1) {
            $limit = 20;
        }
        $limit = min(200, $limit);
        $page = (int) $request->getParam('page', 0);
        if ($page < 0) {
            $page = 0;
        }
        $offset = $page * $limit;
        $typeFilter = $request->getParam('type', null);
        $siteId = $request->getParam('siteId');
        $siteId = $siteId ? (int) $siteId : null;
        $language = $request->getParam('language', null);

        // Skip analytics if explicitly requested (e.g., widget passes skipAnalytics=1 to prevent keystroke spam)
        $skipAnalytics = (bool) $request->getParam('skipAnalytics', false);

        // Analytics options (for mobile apps and custom integrations)
        $source = $request->getParam('source', null);
        $platform = $request->getParam('platform', null);
        $appVersion = $request->getParam('appVersion', null);

        if (empty($query)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        // Parse and validate requested indices
        [$indexHandles, $indicesProvided] = SearchIndex::resolveRequestedIndices(
            $request->getParam('indices', ''),
            $request->getParam('index', ''),
            self::MAX_INDICES_COUNT,
        );

        // If indices were explicitly provided but none are valid/enabled, return empty
        // Don't fall back to "all enabled" - that would expose unintended results
        if ($indicesProvided && empty($indexHandles)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        $options = [
            'limit' => $limit,
            'offset' => $offset,
            'page' => $page,
            'type' => $typeFilter,
            'skipAnalytics' => $skipAnalytics,
        ];

        // Add siteId if provided (scope search to a specific site)
        if ($siteId !== null) {
            $options['siteId'] = $siteId;
        }

        // Add language if provided (for localized boolean operators)
        if ($language !== null) {
            $options['language'] = $language;
        }

        // Add analytics options if provided
        if ($source !== null) {
            $options['source'] = $source;
        }
        if ($platform !== null) {
            $options['platform'] = $platform;
        }
        if ($appVersion !== null) {
            $options['appVersion'] = $appVersion;
        }

        // Run search (single, multi, or all enabled indices)
        if (count($indexHandles) === 1) {
            $results = SearchManager::$plugin->backend->search($indexHandles[0], $query, $options);
        } elseif (count($indexHandles) > 1) {
            $results = SearchManager::$plugin->backend->searchMultiple($indexHandles, $query, $options);
        } else {
            // No indices specified - search all enabled indices
            $allIndices = SearchIndex::findAll();
            $allIndexHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($allIndices, fn($idx) => $idx->enabled)
            );

            if (empty($allIndexHandles)) {
                return $this->asJson([
                    'hits' => [],
                    'total' => 0,
                    'error' => 'No search indices configured',
                ]);
            }

            $results = SearchManager::$plugin->backend->searchMultiple($allIndexHandles, $query, $options);
        }

        if ($enrich) {
            // Enriched mode: resolve elements, generate snippets, expand headings
            // Debug mode: requires devMode OR searchManager:viewDebug permission
            $debugParam = $request->getParam('debug');
            $canViewDebug = Craft::$app->config->general->devMode
                || Craft::$app->getUser()->checkPermission('searchManager:viewDebug');
            $includeDebugMeta = $canViewDebug && ($debugParam !== null ? (bool) $debugParam : Craft::$app->config->general->devMode);

            $enrichOptions = [
                'snippetMode' => (string) $request->getParam('snippetMode', 'balanced'),
                'snippetLength' => (int) $request->getParam('snippetLength', 150),
                'allowCodeSnippets' => (bool) $request->getParam('allowCodeSnippets', false),
                'parseMarkdownSnippets' => (bool) $request->getParam('parseMarkdownSnippets', false),
                'hideResultsWithoutUrl' => (bool) $request->getParam('hideResultsWithoutUrl', false),
                'includeDebugMeta' => $includeDebugMeta,
            ];

            if ($siteId !== null) {
                $enrichOptions['siteId'] = $siteId;
            }

            try {
                $enrichedHits = SearchManager::$plugin->enrichment->enrichResults(
                    $results['hits'] ?? [],
                    $query,
                    $indexHandles,
                    $enrichOptions,
                );
            } catch (\Throwable $e) {
                Craft::error('Enrichment failed: ' . $e->getMessage(), 'search-manager');

                return $this->asJson([
                    'hits' => [],
                    'total' => 0,
                    'query' => $query,
                    'error' => Craft::$app->config->general->devMode ? $e->getMessage() : 'Search enrichment failed',
                ]);
            }

            $total = (int) ($results['total'] ?? count($enrichedHits));
            $totalPages = (int) ceil($total / $limit);

            $response = [
                'hits' => $enrichedHits,
                'total' => $total,
                'query' => $query,
                'page' => $page,
                'hitsPerPage' => $limit,
                'totalPages' => $totalPages,
            ];

            // Include debug meta only when devMode is on OR debug param explicitly set
            if ($includeDebugMeta && !empty($results['meta'])) {
                $response['meta'] = $results['meta'];
                $response['meta']['indices'] = $indexHandles ?: ['all'];
            }

            return $this->asJson($response);
        }

        // Raw mode: strip internal meta and content fields
        unset($results['meta']);

        if (!empty($results['hits'])) {
            foreach ($results['hits'] as &$hit) {
                unset($hit['content'], $hit['body'], $hit['excerpt']);
            }
            unset($hit);
        }

        $total = (int) ($results['total'] ?? 0);
        $results['page'] = $page;
        $results['hitsPerPage'] = $limit;
        $results['totalPages'] = (int) ceil($total / $limit);

        return $this->asJson($results);
    }
}
