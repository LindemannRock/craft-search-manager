<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\PostgreSqlStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * PostgreSQL Backend
 *
 * Search backend using BM25 algorithm with PostgreSQL storage.
 *
 * @since 5.0.0
 */
class PostgreSqlBackend extends AbstractSearchEngineBackend
{
    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return new PostgreSqlStorage($fullIndexName);
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
