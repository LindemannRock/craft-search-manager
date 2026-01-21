<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backends Controller
 *
 * Manages configured backend instances
 */
class BackendsController extends Controller
{
    use LoggingTrait;

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all configured backends
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:viewBackends');

        $backends = ConfiguredBackend::findAll();
        $settings = SearchManager::$plugin->getSettings();

        // Auto-assign default if needed (only if not set via config file)
        if (!$this->isDefaultBackendFromConfig()) {
            $defaultHandle = $settings->defaultBackendHandle;
            $needsReassign = false;

            if (empty($defaultHandle)) {
                // No default set
                $needsReassign = true;
            } else {
                // Check if default exists and is enabled
                $defaultBackend = ConfiguredBackend::findByHandle($defaultHandle);
                if (!$defaultBackend || !$defaultBackend->enabled) {
                    $needsReassign = true;
                }
            }

            if ($needsReassign && !empty($backends)) {
                // Find first enabled backend
                foreach ($backends as $backend) {
                    if ($backend->enabled) {
                        $settings->defaultBackendHandle = $backend->handle;
                        $settings->saveToDatabase();

                        $this->logInfo('Auto-assigned default backend', [
                            'handle' => $backend->handle,
                            'reason' => empty($defaultHandle) ? 'no default set' : 'previous default invalid',
                        ]);
                        break;
                    }
                }
            }
        }

