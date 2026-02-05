<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

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
    /**
     * Default database offset from Craft's Redis database.
     * This ensures search data is isolated from Craft cache data.
     */
    private const SEARCH_DATABASE_OFFSET = 1;

    /**
     * @var \Redis|null Redis client for availability checks
     */
    private ?\Redis $_client = null;

    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        // Use resolved settings (with Craft fallback applied)
        $resolvedSettings = $this->getResolvedRedisSettings();
        return new RedisStorage($fullIndexName, $resolvedSettings);
    }

    /**
     * Get resolved Redis settings, applying Craft cache fallback if needed.
     *
     * When no explicit Redis host is configured, falls back to Craft's Redis
     * cache settings but uses a DIFFERENT database to isolate search data.
     *
     * @return array Resolved Redis settings
     */
    private function getResolvedRedisSettings(): array
    {
        $backendSettings = $this->getBackendSettings();

        // If host is explicitly configured, use those settings as-is
        $configuredHost = $this->resolveEnvVar($backendSettings['host'] ?? null, null);
        if (!empty($configuredHost)) {
            return $backendSettings;
        }

        // Fall back to Craft's Redis cache settings
        if (Craft::$app->cache instanceof \yii\redis\Cache) {
            $redisConnection = Craft::$app->cache->redis;

            $craftDatabase = (int) ($redisConnection->database ?? 0);

            // Use separate database for search data to prevent cache flush conflicts
            // Unless user has explicitly configured a database
            $searchDatabase = isset($backendSettings['database']) && $backendSettings['database'] !== ''
                ? (int) $this->resolveEnvVar($backendSettings['database'], 0)
                : $craftDatabase + self::SEARCH_DATABASE_OFFSET;

            $this->logInfo('Using Craft Redis cache settings for Search Manager', [
                'host' => $redisConnection->hostname ?? 'localhost',
                'port' => $redisConnection->port ?? 6379,
                'craftDatabase' => $craftDatabase,
                'searchDatabase' => $searchDatabase,
            ]);

            return [
                'host' => $redisConnection->hostname ?? 'localhost',
                'port' => $redisConnection->port ?? 6379,
                'password' => $redisConnection->password ?? null,
                'database' => $searchDatabase,
            ];
        }

        // No Craft Redis, use defaults
        return $backendSettings;
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
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $client = $this->getClient();
            $client->ping();
            return true;
        } catch (\Throwable $e) {
            $this->logError('Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        $settings = $this->getBackendSettings();

        return [
            'name' => 'Redis',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => !empty($settings['host']) || Craft::$app->cache instanceof \yii\redis\Cache,
            'available' => $this->isAvailable(),
            'extension' => extension_loaded('redis'),
        ];
    }

    /**
     * Get or create Redis client
     *
     * Uses the same resolved settings as storage to ensure consistency.
     *
     * @return \Redis
     * @throws \Exception
     */
    private function getClient(): \Redis
    {
        if ($this->_client === null) {
            // Use resolved settings (same as storage uses)
            $settings = $this->getResolvedRedisSettings();

            $this->_client = new \Redis();

            $host = $settings['host'] ?? '127.0.0.1';
            $port = (int) ($settings['port'] ?? 6379);
            $password = $settings['password'] ?? null;
            $database = (int) ($settings['database'] ?? 0);

            $this->_client->connect($host, $port);

            if ($password) {
                $this->_client->auth($password);
            }

            $this->_client->select($database);
        }

        return $this->_client;
    }
}
