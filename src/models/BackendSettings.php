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
 * Backend Settings Model
 *
 * Stores configuration for each search backend (Algolia, Meilisearch, MySQL, Typesense)
 * Database-backed model ({{%searchmanager_backend_settings}} table)
 */
class BackendSettings extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public ?int $id = null;

    /**
     * @var string Backend type (algolia|meilisearch|mysql|typesense)
     */
    public string $backend;

    public bool $enabled = false;

    /**
     * @var array Decoded from configJson
     */
    public array $config = [];

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
            [['backend'], 'required'],
            [['backend'], 'in', 'range' => ['algolia', 'file', 'meilisearch', 'mysql', 'pgsql', 'redis', 'typesense']],
            [['enabled'], 'boolean'],
            [['config'], 'safe'],
            [['config'], 'validateBackendConfig'],
        ];
    }

    /**
     * Validate backend-specific configuration
     */
    public function validateBackendConfig($attribute, $params): void
    {
        if ($this->backend === 'algolia') {
            if (empty($this->config['applicationId'])) {
                $this->addError('applicationId', 'Application ID cannot be blank');
            }
            if (empty($this->config['adminApiKey'])) {
                $this->addError('apiKey', 'Admin API Key cannot be blank');
            }
        } elseif ($this->backend === 'meilisearch') {
            if (empty($this->config['host'])) {
                $this->addError('host', 'Host cannot be blank');
            }
            if (empty($this->config['apiKey'])) {
                $this->addError('apiKey', 'API Key cannot be blank');
            }
        } elseif ($this->backend === 'typesense') {
            if (empty($this->config['host'])) {
                $this->addError('host', 'Host is required');
            }
            if (empty($this->config['apiKey'])) {
                $this->addError('apiKey', 'API Key is required');
            }
        } elseif ($this->backend === 'redis') {
            $craftUsesRedis = Craft::$app->cache instanceof \yii\redis\Cache;
            $hasHost = !empty($this->config['host']);
            $hasPort = !empty($this->config['port']);
            $hasDatabase = isset($this->config['database']) && $this->config['database'] !== '';

            // If Craft Redis available, all fields are optional (can leave empty to use Craft's)
            if (!$craftUsesRedis) {
                // No Craft Redis - dedicated connection required, all fields must be filled
                if (!$hasHost) {
                    $this->addError('host', 'Host is required (or configure Craft to use Redis cache)');
                }
                if (!$hasPort) {
                    $this->addError('port', 'Port is required (or configure Craft to use Redis cache)');
                }
                if (!$hasDatabase) {
                    $this->addError('database', 'Database is required (or configure Craft to use Redis cache)');
                }
            } elseif ($hasHost || $hasPort || $hasDatabase) {
                // Craft Redis available but user filled some fields - all must be filled for dedicated connection
                if (!$hasHost) {
                    $this->addError('host', 'Host is required when using dedicated Redis connection');
                }
                if (!$hasPort) {
                    $this->addError('port', 'Port is required when using dedicated Redis connection');
                }
                if (!$hasDatabase) {
                    $this->addError('database', 'Database is required when using dedicated Redis connection');
                }
            }
            // Password is always optional (can be empty/null)
        }
    }

    // =========================================================================
    // CONFIG FILE OVERRIDE DETECTION
    // =========================================================================

    /**
     * Check if a backend config field is overridden by config file
     *
     * @param string $field The config field name (e.g., 'host', 'apiKey')
     * @return bool
     */
    public function isOverriddenByConfig(string $field): bool
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        if (!file_exists($configPath)) {
            return false;
        }

        try {
            $rawConfig = require $configPath;
            $env = Craft::$app->getConfig()->env;

            // Merge environment config
            $mergedConfig = $rawConfig['*'] ?? [];
            if ($env && isset($rawConfig[$env])) {
                $mergedConfig = array_merge($mergedConfig, $rawConfig[$env]);
            }

            // Check backends.{backendName}.{field}
            $backends = $mergedConfig['backends'] ?? [];
            $backendConfig = $backends[$this->backend] ?? [];

            return array_key_exists($field, $backendConfig);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // =========================================================================
    // DATABASE OPERATIONS
    // =========================================================================

    /**
     * Load backend settings from database with config file override
     *
     * @param string $backend Backend name
     * @return self
     */
    public static function loadFromDatabase(string $backend): self
    {
        $settings = new self();
        $settings->backend = $backend;

        try {
            $row = (new Query())
                ->from('{{%searchmanager_backend_settings}}')
                ->where(['backend' => $backend])
                ->one();

            if ($row) {
                $settings->id = (int)$row['id'];
                $settings->enabled = (bool)$row['enabled'];
                $settings->config = json_decode($row['configJson'], true) ?? [];
            }
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load backend settings from database', 'error', 'search-manager', [
                'backend' => $backend,
                'error' => $e->getMessage(),
            ]);
        }

        // Apply config file overrides
        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';
        if (file_exists($configPath)) {
            try {
                $rawConfig = require $configPath;
                $env = Craft::$app->getConfig()->env;

                $mergedConfig = $rawConfig['*'] ?? [];
                if ($env && isset($rawConfig[$env])) {
                    $mergedConfig = array_merge($mergedConfig, $rawConfig[$env]);
                }

                $backends = $mergedConfig['backends'] ?? [];
                $configOverrides = $backends[$backend] ?? [];

                // Merge config overrides into settings config
                $settings->config = array_merge($settings->config, $configOverrides);
            } catch (\Throwable $e) {
                // Ignore config errors, use database settings
            }
        }

        return $settings;
    }

    /**
     * Find backend settings by backend name
     */
    public static function findByBackend(string $backend): ?self
    {
        try {
            $row = (new Query())
                ->from('{{%searchmanager_backend_settings}}')
                ->where(['backend' => $backend])
                ->one();

            if (!$row) {
                return null;
            }

            $model = new self();
            $model->id = (int)$row['id'];
            $model->backend = $row['backend'];
            $model->enabled = (bool)$row['enabled'];
            $model->config = json_decode($row['configJson'], true) ?? [];

            return $model;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load backend settings', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get all backend settings
     */
    public static function findAll(): array
    {
        try {
            $rows = (new Query())
                ->from('{{%searchmanager_backend_settings}}')
                ->orderBy(['backend' => SORT_ASC])
                ->all();

            $models = [];
            foreach ($rows as $row) {
                $model = new self();
                $model->id = (int)$row['id'];
                $model->backend = $row['backend'];
                $model->enabled = (bool)$row['enabled'];
                $model->config = json_decode($row['configJson'], true) ?? [];
                $models[] = $model;
            }

            return $models;
        } catch (\Throwable $e) {
            LoggingService::log('Failed to load backend settings', 'error', 'search-manager', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Save backend settings to database
     */
    public function save(): bool
    {
        // Only validate if backend is enabled
        if ($this->enabled && !$this->validate()) {
            $this->logError('Backend settings validation failed', [
                'backend' => $this->backend,
                'errors' => $this->getErrors(),
            ]);
            return false;
        }

        try {
            $configJson = json_encode($this->config);

            if ($this->id) {
                // Update existing
                $result = Craft::$app->getDb()
                    ->createCommand()
                    ->update(
                        '{{%searchmanager_backend_settings}}',
                        [
                            'enabled' => (int)$this->enabled,
                            'configJson' => $configJson,
                            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        ],
                        ['id' => $this->id]
                    )
                    ->execute();

                return $result !== false;
            } else {
                // Insert new (or upsert based on backend)
                Craft::$app->getDb()
                    ->createCommand()
                    ->upsert(
                        '{{%searchmanager_backend_settings}}',
                        [
                            'backend' => $this->backend,
                            'enabled' => (int)$this->enabled,
                            'configJson' => $configJson,
                            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                            'uid' => StringHelper::UUID(),
                        ],
                        [
                            'enabled' => (int)$this->enabled,
                            'configJson' => $configJson,
                            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
                        ]
                    )
                    ->execute();

                // Get the ID
                $this->id = (int)Craft::$app->getDb()->getLastInsertID();

                return true;
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to save backend settings', [
                'backend' => $this->backend,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get a config value
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set a config value
     */
    public function setConfigValue(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
}
