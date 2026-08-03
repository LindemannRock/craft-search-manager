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
final class IndexMutationLifecycleTest extends TestCase
{
    private const PREFIX = 'audit-housekeeping';
    private const CONFIG_BACKEND = self::PREFIX . '-config-backend';

    private static ?array $settingsRowBeforeSave = null;

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertBackend(self::CONFIG_BACKEND, 'file');
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
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
        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($newHandle));
        self::assertSame(0, $this->countRebuildQueueRows($oldHandle));

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
        $backendService = new IndexMutationBackendService();

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
        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));

        $reloaded = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->documentCount);
    }

    public function testShapeAttributeChangeQueuesOneRebuildJob(): void
    {
        $backendHandle = self::PREFIX . '-shape-backend';
        $indexHandle = self::PREFIX . '-shape';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($indexHandle, $backendHandle, 3);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        $index->criteria = ['sections' => ['news']];

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));
    }

    public function testNoOpSaveDoesNotQueueRebuildJob(): void
    {
        $backendHandle = self::PREFIX . '-noop-backend';
        $indexHandle = self::PREFIX . '-noop';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($indexHandle, $backendHandle, 3);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertFalse($index->wasRebuildQueuedOnLastSave());
        self::assertSame(0, $this->countRebuildQueueRows($indexHandle));
    }

    public function testEnableTransitionQueuesOneRebuildJobAfterDisabledSave(): void
    {
        $backendHandle = self::PREFIX . '-enable-backend';
        $indexHandle = self::PREFIX . '-enable-transition';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($indexHandle, $backendHandle, 3);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        $index->enabled = false;

        self::assertTrue($index->save(), print_r($index->getErrors(), true));
        self::assertFalse($index->wasRebuildQueuedOnLastSave());
        self::assertSame(0, $this->countRebuildQueueRows($indexHandle));

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        $index->enabled = true;

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));
    }

    public function testDisableOnlyDoesNotQueueRebuildJob(): void
    {
        $backendHandle = self::PREFIX . '-disable-backend';
        $indexHandle = self::PREFIX . '-disable-only';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($indexHandle, $backendHandle, 3);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        $index->enabled = false;

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertFalse($index->wasRebuildQueuedOnLastSave());
        self::assertSame(0, $this->countRebuildQueueRows($indexHandle));
    }

    public function testBulkEnableUsesIndexSavePathForAutoRebuilds(): void
    {
        $source = $this->readPluginFile('src/controllers/IndicesController.php');
        $actionBody = $this->methodBody($source, 'actionBulkEnable');
        $helperBody = $this->methodBody($source, 'bulkSetEnabled', 'private');

        self::assertStringContainsString('return $this->bulkSetEnabled(true);', $actionBody);
        self::assertStringContainsString('$index->enabled = $enabled;', $helperBody);
        self::assertStringContainsString('if ($index->save()) {', $helperBody);
    }

    public function testNewEnabledIndexQueuesInitialRebuildJob(): void
    {
        $indexHandle = self::PREFIX . '-new-index';
        $index = new SearchIndex();
        $index->name = 'Audit Housekeeping New Index';
        $index->handle = $indexHandle;
        $index->elementType = Entry::class;
        $index->transformerClass = '';
        $index->enabled = true;
        $index->criteria = [];
        $index->retrievableFields = ['*'];

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));
    }

    public function testCombinedIdentityAndShapeChangeClearsOldStorageAndQueuesOneRebuildJob(): void
    {
        $backendHandle = self::PREFIX . '-combined-backend';
        $oldHandle = self::PREFIX . '-combined-old';
        $newHandle = self::PREFIX . '-combined-new';

        $this->insertBackend($backendHandle, $this->databaseBackendType());
        $this->insertIndex($oldHandle, $backendHandle, 7);
        $this->insertDocumentRow($this->fullHandle($oldHandle));

        $index = SearchIndex::findByHandle($oldHandle);
        self::assertNotNull($index);
        $index->handle = $newHandle;
        $index->criteria = ['sections' => ['news']];

        self::assertTrue($index->save(), print_r($index->getErrors(), true));

        self::assertSame(0, $this->documentRowsForHandle($this->fullHandle($oldHandle)));
        self::assertTrue($index->wasRebuildQueuedOnLastSave());
        self::assertSame(1, $this->countRebuildQueueRows($newHandle));
        self::assertSame(0, $this->countRebuildQueueRows($oldHandle));

        $reloaded = SearchIndex::findByHandle($newHandle);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->documentCount);
    }

    public function testConfigShapeChangeQueuesOnceAndIsIdempotentOnNextSync(): void
    {
        $indexHandle = self::PREFIX . '-config-shape';

        $this->withConfigFileIndices([
            $indexHandle => $this->configIndexDefinition([
                'criteria' => ['sections' => ['news']],
                'splitSections' => true,
            ]),
        ]);
        $this->insertConfigIndexMetadata($indexHandle, [
            'criteria' => ['sections' => ['blog']],
            'splitSections' => false,
        ]);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);

        self::assertTrue($index->syncMetadataFromConfig());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);

        self::assertTrue($index->syncMetadataFromConfig());
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));
    }

    public function testUnchangedConfigSyncDoesNotQueueAcrossRepeatedSyncs(): void
    {
        $indexHandle = self::PREFIX . '-config-unchanged';
        $definition = $this->configIndexDefinition([
            'criteria' => ['sections' => ['news']],
            'retrievableFields' => ['title', 'summary'],
        ]);

        $this->withConfigFileIndices([$indexHandle => $definition]);
        $this->insertConfigIndexMetadata($indexHandle, $definition);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);

        self::assertTrue($index->syncMetadataFromConfig());
        self::assertTrue($index->syncMetadataFromConfig());
        self::assertSame(0, $this->countRebuildQueueRows($indexHandle));
    }

    public function testFirstEnabledConfigMetadataMaterializationQueuesInitialRebuild(): void
    {
        $indexHandle = self::PREFIX . '-config-first';

        $this->withConfigFileIndices([
            $indexHandle => $this->configIndexDefinition([
                'criteria' => ['sections' => ['news']],
            ]),
        ]);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        self::assertNull($index->id);

        self::assertTrue($index->syncMetadataFromConfig());

        self::assertNotNull($index->id);
        self::assertSame(1, $this->countRebuildQueueRows($indexHandle));
    }

    public function testFirstDisabledConfigMetadataMaterializationDoesNotQueueRebuild(): void
    {
        $indexHandle = self::PREFIX . '-config-disabled-first';

        $this->withConfigFileIndices([
            $indexHandle => $this->configIndexDefinition([
                'enabled' => false,
            ]),
        ]);

        $index = SearchIndex::findByHandle($indexHandle);
        self::assertNotNull($index);
        self::assertNull($index->id);

        self::assertTrue($index->syncMetadataFromConfig());

        self::assertNotNull($index->id);
        self::assertSame(0, $this->countRebuildQueueRows($indexHandle));
    }

    #[Depends('testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase')]
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertConfigIndexMetadata(string $handle, array $overrides = []): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $definition = $this->configIndexDefinition($overrides);
        $siteId = $definition['siteId'] ?? null;

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $definition['name'],
            'handle' => $handle,
            'elementType' => $definition['elementType'],
            'siteId' => is_array($siteId) ? null : $siteId,
            'criteria' => json_encode($definition['criteria'] ?? [], JSON_THROW_ON_ERROR),
            'transformerClass' => ($definition['transformer'] ?? null) ?: '',
            'headingLevels' => isset($definition['headingLevels']) ? json_encode($definition['headingLevels'], JSON_THROW_ON_ERROR) : null,
            'language' => $definition['language'] ?? null,
            'backend' => $definition['backend'] ?? null,
            'enabled' => ($definition['enabled'] ?? true) ? 1 : 0,
            'enableAnalytics' => ($definition['enableAnalytics'] ?? true) ? 1 : 0,
            'disableStopWords' => ($definition['disableStopWords'] ?? false) ? 1 : 0,
            'skipEntriesWithoutUrl' => ($definition['skipEntriesWithoutUrl'] ?? false) ? 1 : 0,
            'splitSections' => ($definition['splitSections'] ?? false) ? 1 : 0,
            'retrievableFields' => json_encode(SearchIndex::normalizeRetrievableFields($definition['retrievableFields'] ?? null), JSON_THROW_ON_ERROR),
            'source' => 'config',
            'lastIndexed' => $now,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        if (is_array($siteId)) {
            $indexId = (int)Craft::$app->getDb()->getLastInsertID();
            foreach ($siteId as $id) {
                Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_index_sites}}', [
                    'indexId' => $indexId,
                    'siteId' => (int)$id,
                ])->execute();
            }
        }

        SearchIndex::clearCache();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function configIndexDefinition(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Audit Housekeeping Config Index',
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => [],
            'transformer' => null,
            'headingLevels' => null,
            'language' => null,
            'backend' => self::CONFIG_BACKEND,
            'enabled' => true,
            'enableAnalytics' => true,
            'disableStopWords' => false,
            'skipEntriesWithoutUrl' => false,
            'splitSections' => false,
            'retrievableFields' => ['*'],
        ], $overrides);
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
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::PREFIX])
            ->execute();
        SearchIndex::clearCache();
    }

    private function fullHandle(string $handle): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($handle);
    }

    private function countRebuildQueueRows(string $handle): int
    {
        return (int)(new Query())
            ->from($this->queueTable())
            ->where(['like', 'job', 'RebuildIndexJob'])
            ->andWhere(['like', 'job', $handle])
            ->andWhere(['fail' => false])
            ->andWhere(['timeUpdated' => null])
            ->count();
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

final class IndexMutationBackendService extends BackendService
{
    /**
     * @var array<string, IndexMutationRecordingBackend>
     */
    private array $backends = [];

    public function createBackendFromConfig(\lindemannrock\searchmanager\models\ConfiguredBackend $configuredBackend): ?BackendInterface
    {
        return $this->backendFor($configuredBackend->handle);
    }

    public function backendFor(string $handle): IndexMutationRecordingBackend
    {
        return $this->backends[$handle] ??= new IndexMutationRecordingBackend();
    }
}

final class IndexMutationRecordingBackend implements BackendInterface
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
