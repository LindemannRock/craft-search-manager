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
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\IndexCountBackendInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * End-to-end action-capability coverage for index maintenance.
 *
 * @since 5.54.0
 */
final class IndexMaintenanceCapabilityTest extends TestCase
{
    private const PREFIX = 'sm-pr169-';
    private const BACKEND = self::PREFIX . 'external';
    private const DISABLED_BACKEND = self::PREFIX . 'disabled-backend';
    private const INTERNAL_BACKEND = self::PREFIX . 'internal';

    private mixed $originalConfigCache = null;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?object $originalUser = null;
    private string $originalRequestMethod = 'GET';
    private IndexMaintenanceRecordingBackendService $backendService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->backendService = new IndexMaintenanceRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $this->backendService);
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRows();
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
            if ($this->originalRequest !== null) {
                Craft::$app->set('request', $this->originalRequest);
            }
            if ($this->originalResponse !== null) {
                Craft::$app->set('response', $this->originalResponse);
            }
            if ($this->originalUser !== null) {
                Craft::$app->set('user', $this->originalUser);
            }
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        } finally {
            parent::tearDown();
        }
    }

    public function testCatalogueProjectsDistinctCapabilitiesAndStableReasonsWithoutLivenessChecks(): void
    {
        $enabled = self::PREFIX . 'enabled';
        $disabled = self::PREFIX . 'disabled';
        $warning = self::PREFIX . 'warning';
        $invalid = self::PREFIX . 'invalid';
        $unresolved = self::PREFIX . 'unresolved';
        $this->setConfig([
            $enabled => $this->configIndex('Enabled'),
            $disabled => $this->configIndex('Disabled', false),
            $warning => $this->configIndex('   '),
            $invalid => $this->configIndex('Invalid', true, 'missing\\elements\\Unavailable'),
            $unresolved => 'not-an-index-definition',
        ]);

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $enabledActions = $catalogue[$enabled]['actions'];
        self::assertTrue($enabledActions[DependencyService::ACTION_TARGETED_REBUILD]['allowed']);
        self::assertTrue($enabledActions[DependencyService::ACTION_AUTOMATIC_REBUILD]['allowed']);
        self::assertTrue($enabledActions[DependencyService::ACTION_REBUILD_ALL]['allowed']);
        self::assertTrue($enabledActions[DependencyService::ACTION_CLEAR_DATA]['allowed']);
        self::assertTrue($enabledActions[DependencyService::ACTION_CLEAR_CACHE]['allowed']);
        self::assertTrue($enabledActions[DependencyService::ACTION_SYNC_COUNT]['allowed']);
        self::assertSame('config-owned-index', $enabledActions[DependencyService::ACTION_DELETE]['reasonCode']);

        $disabledActions = $catalogue[$disabled]['actions'];
        self::assertTrue($disabledActions[DependencyService::ACTION_TARGETED_REBUILD]['allowed']);
        self::assertTrue($disabledActions[DependencyService::ACTION_CLEAR_DATA]['allowed']);
        self::assertTrue($disabledActions[DependencyService::ACTION_CLEAR_CACHE]['allowed']);
        self::assertTrue($disabledActions[DependencyService::ACTION_SYNC_COUNT]['allowed']);
        self::assertSame('index-disabled', $disabledActions[DependencyService::ACTION_AUTOMATIC_REBUILD]['reasonCode']);
        self::assertSame('index-disabled', $disabledActions[DependencyService::ACTION_REBUILD_ALL]['reasonCode']);

        self::assertTrue($catalogue[$warning]['actions'][DependencyService::ACTION_TARGETED_REBUILD]['allowed']);
        self::assertTrue($catalogue[$invalid]['actions'][DependencyService::ACTION_VIEW]['allowed']);
        self::assertFalse($catalogue[$invalid]['actions'][DependencyService::ACTION_TARGETED_REBUILD]['allowed']);
        self::assertSame('config-invalid', $catalogue[$invalid]['actions'][DependencyService::ACTION_TARGETED_REBUILD]['reasonCode']);
        self::assertTrue($catalogue[$invalid]['actions'][DependencyService::ACTION_CLEAR_CACHE]['allowed']);
        self::assertArrayNotHasKey($unresolved, $catalogue);

        $plan = $this->ownedPlan();
        self::assertSame([$enabled, $warning], $plan['participants']);
        self::assertSame('index-disabled', $this->skipCode($plan, $disabled));
        self::assertSame('config-invalid', $this->skipCode($plan, $invalid));
        self::assertSame('config-definition-unresolved', $this->skipCode($plan, $unresolved));
        self::assertSame(0, $this->backendService->backend->availabilityChecks);
        self::assertSame(0, $this->backendService->backend->countCalls);
        self::assertSame(0, $this->backendService->backend->clearCalls);
    }

    public function testInvalidDependencyDeniesQueueAndClearButAllowsHandleScopedCacheRecovery(): void
    {
        $handle = self::PREFIX . 'missing-transformer';
        $id = $this->insertIndex($handle, true, self::BACKEND, 'missing\\transformers\\Unavailable', 17);
        $index = SearchIndex::findById($id);
        self::assertNotNull($index);

        $queueBefore = $this->queuedJobsFor($handle);
        $rebuild = SearchManager::$plugin->indexing->rebuildIndexResult($handle);
        self::assertFalse($rebuild['queued']);
        self::assertSame('transformer-unavailable', $rebuild['reasonCode']);
        self::assertSame($queueBefore, $this->queuedJobsFor($handle));

        $clear = SearchManager::$plugin->indexMaintenance->clearIndex($index);
        self::assertSame('failure', $clear['status']);
        self::assertSame('transformer-unavailable', $clear['reasonCode']);
        self::assertSame(17, $this->storedCount($id));
        self::assertSame(0, $this->backendService->backend->clearCalls);
        self::assertSame(0, $this->backendService->cacheClears);

        $cache = SearchManager::$plugin->indexMaintenance->clearIndexCache($index);
        self::assertSame('success', $cache['status']);
        self::assertSame(1, $this->backendService->cacheClears);
        self::assertSame(17, $this->storedCount($id));
    }

    public function testSyncCountVisibilityIsProjectedFromCanonicalBackendApplicability(): void
    {
        $external = $this->insertIndex(self::PREFIX . 'sync-external', true, self::BACKEND);
        $invalidExternal = $this->insertIndex(self::PREFIX . 'sync-invalid-external', true, self::DISABLED_BACKEND);
        $internal = $this->insertIndex(self::PREFIX . 'sync-internal', true, self::INTERNAL_BACKEND);
        $indeterminate = $this->insertIndex(self::PREFIX . 'sync-indeterminate', true, self::PREFIX . 'missing-backend');

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $externalAction = $catalogue[self::PREFIX . 'sync-external']['actions'][DependencyService::ACTION_SYNC_COUNT];
        self::assertTrue($externalAction['visible']);
        self::assertTrue($externalAction['allowed']);

        $invalidExternalAction = $catalogue[self::PREFIX . 'sync-invalid-external']['actions'][DependencyService::ACTION_SYNC_COUNT];
        self::assertTrue($invalidExternalAction['visible']);
        self::assertFalse($invalidExternalAction['allowed']);
        self::assertSame('backend-disabled', $invalidExternalAction['reasonCode']);

        $internalAction = $catalogue[self::PREFIX . 'sync-internal']['actions'][DependencyService::ACTION_SYNC_COUNT];
        self::assertFalse($internalAction['visible']);
        self::assertFalse($internalAction['allowed']);
        self::assertSame('backend-count-unsupported', $internalAction['reasonCode']);

        $indeterminateAction = $catalogue[self::PREFIX . 'sync-indeterminate']['actions'][DependencyService::ACTION_SYNC_COUNT];
        self::assertFalse($indeterminateAction['visible']);
        self::assertFalse($indeterminateAction['allowed']);
        self::assertSame('backend-not-found', $indeterminateAction['reasonCode']);

        foreach ([$internal, $indeterminate] as $id) {
            $index = SearchIndex::findById($id);
            self::assertNotNull($index);
            $result = SearchManager::$plugin->indexMaintenance->syncIndexCount($index);
            self::assertFalse($result['success']);
        }
        self::assertSame(0, $this->backendService->backend->countCalls);
    }

    public function testRenderedIndexRowsUseCanonicalSyncCountVisibilityAndDenial(): void
    {
        $fixtures = $this->syncCountPresentationFixtures();
        $this->actAsMaintenanceUser('row');

        foreach ($fixtures as $fixture) {
            $html = $this->renderCaptured($this->indexListResponse($fixture['index']->handle));
            $this->assertRenderedSyncCount($html, 'row', $fixture['visible'], $fixture['allowed'], $fixture['reason']);
        }
    }

    public function testRenderedIndexViewAndEditUseCanonicalSyncCountVisibilityAndDenial(): void
    {
        $fixtures = $this->syncCountPresentationFixtures();
        $this->actAsMaintenanceUser('details');

        foreach ($fixtures as $fixture) {
            $controller = new IndexMaintenanceIndicesController('indices', SearchManager::$plugin);
            $viewHtml = $this->renderCaptured($controller->actionView($fixture['index']->handle));
            $editHtml = $this->renderCaptured($controller->actionEdit($fixture['index']->id));
            $this->assertRenderedSyncCount($viewHtml, 'view', $fixture['visible'], $fixture['allowed'], $fixture['reason']);
            $this->assertRenderedSyncCount($editHtml, 'edit', $fixture['visible'], $fixture['allowed'], $fixture['reason']);
        }
    }

    public function testStrictBackendOverrideNeverFallsBackAndSyncFailurePreservesMetadata(): void
    {
        $missingHandle = self::PREFIX . 'missing-backend';
        $missingId = $this->insertIndex($missingHandle, true, self::PREFIX . 'does-not-exist', '', 23);
        $missing = SearchIndex::findById($missingId);
        self::assertNotNull($missing);

        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $missingHandle,
            DependencyService::ACTION_SYNC_COUNT,
        );
        self::assertSame('backend-not-found', $capability['reasonCode']);
        $result = SearchManager::$plugin->indexMaintenance->syncIndexCount($missing);
        self::assertSame('failure', $result['status']);
        self::assertSame('backend-not-found', $result['reasonCode']);
        self::assertSame(23, $this->storedCount($missingId));
        self::assertSame(0, $this->backendService->backend->countCalls);

        $runtimeHandle = self::PREFIX . 'runtime-outage';
        $runtimeId = $this->insertIndex($runtimeHandle, true, self::BACKEND, '', 29);
        $runtime = SearchIndex::findById($runtimeId);
        self::assertNotNull($runtime);
        self::assertTrue(SearchManager::$plugin->dependencies->getIndexActionCapability(
            $runtimeHandle,
            DependencyService::ACTION_SYNC_COUNT,
        )['allowed']);
        $this->backendService->backend->throwOnCount = true;

        $runtimeResult = SearchManager::$plugin->indexMaintenance->syncIndexCount($runtime);
        self::assertSame('failure', $runtimeResult['status']);
        self::assertSame(29, $this->storedCount($runtimeId));
        self::assertSame(1, $this->backendService->backend->countCalls);
        self::assertStringNotContainsString('synthetic provider secret', (string)$runtimeResult['error']);
    }

    public function testHealthyDisabledTargetedMaintenanceExecutesButAggregateAndAutomaticSkip(): void
    {
        $handle = self::PREFIX . 'healthy-disabled';
        $id = $this->insertIndex($handle, false, self::BACKEND, '', 31);
        $index = SearchIndex::findById($id);
        self::assertNotNull($index);

        self::assertTrue(SearchManager::$plugin->indexing->rebuildIndex($handle));
        self::assertFalse(SearchManager::$plugin->indexing->rebuildIndexAutomatically($handle));
        self::assertNotContains($handle, $this->ownedPlan()['participants']);

        (new RebuildIndexJob(['indexHandle' => $handle]))->execute(Craft::$app->queue);
        self::assertGreaterThanOrEqual(1, $this->backendService->backend->clearCalls);

        $clear = SearchManager::$plugin->indexMaintenance->clearIndex($index);
        self::assertSame('success', $clear['status']);
        $sync = SearchManager::$plugin->indexMaintenance->syncIndexCount(SearchIndex::findById($id));
        self::assertSame('success', $sync['status']);
        self::assertSame(41, $this->storedCount($id));
    }

    public function testQueuedSingleRebuildOriginsFailClosedAcrossAutomaticAndAffectedDisableRaces(): void
    {
        $configurationChange = self::PREFIX . 'configuration-change-race';
        $affected = self::PREFIX . 'affected-race';
        $this->insertIndex($configurationChange, true, self::BACKEND);
        $affectedId = $this->insertIndex($affected, true, self::BACKEND);
        $affectedIndex = SearchIndex::findById($affectedId);
        self::assertNotNull($affectedIndex);

        $indexing = new IndexMaintenanceRecordingIndexingService();
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);

        self::assertTrue($indexing->rebuildIndexAutomatically($configurationChange));
        self::assertSame([$affected], $indexing->scheduleAffectedIndexRebuilds([$affectedIndex], 'initial'));
        self::assertSame([], $indexing->scheduleAffectedIndexRebuilds([$affectedIndex], 'coalesced'));
        self::assertCount(2, $indexing->jobs);
        self::assertSame(
            [DependencyService::ACTION_AUTOMATIC_REBUILD, DependencyService::ACTION_AUTOMATIC_REBUILD],
            array_map(static fn(RebuildIndexJob $job): string => $job->capabilityAction, $indexing->jobs),
        );

        $cachedEnabledHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::findAll(),
        );
        self::assertContains($configurationChange, $cachedEnabledHandles);
        self::assertContains($affected, $cachedEnabledHandles);

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['enabled' => 0],
            ['handle' => [$configurationChange, $affected]],
        )->execute();

        $indexing->jobs[0]->execute(Craft::$app->queue);
        $indexing->jobs[1]->execute(Craft::$app->queue);
        self::assertCount(3, $indexing->jobs, 'The dirty affected marker should produce one coalesced follow-up.');
        self::assertSame(DependencyService::ACTION_AUTOMATIC_REBUILD, $indexing->jobs[2]->capabilityAction);
        $indexing->jobs[2]->execute(Craft::$app->queue);
        self::assertSame(0, $this->backendService->backend->clearCalls);

        (new RebuildIndexJob([
            'indexHandle' => $configurationChange,
            'capabilityAction' => DependencyService::ACTION_CLEAR_CACHE,
        ]))->execute(Craft::$app->queue);
        self::assertSame(0, $this->backendService->backend->clearCalls, 'Unsupported serialized origins must fail closed.');

        (new RebuildIndexJob(['indexHandle' => $configurationChange]))->execute(Craft::$app->queue);
        self::assertSame(1, $this->backendService->backend->clearCalls, 'Targeted rebuild remains allowed for a healthy disabled index.');
    }

    public function testQueuedAutomaticRebuildRefreshesBeforeMissingIndexRaceCheck(): void
    {
        $handle = self::PREFIX . 'deleted-race';
        $id = $this->insertIndex($handle, true, self::BACKEND);
        $indexing = new IndexMaintenanceRecordingIndexingService();
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);

        self::assertTrue($indexing->rebuildIndexAutomatically($handle));
        self::assertCount(1, $indexing->jobs);
        self::assertContains($handle, array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::findAll(),
        ));

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['id' => $id])
            ->execute();

        $indexing->jobs[0]->execute(Craft::$app->queue);
        self::assertSame(0, $this->backendService->backend->clearCalls);
        self::assertNull(SearchIndex::findByHandle($handle));
    }

    public function testRebuildAllPlanNoEligibleCollisionAndExecutionRaceAreTruthful(): void
    {
        $enabled = self::PREFIX . 'all-enabled';
        $disabled = self::PREFIX . 'all-disabled';
        $invalid = self::PREFIX . 'all-invalid';
        $this->insertIndex($enabled, true, self::BACKEND);
        $this->insertIndex($disabled, false, self::BACKEND);
        $this->insertIndex($invalid, true, self::BACKEND, 'missing\\transformers\\Unavailable');
        $this->setConfig([self::PREFIX . 'all-unresolved' => 'invalid']);

        $plan = $this->ownedPlan();
        self::assertSame([$enabled], $plan['participants']);
        self::assertSame('index-disabled', $this->skipCode($plan, $disabled));
        self::assertSame('transformer-unavailable', $this->skipCode($plan, $invalid));
        self::assertSame('config-definition-unresolved', $this->skipCode($plan, self::PREFIX . 'all-unresolved'));

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['enabled' => 0],
            ['handle' => $enabled],
        )->execute();
        (new RebuildIndexJob([
            'indexHandles' => $plan['participants'],
            'structuralSkips' => $plan['skips'],
        ]))->execute(Craft::$app->queue);
        self::assertSame(0, $this->backendService->backend->clearCalls);

        $noEligible = $this->withOnlySearchIndices(
            $this->ownedIndices(),
            static function(): array {
                SearchManager::$plugin->dependencies->clearIndexCatalogue();
                return SearchManager::$plugin->indexing->rebuildAllResult();
            },
        );
        self::assertFalse($noEligible['queued']);
        self::assertSame('no-eligible-indices', $noEligible['reasonCode']);

        $collision = self::PREFIX . 'collision';
        $this->insertIndex($collision, true, self::BACKEND);
        $this->setConfig([$collision => $this->configIndex('Config Winner')]);
        $collisionPlan = SearchManager::$plugin->dependencies->getRebuildAllPlan();
        self::assertFalse($collisionPlan['allowed']);
        self::assertSame('index-handle-collision', $collisionPlan['reasonCode']);
        self::assertSame([$collision], $collisionPlan['collisions']);
        self::assertSame('config', SearchManager::$plugin->dependencies->getIndexCatalogue()[$collision]['source']);
    }

    public function testRebuildAllRuntimeFailureContinuesEligibleSiblingsAndFailsAggregate(): void
    {
        $failed = self::PREFIX . 'a-runtime-failure';
        $sibling = self::PREFIX . 'b-runtime-sibling';
        $this->insertIndex($failed, true, self::BACKEND);
        $this->insertIndex($sibling, true, self::BACKEND);
        $plan = $this->ownedPlan();
        self::assertSame([$failed, $sibling], $plan['participants']);
        $this->backendService->backend->throwClearHandles[$failed] = true;

        try {
            (new RebuildIndexJob([
                'indexHandles' => $plan['participants'],
                'structuralSkips' => $plan['skips'],
            ]))->execute(Craft::$app->queue);
            self::fail('Expected an eligible runtime failure to fail the aggregate.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($failed, $e->getMessage());
            self::assertStringNotContainsString('synthetic provider secret', $e->getMessage());
        }

        self::assertContains($failed, $this->backendService->backend->clearedHandles);
        self::assertContains($sibling, $this->backendService->backend->clearedHandles);
    }

    public function testBrokenDatabaseDeleteUsesStrictTargetButInvalidTargetAndConfigOwnershipDeny(): void
    {
        $broken = self::PREFIX . 'delete-broken';
        $brokenId = $this->insertIndex($broken, true, self::BACKEND, 'missing\\transformers\\Unavailable');
        $brokenModel = SearchIndex::findById($brokenId);
        self::assertNotNull($brokenModel);
        $deleted = SearchManager::$plugin->indexMaintenance->deleteIndex($brokenModel);
        self::assertSame('success', $deleted['status']);
        self::assertNull(SearchIndex::findById($brokenId));

        $invalid = self::PREFIX . 'delete-invalid-target';
        $invalidId = $this->insertIndex($invalid, true, self::PREFIX . 'missing-backend');
        $invalidModel = SearchIndex::findById($invalidId);
        self::assertNotNull($invalidModel);
        $denied = SearchManager::$plugin->indexMaintenance->deleteIndex($invalidModel);
        self::assertSame('failure', $denied['status']);
        self::assertSame('backend-not-found', $denied['reasonCode']);
        self::assertNotNull(SearchIndex::findById($invalidId));

        $config = self::PREFIX . 'delete-config';
        $this->setConfig([$config => $this->configIndex('Protected Config')]);
        self::assertSame('config-owned-index', SearchManager::$plugin->dependencies->getIndexActionCapability(
            $config,
            DependencyService::ACTION_DELETE,
        )['reasonCode']);
    }

    public function testEveryCanonicalReasonAndAggregateNoticeIsTranslatedInAllLocales(): void
    {
        $keys = [
            'This index no longer exists.',
            'Fix this index in config/search-manager.php before using this action.',
            'Fix this unresolved index definition in config/search-manager.php before rebuilding.',
            'The configured element type is unavailable. Restore it before using this action.',
            'The configured transformer is unavailable. Restore it before using this action.',
            'A plugin required by this index is disabled. Enable it before using this action.',
            'Select a valid default backend or index backend before using this action.',
            'The selected backend does not exist. Correct the backend handle before using this action.',
            'The selected backend is disabled. Enable it or select another backend before using this action.',
            'The selected backend configuration is invalid. Correct it before using this action.',
            'The selected backend type is not supported.',
            'The selected backend cannot be initialized from its configuration.',
            'This backend is unavailable on this host. Select a durable supported backend.',
            'The index storage identity is invalid. Correct the index prefix or handle before using this action.',
            'This backend does not support syncing the document count.',
            'Disabled indices are excluded from automatic and Rebuild All operations.',
            'This index is still in use and cannot be deleted.',
            'Rebuild All is unavailable because one or more index handles exist in both config and the database.',
            'No enabled, structurally valid indices are eligible for Rebuild All.',
            'This action is not available for the current index configuration.',
            'Eligible indices were queued. Some indices were skipped because they are disabled or structurally invalid.',
        ];
        $files = glob(dirname(__DIR__, 2) . '/src/translations/*/search-manager.php') ?: [];
        self::assertCount(12, $files);
        foreach ($files as $file) {
            $translations = require $file;
            self::assertIsArray($translations);
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $translations, $file);
                self::assertNotSame('', trim((string)$translations[$key]), $file);
            }
        }
    }

    public function testUiControllersConsoleServicesAndJobConsumeTheSharedProjection(): void
    {
        $paths = [
            'src/controllers/IndicesController.php' => 'indexMaintenance',
            'src/controllers/UtilitiesController.php' => 'rebuildAllResult',
            'src/console/controllers/IndexController.php' => 'rebuildIndexResult',
            'src/services/IndexingService.php' => 'getIndexActionCapability',
            'src/services/IndexMaintenanceService.php' => 'getIndexActionCapability',
            'src/jobs/RebuildIndexJob.php' => 'getIndexActionCapability',
        ];
        foreach ($paths as $path => $needle) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
            self::assertIsString($source);
            self::assertStringContainsString($needle, $source, $path);
        }

        foreach (['index.twig', 'view.twig', 'edit.twig'] as $template) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/indices/' . $template);
            self::assertIsString($source);
            self::assertStringContainsString('actions.', $source, $template);
            self::assertStringNotContainsString('getEffectiveBackend', $source, $template);
        }
    }

    /** @return array<string, mixed> */
    private function configIndex(string $name, bool $enabled = true, string $elementType = Entry::class): array
    {
        return [
            'name' => $name,
            'elementType' => $elementType,
            'backend' => self::BACKEND,
            'enabled' => $enabled,
        ];
    }

    /** @param array<string, mixed> $indices */
    private function setConfig(array $indices): void
    {
        $config = [
            'backends' => [
                self::BACKEND => [
                    'name' => 'index maintenance External Backend',
                    'backendType' => 'algolia',
                    'settings' => [
                        'applicationId' => 'test-application',
                        'adminApiKey' => 'test-admin-key',
                    ],
                    'enabled' => true,
                ],
                self::DISABLED_BACKEND => [
                    'name' => 'index maintenance Disabled Backend',
                    'backendType' => 'algolia',
                    'settings' => [
                        'applicationId' => 'test-application',
                        'adminApiKey' => 'test-admin-key',
                    ],
                    'enabled' => false,
                ],
                self::INTERNAL_BACKEND => [
                    'name' => 'index maintenance Internal Backend',
                    'backendType' => 'mysql',
                    'settings' => [],
                    'enabled' => true,
                ],
            ],
            'indices' => $indices,
        ];
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = $config;
        $this->setConfigCache($cache);
        $validation = (new ConfigIndexValidator())->validateConfig($config);
        $this->swapPluginComponent('search-manager', 'configIndexValidator', new FixedConfigIndexValidator($validation));
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function insertIndex(
        string $handle,
        bool $enabled,
        ?string $backend,
        string $transformer = '',
        int $documentCount = 0,
    ): int {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{"sections":["sm-pr169-no-such-section"]}',
            'transformerClass' => $transformer,
            'headingLevels' => null,
            'language' => null,
            'backend' => $backend,
            'enabled' => (int)$enabled,
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
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /** @param array<string, mixed> $plan */
    private function skipCode(array $plan, string $handle): ?string
    {
        foreach ($plan['skips'] as $skip) {
            if ($skip['handle'] === $handle) {
                return $skip['reasonCode'];
            }
        }

        return null;
    }

    private function storedCount(int $id): int
    {
        return (int)(new Query())
            ->select(['documentCount'])
            ->from('{{%searchmanager_indices}}')
            ->where(['id' => $id])
            ->scalar();
    }

    private function queuedJobsFor(string $handle): int
    {
        return (int)(new Query())
            ->from($this->queueTable())
            ->where(['like', 'job', $handle])
            ->count();
    }

    /**
     * @return list<array{index: SearchIndex, visible: bool, allowed: bool, reason: string|null}>
     */
    private function syncCountPresentationFixtures(): array
    {
        $definitions = [
            [self::PREFIX . 'render-external', self::BACKEND],
            [self::PREFIX . 'render-invalid-external', self::DISABLED_BACKEND],
            [self::PREFIX . 'render-internal', self::INTERNAL_BACKEND],
            [self::PREFIX . 'render-indeterminate', self::PREFIX . 'missing-backend'],
        ];
        foreach ($definitions as [$handle, $backend]) {
            $this->insertIndex($handle, true, $backend);
        }

        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $fixtures = [];
        foreach ($definitions as [$handle]) {
            $index = SearchIndex::findByHandle($handle);
            self::assertNotNull($index);
            $action = $catalogue[$handle]['actions'][DependencyService::ACTION_SYNC_COUNT];
            $fixtures[] = [
                'index' => $index,
                'visible' => $action['visible'],
                'allowed' => $action['allowed'],
                'reason' => $action['reason'],
            ];
        }

        return $fixtures;
    }

    private function actAsMaintenanceUser(string $suffix): void
    {
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalUser = Craft::$app->getUser();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $user = $this->createTestUser(self::PREFIX . 'render-' . $suffix);
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:manageIndices',
            'searchManager:editIndices',
            'searchManager:deleteIndices',
            'searchManager:rebuildIndices',
            'searchManager:clearIndices',
            'searchManager:clearCache',
        ]);
        $this->actingAs($user);
        $renderUser = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?\craft\elements\User
            {
                return null;
            }
        };
        $renderUser->setIdentity($user);
        Craft::$app->set('user', $renderUser);
    }

    private function indexListResponse(string $handle): \yii\web\Response
    {
        $request = Craft::$app->getRequest();
        $queryParams = $request->getQueryParams();
        $request->setQueryParams(['search' => $handle]);

        try {
            return (new IndexMaintenanceIndicesController('indices', SearchManager::$plugin))->actionIndex();
        } finally {
            $request->setQueryParams($queryParams);
        }
    }

    private function renderCaptured(\yii\web\Response $response): string
    {
        $template = $response->data['template'] ?? null;
        $variables = $response->data['variables'] ?? null;
        self::assertIsString($template);
        self::assertIsArray($variables);

        $currentUser = Craft::$app->getUser()->getIdentity();
        self::assertNotNull($currentUser);
        $variables['currentUser'] = $currentUser;
        if ($template === 'search-manager/indices/edit') {
            $variables['docsManagerTransformerAvailable'] = false;
        }

        return Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
    }

    private function assertRenderedSyncCount(
        string $html,
        string $surface,
        bool $visible,
        bool $allowed,
        ?string $reason,
    ): void {
        $label = 'Sync Count from Backend';
        if (!$visible) {
            self::assertStringNotContainsString($label, $html, $surface);
            return;
        }

        self::assertStringContainsString($label, $html, $surface);
        if ($allowed) {
            $enabledMarker = $surface === 'row' ? 'data-action="sync-count"' : 'id="sync-count-btn"';
            self::assertStringContainsString($enabledMarker, $html, $surface);
            return;
        }

        self::assertNotNull($reason);
        self::assertStringContainsString('aria-disabled="true"', $html, $surface);
        self::assertStringContainsString($reason, $html, $surface);
        if ($surface !== 'row') {
            self::assertMatchesRegularExpression(
                '/<a class="disabled" aria-disabled="true"[^>]*>Sync Count from Backend<\/a>/',
                $html,
                $surface,
            );
            self::assertStringNotContainsString('<span class="menu-item disabled"', $html, $surface);
        }
    }

    /** @return list<SearchIndex> */
    private function ownedIndices(): array
    {
        return array_values(array_filter(
            SearchIndex::findAll(),
            static fn(SearchIndex $index): bool => str_starts_with($index->handle, self::PREFIX),
        ));
    }

    /** @return array<string, mixed> */
    private function ownedPlan(): array
    {
        return $this->withOnlySearchIndices(
            $this->ownedIndices(),
            static function(): array {
                SearchManager::$plugin->dependencies->clearIndexCatalogue();
                return SearchManager::$plugin->dependencies->getRebuildAllPlan();
            },
        );
    }

    private function purgeRows(): void
    {
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();
        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $ids)])
                ->execute();
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $ids)])
                ->execute();
        }
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::PREFIX])
            ->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }
}

