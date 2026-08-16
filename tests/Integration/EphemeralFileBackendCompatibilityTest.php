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
use craft\web\View;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\services\SetupService;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the persistent File backend's ephemeral-filesystem boundary.
 */
final class EphemeralFileBackendCompatibilityTest extends TestCase
{
    private bool $hadEphemeralSetting;
    private mixed $originalEphemeralSetting;

    protected function setUp(): void
    {
        $this->hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $this->originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        parent::setUp();
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator(
                new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_ABSENT),
            ),
        );
    }

    protected function tearDown(): void
    {
        try {
            $_SERVER['CRAFT_EPHEMERAL'] = false;
            parent::tearDown();
        } finally {
            if ($this->hadEphemeralSetting) {
                $_SERVER['CRAFT_EPHEMERAL'] = $this->originalEphemeralSetting;
            } else {
                unset($_SERVER['CRAFT_EPHEMERAL']);
            }
        }
    }

    public function testFileBackendConstructionAndStatusDoNotWriteOnEphemeralFilesystems(): void
    {
        $defaultPath = Craft::$app->getRuntimePath() . '/search-manager/indices';
        self::assertDirectoryDoesNotExist($defaultPath);
        $this->enableEphemeralFilesystem();

        $backend = new FileBackend();
        $status = $backend->getStatus();
        $backend->listIndices();

        self::assertFalse($backend->isAvailable());
        self::assertFalse($status['available']);
        self::assertSame(['name', 'enabled', 'configured', 'available', 'path'], array_keys($status));
        self::assertDirectoryDoesNotExist($defaultPath);
    }

    public function testDirectFileStorageConstructionFailsBeforeAnyFilesystemAccess(): void
    {
        $parentPath = $this->createOwnedStorageDirectory('ephemeral-file-constructor');
        $candidatePath = $parentPath . '/indices';
        $this->enableEphemeralFilesystem();

        try {
            new FileStorage('ephemeral-index', $candidatePath);
            self::fail('FileStorage construction must fail on an ephemeral filesystem.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'File index storage is unavailable on ephemeral filesystems.',
                $exception->getMessage(),
            );
        }

        self::assertDirectoryDoesNotExist($candidatePath);
        self::assertSame([], array_values(array_diff(scandir($parentPath) ?: [], ['.', '..'])));
    }

    public function testFileOperationsFailThroughBoundedContractsWithoutCreatingArtifacts(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('ephemeral-file-operations') . '/indices';
        $this->enableEphemeralFilesystem();
        $backend = new FileBackend();
        $backend->setConfiguredSettings(['storagePath' => $candidatePath]);

        self::assertFalse($backend->index('ephemeral-index', [
            'elementId' => 101,
            'siteId' => 1,
            'title' => 'Ephemeral',
            'content' => 'No persistent writes',
        ]));
        self::assertSame(['hits' => [], 'total' => 0], $backend->search('ephemeral-index', 'ephemeral'));
        self::assertFalse($backend->clearIndex('ephemeral-index'));

        try {
            $backend->getStorage('ephemeral-index');
            self::fail('Direct storage access must fail on an ephemeral filesystem.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString($candidatePath, $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($candidatePath);
    }

    public function testMaintenanceSurfacesRemainReadOnlyAndReportFileStorageUnavailable(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('ephemeral-file-maintenance') . '/indices';
        $fileBackend = $this->configuredBackend('ephemeral-maintenance-file', 'file', $candidatePath, 'config');
        $this->enableEphemeralFilesystem();
        $service = SearchManager::$plugin->storageMaintenance;

        self::assertSame([], $service->getFileTargets([$fileBackend], []));
        self::assertSame([], $service->getOrphanedStoragePlan(['file'])['file']);
        $projection = $service->getProjection();
        self::assertFalse($projection['stats']['file']['available']);
        self::assertSame([], $projection['stats']['file']['targets']);

        $clear = $service->clearStorageByType('file');
        self::assertFalse($clear['success']);
        self::assertSame(0, $clear['count']);
        self::assertStringNotContainsString($candidatePath, (string)$clear['error']);

        $purge = $service->purgeOrphanedStorageHandle('file', 'ephemeral-orphan');
        self::assertFalse($purge['success']);
        self::assertSame(0, $purge['attemptedTargets']);
        self::assertDirectoryDoesNotExist($candidatePath);
    }

    public function testExistingStateIsPreservedWhileNewFileSelectionsAreRejected(): void
    {
        $fileBackend = $this->persistBackend('ephemeral-selection-file', 'file');
        $mysqlBackend = $this->persistBackend('ephemeral-selection-mysql', 'mysql');
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $mysqlBackend->handle;
        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_settings}}',
            ['defaultBackendHandle' => $mysqlBackend->handle],
        )->execute();
        $this->enableEphemeralFilesystem();

        $newSettings = new DatabaseManagedEphemeralSettings();
        $newSettings->defaultBackendHandle = $fileBackend->handle;
        $newSettings->validate(['defaultBackendHandle']);
        self::assertTrue($newSettings->hasErrors('defaultBackendHandle'));

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_settings}}',
            ['defaultBackendHandle' => $fileBackend->handle],
        )->execute();
        $preservedSettings = new DatabaseManagedEphemeralSettings();
        $preservedSettings->defaultBackendHandle = $fileBackend->handle;
        $preservedSettings->validate(['defaultBackendHandle']);
        self::assertFalse($preservedSettings->hasErrors('defaultBackendHandle'));
        $correctedSettings = new DatabaseManagedEphemeralSettings();
        $correctedSettings->defaultBackendHandle = $mysqlBackend->handle;
        $correctedSettings->validate(['defaultBackendHandle']);
        self::assertFalse($correctedSettings->hasErrors('defaultBackendHandle'));

        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $existingIndex = $this->persistIndex('ephemeral-existing-index', $fileBackend->handle);
        $this->enableEphemeralFilesystem();
        $existingIndex->validate(['backend']);
        self::assertFalse($existingIndex->hasErrors('backend'));
        $existingIndex->backend = $mysqlBackend->handle;
        $existingIndex->clearErrors();
        $existingIndex->validate(['backend']);
        self::assertFalse($existingIndex->hasErrors('backend'));

        $newIndex = $this->index('ephemeral-new-index', $fileBackend->handle, true, 'database');
        $newIndex->validate(['backend']);
        self::assertTrue($newIndex->hasErrors('backend'));

        self::assertTrue($fileBackend->enabled);
        self::assertSame('database', $fileBackend->source);
        self::assertSame([], $fileBackend->settings);
    }

    public function testStrictRuntimeResolutionBlocksFileWithoutFallingBackToDefault(): void
    {
        $fileBackend = $this->persistBackend('ephemeral-runtime-file', 'file');
        $mysqlBackend = $this->persistBackend('ephemeral-runtime-mysql', 'mysql');
        $index = $this->persistIndex('ephemeral-runtime-index', $fileBackend->handle);
        SearchManager::$plugin->getSettings()->defaultBackendHandle = $mysqlBackend->handle;
        $this->enableEphemeralFilesystem();

        $backendService = new BackendService();
        self::assertNull($backendService->getBackendForIndex($index->handle));
        self::assertFalse($backendService->index($index->handle, ['elementId' => 1, 'siteId' => 1]));
        self::assertSame([], $backendService->search($index->handle, 'query'));

        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
            $index->handle,
            DependencyService::ACTION_TARGETED_REBUILD,
        );
        self::assertFalse($capability['allowed']);
        self::assertSame('backend-unavailable', $capability['reasonCode']);
        self::assertNull(SearchManager::$plugin->dependencies->getStrictBackendTarget(
            $index->handle,
            DependencyService::ACTION_TARGETED_REBUILD,
        ));

        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAutocompleteCache = false;
        self::assertSame([], SearchManager::$plugin->autocomplete->suggest('query', $index->handle, ['minLength' => 1]));
    }

    public function testSetupIgnoresUnusedFileButBlocksEffectiveDefaultAndEnabledIndices(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('ephemeral-file-setup') . '/indices';
        $fileBackend = $this->configuredBackend('ephemeral-setup-file', 'file', $candidatePath, 'config');
        $mysqlBackend = $this->configuredBackend('ephemeral-setup-mysql', 'mysql');
        $pgsqlBackend = $this->configuredBackend('ephemeral-setup-pgsql', 'pgsql');
        $service = new ControlledEphemeralSetupService();
        $service->backends = [
            $fileBackend->handle => $fileBackend,
            $mysqlBackend->handle => $mysqlBackend,
            $pgsqlBackend->handle => $pgsqlBackend,
        ];
        $this->enableEphemeralFilesystem();

        $unusedSettings = $this->readySettings($mysqlBackend->handle);
        $service->indices = [
            $this->index('mysql-index', $mysqlBackend->handle, true, 'database'),
            $this->index('postgres-index', $pgsqlBackend->handle, true, 'database'),
        ];
        $unusedStatus = $service->getStatus($unusedSettings);
        self::assertTrue($unusedStatus['complete']);
        self::assertTrue($unusedStatus['backendReadinessValid']);
        self::assertSame([], $unusedStatus['backendReadinessFindings']);

        $defaultSettings = new ConfigManagedEphemeralSettings();
        $defaultSettings->ipHashSalt = str_repeat('a', 40);
        $defaultSettings->defaultBackendHandle = $fileBackend->handle;
        $service->indices = [];
        $defaultStatus = $service->getStatus($defaultSettings);
        self::assertFalse($defaultStatus['complete']);
        self::assertSame(['backendReadiness'], $defaultStatus['missing']);
        self::assertCount(1, $defaultStatus['backendReadinessFindings']);
        self::assertNull($defaultStatus['backendReadinessFindings'][0]['handle']);
        self::assertStringContainsString('config file', $defaultStatus['backendReadinessFindings'][0]['message']);

        $service->indices = [
            $this->index('config-file-index', $fileBackend->handle, true, 'config'),
            $this->index('database-file-index', $fileBackend->handle, true, 'database'),
            $this->index('disabled-file-index', $fileBackend->handle, false, 'database'),
            $this->index('postgres-index', $pgsqlBackend->handle, true, 'database'),
        ];
        $indexStatus = $service->getStatus($unusedSettings);
        self::assertFalse($indexStatus['complete']);
        self::assertSame(
            ['config-file-index', 'database-file-index'],
            array_column($indexStatus['backendReadinessFindings'], 'handle'),
        );
        self::assertStringContainsString(
            'config/search-manager.php',
            $indexStatus['backendReadinessFindings'][0]['message'],
        );
        self::assertDirectoryDoesNotExist($candidatePath);
    }

    public function testSetupFindingsAreUniqueAndTriggerTheSharedCrossPageNotice(): void
    {
        $fileBackend = $this->configuredBackend('ephemeral-setup-notice-file', 'file');
        $service = new ControlledEphemeralSetupService();
        $service->backends = [$fileBackend->handle => $fileBackend];
        $service->indices = [
            $this->index('inherited-file-index', null, true, 'database'),
            $this->index('explicit-file-index', $fileBackend->handle, true, 'database'),
        ];
        $this->enableEphemeralFilesystem();
        $status = $service->getStatus($this->readySettings($fileBackend->handle));

        self::assertSame(
            [null, 'inherited-file-index', 'explicit-file-index'],
            array_column($status['backendReadinessFindings'], 'handle'),
        );
        self::assertCount(3, $status['backendReadinessFindings']);
        self::assertCount(3, array_unique(array_map(
            static fn(array $finding): string => ($finding['handle'] ?? 'default') . ':' . $finding['key'],
            $status['backendReadinessFindings'],
        )));

        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/_partials/setup-incomplete-summary',
            [
                'selectedSubnavItem' => 'indices',
                'setupStatus' => $status,
            ],
            View::TEMPLATE_MODE_CP,
        );
        self::assertStringContainsString('lr-info-box--setup-incomplete', $html);
        self::assertStringContainsString(
            'Select a valid default backend or index backend before using this action.',
            $html,
        );
    }

    public function testDurableFileStorageAndNonFileBackendsRemainAvailable(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('durable-file-compatibility') . '/indices';
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        self::assertTrue((new FileBackend())->isAvailable());
        $storage = new FileStorage('durable-index', $candidatePath);
        $storage->storeTermDocument('durable', 1, 101, 2, 'en');

        self::assertSame(['1:101' => 2], $storage->getTermDocuments('durable', 1));
        self::assertFileExists($candidatePath . '/durable-index/manifest.json');
        self::assertDirectoryExists($candidatePath . '/durable-index/terms');
        $storage->clearAll();
        self::assertSame([], $storage->getTermDocuments('durable', 1));

        $backendService = new BackendService();
        self::assertInstanceOf(MySqlBackend::class, $backendService->getBackend('mysql'));
        self::assertInstanceOf(PostgreSqlBackend::class, $backendService->getBackend('pgsql'));

        $fileBackend = $this->configuredBackend('durable-setup-file', 'file', $candidatePath);
        $setup = new ControlledEphemeralSetupService();
        $setup->backends = [$fileBackend->handle => $fileBackend];
        $setup->indices = [$this->index('durable-file-index', $fileBackend->handle, true, 'database')];
        self::assertTrue($setup->getStatus($this->readySettings($fileBackend->handle))['backendReadinessValid']);
    }

    private function enableEphemeralFilesystem(): void
    {
        $_SERVER['CRAFT_EPHEMERAL'] = true;
    }

    private function persistBackend(string $handle, string $type): ConfiguredBackend
    {
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $backend = $this->configuredBackend($handle, $type, null, 'database');
        self::assertTrue($backend->save(), implode(' ', $backend->getErrorSummary(true)));

        return $backend;
    }

    private function configuredBackend(
        string $handle,
        string $type,
        ?string $storagePath = null,
        string $source = 'database',
    ): ConfiguredBackend {
        $backend = new ConfiguredBackend([
            'name' => $handle,
            'handle' => $handle,
            'backendType' => $type,
            'enabled' => true,
            'source' => $source,
        ]);
        $backend->settings = $storagePath === null ? [] : ['storagePath' => $storagePath];

        return $backend;
    }

    private function persistIndex(string $handle, string $backendHandle): SearchIndex
    {
        $_SERVER['CRAFT_EPHEMERAL'] = false;
        $index = $this->index($handle, $backendHandle, false, 'database');
        self::assertTrue($index->save(), implode(' ', $index->getErrorSummary(true)));
        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['enabled' => 1],
            ['id' => $index->id],
        )->execute();
        $index->enabled = true;
        SearchIndex::clearCache();

        return $index;
    }

    private function index(
        string $handle,
        ?string $backendHandle,
        bool $enabled,
        string $source,
    ): SearchIndex {
        return new SearchIndex([
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'backend' => $backendHandle,
            'enabled' => $enabled,
            'source' => $source,
        ]);
    }

    private function readySettings(string $defaultBackendHandle): Settings
    {
        $settings = new Settings();
        $settings->ipHashSalt = str_repeat('a', 40);
        $settings->defaultBackendHandle = $defaultBackendHandle;
        SearchManager::$plugin->getSettings()->defaultBackendHandle = $defaultBackendHandle;

        return $settings;
    }
}

final class ControlledEphemeralSetupService extends SetupService
{
    /** @var array<string, ConfiguredBackend> */
    public array $backends = [];

    /** @var list<SearchIndex> */
    public array $indices = [];

    protected function findBackend(string $handle): ?ConfiguredBackend
    {
        return $this->backends[$handle] ?? null;
    }

    protected function findIndices(): array
    {
        return $this->indices;
    }
}

class DatabaseManagedEphemeralSettings extends Settings
{
    public function isOverriddenByConfig(string $attribute): bool
    {
        return false;
    }
}

final class ConfigManagedEphemeralSettings extends DatabaseManagedEphemeralSettings
{
    public function isOverriddenByConfig(string $attribute): bool
    {
        return $attribute === 'defaultBackendHandle';
    }
}
