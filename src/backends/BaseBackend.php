<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\interfaces\BackendInterface;
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

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
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
     * Config file overrides database settings (standard pattern)
     *
     * @return array Backend configuration
     */
    protected function getBackendSettings(): array
    {
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

        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            return \craft\helpers\App::env($envVarName) ?? $default;
        }

        return \craft\helpers\App::env($value) ?? $default;
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
}
