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
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for fail-closed rebuild preflight.
 *
 * @since 5.54.0
 */
final class RebuildIndexJobPreflightTest extends TestCase
{
    private const INVALID_ELEMENT_INDEX = 'sm-test-rebuild-preflight-invalid-element';
    private const FAILED_SYNC_INDEX = 'sm-test-rebuild-preflight-failed-sync';
    private const ALL_BAD_INDEX = '__sm_rebuild_all_bad';
    private const ALL_GOOD_INDEX = '__sm_rebuild_all_good';
    private const CLEAR_FAILURE_INDEX = '__sm_rebuild_clear_failure';
    private const THROWING_CLOSURE_INDEX = '__sm_rebuild_throwing_closure';
    private const WRONG_TYPE_CLOSURE_INDEX = '__sm_rebuild_wrong_type_closure';
    private const MIXED_BROKEN_INDEX = 'sm-test-config-load-broken';
    private const MIXED_VALID_INDEX = 'sm-test-config-load-valid';
    private const MIXED_BACKEND = 'sm-test-config-load-backend';
    private const GLOBAL_ERROR_DATABASE_INDEX = '__sm_global_error_database';
    private const GLOBAL_ERROR_CONFIG_INDEX = '__sm_global_error_config';

    private mixed $originalConfigCache = null;

