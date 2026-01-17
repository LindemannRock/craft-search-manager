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
            'apiKey' => [
                'type' => 'password',
                'label' => 'API Key',
                'instructions' => 'Meilisearch Master Key',
                'placeholder' => '$MEILISEARCH_API_KEY',
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
                'instructions' => 'Typesense server port',
                'placeholder' => '8108',
                'required' => true,
                'default' => 8108,
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
                'instructions' => 'Redis server host (e.g., redis or localhost)',
                'placeholder' => '$REDIS_HOST or redis',
                'required' => true,
            ],
            'port' => [
                'type' => 'number',
                'label' => 'Port',
                'instructions' => 'Redis server port',
                'placeholder' => '6379',
                'required' => true,
                'default' => 6379,
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
                'instructions' => 'Redis database number',
                'placeholder' => '0',
                'required' => false,
                'default' => 0,
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
            'infoBox' => null,
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
        ];
    }

    /**
     * Validate database backend is compatible with Craft's database driver
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
     */
    public static function findByHandle(string $handle): ?self
    {
        // First, check config file
        $backendConfig = ConfigFileHelper::getConfigByHandle('configuredBackends', $handle);

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
     */
    public static function findAll(): array
    {
        $backends = [];
        $handlesFromConfig = ConfigFileHelper::getHandles('configuredBackends');

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
     */
    public static function findAllFromConfig(): array
    {
        $backends = [];
        $configuredBackends = ConfigFileHelper::getConfiguredBackends();

        foreach ($configuredBackends as $handle => $backendConfig) {
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
     */
    public function delete(): bool
    {
        if (!$this->id) {
            return false;
        }

        try {
            // Check if this is the default backend
            $settings = \lindemannrock\searchmanager\SearchManager::$plugin->getSettings();
            $defaultBackendHandle = $settings->defaultBackendHandle ?? null;

            if ($defaultBackendHandle && $defaultBackendHandle === $this->handle) {
                $this->addError('handle', Craft::t('search-manager', 'Cannot delete the default backend. Change the default backend in Settings first.'));
                return false;
            }

            // Check if any indices are using this backend
            $usageCount = (new Query())
                ->from('{{%searchmanager_indices}}')
                ->where(['backend' => $this->handle])
                ->count();

            if ($usageCount > 0) {
                $this->addError('handle', Craft::t('search-manager', 'Cannot delete: {count} indices are using this backend.', ['count' => $usageCount]));
                return false;
            }

            $result = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_backends}}', ['id' => $this->id])
                ->execute();

            if ($result > 0) {
                $this->logInfo('Backend deleted', ['handle' => $this->handle]);
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

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the display label for this backend's type
     */
    public function getTypeLabel(): string
    {
        return self::BACKEND_TYPES[$this->backendType] ?? $this->backendType;
    }

    /**
     * Get the settings schema for this backend type
     */
    public function getSettingsSchema(): array
    {
        return self::BACKEND_SETTINGS_SCHEMA[$this->backendType] ?? [];
    }

    /**
     * Get a specific setting value
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Check if this backend is properly configured
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
