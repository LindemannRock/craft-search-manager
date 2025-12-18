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
     * Get autocomplete suggestions
     *
     * GET /actions/search-manager/api/suggest?q=test&index=all-sites
     *
     * Parameters:
     * - q: Search query (required)
     * - index: Index handle (default: all-sites)
     * - limit: Max results (default: 10)
     * - format: Response format - 'simple' (default, string[]) or 'detailed' (objects with type)
     * - type: Filter by element type (optional, e.g., 'product', 'category')
     *
     * Simple format response: ["term1", "term2", ...]
     * Detailed format response: [{text: "Product Name", type: "product", id: 123}, ...]
     *
     * @return Response
     */
    public function actionSuggest(): Response
    {
        $query = Craft::$app->getRequest()->getParam('q', '');
        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        $limit = (int)Craft::$app->getRequest()->getParam('limit', 10);
        $format = Craft::$app->getRequest()->getParam('format', 'simple');
        $typeFilter = Craft::$app->getRequest()->getParam('type', null);

        if (empty($query)) {
            return $this->asJson([]);
        }

        // Detailed format: return element objects with type info
        if ($format === 'detailed') {
            $suggestions = SearchManager::$plugin->autocomplete->suggestElements($query, $indexHandle, [
                'limit' => $limit,
                'type' => $typeFilter,
            ]);

            return $this->asJson($suggestions);
        }

        // Simple format (default): return plain strings for backward compatibility
        $suggestions = SearchManager::$plugin->autocomplete->suggest($query, $indexHandle, [
            'limit' => $limit,
        ]);

        return $this->asJson($suggestions);
    }

    /**
     * Perform search
     *
     * GET /actions/search-manager/api/search?q=test&index=all-sites
     *
     * @return Response
     */
    public function actionSearch(): Response
    {
        $query = Craft::$app->getRequest()->getParam('q', '');
        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        // TODO: Make default limit configurable via settings (add 'apiDefaultLimit' config option)
        $limit = (int)Craft::$app->getRequest()->getParam('limit', 20);

        if (empty($query)) {
            return $this->asJson([
                'hits' => [],
                'total' => 0,
            ]);
        }

        $results = SearchManager::$plugin->backend->search($indexHandle, $query, [
            'limit' => $limit,
        ]);

        return $this->asJson($results);
    }
}
