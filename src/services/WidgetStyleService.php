<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\models\WidgetStyle;
use yii\base\Component;

/**
 * Widget Style Service
 *
 * Manages reusable widget style presets.
 *
 * @since 5.39.0
 */
class WidgetStyleService extends Component
{
    use LoggingTrait;

    private const TABLE = '{{%searchmanager_widget_styles}}';

    /**
     * @var array|null Cached config file styles
     */
    private ?array $_configFileStyles = null;

    /** @inheritdoc */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Get all widget styles defined in config file
     *
     * @since 5.39.0
     */
    public function getConfigFileStyles(): array
    {
        if ($this->_configFileStyles !== null) {
            return $this->_configFileStyles;
        }

        $this->_configFileStyles = [];
        $styles = ConfigFileHelper::getWidgetStyles();

        foreach ($styles as $handle => $configData) {
            $this->_configFileStyles[$handle] = $this->createFromConfig($handle, $configData);
        }

        return $this->_configFileStyles;
    }

    private function createFromConfig(string $handle, array $configData): WidgetStyle
    {
        $style = new WidgetStyle();
        $style->handle = $handle;
        $style->name = $configData['name'] ?? ucfirst($handle);
        $style->type = $configData['type'] ?? 'modal';
        $style->enabled = $configData['enabled'] ?? true;
        $style->source = 'config';
        $style->styles = $configData['styles'] ?? [];

        return $style;
    }

    /**
     * @since 5.39.0
     */
    public function getConfigFileByHandle(string $handle): ?WidgetStyle
    {
        $styles = $this->getConfigFileStyles();
        return $styles[$handle] ?? null;
    }

    /**
     * @since 5.39.0
     */
    public function getById(int $id): ?WidgetStyle
    {
        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        return $row ? $this->createFromRow($row) : null;
    }

    /**
     * @since 5.39.0
     */
    public function getByHandle(string $handle): ?WidgetStyle
    {
        $configStyle = $this->getConfigFileByHandle($handle);
        if ($configStyle !== null) {
            return $configStyle;
        }

        $row = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->where(['handle' => $handle])
            ->one();

        return $row ? $this->createFromRow($row) : null;
    }

    /**
     * Get all widget styles (config + database)
     *
     * @since 5.39.0
     */
    public function getAll(?string $type = null, bool $enabledOnly = false): array
    {
        $styles = [];
        $handlesFromConfig = [];

        $configStyles = $this->getConfigFileStyles();
        foreach ($configStyles as $style) {
            if ($type && $style->type !== $type) {
                continue;
            }
            if ($enabledOnly && !$style->enabled) {
                continue;
            }
            $styles[$style->handle] = $style;
            $handlesFromConfig[] = $style->handle;
        }

        $query = (new Query())
            ->select('*')
            ->from(self::TABLE)
            ->orderBy(['name' => SORT_ASC]);

        if ($type) {
            $query->andWhere(['type' => $type]);
        }
        if ($enabledOnly) {
            $query->andWhere(['enabled' => 1]);
        }

        $rows = $query->all();
        foreach ($rows as $row) {
            if (in_array($row['handle'], $handlesFromConfig, true)) {
                continue;
            }
            $style = $this->createFromRow($row);
            $styles[$style->handle] = $style;
        }

        return $styles;
    }

    /**
     * @since 5.39.0
     */
    public function save(WidgetStyle $style): bool
    {
        if ($style->source === 'config') {
            $this->logWarning('Cannot save config-file widget style', ['handle' => $style->handle]);
            return false;
        }

        if (!$style->validate()) {
            return false;
        }

        // Ensure unique handle (append -1, -2, etc. on new styles or handle changes)
        $style->handle = $this->ensureUniqueHandle($style->handle, $style->id);

        $now = Db::prepareDateForDb(new \DateTime());
        $data = $style->prepareForDb();

        if ($style->id) {
            $data['dateUpdated'] = $now;
            Craft::$app->db->createCommand()
                ->update(self::TABLE, $data, ['id' => $style->id])
                ->execute();
        } else {
            $data['dateCreated'] = $now;
            $data['dateUpdated'] = $now;
            Craft::$app->db->createCommand()
                ->insert(self::TABLE, $data)
                ->execute();
            $style->id = (int) Craft::$app->db->getLastInsertID();
        }

        return true;
    }

    /**
     * Ensure a handle is unique by appending -1, -2, etc. if needed
     */
    private function ensureUniqueHandle(string $handle, ?int $excludeId = null): string
    {
        $candidate = $handle;
        $suffix = 0;

        while (true) {
            $query = (new Query())
                ->from(self::TABLE)
                ->where(['handle' => $candidate]);

            if ($excludeId) {
                $query->andWhere(['not', ['id' => $excludeId]]);
            }

            if (!$query->exists()) {
                return $candidate;
            }

            $suffix++;
            $candidate = $handle . '-' . $suffix;
        }
    }

    /**
     * Delete a widget style by ID
     *
     * @since 5.39.0
     */
    public function delete(int $id): bool
    {
        $style = $this->getById($id);
        if (!$style) {
            return false;
        }

        if ($style->source === 'config') {
            $this->logWarning('Cannot delete config-file widget style', ['handle' => $style->handle]);
            return false;
        }

        Craft::$app->db->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute();

        $this->logInfo('Widget style deleted', [
            'id' => $id,
            'handle' => $style->handle,
            'name' => $style->name,
        ]);

        return true;
    }

    /**
     * Get usage counts for all styles (how many widget configs reference each style handle)
     *
     * @return array<string, int> Handle => count
     * @since 5.39.0
     */
    public function getUsageCountsByHandle(): array
    {
        return (new Query())
            ->select(['styleHandle', 'COUNT(*) as cnt'])
            ->from('{{%searchmanager_widget_configs}}')
            ->where(['not', ['styleHandle' => null]])
            ->andWhere(['not', ['styleHandle' => '']])
            ->groupBy(['styleHandle'])
            ->pairs();
    }

    private function createFromRow(array $row): WidgetStyle
    {
        $style = new WidgetStyle();
        $style->id = (int) $row['id'];
        $style->handle = $row['handle'];
        $style->name = $row['name'];
        $style->type = $row['type'] ?? 'modal';
        $style->styles = $row['styles'] ? Json::decodeIfJson($row['styles']) : [];
        $style->enabled = (bool) $row['enabled'];
        $style->dateCreated = $row['dateCreated'] ? new \DateTime($row['dateCreated']) : null;
        $style->dateUpdated = $row['dateUpdated'] ? new \DateTime($row['dateUpdated']) : null;
        $style->uid = $row['uid'] ?? null;

        return $style;
    }
}
