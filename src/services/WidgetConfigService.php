<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\BooleanHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Widget Config Service
 *
 * Manages widget configurations for the search widget.
 * Supports both database-stored configs and config file definitions.
 *
 * @since 5.30.0
 */
class WidgetConfigService extends Component
{
    use LoggingTrait;

    private const PLUGIN_HANDLE = 'search-manager';

    private const TABLE = '{{%searchmanager_widget_configs}}';

    /**
     * @var WidgetConfig|null Cached default config
     */
    private ?WidgetConfig $_defaultConfig = null;

    /**
     * @var array|null Cached config file widget configs
     */
    private ?array $_configFileConfigs = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // CONFIG FILE LOADING
    // =========================================================================

    /**
     * Get all widget configs defined in config file
     *
     */
    public function getConfigFileConfigs(): array
    {
        if ($this->_configFileConfigs !== null) {
            return $this->_configFileConfigs;
        }

        $this->_configFileConfigs = [];
        $widgetConfigs = BaseConfigFileHelper::getConfigSection(self::PLUGIN_HANDLE, 'widgets');

        foreach ($widgetConfigs as $handle => $configData) {
            $this->_configFileConfigs[$handle] = $this->createFromConfig($handle, $configData);
        }

        return $this->_configFileConfigs;
    }

    /**
     * Create a WidgetConfig from config file data
     */
    private function createFromConfig(string $handle, array $configData): WidgetConfig
    {
        $widgetConfig = new WidgetConfig();
        $widgetConfig->handle = $handle;
        $widgetConfig->name = $configData['name'] ?? ucfirst($handle);
        $widgetConfig->type = $configData['type'] ?? 'modal';
        if ($widgetConfig->type !== WidgetStyle::TYPE_MODAL) {
            throw new InvalidConfigException(Craft::t('search-manager', 'Widget "{handle}" uses unsupported type "{type}". Only modal widgets are available in this version.', [
                'handle' => $handle,
                'type' => $widgetConfig->type,
            ]));
        }
        $widgetConfig->enabled = BooleanHelper::normalize($configData['enabled'] ?? null, true);
        $widgetConfig->source = 'config';
        $widgetConfig->styleHandle = $configData['styleHandle'] ?? null;

        // Merge settings with defaults
        $settings = $configData['settings'] ?? [];
        $widgetConfig->settings = array_replace_recursive(
            WidgetConfig::defaultSettings(),
            $settings
        );

        return $widgetConfig;
    }

    /**
     * Get a config from config file by handle
     *
     */
    public function getConfigFileByHandle(string $handle): ?WidgetConfig
    {
        $configs = $this->getConfigFileConfigs();
        return $configs[$handle] ?? null;
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    /**
     * Get widget config by ID
     *
     */
    public function getById(int $id): ?WidgetConfig
    {
        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        return $row ? $this->createFromRow($row) : null;
    }

    /**
     * Get widget config by handle
     * Checks config file first, then database
     *
     */
    public function getByHandle(string $handle): ?WidgetConfig
    {
        // First, check config file
        $configFileConfig = $this->getConfigFileByHandle($handle);
        if ($configFileConfig !== null) {
            return $configFileConfig;
        }

        // Then, check database
        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['handle' => $handle])
            ->one();

        return $row ? $this->createFromRow($row) : null;
    }

    /**
     * Get the default widget config
     * Uses defaultWidgetHandle from plugin settings to determine the default
     *
     */
    public function getDefault(): ?WidgetConfig
    {
        if ($this->_defaultConfig !== null) {
            return $this->_defaultConfig;
        }

        // Get defaultWidgetHandle from plugin settings
        $plugin = \lindemannrock\searchmanager\SearchManager::getInstance();
        $settings = $plugin?->getSettings();
        $defaultHandle = $settings?->defaultWidgetHandle;

        // If a default handle is set, look it up
        if ($defaultHandle) {
            $config = $this->getByHandle($defaultHandle);
            if ($config !== null && $config->enabled) {
                $this->_defaultConfig = $config;
                return $this->_defaultConfig;
            }
        }

        // Fallback: return first enabled config (config file first, then database)
        $configFileConfigs = $this->getConfigFileConfigs();
        foreach ($configFileConfigs as $config) {
            if ($config->enabled) {
                $this->_defaultConfig = $config;
                return $this->_defaultConfig;
            }
        }

        // Then database
        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['enabled' => 1])
            ->orderBy(['id' => SORT_ASC])
            ->one();

