<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use lindemannrock\base\helpers\BooleanHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\BulkMutationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\search\Highlighter;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Widgets Controller
 *
 * Manages search widget configurations in the CP
 *
 * @since 5.30.0
 */
class WidgetsController extends Controller
{
    use LoggingTrait;

    private const PLUGIN_HANDLE = 'search-manager';

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    private function requireProWidgetStyles(): ?Response
    {
        return SearchManager::$plugin->requireEditionOrPrompt(SearchManager::EDITION_PRO, 'Widget Styles');
    }

    /**
     * List all widget configurations.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageWidgetConfigs');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $widgetConfigs = array_values(SearchManager::$plugin->widgetConfigs->getAll());
        $configHandles = BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, 'widgets');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_widget_configs}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // hasAnyWidgets + hasConfigItems are cached before filter narrows the
        // collection so beforeTable warnings and the cp-table checkbox-disable
        // behaviour stay consistent under filtering.
        $hasAnyWidgets = !empty($widgetConfigs);
        $hasConfigItems = false;
        foreach ($widgetConfigs as $widget) {
            if ($widget->isFromConfig()) {
                $hasConfigItems = true;
                break;
            }
        }

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = ['all', 'config', 'database'];
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        $typeFilter = (string) $request->getQueryParam('type', 'all');
        $validTypes = array_merge(['all'], array_map('strval', WidgetStyle::WIDGET_TYPES));
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'type', 'source', 'enabled', 'isDefault'];
        $sort = (string) $request->getParam('sort', 'source');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'source';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $widgetConfigs = array_values(array_filter($widgetConfigs, fn(WidgetConfig $w): bool => $w->enabled));
        } elseif ($statusFilter === 'disabled') {
            $widgetConfigs = array_values(array_filter($widgetConfigs, fn(WidgetConfig $w): bool => !$w->enabled));
        }

        if ($sourceFilter === 'config') {
            $widgetConfigs = array_values(array_filter($widgetConfigs, fn(WidgetConfig $w): bool => $w->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $widgetConfigs = array_values(array_filter($widgetConfigs, fn(WidgetConfig $w): bool => $w->source !== 'config'));
        }

        if ($typeFilter !== 'all') {
            $widgetConfigs = array_values(array_filter($widgetConfigs, fn(WidgetConfig $w): bool => $w->type === $typeFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $widgetConfigs = array_values(array_filter($widgetConfigs, function(WidgetConfig $w) use ($needle): bool {
                return str_contains(mb_strtolower((string) $w->name), $needle)
                    || str_contains(mb_strtolower((string) $w->handle), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $defaultWidgetHandle = $settings->defaultWidgetHandle;
        $widgetConfigs = $this->sortWidgetConfigs($widgetConfigs, $sort, $dir, (string) $defaultWidgetHandle);

        $totalCount = count($widgetConfigs);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $widgetConfigs = array_slice($widgetConfigs, $offset, $limit);

        // Resolve default widget against the unfiltered set so the beforeTable
        // warning logic stays correct under any filter narrowing.
        $defaultWidget = !empty($defaultWidgetHandle)
            ? SearchManager::$plugin->widgetConfigs->getByHandle((string) $defaultWidgetHandle)
            : null;

        return $this->renderTemplate('search-manager/widgets/index', [
            'widgetConfigs' => $widgetConfigs,
            'defaultWidgetHandle' => $defaultWidgetHandle,
            'defaultWidget' => $defaultWidget,
            'hasAnyWidgets' => $hasAnyWidgets,
            'hasConfigItems' => $hasConfigItems,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            'collisionHandles' => $collisionHandles,
            'statusFilter' => $statusFilter,
            'sourceFilter' => $sourceFilter,
            'typeFilter' => $typeFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createWidgetConfigs'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editWidgetConfigs'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deleteWidgetConfigs'),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
        ]);
    }

    /**
     * @param WidgetConfig[] $configs
     * @return WidgetConfig[]
     */
    private function sortWidgetConfigs(array $configs, string $sort, string $dir, string $defaultHandle): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($configs, function(WidgetConfig $a, WidgetConfig $b) use ($sort, $multiplier, $defaultHandle): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'type' => strcasecmp((string) $a->type, (string) $b->type),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                'isDefault' => ((int) ($a->handle === $defaultHandle)) <=> ((int) ($b->handle === $defaultHandle)),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $configs;
    }