        return $this->renderTemplate('search-manager/backends/index', [
            'backends' => $backends,
            'defaultBackendHandle' => $settings->defaultBackendHandle,
            'isDefaultFromConfig' => $this->isDefaultBackendFromConfig(),
        ]);
    }

    /**
     * View a backend (read-only, works for both config and database backends)
     *
     * @param string|int|null $backendId Backend ID (numeric) or handle (string)
     */
    public function actionView(string|int|null $backendId = null): Response
    {
        $this->requirePermission('searchManager:viewBackends');

        if (!$backendId) {
            throw new NotFoundHttpException('Backend ID or handle required');
        }

        // Try by ID first, then by handle
        if (is_numeric($backendId)) {
            $backend = ConfiguredBackend::findById((int)$backendId);
        } else {
            $backend = ConfiguredBackend::findByHandle((string)$backendId);
        }

        if (!$backend) {
            throw new NotFoundHttpException('Backend not found');
        }

        // Get database driver for mysql/pgsql availability checks
        $dbDriver = Craft::$app->getDb()->getDriverName();
        $settings = SearchManager::$plugin->getSettings();

        // Render edit.twig - it handles both editable and config backends via tabs
        return $this->renderTemplate('search-manager/backends/edit', [
            'backend' => $backend,
            'isNew' => false,
            'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
            'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
            'dbDriver' => $dbDriver,
            'defaultBackendHandle' => $settings->defaultBackendHandle,
            'isDefaultFromConfig' => $this->isDefaultBackendFromConfig(),
        ]);
    }

    /**
     * Edit or create a backend
     */
    public function actionEdit(?int $backendId = null): Response
    {
        if ($backendId) {
            $this->requirePermission('searchManager:viewBackends');
            $backend = ConfiguredBackend::findById($backendId);
            if (!$backend) {
                throw new NotFoundHttpException('Backend not found');
            }
        } else {
            $this->requirePermission('searchManager:createBackends');
            $backend = new ConfiguredBackend();
        }

        // Get database driver for mysql/pgsql availability checks
        $dbDriver = Craft::$app->getDb()->getDriverName();
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/backends/edit', [
            'backend' => $backend,
            'isNew' => !$backendId,
            'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
            'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
            'dbDriver' => $dbDriver,
            'defaultBackendHandle' => $settings->defaultBackendHandle,
            'isDefaultFromConfig' => $this->isDefaultBackendFromConfig(),
        ]);
    }

    /**
     * Save a backend
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $backendId = $request->getBodyParam('backendId');

        // Check appropriate permission based on create vs edit
        if ($backendId) {
            $this->requirePermission('searchManager:editBackends');
        } else {
            $this->requirePermission('searchManager:createBackends');
        }

        if ($backendId) {
            $backend = ConfiguredBackend::findById($backendId);
            if (!$backend) {
                throw new NotFoundHttpException('Backend not found');
            }
        } else {
            $backend = new ConfiguredBackend();
        }

        // Set attributes
        $backend->name = $request->getBodyParam('name');
        $backend->handle = $request->getBodyParam('handle');
        $backend->backendType = $request->getBodyParam('backendType');
        $backend->enabled = (bool)$request->getBodyParam('enabled');

        // Get settings based on backend type
        $settings = $request->getBodyParam('settings', []);
        $backend->settings = is_array($settings) ? $settings : [];

        if (!$backend->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save backend.')
            );

            $pluginSettings = SearchManager::$plugin->getSettings();

            // Re-render the edit template with all required variables
            return $this->renderTemplate('search-manager/backends/edit', [
                'backend' => $backend,
                'isNew' => !$backendId,
                'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
                'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
                'dbDriver' => Craft::$app->getDb()->getDriverName(),
                'defaultBackendHandle' => $pluginSettings->defaultBackendHandle,
                'isDefaultFromConfig' => $this->isDefaultBackendFromConfig(),
            ]);
        }

        // Handle "Set as Default" toggle (only if not set via config)
        $isDefault = (bool)$request->getBodyParam('isDefault');
        if ($isDefault && !$this->isDefaultBackendFromConfig()) {
            $pluginSettings = SearchManager::$plugin->getSettings();
            if ($pluginSettings->defaultBackendHandle !== $backend->handle) {
                $pluginSettings->defaultBackendHandle = $backend->handle;
                $pluginSettings->saveToDatabase();

                $this->logInfo('Default backend changed', [
                    'handle' => $backend->handle,
                    'name' => $backend->name,
                ]);
            }
        }

        // Auto-set as default if no default is set and this backend is enabled
        if (!$this->isDefaultBackendFromConfig()) {
            $pluginSettings = SearchManager::$plugin->getSettings();
            if (empty($pluginSettings->defaultBackendHandle) && $backend->enabled) {
                $pluginSettings->defaultBackendHandle = $backend->handle;
                $pluginSettings->saveToDatabase();

                $this->logInfo('Auto-set default backend (first enabled backend)', [
                    'handle' => $backend->handle,
                    'name' => $backend->name,
                ]);
            }
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('search-manager', 'Backend saved.')
        );

        return $this->redirectToPostedUrl($backend);
    }

    /**
     * Delete a backend
     */
    public function actionDelete(): Response
    {
        $this->requirePermission('searchManager:deleteBackends');
        $this->requirePostRequest();

        $backendId = Craft::$app->getRequest()->getRequiredBodyParam('backendId');
        $backend = ConfiguredBackend::findById((int)$backendId);

        if (!$backend) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Backend not found.')]);
            }
            throw new NotFoundHttpException('Backend not found');
        }

        // Check if this is the default backend
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->defaultBackendHandle === $backend->handle) {
            $error = Craft::t('search-manager', 'Cannot delete the default backend. Set another backend as default first.');
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/backends');
        }

        if ($backend->delete()) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => true]);
            }
            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Backend deleted.')
            );
        } else {
            $errors = $backend->getErrors();
            $errorMessage = !empty($errors['handle']) ? $errors['handle'][0] : 'Could not delete backend.';
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', $errorMessage)]);
            }
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', $errorMessage)
            );
        }

        return $this->redirect('search-manager/backends');
    }

    /**
     * Test backend connection
     */
    public function actionTest(): Response
    {
        $this->requirePermission('searchManager:viewBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $backendId = $request->getBodyParam('backendId');

        try {
            if ($backendId) {
                // Test existing backend - try by ID first, then by handle (for config backends)
                if (is_numeric($backendId)) {
                    $configuredBackend = ConfiguredBackend::findById((int)$backendId);
                } else {
                    $configuredBackend = ConfiguredBackend::findByHandle($backendId);
                }

                if (!$configuredBackend) {
                    return $this->asJson([
                        'success' => false,
                        'error' => 'Backend not found',
                    ]);
                }

                // Get the backend adapter and apply configured settings
                $backendAdapter = SearchManager::$plugin->backend->getBackend($configuredBackend->backendType);
                if (!$backendAdapter) {
                    return $this->asJson([
                        'success' => false,
                        'error' => "Unknown backend type: {$configuredBackend->backendType}",
                    ]);
                }

                // Apply configured settings
                $backendAdapter->setConfiguredSettings($configuredBackend->settings);

                // Test availability
                if ($backendAdapter->isAvailable()) {
                    return $this->asJson([
                        'success' => true,
                        'message' => 'Connection successful',
                    ]);
                }

                return $this->asJson([
                    'success' => false,
                    'error' => 'Backend is not available. Check your settings.',
                ]);
            }

            // Test with provided settings (for new backends)
            $backendType = $request->getBodyParam('backendType');
            $settings = $request->getBodyParam('settings', []);

            $backendAdapter = SearchManager::$plugin->backend->getBackend($backendType);
            if (!$backendAdapter) {
                return $this->asJson([
                    'success' => false,
                    'error' => "Unknown backend type: {$backendType}",
                ]);
            }

            if (!empty($settings)) {
                $backendAdapter->setConfiguredSettings($settings);
            }

            if ($backendAdapter->isAvailable()) {
                return $this->asJson([
                    'success' => true,
                    'message' => 'Connection successful',
                ]);
            }

            return $this->asJson([
                'success' => false,
                'error' => 'Backend is not available. Check your settings.',
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get backend info (capabilities and indices)
     */
    public function actionInfo(): Response
    {
        $this->requirePermission('searchManager:viewBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $backendId = $request->getBodyParam('backendId');

        try {
            // Find the configured backend
            if (is_numeric($backendId)) {
                $configuredBackend = ConfiguredBackend::findById((int)$backendId);
            } else {
                $configuredBackend = ConfiguredBackend::findByHandle($backendId);
            }

            if (!$configuredBackend) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Backend not found',
                ]);
            }

            // Get the backend adapter
            $backendAdapter = SearchManager::$plugin->backend->getBackend($configuredBackend->backendType);
            if (!$backendAdapter) {
                return $this->asJson([
                    'success' => false,
                    'error' => "Unknown backend type: {$configuredBackend->backendType}",
                ]);
            }

            // Apply configured settings
            $backendAdapter->setConfiguredSettings($configuredBackend->settings);
            $backendAdapter->setBackendHandle($configuredBackend->handle);

            // Get capabilities
            $supportsBrowse = $backendAdapter->supportsBrowse();
            $supportsMultipleQueries = $backendAdapter->supportsMultipleQueries();

            // Get indices from the backend
            $indices = [];
            try {
                $indices = $backendAdapter->listIndices();
            } catch (\Throwable $e) {
                $this->logWarning('Failed to list indices from backend', [
                    'backend' => $configuredBackend->handle,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->asJson([
                'success' => true,
                'supportsBrowse' => $supportsBrowse,
                'supportsMultipleQueries' => $supportsMultipleQueries,
                'indices' => $indices,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bulk enable backends
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePermission('searchManager:editBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $backendIds = Craft::$app->getRequest()->getBodyParam('backendIds', []);
        $count = 0;

        foreach ($backendIds as $id) {
            $backend = ConfiguredBackend::findById((int)$id);
            if ($backend) {
                $backend->enabled = true;
                if ($backend->save()) {
                    $count++;
                }
            }
        }

        return $this->asJson([
            'success' => true,
            'count' => $count,
        ]);
    }

    /**
     * Bulk disable backends
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePermission('searchManager:editBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $backendIds = Craft::$app->getRequest()->getBodyParam('backendIds', []);
        $settings = SearchManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($backendIds as $id) {
            $backend = ConfiguredBackend::findById((int)$id);
            if ($backend) {
                // Cannot disable default backend
                if ($settings->defaultBackendHandle === $backend->handle) {
                    $errors[] = Craft::t('search-manager', 'Cannot disable default backend "{name}".', ['name' => $backend->name]);
                    continue;
                }
                $backend->enabled = false;
                if ($backend->save()) {
                    $count++;
                } else {
                    $backendErrors = $backend->getErrors();
                    $errorMessage = !empty($backendErrors['enabled']) ? $backendErrors['enabled'][0] : 'Unknown error';
                    $errors[] = "{$backend->name}: {$errorMessage}";
                }
            }
        }

        if ($count > 0 && empty($errors)) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        if ($count > 0) {
            return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
        }

        return $this->asJson(['success' => false, 'errors' => $errors]);
    }

    /**
     * Bulk delete backends
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePermission('searchManager:deleteBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $backendIds = Craft::$app->getRequest()->getBodyParam('backendIds', []);
        $settings = SearchManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($backendIds as $id) {
            $backend = ConfiguredBackend::findById((int)$id);
            if ($backend) {
                // Cannot delete default backend
                if ($settings->defaultBackendHandle === $backend->handle) {
                    $errors[] = Craft::t('search-manager', 'Cannot delete default backend "{name}". Set another backend as default first.', ['name' => $backend->name]);
                    continue;
                }
                if ($backend->delete()) {
                    $count++;
                } else {
                    $backendErrors = $backend->getErrors();
                    $errorMessage = !empty($backendErrors['handle']) ? $backendErrors['handle'][0] : 'Unknown error';
                    $errors[] = "{$backend->name}: {$errorMessage}";
                }
            }
        }

        if ($count > 0 && empty($errors)) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        if ($count > 0) {
            return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
        }

        return $this->asJson(['success' => false, 'errors' => $errors]);
    }

    /**
     * Set a backend as the default
     */
    public function actionSetDefault(): Response
    {
        $this->requirePermission('searchManager:editBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Check if default is set via config - if so, don't allow changes
        if ($this->isDefaultBackendFromConfig()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Default backend is set via config file and cannot be changed here.'),
            ]);
        }

        $backendId = Craft::$app->getRequest()->getBodyParam('backendId');

        // Find the backend - try by ID first, then by handle (for config backends)
        if (is_numeric($backendId)) {
            $backend = ConfiguredBackend::findById((int)$backendId);
        } else {
            $backend = ConfiguredBackend::findByHandle((string)$backendId);
        }

        if (!$backend) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Backend not found.'),
            ]);
        }

        // Update the default backend handle in plugin settings
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $backend->handle;

        if (!$settings->saveToDatabase()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to save settings.'),
            ]);
        }

        $this->logInfo('Default backend changed', [
            'handle' => $backend->handle,
            'name' => $backend->name,
        ]);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('search-manager', 'Default backend updated.'),
        ]);
    }

    /**
     * Check if defaultBackendHandle is set via config file
     */
    private function isDefaultBackendFromConfig(): bool
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (!file_exists($configPath)) {
            return false;
        }

        $config = require $configPath;

        // Check in root level
        if (isset($config['defaultBackendHandle'])) {
            return true;
        }

        // Check in '*' (all environments)
        if (isset($config['*']['defaultBackendHandle'])) {
            return true;
        }

        // Check in current environment
        $env = Craft::$app->getConfig()->env;
        if ($env && isset($config[$env]['defaultBackendHandle'])) {
            return true;
        }

        return false;
    }
}
