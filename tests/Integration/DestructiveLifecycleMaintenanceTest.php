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
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\console\controllers\IndexController;
use lindemannrock\searchmanager\console\controllers\MaintenanceController;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexMaintenanceService;
use lindemannrock\searchmanager\services\StorageMaintenanceService;
use lindemannrock\searchmanager\tests\TestCase;
use yii\console\ExitCode;

/**
 * @since 5.54.0
 */
final class DestructiveLifecycleMaintenanceTest extends TestCase
{
    private const PREFIX = '__sm_pr129_';
    private const CONFIG_INDEX_HANDLE = 'fixture-valid-minimal';
    public const TRANSACTION_MARKER = self::PREFIX . 'transaction-marker';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeRows();
        parent::tearDown();
    }

    public function testCpClearFailurePreservesDefinitionCountSitesAndCaches(): void
    {
        $id = $this->insertIndex('clear-failure', 7, [1, 2]);
        $service = new RecordingIndexMaintenanceService();
        $service->storageOutcomes[self::PREFIX . 'clear-failure'] = false;
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:clearIndices']);
        $this->withPostJson(['indexId' => $id]);

        $data = (new IndicesController('indices', SearchManager::$plugin))->actionClear()->data;

        self::assertSame('failure', $data['status']);
        self::assertFalse($data['storageCleared']);
        self::assertSame(7, $this->storedCount($id));
        self::assertSame(2, $this->siteCount($id));
        self::assertSame([], $service->cacheCalls);
    }

    public function testThrownBackendClearFailureAlsoPreservesAllMetadataAndCaches(): void
    {
        $id = $this->insertIndex('clear-exception', 11, [1]);
        $service = new RecordingIndexMaintenanceService();
        $service->storageOutcomes[self::PREFIX . 'clear-exception'] = 'throw';

        $index = SearchIndex::findByIdOrHandle($id);
        self::assertNotNull($index);
        $result = $service->clearIndex($index);

        self::assertSame('failure', $result['status']);
        self::assertFalse($result['storageCleared']);
        self::assertSame(11, $this->storedCount($id));
        self::assertSame(1, $this->siteCount($id));
        self::assertSame([], $service->cacheCalls);
    }

    public function testClearReloadsPersistedIdentityBeforeSelectingStorageAndMetadata(): void
    {
        $id = $this->insertIndex('authoritative-clear', 13, [1, 2], 'persisted-backend');
        $caller = SearchIndex::findById($id);
        self::assertNotNull($caller);
        $caller->handle = self::PREFIX . 'caller-selected-target';
        $caller->source = 'config';
        $caller->backend = 'caller-selected-backend';
        $caller->siteId = [99];
        $caller->documentCount = 999;

        $service = new RecordingIndexMaintenanceService();
        $result = $service->clearIndex($caller);

        self::assertSame('success', $result['status']);
        self::assertSame(self::PREFIX . 'authoritative-clear', $result['handle']);
        self::assertSame(0, $this->storedCount($id));
        self::assertSame(2, $this->siteCount($id));
        self::assertSame([[
            'id' => $id,
            'handle' => self::PREFIX . 'authoritative-clear',
            'source' => 'database',
            'backend' => 'persisted-backend',
            'siteId' => [1, 2],
            'documentCount' => 13,
        ]], $service->storageIdentities);
        self::assertSame([
            'search:' . self::PREFIX . 'authoritative-clear',
            'autocomplete:' . self::PREFIX . 'authoritative-clear',
        ], $service->cacheCalls);
    }

    public function testDeleteReloadsPersistedIdentityBeforeDependencyPreflight(): void
    {
        $id = $this->insertIndex('authoritative-dependency', 17, [1]);
        $this->insertWidget('authoritative-dependency-widget', [
            self::PREFIX . 'authoritative-dependency',
        ]);
        $caller = SearchIndex::findById($id);
        self::assertNotNull($caller);
        $caller->handle = self::PREFIX . 'dependency-bypass';
        $caller->source = 'database';
        $caller->backend = 'caller-selected-backend';

        $service = new RecordingIndexMaintenanceService();
        $result = $service->deleteIndex($caller);

        self::assertSame('failure', $result['status']);
        self::assertSame(self::PREFIX . 'authoritative-dependency', $result['handle']);
        self::assertStringContainsString('1 widget', (string)$result['error']);
        self::assertSame([
            'preflight:' . self::PREFIX . 'authoritative-dependency',
        ], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
        self::assertTrue($this->indexExists($id));
        self::assertSame(17, $this->storedCount($id));
        self::assertSame(1, $this->siteCount($id));
    }

    public function testModelDeleteReloadsPersistedIdentityBeforeEveryMutation(): void
    {
        $id = $this->insertIndex('authoritative-delete', 18, [1, 2], 'persisted-backend');
        $caller = SearchIndex::findById($id);
        self::assertNotNull($caller);
        $caller->handle = self::PREFIX . 'delete-selected-target';
        $caller->source = 'config';
        $caller->backend = 'caller-selected-backend';
        $caller->siteId = [99];
        $caller->documentCount = 999;

        $service = new RecordingIndexMaintenanceService();
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);

        self::assertTrue($caller->delete());
        self::assertSame([
            'preflight:' . self::PREFIX . 'authoritative-delete',
            'clear:' . self::PREFIX . 'authoritative-delete',
        ], $service->events);
        self::assertSame([[
            'id' => $id,
            'handle' => self::PREFIX . 'authoritative-delete',
            'source' => 'database',
            'backend' => 'persisted-backend',
            'siteId' => [1, 2],
            'documentCount' => 18,
        ]], $service->storageIdentities);
        self::assertSame([
            'search:' . self::PREFIX . 'authoritative-delete',
            'autocomplete:' . self::PREFIX . 'authoritative-delete',
        ], $service->cacheCalls);
        self::assertFalse($this->indexExists($id));
        self::assertSame(0, $this->siteCount($id));
    }

    public function testDeleteReloadsPersistedIdentityBeforeConfigGuard(): void
    {
        $id = $this->insertIndex('authoritative-config', 19, [1, 2]);
        $authoritative = SearchIndex::findById($id);
        $caller = SearchIndex::findById($id);
        self::assertNotNull($authoritative);
        self::assertNotNull($caller);
        $authoritative->source = 'config';
        $caller->handle = self::PREFIX . 'config-bypass';
        $caller->source = 'database';
        $caller->backend = 'caller-selected-backend';

        $service = new RecordingIndexMaintenanceService();
        $service->authoritativeById[$id] = $authoritative;
        $result = $service->deleteIndex($caller);

        self::assertSame('failure', $result['status']);
        self::assertSame(self::PREFIX . 'authoritative-config', $result['handle']);
        self::assertSame(
            'This index is defined in config and cannot be deleted.',
            $result['error'],
        );
        self::assertSame([
            'preflight:' . self::PREFIX . 'authoritative-config',
        ], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
        self::assertTrue($this->indexExists($id));
        self::assertSame(19, $this->storedCount($id));
        self::assertSame(2, $this->siteCount($id));
    }

    public function testStaleIdClearAndDeleteHaveNoSideEffects(): void
    {
        $staleId = $this->insertIndex('stale-id', 23, [1, 2]);
        $controlId = $this->insertIndex('stale-control', 29, [1]);
        $caller = SearchIndex::findById($staleId);
        self::assertNotNull($caller);

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_index_sites}}', ['indexId' => $staleId])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['id' => $staleId])
            ->execute();
        SearchIndex::clearCache();

        $caller->handle = self::PREFIX . 'stale-selected-target';
        $caller->source = 'database';
        $caller->backend = 'caller-selected-backend';
        $service = new RecordingIndexMaintenanceService();

        $clearResult = $service->clearIndex($caller);
        $deleteResult = $service->deleteIndex($caller);

        self::assertSame('failure', $clearResult['status']);
        self::assertSame('failure', $deleteResult['status']);
        self::assertSame($staleId, $clearResult['id']);
        self::assertSame('', $clearResult['handle']);
        self::assertSame('', $deleteResult['handle']);
        self::assertSame([], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
        self::assertFalse($this->indexExists($staleId));
        self::assertSame(0, $this->siteCount($staleId));
        self::assertTrue($this->indexExists($controlId));
        self::assertSame(29, $this->storedCount($controlId));
        self::assertSame(1, $this->siteCount($controlId));
    }

    public function testUnsavedDatabaseClearAndModelDeleteFailWithoutSideEffects(): void
    {
        $controlId = $this->insertIndex('idless-database-control', 31, [1, 2]);
        $unsaved = new SearchIndex([
            'handle' => self::PREFIX . 'idless-database',
            'name' => 'Unsaved Database Index',
            'source' => 'database',
            'backend' => 'caller-selected-backend',
            'siteId' => [99],
            'documentCount' => 999,
        ]);
        $service = new RecordingIndexMaintenanceService();
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);

        $preflightError = $service->preflightDelete($unsaved);
        $clearResult = $service->clearIndex($unsaved);

        self::assertNotNull($preflightError);
        self::assertSame('failure', $clearResult['status']);
        self::assertFalse($unsaved->delete());
        self::assertSame([], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
        self::assertTrue($this->indexExists($controlId));
        self::assertSame(31, $this->storedCount($controlId));
        self::assertSame(2, $this->siteCount($controlId));
    }

    public function testIdlessConfigIdentityCannotResolveThroughDatabaseHandle(): void
    {
        $controlId = $this->insertIndex('idless-config-spoof', 37, [1]);
        $caller = new SearchIndex([
            'handle' => self::PREFIX . 'idless-config-spoof',
            'name' => 'Caller Config Identity',
            'source' => 'config',
            'backend' => 'caller-selected-backend',
        ]);
        $service = new RecordingIndexMaintenanceService();

        $clearResult = $service->clearIndex($caller);
        $deleteResult = $service->deleteIndex($caller);

        self::assertSame('failure', $clearResult['status']);
        self::assertSame('failure', $deleteResult['status']);
        self::assertSame([], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
        self::assertTrue($this->indexExists($controlId));
        self::assertSame(37, $this->storedCount($controlId));
        self::assertSame(1, $this->siteCount($controlId));
    }

    public function testIdlessConfigClearReloadsAuthoritativeConfigIdentity(): void
    {
        $caller = SearchIndex::findByHandle(self::CONFIG_INDEX_HANDLE);
        self::assertNotNull($caller);
        self::assertSame('config', $caller->source);
        $caller->id = null;
        $caller->name = 'Caller Config Name';
        $caller->backend = 'caller-selected-backend';
        $caller->siteId = [99];
        $caller->documentCount = 999;

        $service = new RecordingIndexMaintenanceService();
        $service->recordOnlyCountHandles[] = self::CONFIG_INDEX_HANDLE;
        $result = $service->clearIndex($caller);

        self::assertSame('success', $result['status']);
        self::assertSame(self::CONFIG_INDEX_HANDLE, $result['handle']);
        self::assertSame('Fixture Valid Minimal', $result['name']);
        self::assertSame([[
            'id' => SearchIndex::findByHandle(self::CONFIG_INDEX_HANDLE)?->id,
            'handle' => self::CONFIG_INDEX_HANDLE,
            'source' => 'config',
            'backend' => null,
            'siteId' => null,
            'documentCount' => SearchIndex::findByHandle(self::CONFIG_INDEX_HANDLE)?->documentCount,
        ]], $service->storageIdentities);
        self::assertSame([self::CONFIG_INDEX_HANDLE], $service->countCalls);
        self::assertSame([
            'search:' . self::CONFIG_INDEX_HANDLE,
            'autocomplete:' . self::CONFIG_INDEX_HANDLE,
        ], $service->cacheCalls);
    }

    public function testIdlessConfigDeleteReloadsThenRejectsAuthoritativeConfigIdentity(): void
    {
        $caller = SearchIndex::findByHandle(self::CONFIG_INDEX_HANDLE);
        self::assertNotNull($caller);
        self::assertSame('config', $caller->source);
        $caller->id = null;
        $caller->name = 'Caller Config Name';
        $caller->backend = 'caller-selected-backend';

        $service = new RecordingIndexMaintenanceService();
        $result = $service->deleteIndex($caller);

        self::assertSame('failure', $result['status']);
        self::assertSame(self::CONFIG_INDEX_HANDLE, $result['handle']);
        self::assertSame('Fixture Valid Minimal', $result['name']);
        self::assertSame(
            'This index is defined in config and cannot be deleted.',
            $result['error'],
        );
        self::assertSame([
            'preflight:' . self::CONFIG_INDEX_HANDLE,
        ], $service->events);
        self::assertSame([], $service->storageIdentities);
        self::assertSame([], $service->cacheCalls);
    }

    public function testDeleteMetadataFailureAfterStorageClearIsIrreversiblePartial(): void
    {
        $id = $this->insertIndex('delete-partial', 9, [1, 2]);
        $service = new RecordingIndexMaintenanceService();
        $service->metadataFailureHandles[] = self::PREFIX . 'delete-partial';
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexId' => $id]);

        $data = (new IndicesController('indices', SearchManager::$plugin))->actionDelete()->data;

        self::assertSame('partial', $data['status']);
        self::assertTrue($data['storageCleared']);
        self::assertTrue($data['countReconciled']);
        self::assertTrue($data['cachesInvalidated']);
        self::assertFalse($data['metadataDeleted']);
        self::assertSame(['rebuildIndex' => true, 'retryDelete' => true], $data['recovery']);
        self::assertSame(0, $this->storedCount($id));
        self::assertSame(2, $this->siteCount($id));
        self::assertSame([
            'search:' . self::PREFIX . 'delete-partial',
            'autocomplete:' . self::PREFIX . 'delete-partial',
        ], $service->cacheCalls);
    }

    public function testBulkDeletePreflightsAllThenOrdersFailureSuccessPartialUnattempted(): void
    {
        $failureId = $this->insertIndex('bulk-failure', 4, [1]);
        $successId = $this->insertIndex('bulk-success', 5, [1, 2]);
        $partialId = $this->insertIndex('bulk-partial', 6, [1]);
        $unattemptedId = $this->insertIndex('bulk-unattempted', 7, [1]);

        $service = new RecordingIndexMaintenanceService();
        $service->storageOutcomes[self::PREFIX . 'bulk-failure'] = false;
        $service->metadataFailureHandles[] = self::PREFIX . 'bulk-partial';
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexIds' => [$failureId, $successId, $partialId, $unattemptedId]]);

        $data = (new IndicesController('indices', SearchManager::$plugin))->actionBulkDelete()->data;

        self::assertSame('partial', $data['status']);
        self::assertSame(1, $data['count']);
        self::assertTrue($data['changed']);
        self::assertSame(
            ['failure', 'success', 'partial', 'unattempted'],
            array_column($data['results'], 'status'),
        );
        $firstClear = array_search('clear:' . self::PREFIX . 'bulk-failure', $service->events, true);
        self::assertIsInt($firstClear);
        self::assertGreaterThanOrEqual(4, $firstClear);
        self::assertSame([
            'preflight:' . self::PREFIX . 'bulk-failure',
            'preflight:' . self::PREFIX . 'bulk-success',
            'preflight:' . self::PREFIX . 'bulk-partial',
            'preflight:' . self::PREFIX . 'bulk-unattempted',
        ], array_slice($service->events, 0, 4));

        self::assertSame(4, $this->storedCount($failureId));
        self::assertFalse($this->indexExists($successId));
        self::assertSame(0, $this->siteCount($successId));
        self::assertSame(0, $this->storedCount($partialId));
        self::assertSame(1, $this->siteCount($partialId));
        self::assertSame(7, $this->storedCount($unattemptedId));
        self::assertSame([
            'search:' . self::PREFIX . 'bulk-success',
            'autocomplete:' . self::PREFIX . 'bulk-success',
            'search:' . self::PREFIX . 'bulk-partial',
            'autocomplete:' . self::PREFIX . 'bulk-partial',
        ], $service->cacheCalls);
    }

    public function testConsoleSingleClearReturnsNonZeroOnBackendFailure(): void
    {
        $this->insertIndex('console-failure', 8, [1]);
        $service = new RecordingIndexMaintenanceService();
        $service->storageOutcomes[self::PREFIX . 'console-failure'] = false;
        $this->swapPluginComponent('search-manager', 'indexMaintenance', $service);

        $controller = new CapturingIndexController('index', SearchManager::$plugin);
        $controller->handle = self::PREFIX . 'console-failure';

        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $controller->actionClear());
        self::assertStringContainsString('not fully cleared', $controller->output);
    }

    public function testRedisTargetDiscoveryDeduplicatesAndMapsEveryEffectiveTarget(): void
    {
        $backends = [
            $this->redisBackend('redis-a', 'redis-a.test', 6379, 2, false),
            $this->redisBackend('redis-a-duplicate', 'redis-a.test', 6379, 2, true),
            $this->redisBackend('redis-b', 'redis-b.test', 6380, 3, true),
        ];
        $indices = [
            $this->indexModel('index-a', 'redis-a'),
            $this->indexModel('index-a-duplicate', 'redis-a-duplicate'),
            $this->indexModel('index-b', 'redis-b'),
        ];

        $targets = (new StorageMaintenanceService())->getRedisTargets($backends, $indices);

        self::assertCount(2, $targets);
        self::assertSame('redis-a.test:6379:2', $targets[0]['key']);
        self::assertSame(['redis-a', 'redis-a-duplicate'], $targets[0]['backendHandles']);
        self::assertSame(['index-a', 'index-a-duplicate'], $targets[0]['indexHandles']);
        self::assertSame('redis-b.test:6380:3', $targets[1]['key']);
        self::assertSame(['index-b'], $targets[1]['indexHandles']);
    }

    public function testRedisFallbackWithoutBackendRowsUsesCraftDatabasePlusOne(): void
    {
        $originalCache = Craft::$app->cache;
        $connection = new \yii\redis\Connection([
            'hostname' => 'redis',
            'port' => 6379,
            'database' => 5,
        ]);
        Craft::$app->set('cache', new \yii\redis\Cache(['redis' => $connection]));

        try {
            $targets = (new StorageMaintenanceService())->getRedisTargets([], []);

            self::assertCount(1, $targets);
            self::assertTrue($targets[0]['fallback']);
            self::assertSame('redis', $targets[0]['host']);
            self::assertSame(6, $targets[0]['database']);
        } finally {
            Craft::$app->set('cache', $originalCache);
        }
    }

    public function testRedisClearContinuesAfterSafeTargetFailureAndReconcilesOnlySuccesses(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $service->redisTargets = [
            $this->target('redis-1', ['index-1']),
            $this->target('redis-2', ['index-2']),
            $this->target('redis-3', ['index-3']),
        ];
        $service->redisResults = [
            'redis-1' => ['status' => 'success', 'deletedCount' => 3],
            'redis-2' => ['status' => 'failure', 'deletedCount' => 0, 'error' => 'unreachable'],
            'redis-3' => ['status' => 'success', 'deletedCount' => 2],
        ];

        $result = $this->invokeProtected($service, 'clearRedisStorage');

        self::assertSame('partial', $result['status']);
        self::assertSame(5, $result['count']);
        self::assertSame(['index-1', 'index-3'], $service->reconciled);
        self::assertSame(['success', 'failure', 'success'], array_column($result['results'], 'status'));
    }

    public function testDatabaseClearCoversEightTablesInOneTransactionAndRollsBack(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $service->throwOnDatabaseDelete = 2;
        $service->insertTransactionMarker = true;

        $result = $this->invokeProtected($service, 'clearDatabaseStorage');

        self::assertSame('failure', $result['status']);
        self::assertSame([true, true], $service->databaseTransactionStates);
        self::assertFalse($this->indexHandleExists(self::TRANSACTION_MARKER));

        $success = new RecordingStorageMaintenanceService();
        $successResult = $this->invokeProtected($success, 'clearDatabaseStorage');
        self::assertSame('success', $successResult['status']);
        self::assertCount(8, $success->databaseTables);
        self::assertSame(array_fill(0, 8, true), $success->databaseTransactionStates);
    }

    public function testFileClearReportsFailureCompletePartialAndUnattemptedTruthfully(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $service->fileTargets = [
            $this->fileTarget('file-1', ['index-1']),
            $this->fileTarget('file-2', ['index-2']),
            $this->fileTarget('file-3', ['index-3']),
            $this->fileTarget('file-4', ['index-4']),
        ];
        $service->fileResults = [
            'file-1' => ['status' => 'failure', 'deletedCount' => 0, 'error' => 'read-only'],
            'file-2' => ['status' => 'success', 'deletedCount' => 2],
            'file-3' => ['status' => 'partial', 'deletedCount' => 1, 'error' => 'mid-delete'],
        ];

        $result = $this->invokeProtected($service, 'clearFileStorage');

        self::assertSame('partial', $result['status']);
        self::assertSame(3, $result['count']);
        self::assertSame(
            ['failure', 'success', 'partial', 'unattempted'],
            array_column($result['results'], 'status'),
        );
        self::assertSame(['index-2'], $service->reconciled);
    }

    public function testCpAndConsoleInvokeTheSameStorageAuthorityAndResultShape(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $this->swapPluginComponent('search-manager', 'storageMaintenance', $service);
        $this->withPostJson(['type' => 'database']);

        $cp = (new UtilitiesController('utilities', SearchManager::$plugin))
            ->actionClearStorageByType()
            ->data;
        $console = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $console->type = 'database';
        $exit = $console->actionClearStorage();

        self::assertSame(ExitCode::OK, $exit);
        self::assertSame(['database', 'database'], $service->clearCalls);
        self::assertSame('success', $cp['status']);
        self::assertStringContainsString('cleared', $console->output);
    }

    public function testOrphanPurgeReportsAllSuccessPartialAndAllFailureWithExitCodes(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $service->orphanPlan = ['database' => ['one', 'two']];
        $this->swapPluginComponent('search-manager', 'storageMaintenance', $service);

        $success = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $success->type = 'database';
        self::assertSame(ExitCode::OK, $success->actionPurgeOrphanedStorage());
        self::assertStringContainsString('Purge complete', $success->output);

        $service->orphanResults['two'] = 'failure';
        $partial = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $partial->type = 'database';
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $partial->actionPurgeOrphanedStorage());
        self::assertStringContainsString('partially completed', $partial->output);
        self::assertSame(['one', 'two', 'one', 'two'], $service->orphanCalls);

        $service->orphanResults['one'] = 'failure';
        $failed = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $failed->type = 'database';
        self::assertSame(ExitCode::UNSPECIFIED_ERROR, $failed->actionPurgeOrphanedStorage());
        self::assertStringContainsString('Purge failed', $failed->output);
    }

    public function testOrphanPurgePreservesDryRunCancellationInvalidTypeAndNoCandidates(): void
    {
        $service = new RecordingStorageMaintenanceService();
        $service->orphanPlan = ['database' => ['one']];
        $this->swapPluginComponent('search-manager', 'storageMaintenance', $service);

        $dryRun = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $dryRun->type = 'database';
        $dryRun->dryRun = true;
        self::assertSame(ExitCode::OK, $dryRun->actionPurgeOrphanedStorage());
        self::assertSame([], $service->orphanCalls);

        $cancelled = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $cancelled->type = 'database';
        $cancelled->confirmResult = false;
        self::assertSame(ExitCode::OK, $cancelled->actionPurgeOrphanedStorage());
        self::assertSame([], $service->orphanCalls);

        $invalid = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $invalid->type = 'invalid';
        self::assertSame(ExitCode::USAGE, $invalid->actionPurgeOrphanedStorage());

        $service->orphanPlan = ['database' => []];
        $none = new CapturingMaintenanceController('maintenance', SearchManager::$plugin);
        $none->type = 'database';
        self::assertSame(ExitCode::OK, $none->actionPurgeOrphanedStorage());
        self::assertStringContainsString('No orphaned storage handles', $none->output);
    }

    private function insertIndex(
        string $suffix,
        int $documentCount,
        array $siteIds,
        ?string $backend = null,
    ): int {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => self::PREFIX . $suffix,
            'handle' => self::PREFIX . $suffix,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => $backend,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'documentCount' => $documentCount,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $id = (int)Craft::$app->getDb()->getLastInsertID();
        foreach ($siteIds as $siteId) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_index_sites}}', [
                'indexId' => $id,
                'siteId' => $siteId,
            ])->execute();
        }
        SearchIndex::clearCache();
        return $id;
    }

    /**
     * @param list<string> $indexHandles
     */
    private function insertWidget(string $suffix, array $indexHandles): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $settings = WidgetConfig::defaultSettings();
        $settings['search']['indexHandles'] = $indexHandles;

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . $suffix,
            'name' => 'Destructive Lifecycle Widget',
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function storedCount(int $id): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT [[documentCount]] FROM {{%searchmanager_indices}} WHERE [[id]] = :id',
            ['id' => $id],
        )->queryScalar();
    }

    private function siteCount(int $id): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%searchmanager_index_sites}} WHERE [[indexId]] = :id',
            ['id' => $id],
        )->queryScalar();
    }

    private function indexExists(int $id): bool
    {
        return (bool)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%searchmanager_indices}} WHERE [[id]] = :id',
            ['id' => $id],
        )->queryScalar();
    }

    private function indexHandleExists(string $handle): bool
    {
        return (bool)Craft::$app->getDb()->createCommand(
            'SELECT COUNT(*) FROM {{%searchmanager_indices}} WHERE [[handle]] = :handle',
            ['handle' => $handle],
        )->queryScalar();
    }

    private function redisBackend(string $handle, string $host, int $port, int $database, bool $enabled): ConfiguredBackend
    {
        return new ConfiguredBackend([
            'handle' => $handle,
            'backendType' => 'redis',
            'enabled' => $enabled,
            'settings' => compact('host', 'port', 'database'),
        ]);
    }

    private function indexModel(string $handle, string $backend): SearchIndex
    {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->backend = $backend;
        return $index;
    }

    /**
     * @param list<string> $indexHandles
     * @return array<string, mixed>
     */
    private function target(string $key, array $indexHandles): array
    {
        return [
            'key' => $key,
            'host' => $key,
            'port' => 6379,
            'password' => null,
            'database' => 1,
            'settings' => ['host' => $key, 'port' => 6379, 'password' => null, 'database' => 1],
            'backendHandles' => [],
            'indexHandles' => $indexHandles,
            'fallback' => false,
        ];
    }

    /**
     * @param list<string> $indexHandles
     * @return array<string, mixed>
     */
    private function fileTarget(string $key, array $indexHandles): array
    {
        return [
            'key' => $key,
            'basePath' => '/tmp/' . $key,
            'configuredPath' => null,
            'backendHandles' => [],
            'indexHandles' => $indexHandles,
        ];
    }

    private function invokeProtected(object $service, string $method): array
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);
        $result = $reflection->invoke($service);
        self::assertIsArray($result);
        return $result;
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(array $permissions): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
    {
        $this->originalRequest ??= Craft::$app->getRequest();
        $this->originalResponse ??= Craft::$app->getResponse();
        $this->originalRequestMethod ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
        $this->originalRequest = null;
        $this->originalResponse = null;
        $this->originalRequestMethod = null;
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_index_sites}}', [
                'indexId' => (new \craft\db\Query())
                    ->select(['id'])
                    ->from('{{%searchmanager_indices}}')
                    ->where(['like', 'handle', self::PREFIX . '%', false]),
            ])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        SearchIndex::clearCache();
    }
}

