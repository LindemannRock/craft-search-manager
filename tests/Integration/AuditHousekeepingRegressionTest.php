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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

/**
 * @since 5.53.0
 */
#[CoversClass(SearchIndex::class)]
#[CoversClass(DeviceDetectionService::class)]
final class AuditHousekeepingRegressionTest extends TestCase
{
    private const PREFIX = 'audit-housekeeping';

    private static ?array $settingsRowBeforeSave = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        parent::tearDown();
    }

    public function testHandleRenameClearsOldHandleStorageAndMarksIndexForRebuild(): void
    {
        $backendHandle = self::PREFIX . '-mysql';
        $oldHandle = self::PREFIX . '-old';
        $newHandle = self::PREFIX . '-new';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($oldHandle, $backendHandle, 12);
        $this->insertDocumentRow($this->fullHandle($oldHandle));

        $index = SearchIndex::findByHandle($oldHandle);
        self::assertNotNull($index);
        $index->handle = $newHandle;

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertSame(0, $this->documentRowsForHandle($this->fullHandle($oldHandle)));

        $reloaded = SearchIndex::findByHandle($newHandle);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->documentCount);
        self::assertNull(SearchIndex::findByHandle($oldHandle));
    }

    public function testBackendSwitchClearsPreviousBackendInstanceAndMarksIndexForRebuild(): void
    {
        $oldBackendHandle = self::PREFIX . '-old-backend';
        $newBackendHandle = self::PREFIX . '-new-backend';
        $indexHandle = self::PREFIX . '-backend-switch';
        $backendService = new AuditHousekeepingBackendService();

        $this->insertBackend($oldBackendHandle, 'file');
        $this->insertBackend($newBackendHandle, 'file');
        $this->insertIndex($indexHandle, $oldBackendHandle, 9);
        $this->swapPluginComponent('search-manager', 'backend', $backendService);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        $index->backend = $newBackendHandle;

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertSame([$indexHandle], $backendService->backendFor($oldBackendHandle)->clearedIndices);
        self::assertSame([], $backendService->backendFor($newBackendHandle)->clearedIndices);

        $reloaded = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->documentCount);
    }

    public function testSettingsSaveClearsDeviceDetectionCacheAfterExistingCacheClears(): void
    {
        $settingsController = $this->readPluginFile('src/controllers/SettingsController.php');
        $deviceService = $this->readPluginFile('src/services/DeviceDetectionService.php');

        $body = $this->methodBody($settingsController, 'actionSave');
        $searchClear = strpos($body, 'SearchManager::$plugin->backend->clearAllSearchCache();');
        $autocompleteClear = strpos($body, 'SearchManager::$plugin->autocomplete->clearCache();');
        $deviceClear = strpos($body, 'SearchManager::$plugin->deviceDetection->clearCache();');

        self::assertIsInt($searchClear);
        self::assertIsInt($autocompleteClear);
        self::assertIsInt($deviceClear);
        self::assertLessThan($deviceClear, $autocompleteClear);
        self::assertStringContainsString("clearTrackedRedisKeys(SearchManager::\$plugin->id, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCachePath(SearchManager::\$plugin, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCacheKeyPrefix(SearchManager::\$plugin->id, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCacheKeySet(SearchManager::\$plugin->id, 'device')", $deviceService);
    }

    public function testManualPrefixConcatsUseSettingsFullIndexNameEquivalently(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->indexPrefix = self::PREFIX . '_';

        self::assertSame(
            ($settings->indexPrefix ?? '') . 'sample',
            $settings->getFullIndexName('sample'),
        );

        $indicesController = $this->readPluginFile('src/controllers/IndicesController.php');
        $autocompleteService = $this->readPluginFile('src/services/AutocompleteService.php');

        self::assertStringContainsString('$fullIndexName = $settings->getFullIndexName($index->handle);', $indicesController);
        self::assertStringNotContainsString('$prefix . $index->handle', $indicesController);
        self::assertStringContainsString('$fullIndexHandle = $settings->getFullIndexName($indexHandle);', $autocompleteService);
        self::assertStringNotContainsString('$indexPrefix . $indexHandle', $autocompleteService);
    }

    public function testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase(): void
    {
        self::$settingsRowBeforeSave = $this->fetchSettingsRow();
        self::assertNotNull(self::$settingsRowBeforeSave);

        $settings = SearchManager::$plugin->getSettings();
        $settings->pluginName = 'Search Manager ' . self::PREFIX;
        $settings->defaultWidgetHandle = null;

        self::assertTrue($settings->saveToDatabase(['pluginName', 'defaultWidgetHandle']));
        self::assertNotSame(self::$settingsRowBeforeSave, $this->fetchSettingsRow());
    }

    #[Depends('testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase')]
    public function testSettingsRowIsByteIdenticalAfterPreviousTestTeardown(): void
    {
        self::assertNotNull(self::$settingsRowBeforeSave);
        self::assertSame(self::$settingsRowBeforeSave, $this->fetchSettingsRow());

        $widgetTest = $this->methodBody(
            $this->readPluginFile('tests/Integration/WidgetConfigServiceDeleteTest.php'),
            'tearDown',
            'protected',
        );
        $duplicateTest = $this->methodBody(
            $this->readPluginFile('tests/Integration/DuplicateCpObjectsTest.php'),
            'tearDown',
            'protected',
        );

        self::assertStringNotContainsString('saveToDatabase', $widgetTest);
        self::assertStringNotContainsString('saveToDatabase', $duplicateTest);
    }

    private function insertBackend(string $handle, string $backendType): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Audit Housekeeping ' . $handle,
            'handle' => $handle,
            'backendType' => $backendType,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function insertIndex(string $handle, string $backendHandle, int $documentCount): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Audit Housekeeping ' . $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => $backendHandle,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => $now,
            'documentCount' => $documentCount,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
    }

    private function insertDocumentRow(string $indexHandle): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_search_documents}}', [
            'indexHandle' => $indexHandle,
            'siteId' => 1,
            'elementId' => abs((int)crc32($indexHandle)) % 1000000,
            'documentKey' => $indexHandle . ':1',
            'term' => '_length',
            'frequency' => 1,
            'language' => 'en',
        ])->execute();
    }

    private function documentRowsForHandle(string $indexHandle): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_search_documents}}')
            ->where(['indexHandle' => $indexHandle])
            ->count();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_search_documents}}', ['like', 'indexHandle', $this->fullHandle(self::PREFIX), false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        SearchIndex::clearCache();
    }

    private function fullHandle(string $handle): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($handle);
    }

    private function databaseBackendType(): string
    {
        return Craft::$app->getDb()->getDriverName() === 'pgsql' ? 'pgsql' : 'mysql';
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'public'): string
    {
        preg_match('/' . $visibility . ' function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, $method . ' source should be found.');

        return $matches[0];
    }
}