final class IndexMaintenanceIndicesController extends IndicesController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): \yii\web\Response
    {
        return new Response(['data' => compact('template', 'variables')]);
    }
}

/**
 * Deterministic structural backend that never contacts a provider.
 *
 * @since 5.54.0
 */
final class IndexMaintenanceStructuralBackend extends FileBackend implements IndexCountBackendInterface
{
    public int $availabilityChecks = 0;
    public int $countCalls = 0;
    public int $clearCalls = 0;
    public bool $throwOnCount = false;
    /** @var array<string, true> */
    public array $throwClearHandles = [];
    /** @var list<string> */
    public array $clearedHandles = [];

    public function init(): void
    {
    }

    public function clearIndex(string $indexName): bool
    {
        $this->clearCalls++;
        $this->clearedHandles[] = $indexName;
        if (isset($this->throwClearHandles[$indexName])) {
            throw new \RuntimeException('synthetic provider secret');
        }

        return true;
    }

    public function isAvailable(): bool
    {
        $this->availabilityChecks++;
        return false;
    }

    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int
    {
        $this->countCalls++;
        if ($this->throwOnCount) {
            throw new \RuntimeException('synthetic provider secret');
        }

        return 41;
    }

    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int
    {
        return $this->getDocumentCount($indexName, $siteId);
    }
}