final class RecordingIndexMaintenanceService extends IndexMaintenanceService
{
    /** @var array<string, bool|'throw'> */
    public array $storageOutcomes = [];
    /** @var list<string> */
    public array $metadataFailureHandles = [];
    /** @var list<string> */
    public array $cacheCalls = [];
    /** @var list<string> */
    public array $events = [];
    /** @var list<array<string, mixed>> */
    public array $storageIdentities = [];
    /** @var array<int, SearchIndex|null> */
    public array $authoritativeById = [];
    /** @var list<string> */
    public array $recordOnlyCountHandles = [];
    /** @var list<string> */
    public array $countCalls = [];

    protected function preflightAuthoritativeDelete(SearchIndex $index): ?string
    {
        $this->events[] = 'preflight:' . $index->handle;
        return parent::preflightAuthoritativeDelete($index);
    }

    protected function findIndexById(int $id): ?SearchIndex
    {
        if (array_key_exists($id, $this->authoritativeById)) {
            return $this->authoritativeById[$id];
        }

        return parent::findIndexById($id);
    }

    protected function clearBackendStorage(SearchIndex $index): bool
    {
        $this->events[] = 'clear:' . $index->handle;
        $this->storageIdentities[] = [
            'id' => $index->id,
            'handle' => $index->handle,
            'source' => $index->source,
            'backend' => $index->backend,
            'siteId' => $index->siteId,
            'documentCount' => $index->documentCount,
        ];
        $outcome = $this->storageOutcomes[$index->handle] ?? true;
        if ($outcome === 'throw') {
            throw new \RuntimeException('recording backend failure');
        }
        return $outcome;
    }

