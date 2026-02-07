<?php

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\ConfigFileHelper;
use lindemannrock\searchmanager\traits\ConfigSourceTrait;

/**
 * Configured Backend Model
 *
 * Represents a configured backend instance (e.g., "Production Algolia", "Mobile Typesense")
 * Stores credentials and settings for a specific backend deployment
 *
 * @since 5.28.0
 */
class ConfiguredBackend extends Model
{
    use LoggingTrait;
    use ConfigSourceTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';

    /**
     * @var string Backend type (algolia, meilisearch, typesense, mysql, pgsql, redis, file)
     */
    public string $backendType = '';

    /**
     * @var array Backend-specific settings (credentials, hosts, etc.)
     */
    public array $settings = [];

    public bool $enabled = true;
    public int $sortOrder = 0;

    public ?\DateTime $dateCreated = null;
    public ?\DateTime $dateUpdated = null;

    /**
     * Available backend types and their labels
     */
    public const BACKEND_TYPES = [
        'algolia' => 'Algolia',
        'meilisearch' => 'Meilisearch',
        'typesense' => 'Typesense',
        'mysql' => 'MySQL',
        'pgsql' => 'PostgreSQL',
        'redis' => 'Redis',
        'file' => 'File',
    ];

    /**
     * Settings schema for each backend type
     * Includes instructions, placeholders, and env var suggestions
     */
    public const BACKEND_SETTINGS_SCHEMA = [
        'algolia' => [
            'applicationId' => [
                'type' => 'text',
                'label' => 'Application ID',
                'instructions' => 'Your Algolia Application ID',
                'placeholder' => '$ALGOLIA_APPLICATION_ID',
                'required' => true,
            ],
            'adminApiKey' => [
                'type' => 'password',
                'label' => 'Admin API Key',
                'instructions' => 'Your Algolia Admin API Key (for indexing)',
                'placeholder' => '$ALGOLIA_ADMIN_API_KEY',
                'required' => true,
            ],
            'searchApiKey' => [
                'type' => 'password',
                'label' => 'Search API Key',
                'instructions' => 'Your Algolia Search-Only API Key (for frontend)',
                'placeholder' => '$ALGOLIA_SEARCH_API_KEY',
                'required' => false,
            ],
        ],
        'meilisearch' => [
            'host' => [
                'type' => 'text',
                'label' => 'Host',
                'instructions' => 'Meilisearch server URL',
                'placeholder' => '$MEILISEARCH_HOST or http://localhost:7700',
                'required' => true,
            ],
            'adminApiKey' => [
                'type' => 'password',
                'label' => 'Admin API Key',
                'instructions' => 'Meilisearch Admin/Master Key (for indexing). Required for write operations.',
                'placeholder' => '$MEILISEARCH_ADMIN_API_KEY',
                'required' => false,
            ],
            'searchApiKey' => [
                'type' => 'password',
                'label' => 'Search API Key',
                'instructions' => 'Meilisearch Search-Only API Key (for frontend). If not set, Admin Key is used for search.',
                'placeholder' => '$MEILISEARCH_SEARCH_API_KEY',
                'required' => false,
            ],
        ],
        'typesense' => [
            'host' => [
                'type' => 'text',
                'label' => 'Host',
                'instructions' => 'Typesense server host',
                'placeholder' => '$TYPESENSE_HOST or localhost',
                'required' => true,
            ],
            'port' => [
                'type' => 'number',
                'label' => 'Port',
                'instructions' => 'Typesense server port (default: 8108)',
                'placeholder' => '$TYPESENSE_PORT or 8108',
                'required' => true,
            ],
            'protocol' => [
                'type' => 'select',
                'label' => 'Protocol',
                'instructions' => 'Connection protocol',
                'required' => true,
                'options' => ['http' => 'HTTP', 'https' => 'HTTPS'],
                'default' => 'http',
            ],
            'apiKey' => [
                'type' => 'password',
                'label' => 'API Key',
                'instructions' => 'Typesense API Key',
                'placeholder' => '$TYPESENSE_API_KEY',
                'required' => true,
            ],
        ],
        'redis' => [
            'host' => [
                'type' => 'text',
                'label' => 'Host',
                'instructions' => 'Redis server host. Leave empty to use Craft\'s Redis cache settings.',
                'placeholder' => '$REDIS_HOST or leave empty',
                'required' => false,
            ],
            'port' => [
                'type' => 'number',
                'label' => 'Port',
                'instructions' => 'Redis server port. Leave empty to use Craft\'s Redis settings or default (6379).',
                'placeholder' => '$REDIS_PORT or leave empty',
                'required' => false,
            ],
            'password' => [
                'type' => 'password',
                'label' => 'Password',
                'instructions' => 'Redis password (leave empty if no password required)',
                'placeholder' => '$REDIS_PASSWORD or leave empty',
                'required' => false,
            ],
            'database' => [
                'type' => 'number',
                'label' => 'Database',
                'instructions' => 'Redis database number. When using Craft\'s Redis settings, defaults to Craft database + 1 to isolate search data.',
                'placeholder' => 'Leave empty for auto',
                'required' => false,
            ],
        ],
        'mysql' => [],
        'pgsql' => [],
        'file' => [
            'storagePath' => [
                'type' => 'text',
                'label' => 'Storage Path',
                'instructions' => 'Custom storage path (leave empty for @storage/runtime/search-manager/indices/)',
                'placeholder' => 'Leave empty for default',
                'required' => false,
                'tip' => 'Use Craft path aliases: <code>@storage/search-manager/indices</code> (recommended) or <code>@root/search-indices</code>. Paths must be outside webroot for security. Environment variables like <code>$ENV_VAR</code> are supported.',
            ],
        ],
    ];