    public function testRebuildAllIndicesPassesPreloadedIndexIntoSingleRebuild(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $singleBody = $this->sourceMethodBody($source, 'rebuildSingleIndex');
        $preflightBody = $this->sourceMethodBody($source, 'preflightIndexRebuild');
        $allBody = $this->sourceMethodBody($source, 'rebuildAllIndices');

        self::assertStringContainsString('?SearchIndex $preloadedIndex = null', $source);
        self::assertStringContainsString('$this->preflightIndexRebuild($indexHandle, $preloadedIndex)', $singleBody);
        self::assertStringContainsString('$index = $preloadedIndex ?? SearchIndex::findByHandle($indexHandle);', $preflightBody);
        self::assertStringContainsString('$this->rebuildSingleIndex(', $allBody);
        self::assertStringContainsString('$currentIndices[$indexHandle] ?? null,', $allBody);
        self::assertStringContainsString('foreach (SearchIndex::findAll() as $index)', $allBody);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeOwnedRows();
        SearchManager::$plugin->getSettings()->enableCacheWarming = false;
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeOwnedRows();
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testInvalidConfigElementTypeFailsWithoutClearingStoredDocuments(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::INVALID_ELEMENT_INDEX, ['elementId' => 901, 'title' => 'Previously indexed']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->withConfigFileIndices([
            self::INVALID_ELEMENT_INDEX => [
                'name' => 'Invalid Element Rebuild',
                'elementType' => 'missing\\search\\elements\\UnavailableElement',
                'siteId' => $siteId,
                'enabled' => true,
            ],
        ]);

        (static function(): void {
            (new RebuildIndexJob([
                'indexHandle' => self::INVALID_ELEMENT_INDEX,
            ]))->execute(Craft::$app->queue);
        })();

        self::assertSame([], $backend->clearCallsFor(self::INVALID_ELEMENT_INDEX));
        self::assertSame(
            [['elementId' => 901, 'title' => 'Previously indexed']],
            $backend->documentsFor(self::INVALID_ELEMENT_INDEX),
            'The pre-existing document must remain present when element-type preflight fails.',
        );
    }

    public function testFailedConfigMetadataSyncAbortsBeforeClear(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::FAILED_SYNC_INDEX, ['elementId' => 902, 'title' => 'Stored before sync']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->withConfigFileIndices([
            self::FAILED_SYNC_INDEX => [
                'name' => 'Failed Sync Rebuild',
                'elementType' => User::class,
                'siteId' => $siteId,
                'transformer' => 'missing\\search\\transformers\\UnavailableTransformer',
                'enabled' => true,
            ],
        ]);

        (static function(): void {
            (new RebuildIndexJob([
                'indexHandle' => self::FAILED_SYNC_INDEX,
            ]))->execute(Craft::$app->queue);
        })();

        self::assertSame([], $backend->clearCallsFor(self::FAILED_SYNC_INDEX));
        self::assertSame(
            [['elementId' => 902, 'title' => 'Stored before sync']],
            $backend->documentsFor(self::FAILED_SYNC_INDEX),
        );
    }

    public function testThrowingCriteriaClosureFailsBeforeClearingStoredDocuments(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $index = $this->indexModel(self::THROWING_CLOSURE_INDEX, User::class, $siteId);
        $index->criteria = static function(): never {
            throw new \RuntimeException('Synthetic criteria Closure failure');
        };

        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::THROWING_CLOSURE_INDEX, [
            'elementId' => 905,
            'title' => 'Stored before throwing Closure',
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->withOnlySearchIndices(
            [$index],
            fn(): \RuntimeException => $this->captureRuntimeException(static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            }),
        );

        self::assertStringContainsString(self::THROWING_CLOSURE_INDEX, $error->getMessage());
        self::assertStringContainsString("site {$siteId}", $error->getMessage());
        self::assertStringContainsString('Synthetic criteria Closure failure', $error->getMessage());
        self::assertSame([], $backend->clearCallsFor(self::THROWING_CLOSURE_INDEX));
        self::assertSame(
            [['elementId' => 905, 'title' => 'Stored before throwing Closure']],
            $backend->documentsFor(self::THROWING_CLOSURE_INDEX),
            'A throwing criteria Closure must fail preflight without removing existing backend documents.',
        );
    }

    public function testWrongTypeCriteriaClosureFailsBeforeClearingStoredDocuments(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $index = $this->indexModel(self::WRONG_TYPE_CLOSURE_INDEX, User::class, $siteId);
        $index->criteria = static fn(): string => 'not an element query';

        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::WRONG_TYPE_CLOSURE_INDEX, [
            'elementId' => 906,
            'title' => 'Stored before wrong-type Closure',
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->withOnlySearchIndices(
            [$index],
            fn(): \RuntimeException => $this->captureRuntimeException(static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            }),
        );

        self::assertStringContainsString(self::WRONG_TYPE_CLOSURE_INDEX, $error->getMessage());
        self::assertStringContainsString("site {$siteId}", $error->getMessage());
        self::assertStringContainsString('ElementQuery', $error->getMessage());
        self::assertSame([], $backend->clearCallsFor(self::WRONG_TYPE_CLOSURE_INDEX));
        self::assertSame(
            [['elementId' => 906, 'title' => 'Stored before wrong-type Closure']],
            $backend->documentsFor(self::WRONG_TYPE_CLOSURE_INDEX),
            'A wrong-type criteria Closure must fail preflight without removing existing backend documents.',
        );
    }

    public function testRebuildAllContinuesAfterBadIndexAndReportsPartialFailure(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $user = User::find()
            ->siteId($siteId)
            ->status(User::STATUS_ACTIVE)
            ->one();

        if (!$user instanceof User) {
            self::markTestSkipped('An active primary-site user is required for the rebuild-all regression.');
        }

        $badIndex = $this->indexModel(
            self::ALL_BAD_INDEX,
            'missing\\search\\elements\\UnavailableElement',
            $siteId,
        );
        $goodIndex = $this->insertDatabaseIndex(self::ALL_GOOD_INDEX, User::class, $siteId);
        $goodIndex->criteria = static fn($query) => $query->id((int)$user->id);

        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::ALL_BAD_INDEX, ['elementId' => 903, 'title' => 'Bad index existing document']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices(
            [$badIndex, $goodIndex],
            static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            },
        );

        self::assertSame([], $backend->clearCallsFor(self::ALL_BAD_INDEX));
        self::assertCount(1, $backend->clearCallsFor(self::ALL_GOOD_INDEX));
        self::assertNotSame([], $backend->batchCallsFor(self::ALL_GOOD_INDEX));
        self::assertSame(
            [['elementId' => 903, 'title' => 'Bad index existing document']],
            $backend->documentsFor(self::ALL_BAD_INDEX),
        );
        self::assertNotSame([], $backend->documentsFor(self::ALL_GOOD_INDEX));
    }

