<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests;

use Craft;
use craft\base\ElementInterface;
use craft\cache\FileCache;
use craft\db\Query;
use craft\elements\Entry;
use craft\queue\Queue;
use craft\web\AssetManager;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use lindemannrock\searchmanager\tests\Support\DeterministicFixtureManifest;
use lindemannrock\searchmanager\tests\Support\OwnedProcessRegistry;
use lindemannrock\searchmanager\tests\Support\ProcessRunOwner;
use Throwable;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\LoaderInterface;
use yii\db\Connection;
use yii\db\Transaction;
use yii\web\ForbiddenHttpException;

/**
 * Base test case for search-manager integration tests.
 *
 * Extends the shared {@see IntegrationTestCase} for component snapshot/restore
 * and generic Query helpers, and layers plugin-specific shorthand on top:
 *  - direct accessors for the sync services
 *  - per-test transaction, queue, runtime/cache, and pending-sync ownership
 *    boundaries
 *  - {@see installStubBackend()} convenience wrapper
 *  - {@see findWorkingIndexAndElement()} live-data discovery helper
 *
 * Subclasses can override `setUp()` for additional fixture work but should
 * call `parent::setUp()` to keep those boundaries active.
 *
 * @since 5.46.0
 */
abstract class TestCase extends IntegrationTestCase
{
    private static ?self $activeTest = null;

    protected PendingSyncRepository $repository;
    protected PendingSyncProcessor $processor;

    /** @var array<string, mixed>|null */
    private ?array $settingsAttributesSnapshot = null;
    private ?string $pluginEditionSnapshot = null;
    private ?Transaction $testTransaction = null;
    private ?object $originalQueue = null;
    private ?object $originalCache = null;
    private ?string $originalRuntimePath = null;
    private ?LoaderInterface $originalCpTwigLoader = null;
    private string|false|null $originalRootAlias = null;
    private string|false|null $originalWebrootAlias = null;
    private string|false|null $originalWebAlias = null;
    private ?string $testQueueTable = null;
    private ?string $testQueueRawTable = null;
    private ?string $testQueueShadowTable = null;
    private ?string $testPendingSyncTable = null;
    private ?string $testPendingSyncShadowTable = null;
    /** @var list<string> */
    private array $testStorageTables = [];
    /** @var list<string> */
    private array $testStorageShadowTables = [];
    private bool $isolationFinished = false;
    private bool $baseStateInitialised = false;
    private ?OwnedProcessRegistry $processRegistry = null;
    /** @var list<string> */
    private array $ownedTempPaths = [];
    /** @var array<int, resource> */
    private array $ownedStreams = [];
    /** @var list<callable(): void> */
    private array $ownedCleanupCallbacks = [];
    /** @var array<string, object> */
    private array $originalAppComponents = [];

