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
        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        $limit = (int)Craft::$app->getRequest()->getParam('limit', 10);
        $only = Craft::$app->getRequest()->getParam('only', null);
        $typeFilter = Craft::$app->getRequest()->getParam('type', null);

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

        // Only suggestions: return plain strings
        if ($only === 'suggestions') {
            return $this->asJson($autocomplete->suggest($query, $indexHandle, [
                'limit' => $limit,
            ]));
        }

        // Only results: return element objects with type info
        if ($only === 'results') {
            return $this->asJson($autocomplete->suggestElements($query, $indexHandle, [
                'limit' => $limit,
                'type' => $typeFilter,
            ]));
        }

        // Default: return both
        return $this->asJson([
            'suggestions' => $autocomplete->suggest($query, $indexHandle, [
                'limit' => $limit,
            ]),
            'results' => $autocomplete->suggestElements($query, $indexHandle, [
                'limit' => $limit,
                'type' => $typeFilter,
            ]),
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
     *
     * Response includes type field for each hit:
     * {hits: [{objectID, id, score, type}, ...], total: N}
     *
     * @return Response
     */
    public function actionSearch(): Response
    {
        $query = Craft::$app->getRequest()->getParam('q', '');
        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        // TODO: Make default limit configurable via settings (add 'apiDefaultLimit' config option)
        $limit = (int)Craft::$app->getRequest()->getParam('limit', 20);
        $typeFilter = Craft::$app->getRequest()->getParam('type', null);

        if (empty($query)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        $results = SearchManager::$plugin->backend->search($indexHandle, $query, [
            'limit' => $limit,
            'type' => $typeFilter,
        ]);

        return $this->asJson($results);
    }
}