    public function testClearIndexFalseFailsBeforeBatchIndexing(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $index = $this->indexModel(self::CLEAR_FAILURE_INDEX, User::class, $siteId);
        $backend = new RebuildPreflightRecordingBackendService();
        $backend->failClearFor(self::CLEAR_FAILURE_INDEX);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->withOnlySearchIndices(
            [$index],
            fn(): \RuntimeException => $this->captureRuntimeException(static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            }),
        );

        self::assertStringContainsString(self::CLEAR_FAILURE_INDEX, $error->getMessage());
        self::assertStringContainsString('backend clear failed', $error->getMessage());
        self::assertCount(1, $backend->clearCallsFor(self::CLEAR_FAILURE_INDEX));
        self::assertSame([], $backend->batchCallsFor(self::CLEAR_FAILURE_INDEX));
    }

    public function testConfigSourceConsumesSharedConfigIndexValidatorBeforeClear(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $index = $this->indexModel(self::ALL_GOOD_INDEX, User::class, $siteId);
        $index->source = 'config';
        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::ALL_GOOD_INDEX, ['elementId' => 904, 'title' => 'Stored before validation']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $result = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_PRESENT);
        $result->addFinding(
            self::ALL_GOOD_INDEX,
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'backend',
            'Synthetic shared-validator failure',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($result),
        );

        $this->withOnlySearchIndices(
            [$index],
            static function(): void {
                (new RebuildIndexJob([
                    'indexHandle' => self::ALL_GOOD_INDEX,
                ]))->execute(Craft::$app->queue);
            },
        );

        self::assertSame([], $backend->clearCallsFor(self::ALL_GOOD_INDEX));
        self::assertSame(
            [['elementId' => 904, 'title' => 'Stored before validation']],
            $backend->documentsFor(self::ALL_GOOD_INDEX),
        );
    }

    public function testDatabaseIndexIgnoresUnrelatedGlobalConfigErrorForTargetedAndRebuildAll(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $databaseIndex = $this->insertDatabaseIndex(self::GLOBAL_ERROR_DATABASE_INDEX, User::class, $siteId);
        $configIndex = $this->indexModel(self::GLOBAL_ERROR_CONFIG_INDEX, User::class, $siteId);
        $configIndex->source = 'config';

        $validation = new ConfigIndexValidationResult(ConfigIndexValidationResult::STATUS_LOAD_FAILURE);
        $validation->addFinding(
            null,
            ConfigIndexValidationResult::SEVERITY_ERROR,
            'indices',
            'Synthetic unrelated global config failure',
        );
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($validation),
        );

        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::GLOBAL_ERROR_DATABASE_INDEX, ['elementId' => 907, 'title' => 'Database document']);
        $backend->seedDocument(self::GLOBAL_ERROR_CONFIG_INDEX, ['elementId' => 908, 'title' => 'Config document']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices([$databaseIndex, $configIndex], static function() use ($backend): void {
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
            $plan = SearchManager::$plugin->dependencies->getRebuildAllPlan();
            self::assertSame([self::GLOBAL_ERROR_DATABASE_INDEX], $plan['participants']);
            self::assertSame('config-invalid', $plan['skips'][0]['reasonCode']);

            (new RebuildIndexJob([
                'indexHandle' => self::GLOBAL_ERROR_DATABASE_INDEX,
            ]))->execute(Craft::$app->queue);
            self::assertCount(1, $backend->clearCallsFor(self::GLOBAL_ERROR_DATABASE_INDEX));

            (new RebuildIndexJob([
                'indexHandles' => $plan['participants'],
                'structuralSkips' => $plan['skips'],
            ]))->execute(Craft::$app->queue);
            self::assertCount(2, $backend->clearCallsFor(self::GLOBAL_ERROR_DATABASE_INDEX));

            (new RebuildIndexJob([
                'indexHandle' => self::GLOBAL_ERROR_CONFIG_INDEX,
            ]))->execute(Craft::$app->queue);
            self::assertSame([], $backend->clearCallsFor(self::GLOBAL_ERROR_CONFIG_INDEX));
            self::assertSame(
                [['elementId' => 908, 'title' => 'Config document']],
                $backend->documentsFor(self::GLOBAL_ERROR_CONFIG_INDEX),
            );
        });
    }

    public function testMixedConfigLoadKeepsValidIndexAndDoesNotResurrectSkippedMetadata(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $user = User::find()
            ->siteId($siteId)
            ->status(User::STATUS_ACTIVE)
            ->one();

        if (!$user instanceof User) {
            self::markTestSkipped('An active primary-site user is required for the mixed config-index regression.');
        }

        $this->withConfigFileIndices($this->mixedConfigIndices($siteId, (int)$user->id));
        $ghostMetadataId = $this->insertConfigMetadata(self::MIXED_BROKEN_INDEX, 37);

        $configIndices = SearchIndex::loadFromConfig();
        self::assertSame([self::MIXED_VALID_INDEX], array_map(
            static fn(SearchIndex $index): string => $index->handle,
            $configIndices,
        ));

        $allHandles = array_map(
            static fn(SearchIndex $index): string => $index->handle,
            SearchIndex::findAll(),
        );
        self::assertContains(self::MIXED_VALID_INDEX, $allHandles);
        self::assertNotContains(self::MIXED_BROKEN_INDEX, $allHandles);
        self::assertNotContains('', $allHandles);
        self::assertNull(SearchIndex::findByHandle(self::MIXED_BROKEN_INDEX));
        self::assertNull(SearchIndex::findById($ghostMetadataId));

        $validIndex = SearchIndex::findByHandle(self::MIXED_VALID_INDEX);
        self::assertNotNull($validIndex);
        self::assertSame(1, $validIndex->getExpectedCount());
    }

    public function testRebuildAllReportsSkippedMixedConfigItemsAndRebuildsValidIndex(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $user = User::find()
            ->siteId($siteId)
            ->status(User::STATUS_ACTIVE)
            ->one();

        if (!$user instanceof User) {
            self::markTestSkipped('An active primary-site user is required for the mixed config-index rebuild regression.');
        }

        $mixedIndices = $this->mixedConfigIndices($siteId, (int)$user->id);
        $this->insertFileBackend(self::MIXED_BACKEND);
        $this->withConfigFileIndices($mixedIndices);
        $validation = (new ConfigIndexValidator())->validateConfig(['indices' => $mixedIndices]);
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator($validation),
        );

        $backend = new RebuildPreflightRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $loadedIndices = SearchIndex::loadFromConfig();
        $plan = $this->withOnlySearchIndices(
            $loadedIndices,
            static fn(): array => SearchManager::$plugin->dependencies->getRebuildAllPlan(),
        );
        self::assertContains(self::MIXED_VALID_INDEX, $plan['participants'], json_encode($plan));

        $this->withOnlySearchIndices(
            $loadedIndices,
            static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            },
        );

        self::assertCount(1, $backend->clearCallsFor(self::MIXED_VALID_INDEX));
        self::assertNotSame([], $backend->batchCallsFor(self::MIXED_VALID_INDEX));
        self::assertNotSame([], $backend->documentsFor(self::MIXED_VALID_INDEX));

        $rebuiltIndex = SearchIndex::findByHandle(self::MIXED_VALID_INDEX);
        self::assertNotNull($rebuiltIndex);
        self::assertSame(1, $rebuiltIndex->documentCount);
    }

    private function indexModel(string $handle, string $elementType, int $siteId): SearchIndex
    {
        return new SearchIndex([
            'name' => $handle,
            'handle' => $handle,
            'elementType' => $elementType,
            'siteId' => $siteId,
            'source' => 'database',
            'enabled' => true,
        ]);
    }

    private function insertDatabaseIndex(string $handle, string $elementType, int $siteId): SearchIndex
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => $elementType,
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
        $id = (int)Craft::$app->getDb()->getLastInsertID();
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_index_sites}}', [
            'indexId' => $id,
            'siteId' => $siteId,
        ])->execute();
        SearchIndex::clearCache();

        $index = SearchIndex::findById($id);
        self::assertNotNull($index);
        return $index;
    }

    private function captureRuntimeException(callable $callback): \RuntimeException
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('Expected the rebuild job to throw a RuntimeException.');
    }

    private function purgeOwnedRows(): void
    {
        $handles = [
            self::INVALID_ELEMENT_INDEX,
            self::FAILED_SYNC_INDEX,
            self::ALL_BAD_INDEX,
            self::ALL_GOOD_INDEX,
            self::CLEAR_FAILURE_INDEX,
            self::THROWING_CLOSURE_INDEX,
            self::WRONG_TYPE_CLOSURE_INDEX,
            self::MIXED_BROKEN_INDEX,
            self::MIXED_VALID_INDEX,
            self::GLOBAL_ERROR_DATABASE_INDEX,
            self::GLOBAL_ERROR_CONFIG_INDEX,
        ];
        $indexIds = (new Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handles])
            ->column();

        if ($indexIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $indexIds])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => $handles])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => self::MIXED_BACKEND])
            ->execute();
        foreach ($handles as $handle) {
            Craft::$app->getDb()->createCommand()
                ->delete($this->queueTable(), ['like', 'job', $handle])
                ->execute();
        }
        SearchIndex::clearCache();
    }

    /** @return array<string, mixed> */
    private function mixedConfigIndices(int $siteId, int $userId): array
    {
        return [
            self::MIXED_BROKEN_INDEX => 'not-an-array',
            '' => [
                'name' => 'Empty Handle Fixture',
                'elementType' => User::class,
                'siteId' => $siteId,
                'enabled' => true,
            ],
            self::MIXED_VALID_INDEX => [
                'name' => 'Valid Mixed Config Fixture',
                'elementType' => User::class,
                'siteId' => $siteId,
                'criteria' => static fn($query) => $query->id($userId),
                'backend' => self::MIXED_BACKEND,
                'enabled' => true,
            ],
        ];
    }

    private function insertFileBackend(string $handle): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Rebuild Preflight Backend',
            'handle' => $handle,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function insertConfigMetadata(string $handle, int $documentCount): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Skipped Config Metadata Fixture',
            'handle' => $handle,
            'elementType' => User::class,
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
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'config',
            'lastIndexed' => $now,
            'documentCount' => $documentCount,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $id = (int)Craft::$app->getDb()->getLastInsertID();
        SearchIndex::clearCache();

        return $id;
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function sourceMethodBody(string $source, string $method, string $visibility = 'private'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}