    /**
     * View a widget configuration (read-only, for config and database widgets)
     *
     * @param string|null $handle Widget handle
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:manageWidgetConfigs');

        if (!$handle) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Widget handle required'));
        }

        $widgetConfig = SearchManager::$plugin->widgetConfigs->getByHandle($handle);

        if (!$widgetConfig) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Widget config not found'));
        }

        // Get indices for display
        $indices = SearchIndex::findAll();
        $settings = SearchManager::$plugin->getSettings();

        return $this->renderTemplate('search-manager/widgets/view', [
            'widgetConfig' => $widgetConfig,
            'indices' => $indices,
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
        ]);
    }

    /**
     * Edit or create a widget configuration
     */
    public function actionEdit(?int $configId = null, ?WidgetConfig $widgetConfig = null): Response
    {
        if (!$widgetConfig) {
            if ($configId) {
                $this->requirePermission('searchManager:editWidgetConfigs');
                $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($configId);
                if (!$widgetConfig) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'Widget config not found'));
                }
            } else {
                $this->requirePermission('searchManager:createWidgetConfigs');
                $widgetConfig = new WidgetConfig();
                $widgetConfig->settings = WidgetConfig::defaultSettings();
            }
        }

        $settings = SearchManager::$plugin->getSettings();
        $widgetStyles = SearchManager::$plugin->isPro()
            ? SearchManager::$plugin->widgetStyles->getAll('modal')
            : [];
        $widgetApiKeys = SearchManager::$plugin->apiKeys->widgetUsablePublicKeys();
        $selectedApiKey = $this->getSelectedWidgetApiKey($widgetConfig, $widgetApiKeys);
        [$indexOptions, $allIndexOptions] = $this->getWidgetIndexOptions($widgetConfig, $selectedApiKey);

        return $this->renderTemplate('search-manager/widgets/edit', [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indexOptions' => $indexOptions,
            'allIndexOptions' => $allIndexOptions,
            'widgetStyles' => $widgetStyles,
            'widgetApiKeyOptions' => $this->getWidgetApiKeyOptions($widgetApiKeys),
            'widgetApiKeyScopes' => $this->getWidgetApiKeyScopes($widgetApiKeys),
            'selectedApiKey' => $selectedApiKey,
            'hasWidgetUsableApiKeys' => !empty($widgetApiKeys),
            'snippetOptions' => SnippetOptionsHelper::widgetDefaults(),
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
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
                throw new NotFoundHttpException(Craft::t('search-manager', 'Widget config not found'));
            }
            $storedHandle = $widgetConfig->handle;
        } else {
            $this->requirePermission('searchManager:createWidgetConfigs');
            $widgetConfig = new WidgetConfig();
            $storedHandle = null;
        }

        $storedSettings = $widgetConfig->getSettingsArray();

        // Set basic attributes
        $widgetConfig->name = $request->getBodyParam('name');
        $widgetConfig->handle = SlugHandleHelper::normalizeSlug(
            (string)$request->getBodyParam('handle'),
            (string)$widgetConfig->name,
        );
        if (!$configId && $widgetConfig->handle !== '') {
            $widgetConfig->handle = SlugHandleHelper::makeUnique('{{%searchmanager_widget_configs}}', 'handle', $widgetConfig->handle);
        }
        $widgetConfig->type = (string) $request->getBodyParam('type', 'modal');
        $widgetConfig->enabled = $this->normalizeSubmittedLightswitch($request->getBodyParam('enabled'));

        // Get settings from form
        $widgetSettings = $request->getBodyParam('settings', []);
        if (is_array($widgetSettings)
            && is_array($widgetSettings['search'] ?? null)
            && array_key_exists('indexHandles', $widgetSettings['search'])
        ) {
            $widgetSettings['search']['indexHandles'] = SearchManager::$plugin->dependencies
                ->normalizeSubmittedIndexHandleList($widgetSettings['search']['indexHandles']);
        }

        $defaults = WidgetConfig::defaultSettings();
        if (!is_array($widgetSettings)) {
            $widgetSettings = [];
        } else {
            $widgetSettings = $this->normalizeSubmittedBooleanSettings($widgetSettings, $defaults);
        }

        // Merge with defaults to ensure all keys exist
        $mergedSettings = array_replace_recursive($defaults, $widgetSettings);

        // Strip unknown keys — only allow keys defined in defaults
        $mergedSettings = $this->_filterSettingsKeys($mergedSettings, $defaults);

        // Standard does not submit Pro-only editor fields. Preserve their
        // stored values across unrelated edits so a downgrade never destroys
        // configuration that should become active again after re-upgrading.
        if (!SearchManager::$plugin->isPro()) {
            foreach (['promotionDisplay', 'promotionBadgeText', 'promotionBadgePosition'] as $key) {
                $mergedSettings['behavior'][$key] = $storedSettings['behavior'][$key]
                    ?? $defaults['behavior'][$key];
            }

            $storedAnalytics = $storedSettings['analytics'] ?? [];
            $mergedSettings['analytics'] = is_array($storedAnalytics)
                ? $this->_filterSettingsKeys(array_replace($defaults['analytics'], $storedAnalytics), $defaults['analytics'])
                : $defaults['analytics'];
        }

        $mergedSettings['apiKeyHandle'] = isset($mergedSettings['apiKeyHandle']) && is_string($mergedSettings['apiKeyHandle'])
            ? trim($mergedSettings['apiKeyHandle'])
            : '';

        $widgetConfig->settings = $mergedSettings;

        $pluginSettings = SearchManager::$plugin->getSettings();
        $widgetStyles = SearchManager::$plugin->isPro()
            ? SearchManager::$plugin->widgetStyles->getAll('modal')
            : [];
        $widgetApiKeys = SearchManager::$plugin->apiKeys->widgetUsablePublicKeys();
        $selectedApiKey = $this->getSelectedWidgetApiKey($widgetConfig, $widgetApiKeys);
        [$indexOptions, $allIndexOptions] = $this->getWidgetIndexOptions($widgetConfig, $selectedApiKey);

        // Common route params for error returns (template needs all of these)
        $errorRouteParams = [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indexOptions' => $indexOptions,
            'allIndexOptions' => $allIndexOptions,
            'widgetStyles' => $widgetStyles,
            'widgetApiKeyOptions' => $this->getWidgetApiKeyOptions($widgetApiKeys),
            'widgetApiKeyScopes' => $this->getWidgetApiKeyScopes($widgetApiKeys),
            'selectedApiKey' => $selectedApiKey,
            'hasWidgetUsableApiKeys' => !empty($widgetApiKeys),
            'snippetOptions' => SnippetOptionsHelper::widgetDefaults(),
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
            'defaultWidgetHandle' => $pluginSettings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
        ];

        // Set style handle from form
        if (SearchManager::$plugin->isPro()) {
            $styleHandle = $request->getBodyParam('styleHandle');
            if ($styleHandle) {
                $existingStyle = SearchManager::$plugin->widgetStyles->getByHandle($styleHandle);
                if ($existingStyle === null) {
                    $widgetConfig->addError('styleHandle', Craft::t('search-manager', 'Selected style preset not found'));
                    Craft::$app->getSession()->setError(Craft::t('search-manager', 'Selected style preset not found'));
                    Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
                    return null;
                }
                $widgetConfig->styleHandle = $styleHandle;
            } else {
                $widgetConfig->styleHandle = null;
            }
        }

        // Validate
        if (!$widgetConfig->validate()) {
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config'));
            return null;
        }

        // Save widget config. Renaming the active default is one explicit,
        // atomic owner transition; ordinary direct service saves never rewrite
        // default settings.
        $isDefault = $this->normalizeSubmittedLightswitch($request->getBodyParam('isDefault'));
        $renamesActiveDefault = $storedHandle !== null
            && $storedHandle !== $widgetConfig->handle
            && $isDefault
            && !$this->isDefaultWidgetFromConfig()
            && SearchManager::$plugin->getSettings()->defaultWidgetHandle === $storedHandle;
        $saved = $renamesActiveDefault
            ? SearchManager::$plugin->widgetConfigs->saveWithDefaultHandleTransition($widgetConfig)
            : SearchManager::$plugin->widgetConfigs->save($widgetConfig);
        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config'));
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            return null;
        }

        // Handle "Set as Default" toggle (only if not set via config)
        if ($renamesActiveDefault) {
            $this->logInfo('Default widget changed', [
                'handle' => $widgetConfig->handle,
                'name' => $widgetConfig->name,
            ]);
        } elseif ($isDefault && !$this->isDefaultWidgetFromConfig()) {
            if (!$this->assignDefaultWidget($widgetConfig, 'Default widget changed')) {
                return $this->widgetSaveResponse(
                    $widgetConfig,
                    Craft::t('search-manager', 'Failed to update default widget'),
                );
            }
        }

        if (!$this->ensureDefaultWidget()) {
            return $this->widgetSaveResponse(
                $widgetConfig,
                Craft::t('search-manager', 'Failed to update default widget'),
            );
        }

        return $this->widgetSaveResponse($widgetConfig);
    }

    /**
     * Delete a widget configuration
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:deleteWidgetConfigs');

        $acceptsJson = Craft::$app->getRequest()->getAcceptsJson();
        $configId = Craft::$app->getRequest()->getRequiredBodyParam('configId');

        $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($configId);
        if (!$widgetConfig) {
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Widget config not found')]);
            }
            throw new NotFoundHttpException(Craft::t('search-manager', 'Widget config not found'));
        }

        // Prevent deleting the default widget
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
            $error = Craft::t('search-manager', 'Cannot delete the default widget. Set another widget as default first.');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/widgets');
        }

        if (!SearchManager::$plugin->widgetConfigs->delete($widgetConfig)) {
            $error = Craft::t('search-manager', 'Could not delete widget config');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/widgets');
        }

        if ($acceptsJson) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Widget config deleted'));
        return $this->redirect('search-manager/widgets');
    }

    /**
     * Duplicate a database-backed widget configuration.
     *
     * @since 5.53.0
     */
    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:createWidgetConfigs');

        $request = Craft::$app->getRequest();
        $configId = $request->getRequiredBodyParam('configId');
        $source = SearchManager::$plugin->widgetConfigs->getById((int)$configId);

        if (!$source) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Widget config not found'), 'search-manager/widgets');
        }

        if (!$source->canEdit()) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Config-backed widget configs cannot be duplicated.'), 'search-manager/widgets');
        }

        $widgetConfig = new WidgetConfig();
        $widgetConfig->name = $this->uniqueCopyLabel('{{%searchmanager_widget_configs}}', 'name', $source->name);
        $widgetConfig->handle = SlugHandleHelper::makeUnique(
            '{{%searchmanager_widget_configs}}',
            'handle',
            SlugHandleHelper::normalizeSlug($source->handle . '-copy', $widgetConfig->name),
        );
        $widgetConfig->type = $source->type;
        $widgetConfig->enabled = false;
        $widgetConfig->styleHandle = $source->styleHandle;
        $widgetConfig->settings = $this->sanitizeCopiedWidgetSettings($source->getSettingsArray());

        if (!$widgetConfig->validate() || !SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Could not duplicate widget config'), 'search-manager/widgets');
        }

        $message = Craft::t('search-manager', 'Widget config duplicated');

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('search-manager/widgets');
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
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Widget config not found')]);
        }

        if (!$this->assignDefaultWidget($widgetConfig, 'Default widget changed')) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('search-manager', 'Failed to update default widget'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('search-manager', 'Default widget updated'),
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

        return $this->bulkSetWidgetConfigsEnabled(true);
    }

    /**
     * Bulk disable widget configurations
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetConfigs');

        return $this->bulkSetWidgetConfigsEnabled(false);
    }

    /**
     * Bulk delete widget configurations
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:deleteWidgetConfigs');

        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('configIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        $settings = SearchManager::$plugin->getSettings();
        $widgetConfigs = [];

        foreach ($result->identifiers() as $id) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($id);
            if ($widgetConfig === null) {
                $result->addSkip();
                continue;
            }
            if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
                $result->addError(
                    Craft::t('search-manager', 'Cannot delete the default widget. Set another widget as default first.'),
                );
                continue;
            }
            $widgetConfigs[] = $widgetConfig;
        }

        if ($result->errors() !== [] || $widgetConfigs === []) {
            return $this->asJson($result->toArray());
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($widgetConfigs as $widgetConfig) {
                if ($widgetConfig->id === null || !SearchManager::$plugin->widgetConfigs->deleteById($widgetConfig->id)) {
                    $result->addNamedError(
                        $widgetConfig->name,
                        Craft::t('search-manager', 'Could not delete widget config'),
                    );
                    $transaction->rollBack();
                    return $this->asJson($result->toArray());
                }
            }
            $transaction->commit();
            $result->addSuccess(count($widgetConfigs));
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $result->addNamedError($widgetConfig->name, Craft::t('search-manager', 'Could not delete widget config'));
            $this->logError('Bulk widget config deletion failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->asJson($result->toArray());
    }

    private function bulkSetWidgetConfigsEnabled(bool $enabled): Response
    {
        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('configIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        $settings = SearchManager::$plugin->getSettings();
        foreach ($result->identifiers() as $id) {
            $widgetConfig = SearchManager::$plugin->widgetConfigs->getById($id);
            if ($widgetConfig === null) {
                $result->addSkip();
                continue;
            }
            if ($widgetConfig->enabled === $enabled) {
                $result->addSkip();
                continue;
            }
            if (!$enabled && $settings->defaultWidgetHandle === $widgetConfig->handle) {
                $result->addError(Craft::t('search-manager', 'Cannot disable "{name}" because it is the default widget.', [
                    'name' => $widgetConfig->name,
                ]));
                continue;
            }

            try {
                $widgetConfig->enabled = $enabled;
                if (SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
                    $result->addSuccess();
                } else {
                    $result->addModelErrors(
                        $widgetConfig->name,
                        $widgetConfig,
                        Craft::t('search-manager', 'Could not save widget config'),
                    );
                }
            } catch (\Throwable $e) {
                $result->addNamedError(
                    $widgetConfig->name,
                    Craft::t('search-manager', 'Could not save widget config'),
                );
                $this->logError('Bulk widget config status update failed', [
                    'widgetConfigId' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->asJson($result->toArray());
    }

    // =========================================================================
    // Widget Styles
    // =========================================================================

    /**
     * List all widget styles.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     *
     * @since 5.39.0
     */
    public function actionStylesIndex(): Response
    {
        $upgradeResponse = $this->requireProWidgetStyles();
        if ($upgradeResponse !== null) {
            return $upgradeResponse;
        }

        $this->requirePermission('searchManager:manageWidgetStyles');

        $request = Craft::$app->getRequest();
        $settings = SearchManager::$plugin->getSettings();

        $widgetStyles = array_values(SearchManager::$plugin->widgetStyles->getAll());
        $styleUsageCounts = SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle();

        $configHandles = BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, 'widgetStyles');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_widget_styles}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // `hasConfigItems` controls the cp-table layout's checkbox-disabling for
        // config-source rows. Cached now from the unfiltered set so it's correct
        // even when the source filter narrows the visible items to database-only.
        $hasConfigItems = false;
        foreach ($widgetStyles as $style) {
            if ($style->isFromConfig()) {
                $hasConfigItems = true;
                break;
            }
        }

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = ['all', 'config', 'database'];
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        $typeFilter = (string) $request->getQueryParam('type', 'all');
        // Widget type allowlist is sourced from the model's WIDGET_TYPES array
        // plus the explicit 'all' sentinel — keeps the controller honest if a
        // new widget type is added to the model.
        $validTypes = array_merge(['all'], array_map('strval', WidgetStyle::WIDGET_TYPES));
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'type', 'source', 'enabled'];
        $sort = (string) $request->getParam('sort', 'name');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $widgetStyles = array_values(array_filter($widgetStyles, fn(WidgetStyle $s): bool => $s->enabled));
        } elseif ($statusFilter === 'disabled') {
            $widgetStyles = array_values(array_filter($widgetStyles, fn(WidgetStyle $s): bool => !$s->enabled));
        }

        if ($sourceFilter === 'config') {
            $widgetStyles = array_values(array_filter($widgetStyles, fn(WidgetStyle $s): bool => $s->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $widgetStyles = array_values(array_filter($widgetStyles, fn(WidgetStyle $s): bool => $s->source !== 'config'));
        }

        if ($typeFilter !== 'all') {
            $widgetStyles = array_values(array_filter($widgetStyles, fn(WidgetStyle $s): bool => $s->type === $typeFilter));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $widgetStyles = array_values(array_filter($widgetStyles, function(WidgetStyle $s) use ($needle): bool {
                return str_contains(mb_strtolower((string) $s->name), $needle)
                    || str_contains(mb_strtolower((string) $s->handle), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $widgetStyles = $this->sortWidgetStyles($widgetStyles, $sort, $dir);

        $totalCount = count($widgetStyles);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $widgetStyles = array_slice($widgetStyles, $offset, $limit);

        return $this->renderTemplate('search-manager/widgets/styles/index', [
            'widgetStyles' => $widgetStyles,
            'styleUsageCounts' => $styleUsageCounts,
            'collisionHandles' => $collisionHandles,
            'hasConfigItems' => $hasConfigItems,
            'statusFilter' => $statusFilter,
            'sourceFilter' => $sourceFilter,
            'typeFilter' => $typeFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createWidgetStyles'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editWidgetStyles'),
            'canDelete' => Craft::$app->getUser()->checkPermission('searchManager:deleteWidgetStyles'),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
        ]);
    }

    /**
     * @param WidgetStyle[] $styles
     * @return WidgetStyle[]
     */
    private function sortWidgetStyles(array $styles, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($styles, function(WidgetStyle $a, WidgetStyle $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'type' => strcasecmp((string) $a->type, (string) $b->type),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $styles;
    }

    /**
     * View a widget style (read-only, for config styles)
     *
     * @since 5.39.0
     */
    public function actionViewStyle(?string $handle = null): Response
    {
        $upgradeResponse = $this->requireProWidgetStyles();
        if ($upgradeResponse !== null) {
            return $upgradeResponse;
        }

        $this->requirePermission('searchManager:manageWidgetStyles');

        if (!$handle) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Widget style handle required'));
        }

        $widgetStyle = SearchManager::$plugin->widgetStyles->getByHandle($handle);

        if (!$widgetStyle) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'Widget style not found'));
        }

        $defaultStyles = WidgetConfig::defaultStyleValues();
        $styleUsageCounts = SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle();

        return $this->renderTemplate('search-manager/widgets/styles/view', [
            'widgetStyle' => $widgetStyle,
            'defaultStyles' => $defaultStyles,
            'styles' => array_merge($defaultStyles, $widgetStyle->getStyles()),
            'usageCount' => (int)($styleUsageCounts[$widgetStyle->handle] ?? 0),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
        ]);
    }

    /**
     * Edit or create a widget style
     *
     * @since 5.39.0
     */
    public function actionEditStyle(?int $styleId = null, ?WidgetStyle $widgetStyle = null): Response
    {
        $upgradeResponse = $this->requireProWidgetStyles();
        if ($upgradeResponse !== null) {
            return $upgradeResponse;
        }

        if (!$widgetStyle) {
            if ($styleId) {
                $this->requirePermission('searchManager:editWidgetStyles');
                $widgetStyle = SearchManager::$plugin->widgetStyles->getById($styleId);
                if (!$widgetStyle) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'Widget style not found'));
                }
            } else {
                $this->requirePermission('searchManager:createWidgetStyles');
                $widgetStyle = new WidgetStyle();
            }
        }

        $defaultStyles = WidgetConfig::defaultStyleValues();
        $styleUsageCounts = $styleId
            ? SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle()
            : [];

        return $this->renderTemplate('search-manager/widgets/styles/edit', [
            'widgetStyle' => $widgetStyle,
            'isNew' => !$styleId,
            'defaultStyles' => $defaultStyles,
            'usageCount' => $styleId ? (int)($styleUsageCounts[$widgetStyle->handle] ?? 0) : null,
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
            'widgetWorkspace' => SearchManager::$plugin->getWidgetWorkspaceNavigation(),
        ]);
    }

    /**
     * Save a widget style
     *
     * @since 5.39.0
     */
    public function actionSaveStyle(): ?Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $styleId = $request->getBodyParam('styleId');

        if ($styleId) {
            $this->requirePermission('searchManager:editWidgetStyles');
            $widgetStyle = SearchManager::$plugin->widgetStyles->getById((int) $styleId);
            if (!$widgetStyle) {
                throw new NotFoundHttpException(Craft::t('search-manager', 'Widget style not found'));
            }
        } else {
            $this->requirePermission('searchManager:createWidgetStyles');
            $widgetStyle = new WidgetStyle();
        }

        $widgetStyle->name = (string) $request->getBodyParam('name');
        $widgetStyle->handle = SlugHandleHelper::normalizeSlug(
            (string)$request->getBodyParam('handle'),
            $widgetStyle->name,
        );
        if (!$styleId && $widgetStyle->handle !== '') {
            $widgetStyle->handle = SlugHandleHelper::makeUnique('{{%searchmanager_widget_styles}}', 'handle', $widgetStyle->handle);
        }
        $widgetStyle->enabled = $this->normalizeSubmittedLightswitch($request->getBodyParam('enabled'));
        $widgetStyle->type = (string) $request->getBodyParam('type', 'modal');
        $styles = $request->getBodyParam('styles', []);

        // Strip unknown keys and validate values against strict type allowlists
        $defaults = WidgetConfig::defaultStyleValues();
        $styles = array_intersect_key($styles, $defaults);
        $styles = $this->_validateStyleValues($styles, $defaults);

        $widgetStyle->styles = $styles;

        $defaultStyles = WidgetConfig::defaultStyleValues();
        $styleUsageCounts = $styleId
            ? SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle()
            : [];
        $errorRouteParams = [
            'widgetStyle' => $widgetStyle,
            'isNew' => !$styleId,
            'defaultStyles' => $defaultStyles,
            'usageCount' => $styleId ? (int)($styleUsageCounts[$widgetStyle->handle] ?? 0) : null,
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
        ];

        if (!$widgetStyle->validate()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget style'));
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            return null;
        }

        if (!SearchManager::$plugin->widgetStyles->save($widgetStyle)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget style'));
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Widget style saved'));

        return $this->redirectToPostedUrl($widgetStyle);
    }

    /**
     * Delete a widget style
     *
     * @since 5.39.0
     */
    public function actionDeleteStyle(): Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();
        $this->requirePermission('searchManager:deleteWidgetStyles');

        $acceptsJson = Craft::$app->getRequest()->getAcceptsJson();
        $styleId = Craft::$app->getRequest()->getRequiredBodyParam('styleId');

        $style = SearchManager::$plugin->widgetStyles->getById((int) $styleId);
        if ($style !== null) {
            $usages = SearchManager::$plugin->dependencies->getStyleUsages($style->handle);
            if ($usages !== []) {
                $error = SearchManager::$plugin->dependencies->formatInUseError($style->name, $usages);
                if ($acceptsJson) {
                    return $this->asJson(['success' => false, 'error' => $error]);
                }
                Craft::$app->getSession()->setError($error);
                return $this->redirect('search-manager/widgets/styles');
            }
        }

        if (!SearchManager::$plugin->widgetStyles->delete((int) $styleId)) {
            $error = Craft::t('search-manager', 'Could not delete widget style');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $error]);
            }
            Craft::$app->getSession()->setError($error);
            return $this->redirect('search-manager/widgets/styles');
        }

        if ($acceptsJson) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Widget style deleted'));
        return $this->redirect('search-manager/widgets/styles');
    }

    /**
     * Bulk delete widget styles
     *
     * @since 5.53.0
     */
    public function actionBulkDeleteStyle(): Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:deleteWidgetStyles');

        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('styleIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        $widgetStyles = [];
        foreach ($result->identifiers() as $id) {
            $widgetStyle = SearchManager::$plugin->widgetStyles->getById($id);
            if ($widgetStyle === null) {
                $result->addSkip();
                continue;
            }
            if (!$widgetStyle->canEdit()) {
                $result->addError(Craft::t('search-manager', 'Could not delete widget style'));
                continue;
            }

            $usages = SearchManager::$plugin->dependencies->getStyleUsages($widgetStyle->handle);
            if ($usages !== []) {
                $result->addError(
                    SearchManager::$plugin->dependencies->formatInUseError($widgetStyle->name, $usages),
                );
                continue;
            }
            $widgetStyles[] = $widgetStyle;
        }

        if ($result->errors() !== [] || $widgetStyles === []) {
            return $this->asJson($result->toArray());
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($widgetStyles as $widgetStyle) {
                if ($widgetStyle->id === null || !SearchManager::$plugin->widgetStyles->delete($widgetStyle->id)) {
                    $result->addNamedError(
                        $widgetStyle->name,
                        Craft::t('search-manager', 'Could not delete widget style'),
                    );
                    $transaction->rollBack();
                    return $this->asJson($result->toArray());
                }
            }
            $transaction->commit();
            $result->addSuccess(count($widgetStyles));
        } catch (\Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }
            $result->addNamedError($widgetStyle->name, Craft::t('search-manager', 'Could not delete widget style'));
            $this->logError('Bulk widget style deletion failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->asJson($result->toArray());
    }

    /**
     * Duplicate a database-backed widget style.
     *
     * @since 5.53.0
     */
    public function actionDuplicateStyle(): Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();
        $this->requirePermission('searchManager:createWidgetStyles');

        $request = Craft::$app->getRequest();
        $styleId = $request->getRequiredBodyParam('styleId');
        $source = SearchManager::$plugin->widgetStyles->getById((int)$styleId);

        if (!$source) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Widget style not found'), 'search-manager/widgets/styles');
        }

        if (!$source->canEdit()) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Config-backed widget styles cannot be duplicated.'), 'search-manager/widgets/styles');
        }

        $widgetStyle = new WidgetStyle();
        $widgetStyle->name = $this->uniqueCopyLabel('{{%searchmanager_widget_styles}}', 'name', $source->name);
        $widgetStyle->handle = SlugHandleHelper::makeUnique(
            '{{%searchmanager_widget_styles}}',
            'handle',
            SlugHandleHelper::normalizeSlug($source->handle . '-copy', $widgetStyle->name),
        );
        $widgetStyle->type = $source->type;
        $widgetStyle->enabled = false;

        $defaults = WidgetConfig::defaultStyleValues();
        $styles = array_intersect_key($source->getStyles(), $defaults);
        $widgetStyle->styles = $this->_validateStyleValues($styles, $defaults);

        if (!$widgetStyle->validate() || !SearchManager::$plugin->widgetStyles->save($widgetStyle)) {
            return $this->duplicateFailure(Craft::t('search-manager', 'Could not duplicate widget style'), 'search-manager/widgets/styles');
        }

        $message = Craft::t('search-manager', 'Widget style duplicated');

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'message' => $message]);
        }

        Craft::$app->getSession()->setNotice($message);
        return $this->redirect('search-manager/widgets/styles');
    }

    /**
     * Bulk enable widget styles
     *
     * @since 5.52.0
     */
    public function actionBulkEnableStyle(): Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetStyles');

        return $this->bulkSetWidgetStylesEnabled(true);
    }

    /**
     * Bulk disable widget styles
     *
     * @since 5.52.0
     */
    public function actionBulkDisableStyle(): Response
    {
        $this->requireProWidgetStyles();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:editWidgetStyles');

        return $this->bulkSetWidgetStylesEnabled(false);
    }

    private function bulkSetWidgetStylesEnabled(bool $enabled): Response
    {
        $result = BulkMutationResult::fromIdentifiers(
            Craft::$app->getRequest()->getBodyParam('styleIds', []),
        );
        if (!$result->canMutate()) {
            return $this->asJson($result->toArray());
        }

        foreach ($result->identifiers() as $id) {
            $widgetStyle = SearchManager::$plugin->widgetStyles->getById($id);
            if ($widgetStyle === null) {
                $result->addSkip();
                continue;
            }
            if (!$widgetStyle->canEdit()) {
                $result->addNamedError(
                    $widgetStyle->name,
                    Craft::t('search-manager', 'Could not save widget style'),
                );
                continue;
            }
            if ($widgetStyle->enabled === $enabled) {
                $result->addSkip();
                continue;
            }

            try {
                $widgetStyle->enabled = $enabled;
                if (SearchManager::$plugin->widgetStyles->save($widgetStyle)) {
                    $result->addSuccess();
                } else {
                    $result->addModelErrors(
                        $widgetStyle->name,
                        $widgetStyle,
                        Craft::t('search-manager', 'Could not save widget style'),
                    );
                }
            } catch (\Throwable $e) {
                $result->addNamedError(
                    $widgetStyle->name,
                    Craft::t('search-manager', 'Could not save widget style'),
                );
                $this->logError('Bulk widget style status update failed', [
                    'widgetStyleId' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->asJson($result->toArray());
    }

    private function duplicateFailure(string $error, string $fallbackUrl): Response
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => false, 'error' => $error]);
        }

        Craft::$app->getSession()->setError($error);
        return $this->redirect($fallbackUrl);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitizeCopiedWidgetSettings(array $settings): array
    {
        $defaults = WidgetConfig::defaultSettings();
        $settings = $this->_filterSettingsKeys(array_replace_recursive($defaults, $settings), $defaults);
        $settings['apiKeyHandle'] = isset($settings['apiKeyHandle']) && is_string($settings['apiKeyHandle'])
            ? trim($settings['apiKeyHandle'])
            : '';

        return $settings;
    }

    private function uniqueCopyLabel(string $table, string $column, string $label): string
    {
        $base = trim($label) !== '' ? trim($label) : Craft::t('search-manager', 'Untitled');
        $copyLabel = Craft::t('lindemannrock-base', 'Copy');
        $candidate = mb_substr($base . ' ' . $copyLabel, 0, 255);
        $suffix = 2;

        while ((new Query())->from($table)->where([$column => $candidate])->exists()) {
            $candidate = mb_substr($base . ' ' . $copyLabel . ' ' . $suffix, 0, 255);
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Check if defaultWidgetHandle is set via config file
     */
    private function isDefaultWidgetFromConfig(): bool
    {
        return SearchManager::$plugin->getSettings()->isOverriddenByConfig('defaultWidgetHandle');
    }

    /**
     * Persist one widget as the default and log only after a successful write.
     */
    protected function assignDefaultWidget(WidgetConfig $widget, string $logMessage): bool
    {
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->defaultWidgetHandle === $widget->handle) {
            return true;
        }

        $previousHandle = $settings->defaultWidgetHandle;
        $settings->defaultWidgetHandle = $widget->handle;

        if (!$settings->saveToDatabase(['defaultWidgetHandle'])) {
            $settings->defaultWidgetHandle = $previousHandle;
            return false;
        }

        $this->logInfo($logMessage, [
            'handle' => $widget->handle,
            'name' => $widget->name,
        ]);

        return true;
    }

    /**
     * Assign the deterministic first enabled widget when the saved state has
     * no usable default.
     */
    protected function ensureDefaultWidget(): bool
    {
        if ($this->isDefaultWidgetFromConfig()) {
            return true;
        }

        $settings = SearchManager::$plugin->getSettings();
        $default = $settings->defaultWidgetHandle !== null
            ? SearchManager::$plugin->widgetConfigs->getByHandle($settings->defaultWidgetHandle)
            : null;
        if ($default?->enabled) {
            return true;
        }

        foreach (SearchManager::$plugin->widgetConfigs->getAll() as $widget) {
            if ($widget->enabled) {
                return $this->assignDefaultWidget(
                    $widget,
                    'Auto-set default widget (first enabled widget)',
                );
            }
        }

        return true;
    }

    /**
     * Return the post-save response without reporting success after a failed
     * default-settings write.
     */
    protected function widgetSaveResponse(WidgetConfig $widget, ?string $error = null): Response
    {
        if ($error !== null) {
            Craft::$app->getSession()->setError($error);
        } else {
            Craft::$app->getSession()->setNotice(
                Craft::t('search-manager', 'Widget config saved')
            );
        }

        return $this->redirectToPostedUrl($widget);
    }

    /**
     * Get widget type options for select fields
     *
     * @return array<int, array{value: string, label: string, disabled?: bool}>
     */
    private function getWidgetTypeOptions(): array
    {
        $options = [];
        foreach (WidgetStyle::WIDGET_TYPE_LABELS as $value => $label) {
            $translatedLabel = Craft::t('search-manager', $label);
            $option = ['value' => $value, 'label' => $translatedLabel];

            if ($value !== WidgetStyle::TYPE_MODAL) {
                $option['label'] = Craft::t('search-manager', '{label} (Coming soon)', [
                    'label' => $translatedLabel,
                ]);
                $option['disabled'] = true;
            }

            $options[] = $option;
        }
        return $options;
    }

    /**
     * Delegate Widget choice construction to the canonical index catalogue,
     * applying only the selected API key's irreducible scope restriction.
     *
     * @return array{
     *   0: array<int, array{label: string, value: string, state: string, canChoose: bool, retained: bool}>,
     *   1: array<int, array{label: string, value: string, state: string, canChoose: bool, retained: bool}>
     * }
     */
    private function getWidgetIndexOptions(
        WidgetConfig $widgetConfig,
        ?ApiKey $selectedApiKey,
    ): array {
        $selectedHandles = $widgetConfig->getIndexHandles();
        $allowedHandles = $selectedApiKey !== null && !$selectedApiKey->allowsAllIndices()
            ? $selectedApiKey->allowedIndices
            : null;

        return [
            SearchManager::$plugin->dependencies->getIndexOptions(
                $selectedHandles,
                false,
                $allowedHandles,
            ),
            SearchManager::$plugin->dependencies->getIndexOptions($selectedHandles, false),
        ];
    }

    /**
     * @param ApiKey[] $keys
     * @return array<int, array{value: string, label: string}>
     */
    private function getWidgetApiKeyOptions(array $keys): array
    {
        $options = [
            ['value' => '', 'label' => Craft::t('search-manager', 'None')],
        ];

        foreach ($keys as $key) {
            if ($key->handle === '') {
                continue;
            }
            $options[] = [
                'value' => $key->handle,
                'label' => SearchManager::$plugin->apiKeys->widgetKeyLabel($key),
            ];
        }

        return $options;
    }

    /**
     * @param ApiKey[] $keys
     * @return array<string, list<string>|string>
     */
    private function getWidgetApiKeyScopes(array $keys): array
    {
        $scopes = ['' => ApiKey::ALL_INDICES];

        foreach ($keys as $key) {
            if ($key->handle === '') {
                continue;
            }
            $scopes[$key->handle] = $key->allowsAllIndices()
                ? ApiKey::ALL_INDICES
                : array_values($key->allowedIndices);
        }

        return $scopes;
    }

    /**
     * @param ApiKey[] $keys
     */
    private function getSelectedWidgetApiKey(WidgetConfig $widgetConfig, array $keys): ?ApiKey
    {
        $apiKeyHandle = $widgetConfig->getApiKeyHandle();
        if ($apiKeyHandle === '') {
            return null;
        }

        foreach ($keys as $key) {
            if ($key->handle === $apiKeyHandle) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Recursively strip keys from $data that don't exist in $allowed
     */
    private function _filterSettingsKeys(array $data, array $allowed): array
    {
        $filtered = [];
        foreach ($allowed as $key => $defaultValue) {
            if (!array_key_exists($key, $data)) {
                $filtered[$key] = $defaultValue;
                continue;
            }

            if ($defaultValue === [] && is_array($data[$key])) {
                $filtered[$key] = array_values($data[$key]);
            } elseif (is_array($defaultValue) && is_array($data[$key])) {
                $filtered[$key] = $this->_filterSettingsKeys($data[$key], $defaultValue);
            } else {
                $filtered[$key] = $data[$key];
            }
        }
        return $filtered;
    }

    /**
     * Normalize only boolean settings declared by the typed default contract.
     *
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function normalizeSubmittedBooleanSettings(array $submitted, array $defaults): array
    {
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $submitted)) {
                continue;
            }

            if (is_bool($defaultValue)) {
                $submitted[$key] = $this->normalizeSubmittedLightswitch($submitted[$key], $defaultValue);
                continue;
            }

            if (is_array($defaultValue) && is_array($submitted[$key])) {
                $submitted[$key] = $this->normalizeSubmittedBooleanSettings($submitted[$key], $defaultValue);
            }
        }

        return $submitted;
    }

    /**
     * Normalize a Craft lightswitch value without treating its off sentinel as
     * a valueless HTML attribute.
     */
    private function normalizeSubmittedLightswitch(mixed $value, bool $default = false): bool
    {
        if ($value === '') {
            return false;
        }

        return BooleanHelper::normalize($value, $default);
    }

    /**
     * Validate style values against strict type-based allowlists.
     *
     * Instead of blocklisting dangerous patterns (bypassable via Unicode escapes,
     * backslash tricks, etc.), each property is validated against its expected type.
     * Invalid values are replaced with the default from style-defaults.json.
     *
     * @param array<string, mixed> $styles Submitted style values
     * @param array<string, string> $defaults Default style values
     * @return array<string, string> Validated style values
     */
    private function _validateStyleValues(array $styles, array $defaults): array
    {
        $validated = [];

        foreach ($styles as $key => $value) {
            $submittedValue = $value;
            $value = trim((string) $submittedValue);
            $type = $this->_getStyleValueType($key);

            $valid = match ($type) {
                'color' => $this->_isValidCssColor($value),
                'number' => preg_match('/^\d+(\.\d+)?$/', $value) === 1,
                'shadow' => $this->_isValidCssShadow($value),
                'boolean' => BooleanHelper::isBooleanLike($submittedValue),
                'tag' => in_array(strtolower($value), Highlighter::ALLOWED_TAGS, true),
                'class' => Highlighter::isValidClassTokenList($value),
                default => false,
            };

            $validated[$key] = match (true) {
                $valid && $type === 'boolean' => $this->normalizeSubmittedLightswitch(
                    $submittedValue,
                    BooleanHelper::normalize($defaults[$key] ?? false),
                ) ? '1' : '0',
                $valid && $type === 'tag' => strtolower($value),
                $valid => $value,
                default => (string)($defaults[$key] ?? ''),
            };
        }

        return $validated;
    }

    /**
     * Determine the expected value type for a style property.
     */
    private function _getStyleValueType(string $key): string
    {
        // Exact matches first
        return match ($key) {
            'backdropBlur', 'highlightResultsEnabled' => 'boolean',
            'highlightTag' => 'tag',
            'highlightClass' => 'class',
            'modalShadow', 'modalShadowDark' => 'shadow',
            default => $this->_inferStyleValueType($key),
        };
    }

    /**
     * Infer style value type from the property name suffix.
     */
    private function _inferStyleValueType(string $key): string
    {
        // Color properties: *Color, *ColorDark, *Bg, *BgDark, *BgLight
        if (preg_match('/(Color|Bg)(Dark|Light)?$/', $key)) {
            return 'color';
        }

        // Numeric properties: everything else (radius, width, padding, font size, gap, opacity, blur, max dimensions)
        return 'number';
    }

    /**
     * Validate a CSS color value (hex, rgb/rgba, hsl/hsla, transparent).
     */
    private function _isValidCssColor(string $value): bool
    {
        // transparent
        if ($value === 'transparent') {
            return true;
        }

        // Hex: #fff, #ffffff, #ffffffff (with or without # — Craft's colorField strips it)
        if (preg_match('/^#?[0-9a-fA-F]{3,8}$/', $value)) {
            return true;
        }

        // rgb()/rgba(): only digits, commas, dots, spaces, percent inside parens
        if (preg_match('/^rgba?\(\s*[\d\s,.%\/]+\s*\)$/', $value)) {
            return true;
        }

        // hsl()/hsla(): digits, commas, dots, spaces, percent, deg inside parens
        if (preg_match('/^hsla?\(\s*[\d\s,.%\/deg]+\s*\)$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Validate a CSS box-shadow value.
     * Allows: offsets, blur, spread (numbers with px/em), rgba/hex colors, commas for multiple shadows.
     */
    private function _isValidCssShadow(string $value): bool
    {
        if ($value === 'none' || $value === '') {
            return true;
        }

        // Must not contain backslashes, semicolons, or curly braces
        if (preg_match('/[\\\\;{}]/', $value)) {
            return false;
        }

        // Only allow: digits, hex chars, letters (for px/em/inset/rgba/none), spaces,
        // commas, dots, hyphens, parens, hash, forward slash, percent
        return preg_match('/^[0-9a-zA-Z\s,.\-()#\/%]+$/', $value) === 1;
    }
}