    protected function setUp(): void
    {
        self::$activeTest = $this;
        $this->isolationFinished = false;
        $this->processRegistry = new OwnedProcessRegistry();

        try {
            parent::setUp();
            $this->baseStateInitialised = true;
            $this->snapshotAppComponents();
            $this->settingsAttributesSnapshot = SearchManager::$plugin->getSettings()->getAttributes();
            // Cache lifecycle tests opt into invalidation at the operation boundary.
            SearchManager::$plugin->getSettings()->clearCacheOnSave = false;
            $this->isolateRuntimeAndCache();
            $this->isolateAssetResources();
            $this->isolatePendingSyncTable();
            $this->isolateQueue();
            $this->isolateSearchStorageTables();
            $this->testTransaction = Craft::$app->getDb()->beginTransaction();

            $this->repository = SearchManager::$plugin->pendingSyncs;
            $this->processor = SearchManager::$plugin->pendingSyncProcessor;
            SearchIndex::clearCache();
        } catch (Throwable $exception) {
            try {
                $this->finishIsolation();
            } catch (Throwable $cleanupException) {
                fwrite(STDERR, 'Search Manager setup cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
            }

            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->finishIsolation();
    }

    /**
     * Runner fallback for child tearDown methods that fail before reaching
     * parent::tearDown().
     *
     * @since 5.54.0
     */
    public static function finishActiveTestIsolation(): void
    {
        $active = self::$activeTest;
        if ($active !== null) {
            $active->finishIsolation();
        }
    }

    /**
     * Force Search Manager to an edition for the remainder of the test.
     *
     * The original edition and Craft's permission registry are restored
     * automatically during teardown.
     */
    protected function forcePluginEdition(string $edition): void
    {
        if ($this->pluginEditionSnapshot === null) {
            $this->pluginEditionSnapshot = SearchManager::$plugin->edition;
        }

        SearchManager::$plugin->edition = $edition;
        Craft::$app->getUserPermissions()->reset();
    }

    /**
     * Swap `SearchManager::$plugin->backend` for a {@see StubBackend} so the
     * test can observe which operations the processor drove and force partial-
     * failure paths. Auto-restored in tearDown by the base class.
     */
    protected function installStubBackend(): StubBackend
    {
        $stub = new StubBackend();
        $this->swapPluginComponent('search-manager', 'backend', $stub);

        return $stub;
    }

    protected function queueTable(): string
    {
        if ($this->testQueueTable === null) {
            throw new \LogicException('The per-test queue table has not been initialised.');
        }

        return $this->testQueueTable;
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    protected function registerOwnedProcess($process, array $pipes, string $label = 'test-child'): void
    {
        $this->processRegistry?->register($process, $pipes, $label);
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{exitCode: int, output: string, error: string, pid: int, signaled: bool, termSignal: int}
     */
    protected function finishOwnedProcess($process, array $pipes, bool $terminate = false): array
    {
        if ($this->processRegistry === null) {
            throw new \LogicException('The per-test process registry has not been initialised.');
        }

        return $this->processRegistry->finish($process, $pipes, $terminate);
    }

    protected function trackOwnedTempPath(string $path): void
    {
        ProcessRunOwner::registerPath($path);
        if (!in_array($path, $this->ownedTempPaths, true)) {
            $this->ownedTempPaths[] = $path;
        }
        $this->trackTempPath($path);
    }

    protected function reserveOwnedTempPath(string $label): string
    {
        $path = ProcessRunOwner::reservePath($label);
        $this->trackOwnedTempPath($path);

        return $path;
    }

    protected function createOwnedTempDirectory(string $label): string
    {
        $path = ProcessRunOwner::createDirectory($label);
        $this->trackOwnedTempPath($path);

        return $path;
    }

    protected function createOwnedStorageDirectory(string $label): string
    {
        $path = ProcessRunOwner::createStorageDirectory($label);
        $this->trackOwnedTempPath($path);

        return $path;
    }

    protected function registerRollbackPendingRow(string $uid): void
    {
        ProcessRunOwner::registerRollbackRow('searchmanager_pending_syncs', $uid);
    }

    /** @param resource $stream */
    protected function registerOwnedStream($stream): void
    {
        $this->ownedStreams[get_resource_id($stream)] = $stream;
    }

    protected function registerOwnedCleanup(callable $cleanup): void
    {
        array_unshift($this->ownedCleanupCallbacks, $cleanup);
    }

    protected function createIndependentDatabaseConnection(): Connection
    {
        $db = Craft::$app->getDb();
        $connection = new Connection([
            'dsn' => $db->dsn,
            'username' => $db->username,
            'password' => $db->password,
            'charset' => $db->charset,
            'tablePrefix' => $db->tablePrefix,
            'attributes' => $db->attributes,
        ]);
        $connection->open();

        return $connection;
    }

    protected function fetchSearchIndexStatsByHandle(string $handle): ?array
    {
        $row = (new Query())
            ->select(['documentCount', 'lastIndexed', 'dateUpdated'])
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handle])
            ->one();

        return $row === false ? null : $row;
    }

    protected function fetchSettingsRow(): ?array
    {
        $row = (new Query())
            ->from('{{%searchmanager_settings}}')
            ->where(['id' => 1])
            ->one();

        return $row === false ? null : $row;
    }

    /**
     * Limit SearchIndex::findAll() to a test-owned set while direct indexing.
     *
     * This keeps indexElementNow() from fanning out into real CP indices when a
     * test only means to exercise its marker index. The cache is restored
     * immediately after the callback.
     *
     * @param list<SearchIndex> $indices
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    protected function withOnlySearchIndices(array $indices, callable $callback): mixed
    {
        $property = new \ReflectionProperty(SearchIndex::class, 'allCache');
        $property->setAccessible(true);
        $original = $property->getValue();

        $expiresAtProperty = new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt');
        $expiresAtProperty->setAccessible(true);
        $originalExpiresAt = $expiresAtProperty->getValue();

        $property->setValue(null, $indices);
        $expiresAtProperty->setValue(null, microtime(true) + 3600.0);
        $settings = SearchManager::$plugin->getSettings();
        $originalClearCacheOnSave = $settings->clearCacheOnSave;
        // Direct-indexing consumers assert the cache invalidation contract.
        $settings->clearCacheOnSave = true;

        try {
            return $callback();
        } finally {
            $settings->clearCacheOnSave = $originalClearCacheOnSave;
            $property->setValue(null, $original);
            $expiresAtProperty->setValue(null, $originalExpiresAt);
        }
    }

    /**
     * Replace Search Manager's cached config indices for a test.
     *
     * @param array<string, mixed> $indices
     */
    protected function withConfigFileIndices(array $indices): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = ['indices' => $indices];
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    protected function configCache(): mixed
    {
        $property = new \ReflectionProperty(BaseConfigFileHelper::class, '_configCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    protected function setConfigCache(mixed $cache): void
    {
        $property = new \ReflectionProperty(BaseConfigFileHelper::class, '_configCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cpSection(string $key): array
    {
        foreach (SearchManager::$plugin->getCpSections(SearchManager::$plugin->getSettings()) as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        self::fail("{$key} CP section was not registered.");
    }

    protected function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected the Standard edition gate to reject the operation.');
        } catch (ForbiddenHttpException) {
            self::addToAssertionCount(1);
        }
    }

    private function isolateRuntimeAndCache(): void
    {
        $this->originalRuntimePath = Craft::$app->getRuntimePath();
        $this->originalCache = Craft::$app->getCache();

        $runtimePath = $this->createOwnedTempDirectory('runtime');
        Craft::$app->setRuntimePath($runtimePath);
        Craft::$app->set('cache', new FileCache([
            'cachePath' => $runtimePath . DIRECTORY_SEPARATOR . 'cache',
            'keyPrefix' => 'search-manager-test-' . bin2hex(random_bytes(8)),
        ]));
    }

    private function isolateAssetResources(): void
    {
        $this->originalRootAlias = Craft::getRootAlias('@root');
        $this->originalWebrootAlias = Craft::getRootAlias('@webroot');
        $this->originalWebAlias = Craft::getRootAlias('@web');

        $root = $this->createOwnedTempDirectory('project-root');
        $webroot = $root . DIRECTORY_SEPARATOR . 'web';
        $resourcePath = $webroot . DIRECTORY_SEPARATOR . 'cpresources';
        if (!mkdir($resourcePath, 0700, true) && !is_dir($resourcePath)) {
            throw new \RuntimeException("Unable to create isolated Craft resource path: {$resourcePath}");
        }

        Craft::setAlias('@root', $root);
        Craft::setAlias('@webroot', $webroot);
        Craft::setAlias('@web', '/');
        if (Craft::$app->has('assetManager')) {
            $assetManager = Craft::$app->get('assetManager');
            if (is_object($assetManager)) {
                $this->originalAppComponents['assetManager'] = $assetManager;
            }
        }
        Craft::$app->set('assetManager', new AssetManager([
            'basePath' => $resourcePath,
            'baseUrl' => '/cpresources',
        ]));

        $twig = Craft::$app->getView()->getTwig(\craft\web\View::TEMPLATE_MODE_CP);
        $this->originalCpTwigLoader = $twig->getLoader();
        $twig->setLoader(new ChainLoader([
            new ArrayLoader([
                '_layouts/components/notifications' => '<div id="notifications" role="status"></div>',
            ]),
            $this->originalCpTwigLoader,
        ]));
    }

    /**
     * Shadow the permanent pending-sync table for this connection only.
     *
     * MySQL resolves a same-name temporary table before its permanent sibling,
     * so production repository SQL runs unchanged while owner rows remain
     * invisible and unreachable to every query, claim, retry, purge, and
     * delete issued by the test connection.
     */
    private function isolatePendingSyncTable(): void
    {
        $db = Craft::$app->getDb();
        if ($db->getDriverName() !== 'mysql') {
            throw new \RuntimeException('A12-1 pending-sync isolation currently requires MySQL. PostgreSQL coverage belongs to A12-4.');
        }

        $this->testPendingSyncTable = $db->getSchema()->getRawTableName('{{%searchmanager_pending_syncs}}');
        $this->testPendingSyncShadowTable = $this->testPendingSyncTable . '_a12_' . bin2hex(random_bytes(8));
        $db->createCommand(sprintf(
            'CREATE TEMPORARY TABLE %s LIKE %s',
            $db->quoteTableName($this->testPendingSyncShadowTable),
            $db->quoteTableName($this->testPendingSyncTable),
        ))->execute();
        $db->createCommand(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $db->quoteTableName($this->testPendingSyncShadowTable),
            $db->quoteTableName($this->testPendingSyncTable),
        ))->execute();
        $this->testPendingSyncShadowTable = null;
    }

    private function isolateQueue(): void
    {
        $original = Craft::$app->getQueue();
        if (!$original instanceof Queue) {
            throw new \RuntimeException('Search Manager integration tests require Craft\'s database queue.');
        }

        $this->originalQueue = $original;
        $db = Craft::$app->getDb();
        $this->testQueueTable = $original->tableName;
        $this->testQueueRawTable = $db->getSchema()->getRawTableName($original->tableName);
        $this->testQueueShadowTable = $this->testQueueRawTable . '_a12_' . bin2hex(random_bytes(8));
        $db->createCommand(sprintf(
            'CREATE TEMPORARY TABLE %s LIKE %s',
            $db->quoteTableName($this->testQueueShadowTable),
            $db->quoteTableName($this->testQueueRawTable),
        ))->execute();
        $db->createCommand(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $db->quoteTableName($this->testQueueShadowTable),
            $db->quoteTableName($this->testQueueRawTable),
        ))->execute();
        $this->testQueueShadowTable = null;

        Craft::$app->set('queue', new Queue([
            'db' => $db,
            'mutex' => $original->mutex,
            'tableName' => $this->testQueueTable,
            'channel' => $original->channel,
            'mutexTimeout' => $original->mutexTimeout,
        ]));
    }

    /**
     * Shadow production MySQL search storage for this connection only.
     *
     * Production storage methods execute unchanged while test indexing,
     * searching, clearing, and class fixtures remain unable to see or mutate
     * permanent owner documents.
     */
    private function isolateSearchStorageTables(): void
    {
        $db = Craft::$app->getDb();
        foreach ([
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
        ] as $tableName) {
            $table = $db->getSchema()->getRawTableName($tableName);
            $shadow = $table . '_a12_' . bin2hex(random_bytes(8));
            $this->testStorageShadowTables[] = $shadow;
            $db->createCommand(sprintf(
                'CREATE TEMPORARY TABLE %s LIKE %s',
                $db->quoteTableName($shadow),
                $db->quoteTableName($table),
            ))->execute();
            $db->createCommand(sprintf(
                'ALTER TABLE %s RENAME TO %s',
                $db->quoteTableName($shadow),
                $db->quoteTableName($table),
            ))->execute();
            array_pop($this->testStorageShadowTables);
            $this->testStorageTables[] = $table;
        }
    }

    private function restorePluginEdition(): void
    {
        if ($this->pluginEditionSnapshot === null) {
            return;
        }

        SearchManager::$plugin->edition = $this->pluginEditionSnapshot;
        $this->pluginEditionSnapshot = null;
        Craft::$app->getUserPermissions()->reset();
    }

    private function snapshotAppComponents(): void
    {
        foreach (['db', 'request', 'response', 'user', 'config', 'mutex', 'elements'] as $id) {
            if (Craft::$app->has($id)) {
                $component = Craft::$app->get($id);
                if (is_object($component)) {
                    $this->originalAppComponents[$id] = $component;
                }
            }
        }
    }

    private function restoreAppComponents(): void
    {
        foreach ($this->originalAppComponents as $id => $component) {
            Craft::$app->set($id, $component);
        }
        $this->originalAppComponents = [];
    }

    private function finishIsolation(): void
    {
        if ($this->isolationFinished) {
            return;
        }
        $this->isolationFinished = true;

        $errors = [];
        $this->runCleanupStep($errors, fn() => $this->restoreAppComponents());
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalCpTwigLoader !== null) {
                Craft::$app->getView()->getTwig(\craft\web\View::TEMPLATE_MODE_CP)->setLoader($this->originalCpTwigLoader);
                $this->originalCpTwigLoader = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalRootAlias !== null) {
                Craft::setAlias('@root', $this->originalRootAlias);
                $this->originalRootAlias = null;
            }
            if ($this->originalWebrootAlias !== null) {
                Craft::setAlias('@webroot', $this->originalWebrootAlias);
                $this->originalWebrootAlias = null;
            }
            if ($this->originalWebAlias !== null) {
                Craft::setAlias('@web', $this->originalWebAlias);
                $this->originalWebAlias = null;
            }
        });
        $this->runCleanupStep($errors, fn() => $this->restorePluginEdition());
        $this->runCleanupStep($errors, function(): void {
            if ($this->settingsAttributesSnapshot !== null) {
                SearchManager::$plugin->getSettings()->setAttributes($this->settingsAttributesSnapshot, false);
                $this->settingsAttributesSnapshot = null;
            }
        });
        $this->runCleanupStep($errors, static fn() => SearchIndex::clearCache());
        $this->runCleanupStep($errors, function(): void {
            $this->processRegistry?->cleanup();
            $this->processRegistry = null;
        });
        $this->runCleanupStep($errors, function(): void {
            foreach ($this->ownedStreams as $id => $stream) {
                if (is_resource($stream)) {
                    @flock($stream, LOCK_UN);
                    fclose($stream);
                }
                unset($this->ownedStreams[$id]);
            }
        });
        foreach ($this->ownedCleanupCallbacks as $cleanup) {
            $this->runCleanupStep($errors, $cleanup);
        }
        $this->ownedCleanupCallbacks = [];
        $this->runCleanupStep($errors, function(): void {
            foreach ($this->ownedTempPaths as $path) {
                $this->makeOwnedPathWritable($path);
            }
        });

        if ($this->baseStateInitialised) {
            $this->runBaseCleanupSteps($errors);
            $this->baseStateInitialised = false;
        }
        $this->runCleanupStep($errors, function(): void {
            foreach ($this->ownedTempPaths as $path) {
                if (file_exists($path) || is_link($path)) {
                    throw new \RuntimeException("Owned temporary path was not removed: {$path}");
                }
            }
            $this->ownedTempPaths = [];
        });

        $this->runCleanupStep($errors, function(): void {
            if ($this->originalQueue !== null) {
                Craft::$app->set('queue', $this->originalQueue);
                $this->originalQueue = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalCache !== null) {
                Craft::$app->set('cache', $this->originalCache);
                $this->originalCache = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalRuntimePath !== null) {
                Craft::$app->setRuntimePath($this->originalRuntimePath);
                $this->originalRuntimePath = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->testTransaction !== null && $this->testTransaction->getIsActive()) {
                $this->testTransaction->rollBack();
            }
            $this->testTransaction = null;
        });
        $this->runCleanupStep($errors, function(): void {
            $db = Craft::$app->getDb();
            foreach ([$this->testQueueRawTable, $this->testQueueShadowTable] as $table) {
                if ($table !== null) {
                    $db->createCommand('DROP TEMPORARY TABLE IF EXISTS ' . $db->quoteTableName($table))->execute();
                }
            }
            $this->testQueueTable = null;
            $this->testQueueRawTable = null;
            $this->testQueueShadowTable = null;
        });
        $this->runCleanupStep($errors, function(): void {
            $db = Craft::$app->getDb();
            foreach ([$this->testPendingSyncTable, $this->testPendingSyncShadowTable] as $table) {
                if ($table !== null) {
                    $db->createCommand('DROP TEMPORARY TABLE IF EXISTS ' . $db->quoteTableName($table))->execute();
                }
            }
            $this->testPendingSyncTable = null;
            $this->testPendingSyncShadowTable = null;
        });
        $this->runCleanupStep($errors, function(): void {
            $db = Craft::$app->getDb();
            foreach (array_merge($this->testStorageTables, $this->testStorageShadowTables) as $table) {
                $db->createCommand('DROP TEMPORARY TABLE IF EXISTS ' . $db->quoteTableName($table))->execute();
            }
            $this->testStorageTables = [];
            $this->testStorageShadowTables = [];
        });
        self::$activeTest = null;

        if ($errors !== []) {
            $messages = array_map(
                static fn(Throwable $error): string => $error::class . ': ' . $error->getMessage(),
                $errors,
            );
            throw new \RuntimeException(
                'Search Manager test isolation cleanup failed: ' . implode(' | ', $messages),
                0,
                $errors[0],
            );
        }
    }