/**
 * In-memory backend recorder for rebuild preflight tests.
 *
 * @since 5.54.0
 */
final class RebuildPreflightRecordingBackendService extends BackendService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $documents = [];

    /** @var list<string> */
    private array $clearCalls = [];

    /** @var array<string, list<list<array<string, mixed>>>> */
    private array $batchCalls = [];

    /** @var array<string, true> */
    private array $clearFailures = [];

    private ?BackendInterface $resolvedBackend = null;

    /** @param array<string, mixed> $document */
    public function seedDocument(string $indexHandle, array $document): void
    {
        $this->documents[$indexHandle][] = $document;
    }

    public function failClearFor(string $indexHandle): void
    {
        $this->clearFailures[$indexHandle] = true;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->resolvedBackend ??= new FileBackend();
    }

    public function clearIndex(string $indexName): bool
    {
        $this->clearCalls[] = $indexName;
        if (isset($this->clearFailures[$indexName])) {
            return false;
        }

        $this->documents[$indexName] = [];

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->batchCalls[$indexName][] = $items;
        $this->documents[$indexName] ??= [];
        array_push($this->documents[$indexName], ...$items);

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
    }

    /** @return list<array<string, mixed>> */
    public function documentsFor(string $indexHandle): array
    {
        return $this->documents[$indexHandle] ?? [];
    }

    /** @return list<string> */
    public function clearCallsFor(string $indexHandle): array
    {
        return array_values(array_filter(
            $this->clearCalls,
            static fn(string $handle): bool => $handle === $indexHandle,
        ));
    }

    /** @return list<list<array<string, mixed>>> */
    public function batchCallsFor(string $indexHandle): array
    {
        return $this->batchCalls[$indexHandle] ?? [];
    }
}
