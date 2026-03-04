<?php

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
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

    /** @inheritdoc */
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
        $this->requirePermission('searchManager:manageWidgetConfigs');

        $widgetConfigs = SearchManager::$plugin->widgetConfigs->getAll();
        $settings = SearchManager::$plugin->getSettings();
        $configHandles = ConfigFileHelper::getHandles('widgets');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_widget_configs}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // Auto-assign default if needed (only if not set via config file)
        if (!$this->isDefaultWidgetFromConfig()) {
            $defaultHandle = $settings->defaultWidgetHandle;
            $needsReassign = false;

            if (empty($defaultHandle)) {
                // No default set
                $needsReassign = true;
            } else {
                // Check if default exists and is enabled
                $defaultWidget = SearchManager::$plugin->widgetConfigs->getByHandle($defaultHandle);
                if (!$defaultWidget || !$defaultWidget->enabled) {
                    $needsReassign = true;
                }
            }

            if ($needsReassign && !empty($widgetConfigs)) {
                // Find first enabled widget
                foreach ($widgetConfigs as $widget) {
                    if ($widget->enabled) {
                        $settings->defaultWidgetHandle = $widget->handle;
                        $settings->saveToDatabase();

                        $this->logInfo('Auto-assigned default widget', [
                            'handle' => $widget->handle,
                            'reason' => empty($defaultHandle) ? 'no default set' : 'previous default invalid',
                        ]);
                        break;
                    }
                }
            }
        }

        return $this->renderTemplate('search-manager/widgets/index', [
            'widgetConfigs' => $widgetConfigs,
            'defaultWidgetHandle' => $settings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
            'collisionHandles' => $collisionHandles,
        ]);
    }

    /**
     * View a widget configuration (read-only, for config widgets)
     *
     * @param string|null $handle Widget handle
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:manageWidgetConfigs');

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
    public function actionEdit(?int $configId = null, ?WidgetConfig $widgetConfig = null): Response
    {
        if (!$widgetConfig) {
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
        }

        // Get indices for multi-select
        $indices = SearchIndex::findAll();
        $settings = SearchManager::$plugin->getSettings();
        $widgetStyles = SearchManager::$plugin->widgetStyles->getAll('modal');

        return $this->renderTemplate('search-manager/widgets/edit', [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indices' => $indices,
            'widgetStyles' => $widgetStyles,
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
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
        $widgetConfig->type = (string) $request->getBodyParam('type', 'modal');
        $widgetConfig->enabled = (bool) $request->getBodyParam('enabled');

        // Get settings from form
        $widgetSettings = $request->getBodyParam('settings', []);

        // Merge with defaults to ensure all keys exist
        $defaults = WidgetConfig::defaultSettings();
        $mergedSettings = array_replace_recursive($defaults, $widgetSettings);

        // Strip unknown keys — only allow keys defined in defaults
        $mergedSettings = $this->_filterSettingsKeys($mergedSettings, $defaults);

        // Handle indexHandles - ensure it's always an array
        if (isset($mergedSettings['search']['indexHandles'])) {
            $indexHandles = $mergedSettings['search']['indexHandles'];
            if (!is_array($indexHandles)) {
                $mergedSettings['search']['indexHandles'] = $indexHandles ? [$indexHandles] : [];
            }
        }

        $widgetConfig->settings = $mergedSettings;

        $pluginSettings = SearchManager::$plugin->getSettings();
        $indices = SearchIndex::findAll();
        $widgetStyles = SearchManager::$plugin->widgetStyles->getAll('modal');

        // Common route params for error returns (template needs all of these)
        $errorRouteParams = [
            'widgetConfig' => $widgetConfig,
            'isNew' => !$configId,
            'indices' => $indices,
            'widgetStyles' => $widgetStyles,
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
            'defaultWidgetHandle' => $pluginSettings->defaultWidgetHandle,
            'isDefaultFromConfig' => $this->isDefaultWidgetFromConfig(),
        ];

        // Set style handle from form
        $styleHandle = $request->getBodyParam('styleHandle');
        if ($styleHandle) {
            $existingStyle = SearchManager::$plugin->widgetStyles->getByHandle($styleHandle);
            if ($existingStyle === null) {
                $widgetConfig->addError('styleHandle', Craft::t('search-manager', 'Selected style preset not found.'));
                Craft::$app->getSession()->setError(Craft::t('search-manager', 'Selected style preset not found.'));
                Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
                return null;
            }
            $widgetConfig->styleHandle = $styleHandle;
        } else {
            $widgetConfig->styleHandle = null;
        }

        // Validate
        if (!$widgetConfig->validate()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config.'));
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            return null;
        }

        // Save widget config
        if (!SearchManager::$plugin->widgetConfigs->save($widgetConfig)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget config.'));
            Craft::$app->getUrlManager()->setRouteParams($errorRouteParams);
            return null;
        }

        // Handle "Set as Default" toggle (only if not set via config)
        $isDefault = (bool) $request->getBodyParam('isDefault');
        if ($isDefault && !$this->isDefaultWidgetFromConfig()) {
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
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Widget config not found.')]);
        }

        // Prevent deleting the default widget
        $settings = SearchManager::$plugin->getSettings();
        if ($settings->defaultWidgetHandle === $widgetConfig->handle) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Cannot delete the default widget. Set another widget as default first.')]);
        }

        if (!SearchManager::$plugin->widgetConfigs->delete($configId)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Could not delete widget config.')]);
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
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Widget config not found.')]);
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

    // =========================================================================
    // Widget Styles
    // =========================================================================

    /**
     * List all widget styles
     *
     * @since 5.39.0
     */
    public function actionStylesIndex(): Response
    {
        $this->requirePermission('searchManager:manageWidgetStyles');

        $widgetStyles = SearchManager::$plugin->widgetStyles->getAll();
        $styleUsageCounts = SearchManager::$plugin->widgetStyles->getUsageCountsByHandle();

        $configHandles = ConfigFileHelper::getHandles('widgetStyles');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_widget_styles}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        return $this->renderTemplate('search-manager/widgets/styles/index', [
            'widgetStyles' => $widgetStyles,
            'styleUsageCounts' => $styleUsageCounts,
            'collisionHandles' => $collisionHandles,
        ]);
    }

    /**
     * View a widget style (read-only, for config styles)
     *
     * @since 5.39.0
     */
    public function actionViewStyle(?string $handle = null): Response
    {
        $this->requirePermission('searchManager:manageWidgetStyles');

        if (!$handle) {
            throw new NotFoundHttpException('Widget style handle required');
        }

        $widgetStyle = SearchManager::$plugin->widgetStyles->getByHandle($handle);

        if (!$widgetStyle) {
            throw new NotFoundHttpException('Widget style not found');
        }

        $defaultStyles = WidgetConfig::defaultStyleValues();
        $usageCount = $this->getStyleUsageCount($widgetStyle->handle);

        return $this->renderTemplate('search-manager/widgets/styles/view', [
            'widgetStyle' => $widgetStyle,
            'defaultStyles' => $defaultStyles,
            'styles' => array_merge($defaultStyles, $widgetStyle->getStyles()),
            'usageCount' => $usageCount,
        ]);
    }

    /**
     * Edit or create a widget style
     *
     * @since 5.39.0
     */
    public function actionEditStyle(?int $styleId = null, ?WidgetStyle $widgetStyle = null): Response
    {
        if (!$widgetStyle) {
            if ($styleId) {
                $this->requirePermission('searchManager:editWidgetStyles');
                $widgetStyle = SearchManager::$plugin->widgetStyles->getById($styleId);
                if (!$widgetStyle) {
                    throw new NotFoundHttpException('Widget style not found');
                }
            } else {
                $this->requirePermission('searchManager:createWidgetStyles');
                $widgetStyle = new WidgetStyle();
            }
        }

        $defaultStyles = WidgetConfig::defaultStyleValues();

        return $this->renderTemplate('search-manager/widgets/styles/edit', [
            'widgetStyle' => $widgetStyle,
            'isNew' => !$styleId,
            'defaultStyles' => $defaultStyles,
            'usageCount' => $styleId ? ($this->getStyleUsageCount($widgetStyle->handle)) : null,
            'widgetTypeOptions' => $this->getWidgetTypeOptions(),
        ]);
    }

    /**
     * Save a widget style
     *
     * @since 5.39.0
     */
    public function actionSaveStyle(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $styleId = $request->getBodyParam('styleId');

        if ($styleId) {
            $this->requirePermission('searchManager:editWidgetStyles');
            $widgetStyle = SearchManager::$plugin->widgetStyles->getById((int) $styleId);
            if (!$widgetStyle) {
                throw new NotFoundHttpException('Widget style not found');
            }
        } else {
            $this->requirePermission('searchManager:createWidgetStyles');
            $widgetStyle = new WidgetStyle();
        }

        $widgetStyle->name = (string) $request->getBodyParam('name');
        $widgetStyle->handle = (string) $request->getBodyParam('handle');
        $widgetStyle->enabled = (bool) $request->getBodyParam('enabled');
        $widgetStyle->type = (string) $request->getBodyParam('type', 'modal');
        $styles = $request->getBodyParam('styles', []);

        // Strip unknown keys and validate values against strict type allowlists
        $defaults = WidgetConfig::defaultStyleValues();
        $styles = array_intersect_key($styles, $defaults);
        $styles = $this->_validateStyleValues($styles, $defaults);

        $widgetStyle->styles = $styles;

        $defaultStyles = WidgetConfig::defaultStyleValues();

        if (!$widgetStyle->validate()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget style.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'widgetStyle' => $widgetStyle,
                'isNew' => !$styleId,
                'defaultStyles' => $defaultStyles,
            ]);
            return null;
        }

        if (!SearchManager::$plugin->widgetStyles->save($widgetStyle)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Could not save widget style.'));
            Craft::$app->getUrlManager()->setRouteParams([
                'widgetStyle' => $widgetStyle,
                'isNew' => !$styleId,
                'defaultStyles' => $defaultStyles,
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'Widget style saved.'));

        return $this->redirectToPostedUrl($widgetStyle);
    }

    /**
     * Delete a widget style
     *
     * @since 5.39.0
     */
    public function actionDeleteStyle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('searchManager:deleteWidgetStyles');

        $styleId = Craft::$app->getRequest()->getRequiredBodyParam('styleId');

        if (!SearchManager::$plugin->widgetStyles->delete((int) $styleId)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('search-manager', 'Could not delete widget style.')]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Get usage count for a specific style handle
     */
    private function getStyleUsageCount(string $handle): int
    {
        $counts = SearchManager::$plugin->widgetStyles->getUsageCountsByHandle();
        return (int) ($counts[$handle] ?? 0);
    }

    /**
     * Check if defaultWidgetHandle is set via config file
     */
    private function isDefaultWidgetFromConfig(): bool
    {
        return SearchManager::$plugin->getSettings()->isOverriddenByConfig('defaultWidgetHandle');
    }

    /**
     * Get widget type options for select fields
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getWidgetTypeOptions(): array
    {
        $options = [];
        foreach (WidgetStyle::WIDGET_TYPE_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
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

            if (is_array($defaultValue) && is_array($data[$key])) {
                $filtered[$key] = $this->_filterSettingsKeys($data[$key], $defaultValue);
            } else {
                $filtered[$key] = $data[$key];
            }
        }
        return $filtered;
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
            $value = trim((string) $value);
            $type = $this->_getStyleValueType($key);

            $valid = match ($type) {
                'color' => $this->_isValidCssColor($value),
                'number' => preg_match('/^\d+(\.\d+)?$/', $value) === 1,
                'shadow' => $this->_isValidCssShadow($value),
                'boolean' => $value === '0' || $value === '1',
                'tag' => preg_match('/^[a-z]{1,20}$/', $value) === 1,
                'class' => preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,50}$/', $value) === 1,
                default => false,
            };

            $validated[$key] = $valid ? $value : ($defaults[$key] ?? '');
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
            'highlightEnabled' => 'boolean',
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