    /** @param list<Throwable> $errors */
    private function runBaseCleanupSteps(array &$errors): void
    {
        $this->runCleanupStep($errors, fn() => $this->cleanupExternalState());
        foreach ([
            'restoreActingUser',
            'cleanupTrackedUsers',
            'cleanupTrackedElements',
            'cleanupTrackedTempPaths',
            'restoreSwappedComponents',
        ] as $methodName) {
            $this->runCleanupStep($errors, function() use ($methodName): void {
                $method = new \ReflectionMethod(IntegrationTestCase::class, $methodName);
                $method->invoke($this);
            });
        }

        $this->runCleanupStep($errors, function(): void {
            $property = new \ReflectionProperty(IntegrationTestCase::class, 'testMarkerCounter');
            $property->setValue($this, 0);
        });
    }

    /** @param list<Throwable> $errors */
    private function runCleanupStep(array &$errors, callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }
    }

    private function makeOwnedPathWritable(string $path): void
    {
        if (!file_exists($path) || is_link($path)) {
            return;
        }
        if (is_dir($path)) {
            @chmod($path, 0700);
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                $this->makeOwnedPathWritable($path . DIRECTORY_SEPARATOR . $entry);
            }
            return;
        }

        @chmod($path, 0600);
    }

    /**
     * Return the first enabled `craft\elements\Entry` index whose criteria
     * accepts at least one live entry, or null if none can be found.
     *
     * Tests that need a working (index, element) pair use this so the suite
     * doesn't hard-code IDs that drift with the test install.
     *
     * @return array{0: SearchIndex, 1: ElementInterface}|null
     */
    protected function findWorkingIndexAndElement(): ?array
    {
        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        $fixture = $this->findPackageFixtureRichTextEntry();
        if ($fixture !== null) {
            [$fixtureEntry] = $fixture;
            $manifest = DeterministicFixtureManifest::load();
            $fixtureIndexHandle = $manifest['indices'][0]['handle'] ?? null;
            $fixtureIndex = is_string($fixtureIndexHandle) ? SearchIndex::findByHandle($fixtureIndexHandle) : null;
            if ($fixtureIndex instanceof SearchIndex && $this->indexAcceptsEntry($fixtureIndex, $fixtureEntry, $catalogue)) {
                return [$fixtureIndex, $fixtureEntry];
            }
            foreach (SearchIndex::findAll() as $index) {
                if ($this->indexAcceptsEntry($index, $fixtureEntry, $catalogue)) {
                    return [$index, $fixtureEntry];
                }
            }
        }

        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled) {
                continue;
            }
            if (!($catalogue[$index->handle]['referenceable'] ?? false)) {
                continue;
            }
            if ($index->elementType !== \craft\elements\Entry::class) {
                continue;
            }

            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }

            $entries = \craft\elements\Entry::find()
                ->siteId($siteId)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->andWhere(['entries.primaryOwnerId' => null])
                ->limit(20)
                ->all();

            foreach ($entries as $entry) {
                if ($index->matchesElement($entry)) {
                    return [$index, $entry];
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: Entry, 1: string}|null
     */
    protected function findRichTextFixtureEntry(): ?array
    {
        $fixture = $this->findPackageFixtureRichTextEntry();
        if ($fixture !== null) {
            return $fixture;
        }

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $entries = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->andWhere(['entries.primaryOwnerId' => null])
            ->limit(50)
            ->all();
        foreach ($entries as $entry) {
            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                if (!$field instanceof \craft\ckeditor\Field) {
                    continue;
                }
                $value = (string)$entry->getFieldValue($field->handle);
                if (preg_match('/<h[23][^>]*>/i', $value)) {
                    return [$entry, $field->handle];
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: Entry, 1: string}|null
     */
    private function findPackageFixtureRichTextEntry(): ?array
    {
        $manifest = DeterministicFixtureManifest::load();
        $entryData = $manifest['entries'][0] ?? null;
        $sectionData = $manifest['sections'][0] ?? null;
        $siteData = $manifest['sites'][0] ?? null;
        $fields = $manifest['fields'] ?? null;
        if (!is_array($entryData) || !is_array($sectionData) || !is_array($siteData) || !is_array($fields)) {
            return null;
        }

        $richTextHandle = null;
        foreach ($fields as $field) {
            if (is_array($field) && ($field['type'] ?? null) === \craft\ckeditor\Field::class) {
                $richTextHandle = $field['handle'] ?? null;
                break;
            }
        }
        $slug = $entryData['slug'] ?? null;
        $sectionHandle = $sectionData['handle'] ?? null;
        $siteHandle = $siteData['handle'] ?? null;
        if (!is_string($richTextHandle) || !is_string($slug) || !is_string($sectionHandle) || !is_string($siteHandle)) {
            return null;
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if ($section === null || $site === null) {
            return null;
        }
        $entry = Entry::find()
            ->sectionId($section->id)
            ->slug($slug)
            ->siteId($site->id)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();
        if (!$entry instanceof Entry || !$entry->getFieldLayout()?->getFieldByHandle($richTextHandle)) {
            return null;
        }

        return [$entry, $richTextHandle];
    }

    /**
     * @param array<string, array<string, mixed>> $catalogue
     */
    private function indexAcceptsEntry(SearchIndex $index, Entry $entry, array $catalogue): bool
    {
        if (
            !$index->enabled
            || !($catalogue[$index->handle]['referenceable'] ?? false)
            || $index->elementType !== Entry::class
        ) {
            return false;
        }

        $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();

        return in_array((int)$entry->siteId, array_map('intval', $siteIds), true)
            && $index->matchesElement($entry);
    }

    /**
     * Thin wrapper over {@see IntegrationTestCase::countRows()} pinned to the
     * pending_syncs table — every existing test calls into this shape.
     *
     * @param array<string, mixed>|array<int, mixed> $condition
     */
    protected function countPendingRows(array $condition = []): int
    {
        return $this->countRows('{{%searchmanager_pending_syncs}}', $condition);
    }

    /**
     * Thin wrapper over {@see IntegrationTestCase::fetchRow()} for the
     * composite-key lookup the sync tests use.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchPendingRow(string $indexHandle, int $elementId, int $siteId): ?array
    {
        return $this->fetchRow('{{%searchmanager_pending_syncs}}', [
            'indexHandle' => $indexHandle,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ]);
    }
}
