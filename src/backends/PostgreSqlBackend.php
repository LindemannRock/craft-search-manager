<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * PostgreSQL Backend
 *
 * Search backend using BM25 algorithm with MySQL-compatible storage
 * (PostgreSQL and MySQL share the same SQL structure for search tables)
 */
class PostgreSqlBackend extends AbstractSearchEngineBackend
{
    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        // PostgreSQL uses MySqlStorage - same SQL structure
        return new MySqlStorage($fullIndexName);
    }

    /**
     * @inheritdoc
     */
    protected function getBackendLabel(): string
    {
        return 'PostgreSQL';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->getDriverName() === 'pgsql';
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        return [
            'name' => 'Craft Database (PostgreSQL)',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'driver' => 'pgsql',
        ];
    }
}
