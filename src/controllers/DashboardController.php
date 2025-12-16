<?php

namespace lindemannrock\searchmanager\controllers;

use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\Response;

/**
 * Dashboard Controller
 */
class DashboardController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewIndices');

        $settings = SearchManager::$plugin->getSettings();
        $indices = SearchIndex::findAll();
        $backend = SearchManager::$plugin->backend->getActiveBackend();

        return $this->renderTemplate('search-manager/dashboard/index', [
            'settings' => $settings,
            'indices' => $indices,
            'backend' => $backend,
            'backendStatus' => $backend ? $backend->getStatus() : null,
        ]);
    }
}
