<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\backends;

use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\RedisNativeConnectionFactory;

/**
 * Redis Backend
 *
 * Search backend using BM25 algorithm with Redis storage.
 *
 * IMPORTANT: When using Craft's Redis cache settings (no explicit host configured),
 * search data is stored in a SEPARATE database (Craft database + 1) to prevent
 * data loss when Craft cache is cleared.
 *
 * @since 5.0.0
 */
class RedisBackend extends AbstractSearchEngineBackend
{
    private string $lastConnectionStatus = RedisNativeConnectionFactory::STATUS_NOT_CONFIGURED;

    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return new RedisStorage($fullIndexName, $this->getBackendSettings());
    }

    /**
     * @inheritdoc
     */
    protected function getBackendLabel(): string
    {
        return 'Redis';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'redis';
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        $configuration = SearchManager::$plugin->redisConnections->resolve($this->getBackendSettings());
        $this->lastConnectionStatus = SearchManager::$plugin->redisConnections->probe($configuration);
        if ($this->lastConnectionStatus !== RedisNativeConnectionFactory::STATUS_CONNECTED) {
            $this->logError('Redis availability check failed', [
                'classification' => $this->lastConnectionStatus,
            ]);
        }

        return $this->lastConnectionStatus === RedisNativeConnectionFactory::STATUS_CONNECTED;
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        $configuration = SearchManager::$plugin->redisConnections->resolve($this->getBackendSettings());

        return [
            'name' => 'Redis',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => $configuration->isConfigured(),
            'available' => $this->isAvailable(),
            'extension' => extension_loaded('redis'),
            'connectionStatus' => $this->lastConnectionStatus,
        ];
    }

    /**
     * Return the last fixed availability classification.
     *
     * @since 5.54.0
     */
    public function getLastConnectionStatus(): string
    {
        return $this->lastConnectionStatus;
    }
}