    protected function updateDocumentCount(SearchIndex $index): bool
    {
        if (in_array($index->handle, $this->recordOnlyCountHandles, true)) {
            $this->countCalls[] = $index->handle;
            return true;
        }

        return parent::updateDocumentCount($index);
    }

    protected function invalidateIndexCaches(SearchIndex $index): array
    {
        $this->cacheCalls[] = 'search:' . $index->handle;
        $this->cacheCalls[] = 'autocomplete:' . $index->handle;
        return [];
    }

    protected function deleteIndexMetadata(SearchIndex $index): void
    {
        if (in_array($index->handle, $this->metadataFailureHandles, true)) {
            throw new \RuntimeException('recording metadata failure');
        }
        parent::deleteIndexMetadata($index);
    }
}

final class RecordingStorageMaintenanceService extends StorageMaintenanceService
{
    /** @var list<array<string, mixed>> */
    public array $redisTargets = [];
    /** @var array<string, array<string, mixed>> */
    public array $redisResults = [];
    /** @var list<array<string, mixed>> */
    public array $fileTargets = [];
    /** @var array<string, array<string, mixed>> */
    public array $fileResults = [];
    /** @var list<string> */
    public array $reconciled = [];
    /** @var list<string> */
    public array $databaseTables = [];
    /** @var list<bool> */
    public array $databaseTransactionStates = [];
    public ?int $throwOnDatabaseDelete = null;
    public bool $insertTransactionMarker = false;
    /** @var list<string> */
    public array $clearCalls = [];
    /** @var array<string, list<string>> */
    public array $orphanPlan = [];
    /** @var array<string, string> */
    public array $orphanResults = [];
    /** @var list<string> */
    public array $orphanCalls = [];

