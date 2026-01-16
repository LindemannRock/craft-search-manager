<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * Redis Backend
 *
 * Search backend using BM25 algorithm with Redis storage
 */
class RedisBackend extends AbstractSearchEngineBackend
{
    /**
     * @var \Redis|null Redis client for availability checks
     */
    private ?\Redis $_client = null;

    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        $backendSettings = $this->getBackendSettings();
        return new RedisStorage($fullIndexName, $backendSettings);
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
     * @return \Redis
     * @throws \Exception
     */
    private function getClient(): \Redis
    {
        if ($this->_client === null) {
            $settings = $this->getBackendSettings();

            // If no host configured, use Craft's Redis cache settings
            if (empty($settings['host']) && Craft::$app->cache instanceof \yii\redis\Cache) {
                $this->logInfo('Using Craft Redis cache settings for Search Manager');

                $redisConnection = Craft::$app->cache->redis;
                $this->_client = new \Redis();

                $craftHost = $redisConnection->hostname ?? 'localhost';
                $craftPort = $redisConnection->port ?? 6379;
                $craftPassword = $redisConnection->password ?? null;
                $craftDatabase = $redisConnection->database ?? 0;

                $this->logInfo('Connecting to Craft Redis', [
                    'host' => $craftHost,
                    'port' => $craftPort,
                    'database' => $craftDatabase,
                ]);

                $this->_client->connect($craftHost, (int)$craftPort);

                if ($craftPassword) {
                    $this->_client->auth($craftPassword);
                }

                $this->_client->select((int)$craftDatabase);
            } else {
                // Use configured host/port
                $this->_client = new \Redis();

                $host = $this->resolveEnvVar($settings['host'] ?? null, '127.0.0.1');
                $port = (int)$this->resolveEnvVar($settings['port'] ?? null, 6379);
                $password = $this->resolveEnvVar($settings['password'] ?? null, null);
                $database = (int)$this->resolveEnvVar($settings['database'] ?? null, 0);

                $this->_client->connect($host, $port);

                if ($password) {
                    $this->_client->auth($password);
                }

                $this->_client->select($database);
            }
        }

        return $this->_client;
    }
}