    /**
     * Backend descriptions shown in the edit form
     */
    public const BACKEND_DESCRIPTIONS = [
        'algolia' => [
            'title' => 'Algolia Configuration',
            'description' => 'Get your API keys from your <a href="https://www.algolia.com/dashboard" target="_blank" rel="noopener">Algolia Dashboard</a>',
            'infoBox' => null,
        ],
        'meilisearch' => [
            'title' => 'Meilisearch Configuration',
            'description' => 'Self-hosted open-source search engine. <a href="https://www.meilisearch.com/docs" target="_blank" rel="noopener">Meilisearch Documentation</a>',
            'infoBox' => '<strong>Quick Start:</strong> <code>docker run -d -p 7700:7700 getmeili/meilisearch:latest</code>',
        ],
        'typesense' => [
            'title' => 'Typesense Configuration',
            'description' => 'Open-source search engine with typo tolerance. <a href="https://typesense.org/docs/" target="_blank" rel="noopener">Typesense Documentation</a>',
            'infoBox' => '<strong>Quick Start:</strong> <code>docker run -d -p 8108:8108 typesense/typesense:latest</code>',
        ],
        'redis' => [
            'title' => 'Redis Configuration',
            'description' => 'In-memory search storage. Requires Redis server and PHP Redis extension. <a href="https://redis.io/docs/getting-started/" target="_blank" rel="noopener">Redis Documentation</a>',
            'infoBox' => '<strong>Important:</strong> When no host is configured, Search Manager uses Craft\'s Redis cache settings but stores data in a separate database (Craft database + 1) to prevent data loss when Craft cache is cleared.',
        ],
        'mysql' => [
            'title' => 'MySQL Configuration',
            'description' => 'Uses Craft\'s MySQL database with BM25 search algorithm. No additional configuration required.',
            'infoBox' => null,
        ],
        'pgsql' => [
            'title' => 'PostgreSQL Configuration',
            'description' => 'Uses Craft\'s PostgreSQL database with BM25 search algorithm. No additional configuration required.',
            'infoBox' => null,
        ],
        'file' => [
            'title' => 'File Backend Configuration',
            'description' => 'File backend stores search indices as JSON files. Ideal for simple setups or development.',
            'infoBox' => '<strong>Default Storage:</strong> <code>@storage/runtime/search-manager/indices/</code>',
        ],
    ];

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
            [['name', 'handle', 'backendType'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/'],
            [['handle'], 'validateUniqueHandle'],
            [['backendType'], 'in', 'range' => array_keys(self::BACKEND_TYPES)],
            [['backendType'], 'validateDatabaseBackend'],
            [['enabled'], 'boolean'],
            [['enabled'], 'validateNotDisablingDefault'],
            [['sortOrder'], 'integer'],
            [['settings'], 'safe'],
            [['settings'], 'validateStoragePath'],
        ];
    }

