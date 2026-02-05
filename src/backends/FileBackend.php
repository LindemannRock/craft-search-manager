<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * File Backend
 *
 * Search backend using BM25 algorithm with file-based storage
 *
 * @since 5.0.0
 */
class FileBackend extends AbstractSearchEngineBackend
{
    /**
     * @var string Base path for file storage
     */
    private string $_basePath;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Set base path for file storage
        $this->_basePath = Craft::$app->getPath()->getRuntimePath() . '/search-manager/indices';

        // Ensure directory exists
        if (!is_dir($this->_basePath)) {
            mkdir($this->_basePath, 0775, true);
        }
    }

    /**
     * @inheritdoc
     */
    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return new FileStorage($fullIndexName);
    }

    /**
     * @inheritdoc
     */
    protected function getBackendLabel(): string
    {
        return 'File';
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'file';
    }

    /**
     * @inheritdoc
     */
    public function isAvailable(): bool
    {
        return is_writable(dirname($this->_basePath)) || is_writable($this->_basePath);
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        return [
            'name' => 'File',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => true,
            'available' => $this->isAvailable(),
            'path' => $this->_basePath,
        ];
    }

    /**
     * List all indices with file sizes
     *
     * @inheritdoc
     */
    public function listIndices(): array
    {
        // Get base indices from parent
        $indices = parent::listIndices();

        // Add file size for each index
        foreach ($indices as &$index) {
            $fullIndexName = $this->getFullIndexName($index['handle']);
            $indexPath = $this->_basePath . '/' . $fullIndexName;

            if (is_dir($indexPath)) {
                $index['dataSize'] = $this->getDirectorySize($indexPath);
            }
        }

        return $indices;
    }

    /**
     * Calculate total size of a directory
     *
     * @param string $path
     * @return int Size in bytes
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;

        if (!is_dir($path)) {
            return $size;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
