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
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for fail-closed rebuild preflight.
 *
 * @since 5.54.0
 */
final class RebuildIndexJobPreflightTest extends TestCase
{
    private const INVALID_ELEMENT_INDEX = '__sm_rebuild_preflight_invalid_element';
    private const FAILED_SYNC_INDEX = '__sm_rebuild_preflight_failed_sync';
    private const ALL_BAD_INDEX = '__sm_rebuild_all_bad';
    private const ALL_GOOD_INDEX = '__sm_rebuild_all_good';
    private const CLEAR_FAILURE_INDEX = '__sm_rebuild_clear_failure';

    private mixed $originalConfigCache = null;

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

        $error = $this->captureRuntimeException(static function(): void {
            (new RebuildIndexJob([
                'indexHandle' => self::INVALID_ELEMENT_INDEX,
            ]))->execute(Craft::$app->queue);
        });

        self::assertStringContainsString(self::INVALID_ELEMENT_INDEX, $error->getMessage());
        self::assertStringContainsString('element type', $error->getMessage());
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

        $error = $this->captureRuntimeException(static function(): void {
            (new RebuildIndexJob([
                'indexHandle' => self::FAILED_SYNC_INDEX,
            ]))->execute(Craft::$app->queue);
        });

        self::assertStringContainsString(self::FAILED_SYNC_INDEX, $error->getMessage());
        self::assertStringContainsString('config metadata sync failed', $error->getMessage());
        self::assertSame([], $backend->clearCallsFor(self::FAILED_SYNC_INDEX));
        self::assertSame(
            [['elementId' => 902, 'title' => 'Stored before sync']],
            $backend->documentsFor(self::FAILED_SYNC_INDEX),
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
        $goodIndex = $this->indexModel(self::ALL_GOOD_INDEX, User::class, $siteId);
        $goodIndex->criteria = static fn($query) => $query->id((int)$user->id);

        $backend = new RebuildPreflightRecordingBackendService();
        $backend->seedDocument(self::ALL_BAD_INDEX, ['elementId' => 903, 'title' => 'Bad index existing document']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->withOnlySearchIndices(
            [$badIndex, $goodIndex],
            fn(): \RuntimeException => $this->captureRuntimeException(static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            }),
        );

        self::assertStringContainsString('Rebuild all indices completed with failures', $error->getMessage());
        self::assertStringContainsString(self::ALL_BAD_INDEX, $error->getMessage());
        self::assertStringContainsString('element type', $error->getMessage());
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

    private function captureRuntimeException(callable $callback): \RuntimeException
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e;
        }

        self::fail('Expected the rebuild job to throw a RuntimeException.');
    }

    /**
     * @param array<string, mixed> $indices
     */
    private function withConfigFileIndices(array $indices): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = ['indices' => $indices];
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    private function configCache(): mixed
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function setConfigCache(mixed $cache): void
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }

    private function purgeOwnedRows(): void
    {
        $handles = [
            self::INVALID_ELEMENT_INDEX,
            self::FAILED_SYNC_INDEX,
            self::ALL_BAD_INDEX,
            self::ALL_GOOD_INDEX,
            self::CLEAR_FAILURE_INDEX,
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
        foreach ($handles as $handle) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%queue}}', ['like', 'job', $handle])
                ->execute();
        }
        SearchIndex::clearCache();
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
