<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\WidgetConfig;
use yii\base\Component;

/**
 * Widget Config Service
 *
 * Manages widget configurations for the search widget.
 */
class WidgetConfigService extends Component
{
    use LoggingTrait;

    private const TABLE = '{{%searchmanager_widget_configs}}';

    /**
     * @var WidgetConfig|null Cached default config
     */
    private ?WidgetConfig $_defaultConfig = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // GETTERS
    // =========================================================================

    /**
     * Get widget config by ID
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
     */
    public function getByHandle(string $handle): ?WidgetConfig
    {
        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['handle' => $handle])
            ->one();

        return $row ? $this->createFromRow($row) : null;
    }

    /**
     * Get the default widget config
     */
    public function getDefault(): ?WidgetConfig
    {
        if ($this->_defaultConfig !== null) {
            return $this->_defaultConfig;
        }

        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['isDefault' => 1])
            ->one();

        $this->_defaultConfig = $row ? $this->createFromRow($row) : null;

        // If no default exists, return first enabled config
        if ($this->_defaultConfig === null) {
            $row = (new Query())
                ->select('*')
                ->from(self::TABLE)
                ->where(['enabled' => 1])
                ->orderBy(['id' => SORT_ASC])
                ->one();

            $this->_defaultConfig = $row ? $this->createFromRow($row) : null;
        }

        return $this->_defaultConfig;
    }

    /**
     * Get all widget configs
     */
    public function getAll(bool $enabledOnly = false): array
    {
        $query = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->orderBy(['isDefault' => SORT_DESC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => 1]);
        }

        $rows = $query->all();

        return array_map(fn($row) => $this->createFromRow($row), $rows);
    }

    /**
     * Get config count
     */
    public function getCount(): int
    {
        return (int) (new Query())
            ->from(self::TABLE)
            ->count();
    }

    /**
     * Get config for use in widget - by handle or returns default
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
            $default->isDefault = true;
            $default->enabled = true;
        }

        return $default;
    }

    // =========================================================================
    // SAVE / DELETE
    // =========================================================================

    /**
     * Save a widget config
     */
    public function save(WidgetConfig $config): bool
    {
        if (!$config->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $data = $config->prepareForDb();

        // If setting as default, unset others
        if ($config->isDefault) {
            Craft::$app->db->createCommand()
                ->update(self::TABLE, ['isDefault' => 0])
                ->execute();
        }

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
     */
    public function delete(WidgetConfig $config): bool
    {
        if (!$config->id) {
            return false;
        }

        // Don't delete the default config if it's the only one
        if ($config->isDefault && $this->getCount() <= 1) {
            $this->logWarning('Cannot delete the only widget config');
            return false;
        }

        Craft::$app->db->createCommand()
            ->delete(self::TABLE, ['id' => $config->id])
            ->execute();

        // If we deleted the default, set another as default
        if ($config->isDefault) {
            $first = (new Query())
                ->select('id')
                ->from(self::TABLE)
                ->orderBy(['id' => SORT_ASC])
                ->scalar();

            if ($first) {
                Craft::$app->db->createCommand()
                    ->update(self::TABLE, ['isDefault' => 1], ['id' => $first])
                    ->execute();
            }
        }

        // Clear cache
        $this->_defaultConfig = null;

        $this->logInfo('Widget config deleted', ['handle' => $config->handle]);

        return true;
    }

    /**
     * Delete a widget config by ID
     */
    public function deleteById(int $id): bool
    {
        $config = $this->getById($id);
        if ($config === null) {
            return false;
        }

        return $this->delete($config);
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
        $config->settings = Json::decodeIfJson($row['settings']) ?: WidgetConfig::defaultSettings();
        $config->isDefault = (bool) $row['isDefault'];
        $config->enabled = (bool) $row['enabled'];
        $config->dateCreated = $row['dateCreated'] ? new \DateTime($row['dateCreated']) : null;
        $config->dateUpdated = $row['dateUpdated'] ? new \DateTime($row['dateUpdated']) : null;
        $config->uid = $row['uid'] ?? null;

        return $config;
    }
}
