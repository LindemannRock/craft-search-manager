<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\services\LoggingService;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Search Index Model
 *
 * Represents a search index configuration
 * Can be defined in config file OR database (hybrid approach)
 * Database-backed model ({{%searchmanager_indices}} table)
 */
class SearchIndex extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $elementType = '';
    public ?int $siteId = null;

    /**
     * @var array|\Closure Decoded from criteriaJson (array) or callable from config (Closure)
     */
    public array|\Closure $criteria = [];

    public ?string $transformerClass = null;

    /**
     * @var string|null Language code (en, ar, fr, es, de) - null = auto-detect from site
     */
    public ?string $language = null;

    public bool $enabled = true;

    /**
     * @var string Source (config|database)
     */
    public string $source = 'database';
    public ?\DateTime $lastIndexed = null;
    public int $documentCount = 0;
    public int $sortOrder = 0;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    public function rules(): array
    {
        return [
            [['name', 'handle', 'elementType'], 'required'],
            [['name', 'handle', 'elementType', 'transformerClass'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z0-9_-]+$/'],
            [['language'], 'string', 'max' => 10],
            [['language'], 'match', 'pattern' => '/^[a-z]{2}(-[a-z]{2})?$/i', 'skipOnEmpty' => true, 'message' => 'Language must be a valid language code (e.g., en, ar, fr-ca)'],
            [['enabled'], 'boolean'],
            [['siteId', 'documentCount', 'sortOrder'], 'integer'],
            [['source'], 'in', 'range' => ['config', 'database']],
            [['criteria'], 'safe'],
        ];
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find index by ID
     */
    public static function findById(int $id): ?self
    {
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['id' => $id])
                ->one();

            if (!$row) {
                return null;
            }

            return self::fromRow($row);
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find index by handle
     */
    public static function findByHandle(string $handle): ?self
    {
        // 1. Check database first
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->one();

            if ($row) {
                return self::fromRow($row);
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index from database', 'error', 'search-manager', ['error' => $e->getMessage()]);
        }

        // 2. Check config file
        $configIndices = self::loadFromConfig();
        foreach ($configIndices as $index) {
            if ($index->handle === $handle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Get all indices (database + config)
     */
    public static function findAll(): array
    {
        $indices = [];

        // 1. Load database indices (only source='database')
        try {
            $rows = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['source' => 'database'])
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                $indices[] = self::fromRow($row);
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load database indices', 'error', 'search-manager', ['error' => $e->getMessage()]);
        }

        // 2. Load config file indices (these are the source of truth)
        // Database metadata for config indices is only used for stats
        $configIndices = self::loadFromConfig();
        foreach ($configIndices as $configIndex) {
            // Check for handle collision with database indices
            $collision = false;
            foreach ($indices as $dbIndex) {
                if ($dbIndex->handle === $configIndex->handle) {
                    $collision = true;
                    LoggingService::log(
                        'Index handle collision: handle exists in both database and config. Database index takes precedence.',
                        'warning',
                        'search-manager',
                        ['handle' => $configIndex->handle]
                    );
                    break;
                }
            }

            // Only add config index if no collision
            if (!$collision) {
                $indices[] = $configIndex;
            }
        }

        return $indices;
    }

    /**
     * Load indices from config file
     */
    public static function loadFromConfig(): array
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (!file_exists($configPath)) {
            return [];
        }

        try {
            $config = require $configPath;
            $env = Craft::$app->getConfig()->env;

            // Merge environment config
            $mergedConfig = $config['*'] ?? [];
            if ($env && isset($config[$env])) {
                $mergedConfig = array_merge($mergedConfig, $config[$env]);
            }

            $configIndices = $mergedConfig['indices'] ?? [];
            $indices = [];

            foreach ($configIndices as $handle => $indexConfig) {
                $model = new self();
                $model->handle = $handle;
                $model->name = $indexConfig['name'] ?? $handle;
                $model->elementType = $indexConfig['elementType'] ?? \craft\elements\Entry::class;
                $model->siteId = $indexConfig['siteId'] ?? null;
                $model->criteria = $indexConfig['criteria'] ?? [];
                $model->transformerClass = $indexConfig['transformer'] ?? null;
                $model->language = $indexConfig['language'] ?? null;
                $model->enabled = $indexConfig['enabled'] ?? true;
                $model->source = 'config';

                // Check if database metadata exists for this config index
                $metadataRow = (new Query())
                    ->from('{{%searchmanager_indices}}')
                    ->where(['handle' => $handle, 'source' => 'config'])
                    ->one();

                if ($metadataRow) {
                    // Use database metadata for stats
                    $model->id = (int)$metadataRow['id'];
                    // Convert from UTC to user's timezone
                    if ($metadataRow['lastIndexed']) {
                        $utcDate = new \DateTime($metadataRow['lastIndexed'], new \DateTimeZone('UTC'));
                        $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
                        $model->lastIndexed = $utcDate;
                    } else {
                        $model->lastIndexed = null;
                    }
                    $model->documentCount = (int)$metadataRow['documentCount'];
                } else {
                    // No metadata yet - will be created on first rebuild
                    $model->lastIndexed = null;
                    $model->documentCount = 0;
                }

                $indices[] = $model;
            }

            return $indices;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load config indices', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Create model from database row
     */
    private static function fromRow(array $row): self
    {
        $model = new self();
        $model->id = (int)$row['id'];
        $model->name = $row['name'];
        $model->handle = $row['handle'];
        $model->elementType = $row['elementType'];
        $model->siteId = $row['siteId'] ? (int)$row['siteId'] : null;
        $model->criteria = json_decode($row['criteriaJson'], true) ?? [];
        $model->transformerClass = $row['transformerClass'];
        $model->language = $row['language'] ?? null;
        $model->enabled = (bool)$row['enabled'];
        $model->source = $row['source'];
        // Convert from UTC to user's timezone
        if ($row['lastIndexed']) {
            $utcDate = new \DateTime($row['lastIndexed'], new \DateTimeZone('UTC'));
            $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
            $model->lastIndexed = $utcDate;
        } else {
            $model->lastIndexed = null;
        }
        $model->documentCount = (int)$row['documentCount'];
        $model->sortOrder = (int)$row['sortOrder'];

        return $model;
    }

    /**
     * Save index to database
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('Index validation failed', [
                'handle' => $this->handle ?? 'unknown',
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $attributes = [
                'name' => $this->name,
                'handle' => $this->handle,
                'elementType' => $this->elementType,
                'siteId' => $this->siteId,
                'criteriaJson' => json_encode($this->criteria),
                'transformerClass' => $this->transformerClass,
                'language' => $this->language,
                'enabled' => (int)$this->enabled,
                'source' => $this->source,
                'lastIndexed' => $this->lastIndexed ? Db::prepareDateForDb($this->lastIndexed) : null,
                'documentCount' => $this->documentCount,
                'sortOrder' => $this->sortOrder,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                // Update existing
                $result = Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_indices}}', $attributes, ['id' => $this->id])
                    ->execute();

                return $result !== false;
            } else {
                // Insert new
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_indices}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();

                return true;
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to save index', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete index from database
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $this->id])
                ->execute();

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete index', [
                'id' => $this->id,
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update last indexed timestamp and document count
     */
    public function updateStats(int $documentCount): bool
    {
        // Config indices: create/update database record for stats only
        if (!$this->id && $this->source === 'config') {
            // Check if database record exists for this config index
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $this->handle, 'source' => 'config'])
                ->one();

            if ($row) {
                // Update existing metadata record
                Craft::$app->getDb()
                    ->createCommand()
                    ->update(
                        '{{%searchmanager_indices}}',
                        [
                            'lastIndexed' => Db::prepareDateForDb(new \DateTime()),
                            'documentCount' => $documentCount,
                            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        ],
                        ['id' => $row['id']]
                    )
                    ->execute();
            } else {
                // Create new metadata record for config index
                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_indices}}', [
                        'name' => $this->name,
                        'handle' => $this->handle,
                        'elementType' => $this->elementType,
                        'siteId' => $this->siteId,
                        'criteriaJson' => '{}', // Empty - actual criteria is in config
                        'transformerClass' => $this->transformerClass ?: '', // Empty string if null
                        'language' => $this->language,
                        'enabled' => (int)$this->enabled,
                        'source' => 'config',
                        'lastIndexed' => Db::prepareDateForDb(new \DateTime()),
                        'documentCount' => $documentCount,
                        'sortOrder' => 999,
                        'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])
                    ->execute();
            }

            $this->lastIndexed = new \DateTime();
            $this->documentCount = $documentCount;
            return true;
        }

        // Database indices: save stats to database
        try {
            $this->lastIndexed = new \DateTime();
            $this->documentCount = $documentCount;

            $result = Craft::$app->getDb()
                ->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'lastIndexed' => Db::prepareDateForDb($this->lastIndexed),
                        'documentCount' => $this->documentCount,
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ],
                    ['id' => $this->id]
                )
                ->execute();

            return $result !== false;
        } catch (\Throwable $e) {
            $this->logError('Failed to update index stats', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Convert to config array format (for export)
     */
    public function toConfigArray(): array
    {
        return [
            'name' => $this->name,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'criteria' => $this->criteria,
            'transformer' => $this->transformerClass,
            'language' => $this->language,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * Check if index is from config file
     */
    public function isFromConfig(): bool
    {
        return $this->source === 'config';
    }

    /**
     * Check if index can be edited
     */
    public function canEdit(): bool
    {
        return $this->source === 'database';
    }
}