/**
 * Records the checked backend boundary while avoiding live services.
 *
 * @since 5.54.0
 */
final class IndexMaintenanceRecordingBackendService extends BackendService
{
    public IndexMaintenanceStructuralBackend $backend;
    public int $cacheClears = 0;

    public function init(): void
    {
        parent::init();
        $this->backend = new IndexMaintenanceStructuralBackend();
    }

    public function createBackendFromConfig(ConfiguredBackend $configuredBackend): ?BackendInterface
    {
        return $this->backend;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->cacheClears++;
    }
}

/**
 * Records serialized rebuild jobs without using the shared application queue.
 *
 * @since 5.54.0
 */
final class IndexMaintenanceRecordingIndexingService extends IndexingService
{
    /** @var list<RebuildIndexJob> */
    public array $jobs = [];

    /** @var array<string, array{active: true, dirty: bool}> */
    private array $states = [];

    protected function pushIndexRebuildJob(RebuildIndexJob $job): string|int|null
    {
        $this->jobs[] = $job;
        return count($this->jobs);
    }

    protected function withAffectedRebuildLock(string $indexHandle, callable $callback): mixed
    {
        return $callback();
    }

    protected function getAffectedRebuildState(string $indexHandle): ?array
    {
        return $this->states[$indexHandle] ?? null;
    }

    protected function setAffectedRebuildState(string $indexHandle, bool $dirty): void
    {
        $this->states[$indexHandle] = ['active' => true, 'dirty' => $dirty];
    }

    protected function deleteAffectedRebuildState(string $indexHandle): void
    {
        unset($this->states[$indexHandle]);
    }

    protected function pushAffectedRebuildJob(RebuildIndexJob $job): string|int|null
    {
        $this->jobs[] = $job;
        return count($this->jobs);
    }
}
