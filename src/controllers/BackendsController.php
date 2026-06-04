<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backends Controller
 *
 * Manages configured backend instances
 *
 * @since 5.28.0
 */
class BackendsController extends Controller
{
    use LoggingTrait;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * List all configured backends.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageBackends');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $backends = ConfiguredBackend::findAll();
        // Whether the install has any backends at all — referenced by the
        // template's "no default backend configured" warning, which must
        // survive a narrowed filter. Cached now before filter shrinks $backends.
        $hasAnyBackends = !empty($backends);
        $configHandles = ConfigFileHelper::getHandles('backends');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_backends}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // Auto-assign default if needed (only if not set via config file).
        // Runs against the full backend list, not the filtered subset, so a
        // narrowed status/type filter never accidentally promotes a default.
        if (!$this->isDefaultBackendFromConfig()) {
            $defaultHandle = $settings->defaultBackendHandle;
            $needsReassign = false;

            if (empty($defaultHandle)) {
                $needsReassign = true;
            } else {
                $defaultBackend = ConfiguredBackend::findByHandle($defaultHandle);
                if (!$defaultBackend || !$defaultBackend->enabled) {
                    $needsReassign = true;
                }
            }

            if ($needsReassign && !empty($backends)) {
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

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $typeFilter = (string) $request->getQueryParam('type', 'all');
        $validTypes = ['all', 'algolia', 'meilisearch', 'typesense', 'mysql', 'pgsql', 'redis', 'file'];
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = ['all', 'config', 'database'];
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'type', 'source', 'enabled'];
        $sort = (string) $request->getParam('sort', 'source');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'source';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $backends = array_values(array_filter($backends, fn(ConfiguredBackend $b): bool => $b->enabled));
        } elseif ($statusFilter === 'disabled') {
            $backends = array_values(array_filter($backends, fn(ConfiguredBackend $b): bool => !$b->enabled));
        }

        if ($typeFilter !== 'all') {
            $backends = array_values(array_filter($backends, fn(ConfiguredBackend $b): bool => $b->backendType === $typeFilter));
        }

        if ($sourceFilter === 'config') {
            $backends = array_values(array_filter($backends, fn(ConfiguredBackend $b): bool => $b->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $backends = array_values(array_filter($backends, fn(ConfiguredBackend $b): bool => $b->source !== 'config'));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $backends = array_values(array_filter($backends, function(ConfiguredBackend $b) use ($needle): bool {
                return str_contains(mb_strtolower($b->name), $needle)
                    || str_contains(mb_strtolower($b->handle), $needle)
                    || str_contains(mb_strtolower($b->backendType), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $backends = $this->sortBackends($backends, $sort, $dir);

        // Total count reflects the filtered subset so the pager matches the
        // visible list — not the unfiltered ConfiguredBackend::findAll() size.
        $totalCount = count($backends);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $backends = array_slice($backends, $offset, $limit);

        // Resolve the default backend once (against the full set, not the
        // filtered/paginated $backends) so beforeTable warnings render
        // consistently regardless of the current filter state.
        $defaultBackendHandle = $settings->defaultBackendHandle;
        $defaultBackend = !empty($defaultBackendHandle)
            ? ConfiguredBackend::findByHandle($defaultBackendHandle)
            : null;

        return $this->renderTemplate('search-manager/backends/index', [
            'backends' => $backends,
            'hasAnyBackends' => $hasAnyBackends,
            'defaultBackendHandle' => $defaultBackendHandle,
            'defaultBackend' => $defaultBackend,
            'isDefaultFromConfig' => $this->isDefaultBackendFromConfig(),
            'collisionHandles' => $collisionHandles,
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'sourceFilter' => $sourceFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createBackends'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editBackends'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deleteBackends'),
        ]);
    }

    /**
     * Sort the loaded backends array in PHP. Small dataset → array-side sort
     * is fine. The sort key allowlist is enforced in actionIndex() before we
     * land here, so the default branch is reached only on a logic bug.
     *
     * @param ConfiguredBackend[] $backends
     * @return ConfiguredBackend[]
     */
    private function sortBackends(array $backends, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($backends, function(ConfiguredBackend $a, ConfiguredBackend $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                // Column key 'type' maps to the model's `backendType` property —
                // the column key is the URL-visible label, not the field name.
                'type' => strcasecmp((string) $a->backendType, (string) $b->backendType),
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            // Stable tie-break by name so equal primary keys don't shuffle
            // between requests — keeps pagination predictable.
            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $backends;
    }

    /**
     * View a backend (read-only, works for both config and database backends)
     *
     * @param string|int|null $backendId Backend ID (numeric) or handle (string)
     */
    public function actionView(string|int|null $backendId = null): Response
    {
        $this->requirePermission('searchManager:manageBackends');

        if (!$backendId) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Backend ID or handle required'));
        }

        $backend = ConfiguredBackend::findByIdOrHandle($backendId);

        if (!$backend) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Backend not found'));
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
            $this->requirePermission('searchManager:editBackends');
            $backend = ConfiguredBackend::findById($backendId);
            if (!$backend) {
                throw new NotFoundHttpException(Craft::t('search-manager', 'Backend not found'));
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
                throw new NotFoundHttpException(Craft::t('search-manager', 'Backend not found'));
            }
        } else {
            $backend = new ConfiguredBackend();
        }

        // Set attributes
        $backend->name = $request->getBodyParam('name');
        $backend->handle = SlugHandleHelper::normalizeSlug(
            (string)$request->getBodyParam('handle'),
            (string)$backend->name,
        );
        if (!$backendId && $backend->handle !== '') {
            $backend->handle = SlugHandleHelper::makeUnique('{{%searchmanager_backends}}', 'handle', $backend->handle);
        }
        $backend->backendType = $request->getBodyParam('backendType');
        $backend->enabled = (bool)$request->getBodyParam('enabled');

        // Get settings based on backend type
        $settings = $request->getBodyParam('settings', []);
        $backend->settings = is_array($settings) ? $settings : [];

        if (!$backend->save()) {
            Craft::$app->getSession()->setError(
                Craft::t('search-manager', 'Could not save backend')
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
            Craft::t('search-manager', 'Backend saved')
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
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Backend not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Backend not found'));
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
                Craft::t('search-manager', 'Backend deleted')
            );
        } else {
            $errors = $backend->getErrors();
            $errorMessage = !empty($errors['handle']) ? $errors['handle'][0] : 'Could not delete backend';
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
        $this->requirePermission('searchManager:manageBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $backendId = $request->getBodyParam('backendId');

        try {
            if ($backendId) {
                $configuredBackend = ConfiguredBackend::findByIdOrHandle($backendId);

                if (!$configuredBackend) {
                    return $this->asJson([
                        'success' => false,
                        'error' => Craft::t('search-manager', 'Backend not found'),
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
            $this->logError('Backend connection test failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Connection test failed. Check logs for details.'),
            ]);
        }
    }

    /**
     * Get backend info (capabilities and indices)
     */
    public function actionInfo(): Response
    {
        $this->requirePermission('searchManager:manageBackends');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $backendId = $request->getBodyParam('backendId');

        try {
            $configuredBackend = ConfiguredBackend::findByIdOrHandle($backendId);

            if (!$configuredBackend) {
                return $this->asJson([
                    'success' => false,
                    'error' => Craft::t('search-manager', 'Backend not found'),
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
            $this->logError('Failed to get backend info', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $e->getMessage()
                    : Craft::t('search-manager', 'Failed to load backend info. Check logs for details.'),
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
                    $errors[] = Craft::t('search-manager', 'Cannot disable default backend "{name}"', ['name' => $backend->name]);
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

        $backend = ConfiguredBackend::findByIdOrHandle($backendId);

        if (!$backend) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Backend not found'),
            ]);
        }

        // Update the default backend handle in plugin settings
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $backend->handle;

        if (!$settings->saveToDatabase()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to save settings'),
            ]);
        }

        $this->logInfo('Default backend changed', [
            'handle' => $backend->handle,
            'name' => $backend->name,
        ]);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('search-manager', 'Default backend updated'),
        ]);
    }

    /**
     * Check if defaultBackendHandle is set via config file
     */
    private function isDefaultBackendFromConfig(): bool
    {
        return SearchManager::$plugin->getSettings()->isOverriddenByConfig('defaultBackendHandle');
    }
}
