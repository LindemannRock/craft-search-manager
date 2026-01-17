<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Widgets Controller
 *
 * Manages search widget configurations in the CP
 */
class WidgetsController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all widget configurations
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewWidgetConfigs');

        $widgetConfigs = SearchManager::$plugin->widgetConfigs->getAll();

        return $this->renderTemplate('search-manager/widgets/index', [
            'widgetConfigs' => $widgetConfigs,
        ]);
    }

    /**
     * Edit or create a widget configuration
     */
    public function actionEdit(?int $configId = null): Response
    {
        if ($configId) {
            $this->requirePermission('searchManager:editWidgetConfigs');
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($configId);
            if (!$widgetConfig) {
                throw new NotFoundHttpException('Widget config not found');
            }
        } else {
            $this->requirePermission('searchManager:createWidgetConfigs');
            $widgetConfig = new WidgetConfig();
            $widgetConfig->settings = WidgetConfig::defaultSettings();
        }

        // Get indices for multi-select
        $indices = SearchIndex::findAll();

        return $this->renderTemplate('search-manager/widgets/edit', [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indices' => $indices,
        ]);
    }

    /**
     * Save a widget configuration
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $configId = $request->getBodyParam('configId');

        if ($configId) {
            $this->requirePermission('searchManager:editWidgetConfigs');
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($configId);
            if (!$widgetConfig) {
                throw new NotFoundHttpException('Widget config not found');
            }
        } else {
            $this->requirePermission('searchManager:createWidgetConfigs');
            $widgetConfig = new WidgetConfig();
        }

        // Check if trying to unset default on the current default config
        $wasDefault = $configId ? SearchManager::$plugin->widgetConfigs->getById($configId)?->isDefault : false;
        $newIsDefault = (bool) $request->getBodyParam('isDefault');

        if ($wasDefault && !$newIsDefault) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Cannot remove default status. Set another config as default first.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'widgetConfig' => $widgetConfig,
            ]);

            return null;
        }

        // Set basic attributes
        $widgetConfig->name = $request->getBodyParam('name');
        $widgetConfig->handle = $request->getBodyParam('handle');
        $widgetConfig->enabled = (bool) $request->getBodyParam('enabled');
        $widgetConfig->isDefault = $newIsDefault;

        // Get settings from form
        $settings = $request->getBodyParam('settings', []);

        // Merge with defaults to ensure all keys exist
        $defaults = WidgetConfig::defaultSettings();
        $mergedSettings = array_replace_recursive($defaults, $settings);

        // Handle indexHandles - ensure it's always an array
        if (isset($mergedSettings['search']['indexHandles'])) {
            $indexHandles = $mergedSettings['search']['indexHandles'];
            if (!is_array($indexHandles)) {
                $mergedSettings['search']['indexHandles'] = $indexHandles ? [$indexHandles] : [];
            }
        }

        $widgetConfig->settings = $mergedSettings;

        // Validate
        if (!$widgetConfig->validate()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'widgetConfig' => $widgetConfig,
            ]);

            return null;
        }

        // Save
        if (!SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'widgetConfig' => $widgetConfig,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Widget config saved.'));

        return $this->redirectToPostedUrl($widgetConfig);
    }

    /**
     * Delete a widget configuration
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:deleteWidgetConfigs');

        $configId = Craft::$app->getRequest()->getRequiredBodyParam('configId');

        $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($configId);
        if (!$widgetConfig) {
            return $this->asJson(['success' => false, 'error' => 'Widget config not found']);
        }

        // Prevent deleting the default config
        if ($widgetConfig->isDefault) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Cannot delete the default widget config. Set another config as default first.')]);
        }

        if (!SearchManager::$plugin->widgetConfigs->delete($configId)) {
            return $this->asJson(['success' => false, 'error' => 'Could not delete widget config']);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Set a widget configuration as default
     */
    public function actionSetDefault(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetConfigs');

        $configId = Craft::$app->getRequest()->getRequiredBodyParam('configId');

        $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
        if (!$widgetConfig) {
            return $this->asJson(['success' => false, 'error' => 'Widget config not found']);
        }

        $widgetConfig->isDefault = true;

        if (!SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
            return $this->asJson(['success' => false, 'error' => 'Could not set default config']);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Bulk enable widget configurations
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetConfigs');

        $configIds = Craft::$app->getRequest()->getRequiredBodyParam('configIds');
        $count = 0;

        foreach ($configIds as $configId) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
            if ($widgetConfig) {
                $widgetConfig->enabled = true;
                if (SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
                    $count++;
                }
            }
        }

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * Bulk disable widget configurations
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetConfigs');

        $configIds = Craft::$app->getRequest()->getRequiredBodyParam('configIds');
        $count = 0;

        foreach ($configIds as $configId) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
            if ($widgetConfig) {
                $widgetConfig->enabled = false;
                if (SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
                    $count++;
                }
            }
        }

        return $this->asJson(['success' => true, 'count' => $count]);
    }

    /**
     * Bulk delete widget configurations
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:deleteWidgetConfigs');

        $configIds = Craft::$app->getRequest()->getRequiredBodyParam('configIds');
        $count = 0;
        $errors = [];

        foreach ($configIds as $configId) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
            if (!$widgetConfig) {
                continue;
            }

            // Skip the default config
            if ($widgetConfig->isDefault) {
                $errors[] = Craft::t('search-manager', 'Cannot delete "{name}" because it is the default config.', ['name' => $widgetConfig->name]);
                continue;
            }

            if (SearchManager::$plugin->widgetConfigs->deleteById((int)$configId)) {
                $count++;
            }
        }

        return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
    }
}
