<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins audit Pass 36 fixes #197-#198.
 */
final class AuditPass36Test extends TestCase
{
    public function testClearIndexClearsLanguageSpecificSearchEngineCaches(): void
    {
        $storage = new RecordingStorage([], [], [], 0, 1.0);
        $backend = new AuditPass36Backend($storage);

        $fullIndexName = $backend->seedCaches('test-index', ['en', 'ar']);
        $otherIndexName = $backend->seedCaches('other-index', ['en']);

        self::assertTrue($backend->clearIndex('test-index'));

        self::assertSame([$otherIndexName, $otherIndexName . '_en'], $backend->searchEngineCacheKeys());
        self::assertFalse($backend->hasStorageCache('test-index'));
        self::assertTrue($backend->hasStorageCache('other-index'));
        self::assertNotContains($fullIndexName, $backend->searchEngineCacheKeys());
        self::assertNotContains($fullIndexName . '_en', $backend->searchEngineCacheKeys());
        self::assertNotContains($fullIndexName . '_ar', $backend->searchEngineCacheKeys());
    }

    public function testConfiguredBackendValidationTranslatesSchemaFieldLabels(): void
    {
        $previousLanguage = Craft::$app->language;
        Craft::$app->language = 'de';

        try {
            $backend = new ConfiguredBackend();
            $backend->backendType = 'algolia';
            $backend->settings = [
                'applicationId' => '',
                'adminApiKey' => '',
            ];

            self::assertFalse($backend->validate(['settings']));

            $errors = $backend->getErrors('settings.applicationId');
            self::assertNotEmpty($errors);
            self::assertStringContainsString('Anwendungs-ID', $errors[0]);
            self::assertStringNotContainsString('Application ID', $errors[0]);
        } finally {
            Craft::$app->language = $previousLanguage;
        }
    }
}

final class AuditPass36Backend extends AbstractSearchEngineBackend
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    /**
     * @param string[] $languages
     */
    public function seedCaches(string $indexName, array $languages): string
    {
        $fullIndexName = $this->fullIndexName($indexName);
        $this->searchEngines[$fullIndexName] = $this->engine($fullIndexName);

        foreach ($languages as $language) {
            $this->searchEngines[$fullIndexName . '_' . $language] = $this->engine($fullIndexName);
        }

        $this->storages[$fullIndexName] = $this->storage;

        return $fullIndexName;
    }

    public function hasStorageCache(string $indexName): bool
    {
        return isset($this->storages[$this->fullIndexName($indexName)]);
    }

    /**
     * @return string[]
     */
    public function searchEngineCacheKeys(): array
    {
        return array_keys($this->searchEngines);
    }

    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return $this->storage;
    }

    protected function getBackendLabel(): string
    {
        return 'Test';
    }

    public function getName(): string
    {
        return 'test';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }

    private function fullIndexName(string $indexName): string
    {
        return $this->getFullIndexName($indexName);
    }

    private function engine(string $indexName): SearchEngine
    {
        return new SearchEngine($this->storage, $indexName, ['enableStopWords' => false]);
    }
}
