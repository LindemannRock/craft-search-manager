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
 */
class ApiController extends Controller
{
    /**
     * Maximum query length to prevent resource exhaustion
     */
    private const MAX_QUERY_LENGTH = 256;

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
     * - index: Index handle (default: all-sites)
     * - limit: Max results (default: 10)
     * - only: Return only 'suggestions' or 'results' (optional, default returns both)
     * - type: Filter results by element type (optional, e.g., 'product', 'category')
     *
     * Response formats:
     * - Default: {suggestions: ["term1", ...], results: [{text, type, id}, ...]}
     * - only=suggestions: ["term1", "term2", ...]
     * - only=results: [{text: "Product Name", type: "product", id: 123}, ...]
     *
     * @return Response
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

        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        $limit = (int) Craft::$app->getRequest()->getParam('limit', 10);
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

        // Validate index handle - block disabled indices on public endpoints
        if ($indexHandle !== 'all-sites') {
            $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
            if (!$index || !$index->enabled) {
                // Return empty results for disabled or unknown indices
                if ($only === 'suggestions') {
                    return $this->asJson([]);
                }
                if ($only === 'results') {
                    return $this->asJson([]);
                }
                return $this->asJson(['suggestions' => [], 'results' => []]);
            }
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
            return $this->asJson($autocomplete->suggest($query, $indexHandle, $options));
        }

        // Only results: return element objects with type info
        if ($only === 'results') {
            $options['type'] = $typeFilter;
            return $this->asJson($autocomplete->suggestElements($query, $indexHandle, $options));
        }

        // Default: return both
        return $this->asJson([
            'suggestions' => $autocomplete->suggest($query, $indexHandle, $options),
            'results' => $autocomplete->suggestElements($query, $indexHandle, array_merge($options, ['type' => $typeFilter])),
        ]);
    }

    /**
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - index: Index handle (default: all-sites)
     * - limit: Max results (default: 20)
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

        $indexHandle = $request->getParam('index', 'all-sites');
        $limit = (int) $request->getParam('limit', 20);
        // Normalize limit: negative = default, 0 = no limit, positive = capped at 100
        if ($limit < 0) {
            $limit = 20;
        } elseif ($limit > 0) {
            $limit = min(100, $limit);
        }
        // $limit === 0 means "no limit" (passed through to backend)
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

        // Validate index handle - block disabled indices on public endpoints
        if ($indexHandle !== 'all-sites') {
            $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexHandle);
            if (!$index || !$index->enabled) {
                // Return empty results for disabled or unknown indices
                return $this->asJson([
                    'hits' => [],
                    'total' => 0,
                ]);
            }
        }

        $options = [
            'limit' => $limit,
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

        $results = SearchManager::$plugin->backend->search($indexHandle, $query, $options);

        // Strip internal meta from public API response
        // Meta contains rule IDs, names, action values which expose internal logic
        // TODO: Add API key authentication with debug flag to allow meta for trusted clients
        unset($results['meta']);

        return $this->asJson($results);
    }
}
