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
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/widgets/index', [
            'widgetConfigs' => $widgetConfigs,
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
        ]);
    }

    /**
     * View a widget configuration (read-only, for config widgets)
     *
     * @param string|null $handle Widget handle
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:viewWidgetConfigs');

        if (!$handle) {
            throw new NotFoundHttpException('Widget handle required');
        }

        $widgetConfig = SearchManager::$plugin->widgetConfigs->getByHandle($handle);

        if (!$widgetConfig) {
            throw new NotFoundHttpException('Widget config not found');
        }

        // Get indices for display
        $indices = SearchIndex::findAll();
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/widgets/view', [
            'widgetConfig' => $widgetConfig,
            'indices' => $indices,
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
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
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/widgets/edit', [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indices' => $indices,
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
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

        // Set basic attributes
        $widgetConfig->name = $request->getBodyParam('name');
        $widgetConfig->handle = $request->getBodyParam('handle');
        $widgetConfig->enabled = (bool) $request->getBodyParam('enabled');

        // Get settings from form
        $widgetSettings = $request->getBodyParam('settings', []);

        // Merge with defaults to ensure all keys exist
        $defaults = WidgetConfig::defaultSettings();
        $mergedSettings = array_replace_recursive($defaults, $widgetSettings);

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

            $pluginSettings = SearchManager::$plugin->getSettings();
            Craft::$app->getUrlManager()->setRouteParams([
                'widgetConfig' => $widgetConfig,
                'defaultWidgetHandle' => $pluginSettings->defaultWidgetHandle,
                'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            ]);

            return null;
        }

        // Save widget config
        if (!SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config.'));

            $pluginSettings = SearchManager::$plugin->getSettings();
            Craft::$app->getUrlManager()->setRouteParams([
                'widgetConfig' => $widgetConfig,
                'defaultWidgetHandle' => $pluginSettings->defaultWidgetHandle,
                'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            ]);

            return null;
        }

        // Handle "Set as Default" toggle (only if not set via config)
        $isDefault = (bool) $request->getBodyParam('isDefault');
        if ($isDefault && !$this->isDefaultWidgetFromConfig()) {
            $pluginSettings = SearchManager::$plugin->getSettings();
            if ($pluginSettings->defaultWidgetHandle !== $widgetConfig->handle) {
                $pluginSettings->defaultWidgetHandle = $widgetConfig->handle;
                $pluginSettings->saveToDatabase();

                $this->logInfo('Default widget changed', [
                    'handle' => $widgetConfig->handle,
                    'name' => $widgetConfig->name,
                ]);
            }
        }

        // Auto-set as default if no default is set and this widget is enabled
        if (!$this->isDefaultWidgetFromConfig()) {
            $pluginSettings = SearchManager::$plugin->getSettings();
            if (empty($pluginSettings->defaultWidgetHandle) && $widgetConfig->enabled) {
                $pluginSettings->defaultWidgetHandle = $widgetConfig->handle;
                $pluginSettings->saveToDatabase();

                $this->logInfo('Auto-set default widget (first enabled widget)', [
                    'handle' => $widgetConfig->handle,
                    'name' => $widgetConfig->name,
                ]);
            }
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

        // Prevent deleting the default widget
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Cannot delete the default widget. Set another widget as default first.')]);
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

        // Check if default is set via config - if so, don't allow changes
        if ($this->isDefaultWidgetFromConfig()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Default widget is set via config file and cannot be changed here.'),
            ]);
        }

        $configId = Craft::$app->getRequest()->getRequiredBodyParam('configId');

        // Find the widget - try by ID first, then by handle (for config widgets)
        if (is_numeric($configId)) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
        } else {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getByHandle((string)$configId);
        }

        if (!$widgetConfig) {
            return $this->asJson(['success' => false, 'error' => 'Widget config not found']);
        }

        // Update the default widget handle in plugin settings
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $widgetConfig->handle;

        if (!$settings->saveToDatabase()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to save settings.'),
            ]);
        }

        $this->logInfo('Default widget changed', [
            'handle' => $widgetConfig->handle,
            'name' => $widgetConfig->name,
        ]);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('search-manager', 'Default widget updated.'),
        ]);
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
        $settings = SearchManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($configIds as $configId) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
            if ($widgetConfig) {
                // Skip the default widget
                if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
                    $errors[] = Craft::t('search-manager', 'Cannot disable "{name}" because it is the default widget.', ['name' => $widgetConfig->name]);
                    continue;
                }

                $widgetConfig->enabled = false;
                if (SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
                    $count++;
                }
            }
        }

        return $this->asJson(['success' => count($errors) === 0, 'count' => $count, 'errors' => $errors]);
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
        $settings = SearchManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($configIds as $configId) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById((int)$configId);
            if (!$widgetConfig) {
                continue;
            }

            // Skip the default widget
            if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
                $errors[] = Craft::t('search-manager', 'Cannot delete "{name}" because it is the default widget.', ['name' => $widgetConfig->name]);
                continue;
            }

            if (SearchManager::$plugin->widgetConfigs->deleteById((int)$configId)) {
                $count++;
            }
        }

        return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
    }

    /**
     * Check if defaultWidgetHandle is set via config file
     */
    private function isDefaultWidgetFromConfig(): bool
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (!file_exists($configPath)) {
            return false;
        }

        $config = require $configPath;

        // Check in root level
        if (isset($config['defaultWidgetHandle'])) {
            return true;
        }

        // Check in '*' (all environments)
        if (isset($config['*']['defaultWidgetHandle'])) {
            return true;
        }

        // Check in current environment
        $env = Craft::$app->getConfig()->env;
        if ($env && isset($config[$env]['defaultWidgetHandle'])) {
            return true;
        }

        return false;
    }
}
