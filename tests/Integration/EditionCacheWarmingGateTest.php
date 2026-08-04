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
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\CacheWarmJob;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.54.0
 */
final class EditionCacheWarmingGateTest extends TestCase
{
    private const INDEX_HANDLE = '__sm_edition_cache_warming';
    private const POPULAR_QUERY = '__sm_edition_popular_query';

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $this->purgeOwnedRows();
        $this->insertTestIndex();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeOwnedRows();
        } finally {
            parent::tearDown();
        }
    }

    public function testStandardRebuildDoesNotEnqueueCacheWarming(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        [$backend] = $this->installRecordingServices();
        $this->enableCacheWarmingSettings();

        (new RebuildIndexJob([
            'indexHandle' => self::INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        self::assertCount(1, $backend->callsFor('clearIndex'));
        self::assertSame(0, $this->cacheWarmQueueCount());
    }

    public function testQueuedCacheWarmJobNoOpsCleanlyInStandard(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        [$backend, $autocomplete] = $this->installRecordingServices();
        $this->enableCacheWarmingSettings();
        $this->seedAnalyticsRow();

        (new CacheWarmJob([
            'indexHandle' => self::INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        self::assertSame([], $backend->callsFor('search'));
        self::assertSame([], $autocomplete->suggestCalls);
    }

    public function testProRebuildEnqueuesAndWarmsFromAnalyticsHistory(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        [$backend] = $this->installRecordingServices();
        $this->enableCacheWarmingSettings();
        $this->seedAnalyticsRow();

        (new RebuildIndexJob([
            'indexHandle' => self::INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        self::assertSame(1, $this->cacheWarmQueueCount());

        (new CacheWarmJob([
            'indexHandle' => self::INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        $searchCalls = $backend->callsFor('search');
        self::assertCount(1, $searchCalls);
        self::assertSame(self::POPULAR_QUERY, $searchCalls[0]['items'][0]['query']);
        self::assertSame($this->siteId, $searchCalls[0]['items'][0]['options']['siteId']);
        self::assertTrue($searchCalls[0]['items'][0]['options']['skipAnalytics']);
    }

    /**
     * @return array{0: EditionCacheWarmingBackendService, 1: EditionCacheWarmingAutocompleteService}
     */
    private function installRecordingServices(): array
    {
        $backend = new EditionCacheWarmingBackendService();
        $autocomplete = new EditionCacheWarmingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        return [$backend, $autocomplete];
    }

    private function enableCacheWarmingSettings(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCacheWarming = true;
        $settings->enableCache = true;
        $settings->enableAutocompleteCache = false;
        $settings->cacheWarmingQueryCount = 50;
    }

    private function seedAnalyticsRow(): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => self::INDEX_HANDLE,
            'query' => self::POPULAR_QUERY,
            'normalizedQuery' => QueryNormalizer::forCacheIdentity(self::POPULAR_QUERY),
            'resultsCount' => 1,
            'executionTime' => 1.0,
            'backend' => 'edition-cache-warming-test',
            'siteId' => $this->siteId,
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function insertTestIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Edition Cache Warming Test',
            'handle' => self::INDEX_HANDLE,
            'elementType' => User::class,
            'siteId' => json_encode([$this->siteId], JSON_THROW_ON_ERROR),
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
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function cacheWarmQueueCount(): int
    {
        return (int) (new Query())
            ->from($this->queueTable())
            ->where(['like', 'job', CacheWarmJob::class])
            ->andWhere(['like', 'job', self::INDEX_HANDLE])
            ->count();
    }

    private function purgeOwnedRows(): void
    {
        $ids = (new Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => self::INDEX_HANDLE])
            ->column();

        if ($ids !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['indexHandle' => self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), [
                'and',
                ['like', 'job', CacheWarmJob::class],
                ['like', 'job', self::INDEX_HANDLE],
            ])
            ->execute();

        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function fixtureBackendHandle(): string
    {
        $handle = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_backends}}')
            ->where(['handle' => 'fixtureMysql'])
            ->scalar();

        return is_string($handle) ? $handle : 'mysql';
    }
}

final class EditionCacheWarmingBackendService extends BackendService
{
    /**
     * @var list<array{method: string, indexName: string, items?: list<array<string, mixed>>}>
     */
    private array $calls = [];

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return new \lindemannrock\searchmanager\backends\FileBackend();
    }

    public function clearIndex(string $indexName): bool
    {
        $this->calls[] = ['method' => 'clearIndex', 'indexName' => $indexName];

        return true;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchIndex', 'indexName' => $indexName, 'items' => $items];

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->calls[] = ['method' => 'clearSearchCache', 'indexName' => $indexName];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{hits: list<array<string, mixed>>, total: int}
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'search',
            'indexName' => $indexName,
            'items' => [[
                'query' => $query,
                'options' => $options,
            ]],
        ];

        return ['hits' => [], 'total' => 0];
    }

    /**
     * @return list<array{method: string, indexName: string, items?: list<array<string, mixed>>}>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(array $call): bool => $call['method'] === $method,
        ));
    }
}

final class EditionCacheWarmingAutocompleteService extends AutocompleteService
{
    /** @var list<array{query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $suggestCalls = [];

    /** @var list<string|null> */
    public array $clearCacheCalls = [];

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->suggestCalls[] = [
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        return [];
    }

    public function clearCache(?string $indexHandle = null): void
    {
        $this->clearCacheCalls[] = $indexHandle;
    }
}