    public function clearStorageByType(string $type): array
    {
        $this->clearCalls[] = $type;
        return [
            'status' => 'success',
            'success' => true,
            'type' => $type,
            'count' => 1,
            'changed' => true,
            'message' => ucfirst($type) . ' storage cleared',
            'error' => null,
            'results' => [],
        ];
    }

    public function getRedisTargets(?array $backends = null, ?array $indices = null): array
    {
        return $this->redisTargets;
    }

    public function getFileTargets(?array $backends = null, ?array $indices = null): array
    {
        return $this->fileTargets;
    }

    protected function clearRedisTarget(array $target): array
    {
        return array_merge([
            'target' => $target['key'],
            'indexHandles' => $target['indexHandles'],
        ], $this->redisResults[$target['key']]);
    }

    protected function clearFileTarget(array $target): array
    {
        return array_merge([
            'target' => $target['key'],
            'indexHandles' => $target['indexHandles'],
        ], $this->fileResults[$target['key']]);
    }

    protected function reconcileClearedIndices(array $indexHandles): array
    {
        $this->reconciled = array_merge($this->reconciled, $indexHandles);
        return ['success' => true, 'updated' => $indexHandles, 'failed' => []];
    }

    protected function deleteDatabaseTable(string $table): int
    {
        $this->databaseTables[] = $table;
        $this->databaseTransactionStates[] = Craft::$app->getDb()->getTransaction()?->getIsActive() === true;
        if ($this->insertTransactionMarker && count($this->databaseTables) === 1) {
            $now = Db::prepareDateForDb(new \DateTimeImmutable());
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
                'name' => DestructiveLifecycleMaintenanceTest::TRANSACTION_MARKER,
                'handle' => DestructiveLifecycleMaintenanceTest::TRANSACTION_MARKER,
                'elementType' => Entry::class,
                'siteId' => null,
                'criteria' => '{}',
                'transformerClass' => '',
                'headingLevels' => null,
                'language' => null,
                'backend' => null,
                'enabled' => 1,
                'enableAnalytics' => 1,
                'disableStopWords' => 0,
                'skipEntriesWithoutUrl' => 0,
                'splitSections' => 0,
                'retrievableFields' => '["*"]',
                'source' => 'database',
                'documentCount' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
        if ($this->throwOnDatabaseDelete === count($this->databaseTables)) {
            throw new \RuntimeException('recording table failure');
        }
        return 1;
    }

    public function getOrphanedStoragePlan(array $types): array
    {
        return $this->orphanPlan;
    }

    public function purgeOrphanedStorageHandle(string $type, string $fullIndexHandle): array
    {
        $this->orphanCalls[] = $fullIndexHandle;
        $status = $this->orphanResults[$fullIndexHandle] ?? 'success';
        return [
            'status' => $status,
            'success' => $status === 'success',
            'type' => $type,
            'handle' => $fullIndexHandle,
            'attemptedTargets' => 1,
            'successfulTargets' => $status === 'success' ? 1 : 0,
            'errors' => $status === 'success' ? [] : ['recording failure'],
        ];
    }
}

final class CapturingIndexController extends IndexController
{
    public string $output = '';

    public function confirm($message, $default = false)
    {
        return true;
    }

    public function stdout($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    public function stderr($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }
}

final class CapturingMaintenanceController extends MaintenanceController
{
    public string $output = '';
    public bool $confirmResult = true;

    public function confirm($message, $default = false)
    {
        return $this->confirmResult;
    }

    public function stdout($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }

    public function stderr($string)
    {
        $this->output .= (string)$string;
        return strlen((string)$string);
    }
}
