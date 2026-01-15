<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Base Backend
 *
 * Abstract base class for all search backend adapters
 * Provides common functionality and enforces the BackendInterface contract
 */
abstract class BaseBackend extends Component implements BackendInterface
{
    use LoggingTrait;

    /**
     * @var array|null Settings from a ConfiguredBackend (overrides global config)
     */
    protected ?array $_configuredSettings = null;

    /**
     * @var string|null Handle of the ConfiguredBackend this adapter is associated with
     */
    protected ?string $_backendHandle = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Set configured settings from a ConfiguredBackend
     * These settings will be used instead of global config
     *
     * @param array $settings
     * @return void
     */
    public function setConfiguredSettings(array $settings): void
    {
        $this->_configuredSettings = $settings;
        $this->logDebug('ConfiguredSettings set', [
            'backend' => $this->getName(),
            'settingsKeys' => array_keys($settings),
            'host' => $settings['host'] ?? 'NOT SET',
            'port' => $settings['port'] ?? 'NOT SET',
        ]);
    }

    /**
     * Set the backend handle this adapter is associated with
     */
    public function setBackendHandle(string $handle): void
    {
        $this->_backendHandle = $handle;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the full index name with prefix
     *
     * @param string $indexName Base index name
     * @return string Full index name with prefix
     */
    protected function getFullIndexName(string $indexName): string
    {
        $settings = SearchManager::$plugin->getSettings();
        $prefix = $settings->indexPrefix ?? '';

        return $prefix . $indexName;
    }

    /**
     * Get backend settings
     *
     * Priority: ConfiguredBackend settings > Config file > Database settings
     *
     * @return array Backend configuration
     */
    protected function getBackendSettings(): array
    {
        // If we have configured settings from a ConfiguredBackend, use those
        if ($this->_configuredSettings !== null) {
            $this->logDebug('Using ConfiguredBackend settings', [
                'backend' => $this->getName(),
                'host' => $this->_configuredSettings['host'] ?? 'NOT SET',
            ]);
            return $this->_configuredSettings;
        }

        $this->logDebug('No ConfiguredBackend settings, falling back', [
            'backend' => $this->getName(),
        ]);

        $configPath = Craft::$app->getPath()->getConfigPath() . '/search-manager.php';

        // Try config file first
        if (file_exists($configPath)) {
            try {
                $config = require $configPath;
                $env = Craft::$app->getConfig()->env;

                // Merge environment config
                $mergedConfig = $config['*'] ?? [];
                if ($env && isset($config[$env])) {
                    $mergedConfig = array_merge($mergedConfig, $config[$env]);
                }

                $backends = $mergedConfig['backends'] ?? [];
                if (isset($backends[$this->getName()])) {
                    return $backends[$this->getName()];
                }
            } catch (\Throwable $e) {
                $this->logError('Failed to load backend settings from config', [
                    'backend' => $this->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to database settings
        $backendSettings = \lindemannrock\searchmanager\models\BackendSettings::findByBackend($this->getName());
        $config = $backendSettings ? $backendSettings->config : [];

        $this->logDebug('Loaded backend settings', [
            'backend' => $this->getName(),
            'config' => $config,
        ]);

        return $config;
    }

    /**
     * Check if backend is enabled in config
     *
     * @return bool
     */
    protected function isEnabledInConfig(): bool
    {
        $settings = $this->getBackendSettings();
        return ($settings['enabled'] ?? false) === true;
    }

    /**
     * Resolve environment variable
     * Strips $ prefix if present and calls App::env()
     *
     * @param mixed $value Config value (e.g., "$REDIS_HOST" or "REDIS_HOST" or "redis")
     * @param mixed $default Default value if env var not found
     * @return mixed Resolved value
     */
    protected function resolveEnvVar($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // If value starts with $, it's an env var reference
        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            return \craft\helpers\App::env($envVarName) ?? $default;
        }

        // Otherwise return the value as-is (it's a plain string, not an env var)
        return $value;
    }

    // =========================================================================
    // ABSTRACT METHODS (must be implemented by subclasses)
    // =========================================================================

    abstract public function index(string $indexName, array $data): bool;
    abstract public function batchIndex(string $indexName, array $items): bool;
    abstract public function delete(string $indexName, int $elementId, ?int $siteId = null): bool;
    abstract public function search(string $indexName, string $query, array $options = []): array;
    abstract public function clearIndex(string $indexName): bool;
    abstract public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool;
    abstract public function isAvailable(): bool;
    abstract public function getStatus(): array;
    abstract public function getName(): string;

    // =========================================================================
    // DEFAULT IMPLEMENTATIONS (can be overridden by subclasses)
    // =========================================================================

    /**
     * Browse/iterate through all documents in an index
     * Default: not supported, returns empty array
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        $this->logWarning('browse() not supported by this backend', ['backend' => $this->getName()]);
        return [];
    }

    /**
     * Perform multiple search queries in a single request
     * Default: sequential search fallback
     */
    public function multipleQueries(array $queries = []): array
    {
        // Fallback: execute queries sequentially
        $results = [];
        foreach ($queries as $query) {
            $indexName = $query['indexName'] ?? '';
            $searchQuery = $query['query'] ?? '';
            $params = $query['params'] ?? [];

            $results[] = $this->search($indexName, $searchQuery, $params);
        }

        return ['results' => $results];
    }

    /**
     * Parse filters array into backend-specific filter string
     * Default: basic SQL-like syntax
     */
    public function parseFilters(array $filters = []): string
    {
        $filterParts = [];

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                // Multiple values = OR condition
                $orParts = array_map(function($v) use ($key) {
                    $v = is_bool($v) ? ($v ? 'true' : 'false') : $v;
                    return $key . ' = "' . $v . '"';
                }, $value);
                $filterParts[] = '(' . implode(' OR ', $orParts) . ')';
            } else {
                $value = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                $filterParts[] = $key . ' = "' . $value . '"';
            }
        }

        return implode(' AND ', $filterParts);
    }

    /**
     * Check if this backend supports browse functionality
     */
    public function supportsBrowse(): bool
    {
        return false;
    }

    /**
     * Check if this backend supports multiple queries
     */
    public function supportsMultipleQueries(): bool
    {
        return false;
    }

    /**
     * List all indices available in the backend
     * Default: returns indices from database that use this backend
     */
    public function listIndices(): array
    {
        $settings = SearchManager::$plugin->getSettings();
        $indices = [];

        // Get indices from database that use this backend
        $searchIndices = SearchIndex::findAll();
        $defaultBackendHandle = $settings->defaultBackendHandle;

        foreach ($searchIndices as $searchIndex) {
            // Check if this index uses this backend
            $indexBackend = $searchIndex->backend ?: $defaultBackendHandle;

            if ($this->_backendHandle && $indexBackend === $this->_backendHandle) {
                $indices[] = [
                    'name' => $this->getFullIndexName($searchIndex->handle),
                    'handle' => $searchIndex->handle,
                    'entries' => $searchIndex->documentCount,
                    'source' => $searchIndex->source,
                ];
            }
        }

        // Also check config-based indices
        foreach ($settings->indices ?? [] as $handle => $config) {
            $indexBackend = $config['backend'] ?? $defaultBackendHandle;

            if ($this->_backendHandle && $indexBackend === $this->_backendHandle) {
                // Check if not already added from database
                $alreadyAdded = false;
                foreach ($indices as $index) {
                    if ($index['handle'] === $handle) {
                        $alreadyAdded = true;
                        break;
                    }
                }

                if (!$alreadyAdded) {
                    $indices[] = [
                        'name' => $this->getFullIndexName($handle),
                        'handle' => $handle,
                        'source' => 'config',
                    ];
                }
            }
        }

        return $indices;
    }
}
