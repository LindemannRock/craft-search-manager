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

        return $this->renderTemplate('search-manager/backends/index', [
            'backends' => $backends,
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

        // Render edit.twig - it handles both editable and config backends via tabs
        return $this->renderTemplate('search-manager/backends/edit', [
            'backend' => $backend,
            'isNew' => false,
            'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
            'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
            'dbDriver' => $dbDriver,
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

        return $this->renderTemplate('search-manager/backends/edit', [
            'backend' => $backend,
            'isNew' => !$backendId,
            'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
            'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
            'dbDriver' => $dbDriver,
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

            // Re-render the edit template with all required variables
            return $this->renderTemplate('search-manager/backends/edit', [
                'backend' => $backend,
                'isNew' => !$backendId,
                'backendTypes' => ConfiguredBackend::BACKEND_TYPES,
                'settingsSchemas' => ConfiguredBackend::BACKEND_SETTINGS_SCHEMA,
                'dbDriver' => Craft::$app->getDb()->getDriverName(),
            ]);
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
            throw new NotFoundHttpException('Backend not found');
        }

        if ($backend->delete()) {
            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Backend deleted.')
            );
        } else {
            $errors = $backend->getErrors();
            $errorMessage = !empty($errors['handle']) ? $errors['handle'][0] : 'Could not delete backend.';
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
        $count = 0;
        $errors = [];

        foreach ($backendIds as $id) {
            $backend = ConfiguredBackend::findById((int)$id);
            if ($backend) {
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

        return $this->asJson([
            'success' => count($errors) === 0,
            'count' => $count,
            'errors' => $errors,
        ]);
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
        $count = 0;
        $errors = [];

        foreach ($backendIds as $id) {
            $backend = ConfiguredBackend::findById((int)$id);
            if ($backend) {
                if ($backend->delete()) {
                    $count++;
                } else {
                    $backendErrors = $backend->getErrors();
                    $errorMessage = !empty($backendErrors['handle']) ? $backendErrors['handle'][0] : 'Unknown error';
                    $errors[] = "{$backend->name}: {$errorMessage}";
                }
            }
        }

        return $this->asJson([
            'success' => count($errors) === 0,
            'count' => $count,
            'errors' => $errors,
        ]);
    }
}