        $this->_defaultConfig = $row ? $this->createFromRow($row) : null;

        return $this->_defaultConfig;
    }

    /**
     * Get all widget configs (from config file and database)
     * Config file configs take precedence over database configs with same handle
     *
     */
    public function getAll(bool $enabledOnly = false): array
    {
        $configs = [];
        $handlesFromConfig = [];

        // First, load configs from config file
        $configFileConfigs = $this->getConfigFileConfigs();
        foreach ($configFileConfigs as $config) {
            if ($enabledOnly && !$config->enabled) {
                continue;
            }
            $configs[$config->handle] = $config;
            $handlesFromConfig[] = $config->handle;
        }

        // Then, load configs from database (excluding those defined in config)
        $query = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->orderBy(['name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => 1]);
        }

        $rows = $query->all();

        foreach ($rows as $row) {
            // Skip if this handle is already defined in config
            if (in_array($row['handle'], $handlesFromConfig, true)) {
                continue;
            }
            $configs[$row['handle']] = $this->createFromRow($row);
        }

        // Sort by name only - template handles default-first and other sorting
        usort($configs, fn($a, $b) => strcasecmp($a->name, $b->name));

        return array_values($configs);
    }

    /**
     * Get widget configs that reference a selected API key handle.
     *
     * Config-file widgets count because they participate in runtime rendering
     * and can be broken by deleting or narrowing the referenced key.
     *
     * @return WidgetConfig[]
     */
    public function findConfigsUsingApiKeyHandle(string $handle): array
    {
        $handle = trim($handle);
        if ($handle === '') {
            return [];
        }

        return array_values(array_filter(
            $this->getAll(),
            static fn(WidgetConfig $config): bool => $config->getApiKeyHandle() === $handle,
        ));
    }

    /**
     * Get widget configs whose selected index handles no longer fit the key.
     *
     * Empty widget selections remain valid: at runtime they inherit the key's
     * own allowed index scope.
     *
     * @return WidgetConfig[]
     */
    public function findConfigsBrokenByApiKeyScope(ApiKey $apiKey): array
    {
        if ($apiKey->handle === '' || $apiKey->allowsAllIndices()) {
            return [];
        }

        $broken = [];
        foreach ($this->findConfigsUsingApiKeyHandle($apiKey->handle) as $config) {
            $handles = $config->getSetting('search.indexHandles', []);
            if ($handles === '' || $handles === [] || !is_array($handles)) {
                continue;
            }

            foreach ($handles as $handle) {
                if (!is_string($handle) || !$apiKey->allowsIndex($handle)) {
                    $broken[] = $config;
                    break;
                }
            }
        }

        return $broken;
    }

    /**
     * Format widget names for concise dependency errors.
     *
     * @param WidgetConfig[] $configs
     */
    public function formatWidgetDependencyNames(array $configs): string
    {
        $labels = array_map(
            static fn(WidgetConfig $config): string => trim($config->name) !== ''
                ? $config->name
                : $config->handle,
            $configs,
        );

        $labels = array_values(array_unique(array_filter($labels, static fn(string $label): bool => $label !== '')));
        $visible = array_slice($labels, 0, 3);
        $formatted = implode(', ', $visible);
        $remaining = count($labels) - count($visible);

        if ($remaining > 0) {
            $formatted .= Craft::t('search-manager', ' and {count} more', ['count' => $remaining]);
        }

        return $formatted;
    }

    /**
     * Get config count
     *
     */
    public function getCount(): int
    {
        return (int) (new Query())
            ->from(self::TABLE)
            ->count();
    }

    /**
     * Get config for use in widget - by handle or returns default
     *
     */
    public function getConfigForWidget(?string $handle = null): WidgetConfig
    {
        if ($handle !== null) {
            $config = $this->getByHandle($handle);
            if ($config !== null && $config->enabled) {
                return $config;
            }
        }

        // Fall back to default
        $default = $this->getDefault();

        // If no default, return a new config with defaults
        if ($default === null) {
            $default = new WidgetConfig();
            $default->handle = 'default';
            $default->name = 'Default';
            $default->settings = WidgetConfig::defaultSettings();
            $default->enabled = true;
        }

        return $default;
    }

    // =========================================================================
    // SAVE / DELETE
    // =========================================================================

    /**
     * Save a widget config
     * Config-file configs cannot be saved
     *
     */
    public function save(WidgetConfig $config): bool
    {
        // Prevent saving config-file configs
        if ($config->source === 'config') {
            $this->logWarning('Cannot save config-file widget config', ['handle' => $config->handle]);
            return false;
        }

        if (!$config->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $data = $config->prepareForDb();

        if ($config->id) {
            // Update
            $data['dateUpdated'] = $now;
            Craft::$app->db->createCommand()
                ->update(self::TABLE, $data, ['id' => $config->id])
                ->execute();
        } else {
            // Insert
            $data['dateCreated'] = $now;
            $data['dateUpdated'] = $now;
            $data['uid'] = StringHelper::UUID();

            Craft::$app->db->createCommand()
                ->insert(self::TABLE, $data)
                ->execute();

            $config->id = (int) Craft::$app->db->getLastInsertID();
        }

        // Clear cache
        $this->_defaultConfig = null;

        $this->logInfo('Widget config saved', ['handle' => $config->handle]);

        return true;
    }

    /**
     * Delete a widget config
     * Config-file configs cannot be deleted
     *
     */
    public function delete(WidgetConfig $config): bool
    {
        // Prevent deleting config-file configs
        if ($config->source === 'config') {
            $this->logWarning('Cannot delete config-file widget config', ['handle' => $config->handle]);
            return false;
        }

        if (!$config->id) {
            return false;
        }

        // Check if this is the default widget
        $plugin = \lindemannrock\searchmanager\SearchManager::getInstance();
        $settings = $plugin?->getSettings();
        $isDefault = $settings?->defaultWidgetHandle === $config->handle;

        // Do not remove the last enabled effective widget. Config-file widgets
        // count as alternatives, but are never deleted by this DB path.
        if ($config->enabled && !$this->hasEnabledConfigAfterDelete($config)) {
            $this->logWarning('Cannot delete the only widget config');
            return false;
        }

        Craft::$app->db->createCommand()
            ->delete(self::TABLE, ['id' => $config->id])
            ->execute();

        // If we deleted the default, set another widget as the default
        if ($isDefault && $settings !== null) {
            $first = $this->getFirstEnabledHandle();
            if ($first !== null) {
                $settings->defaultWidgetHandle = $first;
                $settings->saveToDatabase();
                $this->logInfo('Set new default widget after deletion', ['handle' => $first]);
            }
        }

        // Clear cache
        $this->_defaultConfig = null;

        $this->logInfo('Widget config deleted', ['handle' => $config->handle]);

        return true;
    }

    /**
     * Delete a widget config by ID
     *
     */
    public function deleteById(int $id): bool
    {
        $config = $this->getById($id);
        if ($config === null) {
            return false;
        }

        return $this->delete($config);
    }

    /**
     * Whether at least one enabled effective widget remains after deleting a DB widget.
     */
    private function hasEnabledConfigAfterDelete(WidgetConfig $deletedConfig): bool
    {
        foreach ($this->getAll(true) as $config) {
            if ($deletedConfig->id !== null && $config->id === $deletedConfig->id) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Get the first enabled effective widget handle after a delete.
     */
    private function getFirstEnabledHandle(): ?string
    {
        $first = $this->getAll(true)[0] ?? null;
        return $first?->handle;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Create WidgetConfig from database row
     */
    private function createFromRow(array $row): WidgetConfig
    {
        $config = new WidgetConfig();
        $config->id = (int) $row['id'];
        $config->handle = $row['handle'];
        $config->name = $row['name'];
        $config->type = $row['type'] ?? 'modal';
        $config->styleHandle = $row['styleHandle'] ?? null;
        $config->settings = Json::decodeIfJson($row['settings']) ?: WidgetConfig::defaultSettings();
        $config->enabled = (bool) $row['enabled'];
        $config->dateCreated = $row['dateCreated'] ? new \DateTime((string)$row['dateCreated'], new \DateTimeZone('UTC')) : null;
        $config->dateUpdated = $row['dateUpdated'] ? new \DateTime((string)$row['dateUpdated'], new \DateTimeZone('UTC')) : null;
        $config->uid = $row['uid'] ?? null;

        return $config;
    }
}
