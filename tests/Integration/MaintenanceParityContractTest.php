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
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit findings #447-#452.
 *
 * @since 5.54.0
 */
final class MaintenanceParityContractTest extends TestCase
{
    private const DATABASE_INDEX_HANDLE = '__sm_audit_batch8_database';
    private const CONFIG_INDEX_HANDLE = '__sm_audit_batch8_config';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseIndex();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();

        parent::tearDown();
    }

    public function testConsoleIndexClearMatchesControlPanelSideEffects(): void
    {
        $index = SearchIndex::findByHandle(self::DATABASE_INDEX_HANDLE);
        self::assertNotNull($index);

        $backend = $this->installStubBackend();
        $autocomplete = new MaintenanceRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $result = SearchManager::$plugin->indexMaintenance->clearIndex($index);

        self::assertSame('success', $result['status']);
        self::assertCount(1, $backend->callsFor('clearIndex'));
        self::assertCount(1, $backend->callsFor('clearSearchCache'));
        self::assertSame([self::DATABASE_INDEX_HANDLE], $autocomplete->clearCacheCalls);
        self::assertSame(0, (int)$this->fetchRow('{{%searchmanager_indices}}', [
            'handle' => self::DATABASE_INDEX_HANDLE,
        ])['documentCount']);
    }

    public function testConsoleStorageResetMatchesControlPanelCountReset(): void
    {
        $method = new \ReflectionMethod(SearchManager::$plugin->storageMaintenance, 'reconcileClearedIndices');
        $method->setAccessible(true);
        $result = $method->invoke(SearchManager::$plugin->storageMaintenance, [self::DATABASE_INDEX_HANDLE]);

        self::assertTrue($result['success']);
        self::assertSame(0, (int)$this->fetchRow('{{%searchmanager_indices}}', [
            'handle' => self::DATABASE_INDEX_HANDLE,
        ])['documentCount']);
    }

    private function insertDatabaseIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 8 Database Index',
            'handle' => self::DATABASE_INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => json_encode([(int)Craft::$app->getSites()->getPrimarySite()->id], JSON_THROW_ON_ERROR),
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => $this->fixtureBackendHandle(),
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 9,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => [
                self::DATABASE_INDEX_HANDLE,
                self::CONFIG_INDEX_HANDLE,
            ]])
            ->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function fixtureBackendHandle(): string
    {
        $handle = (new \craft\db\Query())
            ->select(['handle'])
            ->from('{{%searchmanager_backends}}')
            ->where(['handle' => 'fixtureMysql'])
            ->scalar();

        return is_string($handle) ? $handle : 'mysql';
    }
}

/**
 * @since 5.54.0
 */
final class MaintenanceRecordingAutocompleteService extends AutocompleteService
{
    /** @var list<string|null> */
    public array $clearCacheCalls = [];

    /** @inheritdoc */
    public function clearCache(?string $indexHandle = null): void
    {
        $this->clearCacheCalls[] = $indexHandle;
    }
}