final class AuditHousekeepingBackendService extends BackendService
{
    /**
     * @var array<string, AuditHousekeepingRecordingBackend>
     */
    private array $backends = [];

    public function createBackendFromConfig(\lindemannrock\searchmanager\models\ConfiguredBackend $configuredBackend): ?BackendInterface
    {
        return $this->backendFor($configuredBackend->handle);
    }

    public function backendFor(string $handle): AuditHousekeepingRecordingBackend
    {
        return $this->backends[$handle] ??= new AuditHousekeepingRecordingBackend();
    }
}

final class AuditHousekeepingRecordingBackend implements BackendInterface
{
    /**
     * @var list<string>
     */
    public array $clearedIndices = [];

    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    public function indexWithResult(string $indexName, array $data): array
    {
        return ['success' => true, 'wasCreated' => true];
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    public function batchDelete(string $indexName, array $items): bool
    {
        return true;
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        return ['success' => true, 'existed' => true];
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        return [];
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
    }

    public function clearIndex(string $indexName): bool
    {
        $this->clearedIndices[] = $indexName;

        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'audit-housekeeping';
    }

    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

    public function multipleQueries(array $queries = []): array
    {
        return [];
    }

    public function parseFilters(array $filters = []): string
    {
        return '';
    }

    public function supportsBrowse(): bool
    {
        return false;
    }

    public function supportsMultipleQueries(): bool
    {
        return false;
    }

    public function listIndices(): array
    {
        return [];
    }

    public function setConfiguredSettings(array $settings): void
    {
    }

    public function setBackendHandle(string $handle): void
    {
    }
}
