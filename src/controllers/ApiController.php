<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
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

        // Get indices from new 'indices' param or legacy 'index' param
        $indicesParam = Craft::$app->getRequest()->getParam('indices', '');
        $indexHandle = Craft::$app->getRequest()->getParam('index', '');
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

        // Parse indices - comma-separated string to array
        // Track whether caller explicitly provided indices
        $indexHandles = [];
        $indicesProvided = false;
        if (!empty($indicesParam)) {
            $indicesProvided = true;
            $indexHandles = array_filter(array_map('trim', explode(',', $indicesParam)));
        } elseif (!empty($indexHandle)) {
            $indicesProvided = true;
            $indexHandles = [$indexHandle];
        }

        // Cap indices count to prevent fan-out attacks
        if (count($indexHandles) > self::MAX_INDICES_COUNT) {
            $indexHandles = array_slice($indexHandles, 0, self::MAX_INDICES_COUNT);
        }

        // Validate requested indices - only allow enabled indices on public endpoints
        if (!empty($indexHandles)) {
            $enabledIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
            $enabledHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($enabledIndices, fn($idx) => $idx->enabled)
            );
            // Filter to only enabled indices
            $indexHandles = array_values(array_intersect($indexHandles, $enabledHandles));
        }

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
            $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
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
            $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
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

        $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
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
     * - hitsPerPage: Max results (default: 20)
     * - page: Page number (0-based, default: 0)
     * - type: Filter by element type (optional, e.g., 'product', 'category', 'product,category')
     * - language: Language code for localized operators (optional, e.g., 'de', 'fr', 'es', 'ar')
     *             Supports: AND/OR/NOT in English, UND/ODER/NICHT (German), ET/OU/SAUF (French),
     *             Y/O/NO (Spanish), و/أو/ليس (Arabic). Defaults to site language.
     * - source: Analytics source identifier (optional, e.g., 'ios-app', 'android-app')
     * - platform: Platform info (optional, e.g., 'iOS 17.2', 'Android 14')
     * - appVersion: App version (optional, e.g., '2.1.0')
     *
     * Response includes type field for each hit:
     * {hits: [{objectID, id, score, type}, ...], total: N}
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionSearch(): Response
    {
        $request = Craft::$app->getRequest();
        $query = $request->getParam('q', '');

        // Enforce query length cap to prevent resource exhaustion
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
                'error' => 'Query too long',
            ]);
        }

        // Get indices from new 'indices' param or legacy 'index' param
        $indicesParam = $request->getParam('indices', '');
        $indexHandle = $request->getParam('index', '');
        $limit = (int) $request->getParam('hitsPerPage', 20);
        // Normalize limit: negative = default, 0 = no limit, positive = capped at 100
        if ($limit < 0) {
            $limit = 20;
        } elseif ($limit > 0) {
            $limit = min(100, $limit);
        }
        // $limit === 0 means "no limit" (passed through to backend)
        $page = (int) $request->getParam('page', 0);
        if ($page < 0) {
            $page = 0;
        }
        $offset = $limit > 0 ? $page * $limit : 0;
        $typeFilter = $request->getParam('type', null);
        $language = $request->getParam('language', null);

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

        // Parse indices - comma-separated string to array
        // Track whether caller explicitly provided indices
        $indexHandles = [];
        $indicesProvided = false;
        if (!empty($indicesParam)) {
            $indicesProvided = true;
            $indexHandles = array_filter(array_map('trim', explode(',', $indicesParam)));
        } elseif (!empty($indexHandle)) {
            $indicesProvided = true;
            $indexHandles = [$indexHandle];
        }

        // Cap indices count to prevent fan-out attacks
        if (count($indexHandles) > self::MAX_INDICES_COUNT) {
            $indexHandles = array_slice($indexHandles, 0, self::MAX_INDICES_COUNT);
        }

        // Validate requested indices - only allow enabled indices on public endpoints
        if (!empty($indexHandles)) {
            $enabledIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
            $enabledHandles = array_map(
                fn($idx) => $idx->handle,
                array_filter($enabledIndices, fn($idx) => $idx->enabled)
            );
            // Filter to only enabled indices
            $indexHandles = array_values(array_intersect($indexHandles, $enabledHandles));
        }

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
        ];

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
            $allIndices = \lindemannrock\searchmanager\models\SearchIndex::findAll();
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

        // Strip internal meta from public API response
        // Meta contains rule IDs, names, action values which expose internal logic
        // TODO: Add API key authentication with debug flag to allow meta for trusted clients
        unset($results['meta']);

        $total = (int) ($results['total'] ?? 0);
        $results['page'] = $page;
        $results['hitsPerPage'] = $limit;
        $results['totalPages'] = $limit > 0 ? (int) ceil($total / $limit) : 1;

        return $this->asJson($results);
    }
}
