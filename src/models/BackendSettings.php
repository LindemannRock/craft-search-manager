<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

use Craft;
use craft\base\Model;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Backend Settings Model
 *
 * Validates backend configuration shapes used by legacy form helpers and tests.
 *
 * @since 5.0.0
 */
class BackendSettings extends Model
{
    use LoggingTrait;

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    /**
     * @var string Backend type (algolia|meilisearch|mysql|typesense)
     * @since 5.28.0
     */
    public string $backend;

    /**
     * @var bool
     * @since 5.28.0
     */
    public bool $enabled = false;

    /**
     * @var array Decoded from config
     * @since 5.28.0
     */
    public array $config = [];

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
    // VALIDATION
    // =========================================================================

    /** @inheritdoc */
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
     *
     * @param string $attribute
     * @param array|null $params
     */
    public function validateBackendConfig($attribute, $params): void
    {
        if ($this->backend === 'algolia') {
            if (empty($this->config['applicationId'])) {
                $this->addError('applicationId', $this->_fieldCannotBeBlankMessage('Application ID'));
            }
            if (empty($this->config['adminApiKey'])) {
                $this->addError('apiKey', $this->_fieldCannotBeBlankMessage('Admin API Key'));
            }
        } elseif ($this->backend === 'meilisearch') {
            if (empty($this->config['host'])) {
                $this->addError('host', $this->_fieldCannotBeBlankMessage('Host'));
            }
            if (empty($this->config['apiKey'])) {
                $this->addError('apiKey', $this->_fieldCannotBeBlankMessage('API Key'));
            }
        } elseif ($this->backend === 'typesense') {
            if (empty($this->config['host'])) {
                $this->addError('host', $this->_fieldCannotBeBlankMessage('Host'));
            }
            if (empty($this->config['apiKey'])) {
                $this->addError('apiKey', $this->_fieldCannotBeBlankMessage('API Key'));
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
                    $this->addError('host', $this->_redisCraftRequiredMessage('Host'));
                }
                if (!$hasPort) {
                    $this->addError('port', $this->_redisCraftRequiredMessage('Port'));
                }
                if (!$hasDatabase) {
                    $this->addError('database', $this->_redisCraftRequiredMessage('Database'));
                }
            } elseif ($hasHost || $hasPort || $hasDatabase) {
                // Craft Redis available but user filled some fields - all must be filled for dedicated connection
                if (!$hasHost) {
                    $this->addError('host', $this->_redisDedicatedRequiredMessage('Host'));
                }
                if (!$hasPort) {
                    $this->addError('port', $this->_redisDedicatedRequiredMessage('Port'));
                }
                if (!$hasDatabase) {
                    $this->addError('database', $this->_redisDedicatedRequiredMessage('Database'));
                }
            }
            // Password is always optional (can be empty/null)
        }
    }

    private function _fieldCannotBeBlankMessage(string $field): string
    {
        return Craft::t('search-manager', '{field} cannot be blank.', [
            'field' => Craft::t('search-manager', $field),
        ]);
    }

    private function _redisCraftRequiredMessage(string $field): string
    {
        return Craft::t('search-manager', '{field} is required (or configure Craft to use Redis cache).', [
            'field' => Craft::t('search-manager', $field),
        ]);
    }

    private function _redisDedicatedRequiredMessage(string $field): string
    {
        return Craft::t('search-manager', '{field} is required when using a dedicated Redis connection.', [
            'field' => Craft::t('search-manager', $field),
        ]);
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
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('search-manager');
            $backends = $config['backends'] ?? [];
            $backendConfig = $backends[$this->backend] ?? [];

            return array_key_exists($field, $backendConfig);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get a config value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set a config value
     *
     * @param string $key
     * @param mixed $value
     */
    public function setConfigValue(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
}
