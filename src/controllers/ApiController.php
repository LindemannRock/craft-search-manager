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
     * @return Response
     */
    public function actionSuggest(): Response
    {
        $query = Craft::$app->getRequest()->getParam('q', '');
        $indexHandle = Craft::$app->getRequest()->getParam('index', 'all-sites');
        $limit = (int)Craft::$app->getRequest()->getParam('limit', 10);

        if (empty($query)) {
            return $this->asJson([]);
        }

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
