<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\services\LoggingService;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\traits\ConfigSourceTrait;

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
    use ConfigSourceTrait;

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

    /**
     * @var string|null Handle of configured backend to use - null means use global default from settings
     */
    public ?string $backend = null;

    public bool $enabled = true;

    /**
     * @var bool Whether to track analytics for searches on this index
     */
    public bool $enableAnalytics = true;

    /**
     * @var bool Whether to skip indexing entries that don't have a URL
     */
    public bool $skipEntriesWithoutUrl = false;

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
            [['backend'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
            [['siteId', 'documentCount', 'sortOrder'], 'integer'],
            [['source'], 'in', 'range' => ['config', 'database']],
            [['criteria'], 'safe'],
            [['transformerClass'], 'validateTransformerClass'],
        ];
    }

    /**
     * Validate transformer class exists
     */
    public function validateTransformerClass($attribute): void
    {
        if (empty($this->$attribute)) {
            return; // Null/empty is allowed
        }

        // Check if class exists
        if (!class_exists($this->$attribute)) {
            $this->addError($attribute, "Transformer class does not exist: {$this->$attribute}");
            $this->logWarning('Invalid transformer class in config', [
                'handle' => $this->handle,
                'transformer' => $this->$attribute,
            ]);
        }
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
     * For config indices, config is the source of truth
     */
    public static function findByHandle(string $handle): ?self
    {
        // 1. Check config file FIRST (prevents loading stale database metadata)
        $configData = self::loadConfigForHandle($handle);

        if ($configData) {
            // This is a config index - build from config (source of truth)
            $model = new self();
            $model->handle = $handle;
            $model->name = $configData['name'] ?? $handle;
            $model->elementType = $configData['elementType'] ?? \craft\elements\Entry::class;
            $model->siteId = isset($configData['siteId']) ? (int)$configData['siteId'] : null;
            $model->criteria = $configData['criteria'] ?? [];
            $model->transformerClass = $configData['transformer'] ?? null;
            $model->language = $configData['language'] ?? null;
            $model->backend = $configData['backend'] ?? null;
            $model->enabled = $configData['enabled'] ?? true;
            $model->enableAnalytics = $configData['enableAnalytics'] ?? true;
            $model->skipEntriesWithoutUrl = $configData['skipEntriesWithoutUrl'] ?? false;
            $model->source = 'config';

            // Load stats from database if metadata record exists
            try {
                $metadataRow = (new Query())
                    ->from('{{%searchmanager_indices}}')
                    ->where(['handle' => $handle, 'source' => 'config'])
                    ->one();

                if ($metadataRow) {
                    $model->id = (int)$metadataRow['id'];
                    $model->lastIndexed = self::convertToLocalTime($metadataRow['lastIndexed']);
                    $model->documentCount = (int)$metadataRow['documentCount'];
                }
            } catch (\Throwable $e) {
                LoggingService::log('Failed to load metadata for config index', 'error', 'search-manager', [
                    'handle' => $handle,
                    'error' => $e->getMessage(),
                ]);
            }

            return $model;
        }

        // 2. Not in config - check database for database-source indices
        try {
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->one();

            if ($row) {
                return self::fromRow($row);
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load index from database', 'error', 'search-manager', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);
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
        try {
            $configIndices = ConfigFileHelper::getIndices();
            $indices = [];

            // Fetch ALL config metadata in one query (instead of N queries)
            $allMetadata = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['source' => 'config'])
                ->indexBy('handle')
                ->all();

            foreach ($configIndices as $handle => $indexConfig) {
                $model = new self();
                $model->handle = $handle;
                $model->name = $indexConfig['name'] ?? $handle;
                $model->elementType = $indexConfig['elementType'] ?? \craft\elements\Entry::class;
                $model->siteId = isset($indexConfig['siteId']) ? (int)$indexConfig['siteId'] : null;
                $model->criteria = $indexConfig['criteria'] ?? [];
                $model->transformerClass = $indexConfig['transformer'] ?? null;
                $model->language = $indexConfig['language'] ?? null;
                $model->backend = $indexConfig['backend'] ?? null;
                $model->enabled = $indexConfig['enabled'] ?? true;
                $model->enableAnalytics = $indexConfig['enableAnalytics'] ?? true;
                $model->skipEntriesWithoutUrl = $indexConfig['skipEntriesWithoutUrl'] ?? false;
                $model->source = 'config';

                // Check if database metadata exists for this config index (array lookup)
                if (isset($allMetadata[$handle])) {
                    $metadataRow = $allMetadata[$handle];

                    // Use database metadata for stats
                    $model->id = (int)$metadataRow['id'];
                    $model->lastIndexed = self::convertToLocalTime($metadataRow['lastIndexed']);
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
     * Clear the config cache
     * Useful for testing or when config file changes during runtime
     */
    public static function clearConfigCache(): void
    {
        ConfigFileHelper::clearCache();
    }

    /**
     * Load a specific index configuration by handle (efficient version)
     * Only parses config file once, no database queries
     *
     * @param string $handle Index handle to load
     * @return array|null Config array or null if not found
     */
    private static function loadConfigForHandle(string $handle): ?array
    {
        return ConfigFileHelper::getConfigByHandle('indices', $handle);
    }

    /**
     * Convert UTC datetime string to local timezone
     *
     * @param string|null $utcDateTime UTC datetime string or null
     * @return \DateTime|null Datetime in user's timezone or null
     */
    private static function convertToLocalTime(?string $utcDateTime): ?\DateTime
    {
        if (!$utcDateTime) {
            return null;
        }

        $utcDate = new \DateTime($utcDateTime, new \DateTimeZone('UTC'));
        $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
        return $utcDate;
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
        $model->backend = $row['backend'] ?? null;
        $model->enabled = (bool)$row['enabled'];
        $model->enableAnalytics = (bool)($row['enableAnalytics'] ?? true);
        $model->skipEntriesWithoutUrl = (bool)($row['skipEntriesWithoutUrl'] ?? false);
        $model->source = $row['source'];
        $model->lastIndexed = self::convertToLocalTime($row['lastIndexed']);
        $model->documentCount = (int)$row['documentCount'];
        $model->sortOrder = (int)$row['sortOrder'];

        return $model;
    }

    /**
     * Save index to database
     */
    public function save(): bool
    {
        // Prevent saving config indices - they should only be modified via config file
        if ($this->source === 'config') {
            $this->logError('Cannot save config index - modify config file instead', [
                'handle' => $this->handle,
                'source' => $this->source,
            ]);
            return false;
        }

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
                'backend' => $this->backend ?: null,
                'enabled' => (int)$this->enabled,
                'enableAnalytics' => (int)$this->enableAnalytics,
                'skipEntriesWithoutUrl' => (int)$this->skipEntriesWithoutUrl,
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

        // Prevent deleting config index metadata - remove from config file instead
        if ($this->source === 'config') {
            $this->logError('Cannot delete config index - remove from config file instead', [
                'handle' => $this->handle,
                'source' => $this->source,
            ]);
            return false;
        }

        try {
            // Clear backend storage first (MySQL tables, Redis keys, files, etc.)
            \lindemannrock\searchmanager\SearchManager::$plugin->backend->clearIndex($this->handle);

            // Then delete the database record
            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('Index deleted successfully', [
                    'handle' => $this->handle,
                    'name' => $this->name,
                ]);
            }

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
     * Sync metadata from config file (for config indices)
     * Updates name, transformer, language from config without changing stats
     */
    public function syncMetadataFromConfig(): bool
    {
        if ($this->source !== 'config' || !$this->id) {
            $this->logDebug('Sync skipped - not config or no ID', [
                'source' => $this->source,
                'id' => $this->id,
                'handle' => $this->handle,
            ]);
            return false;
        }

        try {
            // Load fresh config values efficiently (no database queries, targeted config load)
            $configData = self::loadConfigForHandle($this->handle);

            if (!$configData) {
                $this->logError('Config not found for handle', ['handle' => $this->handle]);
                return false;
            }

            // Extract fresh values from config
            $freshName = $configData['name'] ?? $this->handle;
            $freshTransformer = $configData['transformer'] ?? null;
            $freshLanguage = $configData['language'] ?? null;
            $freshEnabled = $configData['enabled'] ?? true;

            // Validate transformer class before syncing
            if ($freshTransformer && !class_exists($freshTransformer)) {
                $this->logError('Invalid transformer class in config', [
                    'handle' => $this->handle,
                    'transformer' => $freshTransformer,
                ]);
                return false;
            }

            $this->logInfo('Syncing metadata from config', [
                'handle' => $this->handle,
                'old_name' => $this->name,
                'new_name' => $freshName,
                'old_transformer' => $this->transformerClass,
                'new_transformer' => $freshTransformer,
            ]);

            Craft::$app->getDb()
                ->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'name' => $freshName,
                        'transformerClass' => $freshTransformer ?: '',
                        'language' => $freshLanguage,
                        'enabled' => (int)$freshEnabled,
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ],
                    ['id' => $this->id]
                )
                ->execute();

            // Update current object with fresh values
            $this->name = $freshName;
            $this->transformerClass = $freshTransformer;
            $this->language = $freshLanguage;
            $this->enabled = $freshEnabled;

            $this->logInfo('Metadata synced successfully', ['handle' => $this->handle]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to sync config metadata', [
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
            // Load fresh config values to avoid saving stale metadata
            $configData = self::loadConfigForHandle($this->handle);

            if (!$configData) {
                $this->logError('Config not found for handle in updateStats', ['handle' => $this->handle]);
                return false;
            }

            // Extract fresh values from config
            $freshName = $configData['name'] ?? $this->handle;
            $freshTransformer = $configData['transformer'] ?? null;
            $freshLanguage = $configData['language'] ?? null;
            $freshEnabled = $configData['enabled'] ?? true;

            // Validate transformer class before updating stats
            if ($freshTransformer && !class_exists($freshTransformer)) {
                $this->logError('Invalid transformer class in config', [
                    'handle' => $this->handle,
                    'transformer' => $freshTransformer,
                ]);
                return false;
            }

            // Check if database record exists for this config index
            $row = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $this->handle, 'source' => 'config'])
                ->one();

            if ($row) {
                // Update existing metadata record - use FRESH config values
                Craft::$app->getDb()
                    ->createCommand()
                    ->update(
                        '{{%searchmanager_indices}}',
                        [
                            'name' => $freshName,
                            'transformerClass' => $freshTransformer ?: '',
                            'language' => $freshLanguage,
                            'enabled' => (int)$freshEnabled,
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
                        'name' => $freshName,
                        'handle' => $this->handle,
                        'elementType' => $this->elementType,
                        'siteId' => $this->siteId,
                        'criteriaJson' => '{}', // Empty - actual criteria is in config
                        'transformerClass' => $freshTransformer ?: '',
                        'language' => $freshLanguage,
                        'enabled' => (int)$freshEnabled,
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

            // Update current object with fresh values
            $this->name = $freshName;
            $this->transformerClass = $freshTransformer;
            $this->language = $freshLanguage;
            $this->enabled = $freshEnabled;
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
     * Increment document count by 1
     * Used when a single element is added to the index
     */
    public static function incrementDocumentCount(string $handle): bool
    {
        return self::adjustDocumentCount($handle, 1);
    }

    /**
     * Decrement document count by 1
     * Used when a single element is removed from the index
     */
    public static function decrementDocumentCount(string $handle): bool
    {
        return self::adjustDocumentCount($handle, -1);
    }

    /**
     * Adjust document count by a delta value
     */
    private static function adjustDocumentCount(string $handle, int $delta): bool
    {
        try {
            $db = Craft::$app->getDb();

            // Use SQL expression to atomically increment/decrement
            // This avoids race conditions when multiple requests update simultaneously
            $result = $db->createCommand()
                ->update(
                    '{{%searchmanager_indices}}',
                    [
                        'documentCount' => new \yii\db\Expression("GREATEST(0, [[documentCount]] + :delta)", [':delta' => $delta]),
                        'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                    ],
                    ['handle' => $handle]
                )
                ->execute();

            return $result > 0;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to adjust document count', 'error', 'search-manager', [
                'handle' => $handle,
                'delta' => $delta,
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
        $config = [
            'name' => $this->name,
            'elementType' => $this->elementType,
            'siteId' => $this->siteId,
            'criteria' => $this->criteria,
            'transformer' => $this->transformerClass,
            'language' => $this->language,
            'enabled' => $this->enabled,
        ];

        // Only include backend if set (optional override)
        if ($this->backend) {
            $config['backend'] = $this->backend;
        }

        return $config;
    }

    /**
     * Check if index is from config file
     */
    public function isFromConfig(): bool
    {
        return $this->source === 'config';
    }

    /**
     * Check if this index has a custom backend override
     */
    public function hasBackendOverride(): bool
    {
        return !empty($this->backend);
    }

    /**
     * Get the effective backend handle for this index
     * Returns the index-specific backend if set, otherwise the global default
     */
    public function getEffectiveBackend(): ?string
    {
        if ($this->backend) {
            return $this->backend;
        }

        // Fall back to global default backend handle
        return \lindemannrock\searchmanager\SearchManager::$plugin->getSettings()->defaultBackendHandle;
    }

    /**
     * Get the effective backend TYPE for this index (e.g., 'algolia', 'mysql', 'meilisearch')
     * Resolves the configured backend handle to its type
     */
    public function getEffectiveBackendType(): ?string
    {
        $backendHandle = $this->getEffectiveBackend();

        if (!$backendHandle) {
            return null;
        }

        // Look up the configured backend to get its type
        $configuredBackend = ConfiguredBackend::findByHandle($backendHandle);
        if ($configuredBackend) {
            return $configuredBackend->backendType;
        }

        // If not found as a configured backend, it might be a legacy backend type directly
        // (for backwards compatibility during migration)
        $validTypes = ['algolia', 'meilisearch', 'typesense', 'mysql', 'pgsql', 'redis', 'file'];
        if (in_array($backendHandle, $validTypes, true)) {
            return $backendHandle;
        }

        return null;
    }

    /**
     * Get the configured backend for this index
     */
    public function getConfiguredBackend(): ?ConfiguredBackend
    {
        $backendHandle = $this->getEffectiveBackend();

        if (!$backendHandle) {
            return null;
        }

        return ConfiguredBackend::findByHandle($backendHandle);
    }

    /**
     * Get raw config display string for config indices
     * Returns a formatted representation of the config file definition
     */
    public function getRawConfigDisplay(): ?string
    {
        if ($this->source !== 'config') {
            return null;
        }

        $configData = self::loadConfigForHandle($this->handle);
        if (!$configData) {
            return null;
        }

        $lines = ["'{$this->handle}' => ["];

        // Name
        if (isset($configData['name'])) {
            $lines[] = "    'name' => '{$configData['name']}',";
        }

        // Element type - shorten the class name
        if (isset($configData['elementType'])) {
            $elementType = $configData['elementType'];
            // Show as ::class syntax for readability
            $shortName = (new \ReflectionClass($elementType))->getShortName();
            $lines[] = "    'elementType' => \\craft\\elements\\{$shortName}::class,";
        }

        // Site ID
        if (isset($configData['siteId'])) {
            $lines[] = "    'siteId' => {$configData['siteId']},";
        }

        // Transformer
        if (!empty($configData['transformer'])) {
            $transformer = $configData['transformer'];
            $lines[] = "    'transformer' => '{$transformer}',";
        }

        // Language
        if (!empty($configData['language'])) {
            $lines[] = "    'language' => '{$configData['language']}',";
        }

        // Criteria - show as closure placeholder if it's a closure
        if (isset($configData['criteria'])) {
            if ($configData['criteria'] instanceof \Closure) {
                $lines[] = "    'criteria' => function(\$query) { ... },";
            } elseif (is_array($configData['criteria']) && !empty($configData['criteria'])) {
                $criteriaJson = json_encode($configData['criteria'], JSON_PRETTY_PRINT);
                $criteriaJson = str_replace("\n", "\n        ", $criteriaJson);
                $lines[] = "    'criteria' => {$criteriaJson},";
            }
        }

        // Enabled
        $enabled = ($configData['enabled'] ?? true) ? 'true' : 'false';
        $lines[] = "    'enabled' => {$enabled},";

        $lines[] = "],";

        return implode("\n", $lines);
    }

    /**
     * Get expected element count based on index criteria
     * Runs the element query with count() to determine how many elements should be indexed
     * Matches the logic in RebuildIndexJob for accurate comparison
     *
     * @return int Expected number of elements matching the index criteria
     */
    public function getExpectedCount(): int
    {
        try {
            // Get the element type class
            $elementType = $this->elementType;
            if (!class_exists($elementType)) {
                $this->logError('Element type class not found', ['elementType' => $elementType]);
                return 0;
            }

            $totalCount = 0;

            // Handle multi-site indices (siteId = null means all sites)
            $sitesToCount = [];
            if ($this->siteId) {
                $sitesToCount[] = $this->siteId;
            } else {
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    $sitesToCount[] = $site->id;
                }
            }

            foreach ($sitesToCount as $siteId) {
                // Create base query matching RebuildIndexJob logic
                /** @var \craft\elements\db\ElementQuery $query */
                $query = $elementType::find()
                    ->siteId((int)$siteId)
                    ->drafts(false)
                    ->revisions(false);

                $this->logDebug('Building expected count query', [
                    'indexHandle' => $this->handle,
                    'indexSiteId' => $this->siteId,
                    'querySiteId' => $siteId,
                ]);

                // Apply criteria
                $hasClosure = false;
                if (!empty($this->criteria)) {
                    // Config indices: criteria is a Closure that returns the modified query
                    if ($this->criteria instanceof \Closure) {
                        $hasClosure = true;
                        $criteriaCallback = $this->criteria;
                        $query = $criteriaCallback($query);
                    } elseif (is_array($this->criteria)) {
                        // Database indices: criteria is an array with section/volume/group filters
                        if ($elementType === \craft\elements\Entry::class && !empty($this->criteria['sections'])) {
                            /** @var \craft\elements\db\EntryQuery $query */
                            $query->section($this->criteria['sections']);
                        }
                        if ($elementType === \craft\elements\Asset::class && !empty($this->criteria['volumes'])) {
                            /** @var \craft\elements\db\AssetQuery $query */
                            $query->volume($this->criteria['volumes']);
                        }
                        if ($elementType === \craft\elements\Category::class && !empty($this->criteria['groups'])) {
                            /** @var \craft\elements\db\CategoryQuery $query */
                            $query->group($this->criteria['groups']);
                        }
                    }
                }

                // For entries, only count live status (matching RebuildIndexJob filtering)
                if ($elementType === \craft\elements\Entry::class) {
                    $query->status(\craft\elements\Entry::STATUS_LIVE);
                }

                // If skipEntriesWithoutUrl is enabled, we need to iterate and check URLs
                if ($this->skipEntriesWithoutUrl) {
                    foreach ($query->all() as $element) {
                        if ($element->url !== null) {
                            $totalCount++;
                        }
                    }
                } elseif ($hasClosure) {
                    // Use ids() for Closure criteria to ensure custom query scopes are properly evaluated
                    // Some custom scopes may not work correctly with count() but work with ids()
                    $ids = $query->ids();
                    $siteCount = count($ids);
                    $totalCount += $siteCount;

                    $this->logDebug('Expected count result (closure)', [
                        'indexHandle' => $this->handle,
                        'siteId' => $siteId,
                        'count' => $siteCount,
                    ]);
                } else {
                    // Use count() for array criteria or no criteria (more efficient for large indices)
                    $siteCount = (int) $query->count();
                    $totalCount += $siteCount;

                    $this->logDebug('Expected count result', [
                        'indexHandle' => $this->handle,
                        'siteId' => $siteId,
                        'count' => $siteCount,
                    ]);
                }
            }

            return $totalCount;
        } catch (\Throwable $e) {
            $this->logError('Failed to get expected count', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
