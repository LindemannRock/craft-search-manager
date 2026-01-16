<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * MySQL Backend
 *
 * Search backend using BM25 algorithm with MySQL storage
 */
class MySqlBackend extends AbstractSearchEngineBackend
{
    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return new MySqlStorage($fullIndexName);
    }

    /**
     * @inheritdoc
     */
    protected function getBackendLabel(): string
    {
        return 'MySQL';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'mysql';
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        return Craft::$app->getDb()->getDriverName() === 'mysql';
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        return [
            'name' => 'Craft Database (MySQL)',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'driver' => 'mysql',
        ];
    }
}