    /**
     * Validate storagePath setting against directory traversal and allowed locations
     *
     * @param string $attribute
     */
    public function validateStoragePath(string $attribute): void
    {
        $storagePath = $this->settings['storagePath'] ?? null;

        if ($storagePath === null || $storagePath === '') {
            return;
        }

        // Only validate for file backends
        if ($this->backendType !== 'file') {
            return;
        }

        // Check for directory traversal
        if (str_contains($storagePath, '..')) {
            $this->addError($attribute, Craft::t('search-manager', 'Storage path cannot contain directory traversal sequences (..).'));
            return;
        }

        // Must start with an allowed alias or env variable
        $allowedPrefixes = ['@root', '@storage', '$'];
        $isValid = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($storagePath, $prefix)) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            $this->addError($attribute, Craft::t('search-manager', 'Storage path must start with @root, @storage, or an environment variable ($). Example: @storage/search-manager/indices'));
            return;
        }

        // Resolve and validate the path
        try {
            $resolvedPath = Craft::getAlias($storagePath);
            $webroot = Craft::getAlias('@webroot');

            // Prevent storage in web-accessible directory
            if (str_starts_with($resolvedPath, $webroot)) {
                $this->addError($attribute, Craft::t('search-manager', 'Storage path cannot be in a web-accessible directory (@webroot).'));
            }
        } catch (\Exception $e) {
            $this->addError($attribute, Craft::t('search-manager', 'Invalid storage path: {error}', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Validate database backend is compatible with Craft's database driver
     *
     * @param string $attribute
     * @since 5.28.0
     */
    public function validateDatabaseBackend(string $attribute): void
    {
        $dbDriver = Craft::$app->getDb()->getDriverName();

        if ($this->backendType === 'mysql' && $dbDriver !== 'mysql') {
            $this->addError($attribute, Craft::t('search-manager', 'MySQL backend requires Craft to use MySQL. Your installation uses PostgreSQL.'));
        }

        if ($this->backendType === 'pgsql' && $dbDriver !== 'pgsql') {
            $this->addError($attribute, Craft::t('search-manager', 'PostgreSQL backend requires Craft to use PostgreSQL. Your installation uses MySQL.'));
        }
    }

    /**
     * Validate that the default backend cannot be disabled
     *
     * @param string $attribute
     * @since 5.28.0
     */
    public function validateNotDisablingDefault(string $attribute): void
    {
        // Only check if we're disabling (enabled = false)
        if ($this->enabled) {
            return;
        }

        // Check if this backend is the default
        $settings = \lindemannrock\searchmanager\SearchManager::$plugin->getSettings();
        $defaultBackendHandle = $settings->defaultBackendHandle ?? null;

        if ($defaultBackendHandle && $defaultBackendHandle === $this->handle) {
            $this->addError($attribute, Craft::t('search-manager', 'Cannot disable the default backend. Change the default backend in Settings first.'));
        }
    }

    /**
     * Validate handle is unique
     *
     * @param string $attribute
     * @since 5.28.0
     */
    public function validateUniqueHandle(string $attribute): void
    {
        $query = (new Query())
            ->from('{{%searchmanager_backends}}')
            ->where(['handle' => $this->handle]);

        if ($this->id) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError($attribute, 'Handle must be unique.');
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Find backend by ID
     *
     * @param int $id
     * @return self|null
     * @since 5.28.0
     */
    public static function findById(int $id): ?self
    {
        try {
            $row = (new Query())
                ->from('{{%searchmanager_backends}}')
                ->where(['id' => $id])
                ->one();

            return $row ? self::fromRow($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find backend by handle
     *
     * @param string $handle
     * @return self|null
     * @since 5.28.0
     */
    public static function findByHandle(string $handle): ?self
    {
        // First, check config file
        $backendConfig = ConfigFileHelper::getConfigByHandle('backends', $handle);

        if ($backendConfig !== null) {
            return self::createFromConfig($handle, $backendConfig);
        }

        // Then, check database
        try {
            $row = (new Query())
                ->from('{{%searchmanager_backends}}')
                ->where(['handle' => $handle])
                ->one();

            return $row ? self::fromRow($row) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get all configured backends
     *
     * @return self[]
     * @since 5.28.0
     */
    public static function findAll(): array
    {
        $backends = [];
        $handlesFromConfig = ConfigFileHelper::getHandles('backends');

        // First, load backends from config file
        $configBackends = self::findAllFromConfig();
        foreach ($configBackends as $backend) {
            $backends[$backend->handle] = $backend;
        }

        // Then, load backends from database (excluding those defined in config)
        try {
            $rows = (new Query())
                ->from('{{%searchmanager_backends}}')
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($rows as $row) {
                // Skip if this handle is already defined in config
                if (in_array($row['handle'], $handlesFromConfig, true)) {
                    continue;
                }
                $backends[$row['handle']] = self::fromRow($row);
            }
        } catch (\Throwable $e) {
            Craft::error('ConfiguredBackend::findAll() error: ' . $e->getMessage(), 'search-manager');
        }

        return array_values($backends);
    }

    /**
     * Get all backends defined in config file
     *
     * @return self[]
     * @since 5.28.0
     */
    public static function findAllFromConfig(): array
    {
        $backends = [];
        $backendConfigs = ConfigFileHelper::getConfiguredBackends();

        foreach ($backendConfigs as $handle => $backendConfig) {
            $backends[] = self::createFromConfig($handle, $backendConfig);
        }

        return $backends;
    }

    /**
     * Create a model from config file data
     */
    private static function createFromConfig(string $handle, array $config): self
    {
        $model = new self();
        $model->handle = $handle;
        $model->name = $config['name'] ?? ucfirst($handle);
        $model->backendType = $config['backendType'] ?? '';
        $model->settings = $config['settings'] ?? [];
        $model->enabled = $config['enabled'] ?? true;
        $model->sortOrder = $config['sortOrder'] ?? 0;
        $model->source = 'config';
        return $model;
    }

    /**
     * Get all enabled configured backends
     *
     * @return self[]
     * @since 5.28.0
     */
    public static function findAllEnabled(): array
    {
        return array_filter(self::findAll(), fn($b) => $b->enabled);
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
        $model->backendType = $row['backendType'];

        // Handle JSON settings - ensure we always get an array
        // Settings could be: JSON string, already-decoded array, or null
        $settings = [];
        if (!empty($row['settings'])) {
            if (is_array($row['settings'])) {
                // Already decoded (MySQL JSON column with PDO)
                $settings = $row['settings'];
            } elseif (is_string($row['settings'])) {
                $decoded = json_decode($row['settings'], true);
                if (is_array($decoded)) {
                    $settings = $decoded;
                }
            }
        }
        $model->settings = $settings;

        $model->enabled = (bool)$row['enabled'];
        $model->sortOrder = (int)$row['sortOrder'];

        if (!empty($row['dateCreated'])) {
            $model->dateCreated = new \DateTime($row['dateCreated']);
        }
        if (!empty($row['dateUpdated'])) {
            $model->dateUpdated = new \DateTime($row['dateUpdated']);
        }

        return $model;
    }

    /**
     * Save backend to database
     *
     * @return bool
     * @since 5.28.0
     */
    public function save(): bool
    {
        if (!$this->validate()) {
            $this->logError('Backend validation failed', [
                'handle' => $this->handle ?? 'unknown',
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            // Don't json_encode settings - the JSON column handles it,
            // or if TEXT column, we need to encode. Check column type.
            // Using json_encode for TEXT compatibility, but MySQL JSON column
            // may double-encode. Use Db::prepareValueForDb for safety.
            $settingsValue = !empty($this->settings) ? json_encode($this->settings) : null;

            $attributes = [
                'name' => $this->name,
                'handle' => $this->handle,
                'backendType' => $this->backendType,
                'settings' => $settingsValue,
                'enabled' => (int)$this->enabled,
                'sortOrder' => $this->sortOrder,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ];

            if ($this->id) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->update('{{%searchmanager_backends}}', $attributes, ['id' => $this->id])
                    ->execute();
            } else {
                $attributes['dateCreated'] = Db::prepareDateForDb(new \DateTime());
                $attributes['uid'] = StringHelper::UUID();

                Craft::$app->getDb()
                    ->createCommand()
                    ->insert('{{%searchmanager_backends}}', $attributes)
                    ->execute();

                $this->id = (int)Craft::$app->getDb()->getLastInsertID();
            }

            $this->logInfo('Backend saved', ['handle' => $this->handle]);
            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to save backend', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete backend from database
     *
     * @return bool
     * @since 5.28.0
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            // Check if any indices are using this backend
            $usageCount = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['backend' => $this->handle])
                ->count();

            if ($usageCount > 0) {
                $this->addError('handle', Craft::t('search-manager', 'Cannot delete: {count} indices are using this backend.', ['count' => $usageCount]));
                return false;
            }

            // Check if this is the default backend
            $plugin = \lindemannrock\searchmanager\SearchManager::$plugin;
            $settings = $plugin->getSettings();
            $isDefault = ($settings->defaultBackendHandle ?? null) === $this->handle;

            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_backends}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('Backend deleted', ['handle' => $this->handle]);

                // If we deleted the default, auto-assign another backend as default
                if ($isDefault) {
                    $this->_reassignDefaultBackend($plugin, $settings);
                }
            }

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete backend', [
                'id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reassign default backend after deletion
     */
    private function _reassignDefaultBackend($plugin, $settings): void
    {
        // First, check for enabled config file backends
        $configBackends = self::findAllFromConfig();
        foreach ($configBackends as $backend) {
            if ($backend->enabled) {
                $settings->defaultBackendHandle = $backend->handle;
                $settings->saveToDatabase();
                $this->logInfo('Auto-assigned new default backend after deletion', ['handle' => $backend->handle]);
                return;
            }
        }

        // Then, check for enabled database backends
        $row = (new Query())
            ->select('handle')
            ->from('{{%searchmanager_backends}}')
            ->where(['enabled' => 1])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->one();

        if ($row) {
            $settings->defaultBackendHandle = $row['handle'];
            $settings->saveToDatabase();
            $this->logInfo('Auto-assigned new default backend after deletion', ['handle' => $row['handle']]);
        } else {
            // No enabled backends left - clear the default
            $settings->defaultBackendHandle = null;
            $settings->saveToDatabase();
            $this->logWarning('No enabled backends available to set as default');
        }
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the display label for this backend's type
     *
     * @return string
     * @since 5.28.0
     */
    public function getTypeLabel(): string
    {
        return self::BACKEND_TYPES[$this->backendType] ?? $this->backendType;
    }

    /**
     * Get the settings schema for this backend type
     *
     * @return array
     * @since 5.28.0
     */
    public function getSettingsSchema(): array
    {
        return self::BACKEND_SETTINGS_SCHEMA[$this->backendType] ?? [];
    }

    /**
     * Get a specific setting value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     * @since 5.28.0
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Check if this backend is properly configured
     *
     * @return bool
     * @since 5.28.0
     */
    public function isConfigured(): bool
    {
        $schema = $this->getSettingsSchema();

        foreach ($schema as $key => $config) {
            if (($config['required'] ?? false) && empty($this->settings[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get options array for select dropdowns
     *
     * @param bool $includeDefault
     * @return array
     * @since 5.28.0
     */
    public static function getSelectOptions(bool $includeDefault = true): array
    {
        $options = [];

        if ($includeDefault) {
            $defaultBackendHandle = \lindemannrock\searchmanager\SearchManager::$plugin->getSettings()->defaultBackendHandle;
            $defaultLabel = 'None';

            if ($defaultBackendHandle) {
                // Look up the configured backend to get its name
                $defaultBackend = self::findByHandle($defaultBackendHandle);
                if ($defaultBackend) {
                    $defaultLabel = $defaultBackend->name;
                } else {
                    // Fallback to handle if backend not found
                    $defaultLabel = $defaultBackendHandle;
                }
            }

            $options[''] = "Default ({$defaultLabel})";
        }

        foreach (self::findAllEnabled() as $backend) {
            $options[$backend->handle] = $backend->name . ' (' . $backend->getTypeLabel() . ')';
        }

        return $options;
    }

    /**
     * Get raw config display for showing in tooltip (config backends only)
     *
     * @return string
     * @since 5.28.0
     */
    public function getRawConfigDisplay(): string
    {
        if (!$this->isFromConfig()) {
            return '';
        }

        $config = [
            'name' => $this->name,
            'backendType' => $this->backendType,
            'enabled' => $this->enabled,
        ];

        if (!empty($this->settings)) {
            $config['settings'] = $this->settings;
        }

        $sensitiveKeys = ['apiKey', 'adminApiKey', 'searchApiKey', 'password'];
        return $this->formatConfigDisplay($config, $this->handle, $sensitiveKeys);
    }
}
