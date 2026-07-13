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
use lindemannrock\searchmanager\adapters\CraftSearchAdapter;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #353 and #355.
 *
 * @since 5.53.0
 */
final class CraftSearchAdapterRegressionTest extends TestCase
{
    public function testAllSitesSearchKeysScoresByHitSiteId(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
                ['elementId' => 49639, 'siteId' => 2, 'score' => 11.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $scores = $this->withOnlySearchIndices([$this->index('products', null)], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = '*';

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame([
            '49639-1' => 12.5,
            '49639-2' => 11.5,
        ], $scores);
        self::assertSame('*', $backend->searchCalls[0]['options']['siteId'] ?? null);
        self::assertSame(0, $backend->searchCalls[0]['options']['limit'] ?? null);
    }

    public function testSiteIdArraySearchPassesNormalizedScopeAndKeysReturnedSites(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
                ['elementId' => 49639, 'siteId' => 2, 'score' => 11.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $scores = $this->withOnlySearchIndices([$this->index('products', [1, 2])], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = [2, 1, 1];

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame([
            '49639-1' => 12.5,
            '49639-2' => 11.5,
        ], $scores);
        self::assertSame([1, 2], $backend->searchCalls[0]['options']['siteId'] ?? null);
    }

    public function testSingleSiteSearchKeepsCraftScoreKeyShape(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $scores = $this->withOnlySearchIndices([$this->index('products', 1)], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame(['49639-1' => 12.5], $scores);
        self::assertSame(1, $backend->searchCalls[0]['options']['siteId'] ?? null);
    }

    public function testNativeReplacementUsesResolvedIndexBackend(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $scores = $this->withOnlySearchIndices([$this->index('products', 1, 'local-backend')], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame(['49639-1' => 12.5], $scores);
        self::assertCount(1, $backend->searchCalls);
    }

    public function testNativeReplacementFallsBackWhenResolvedIndexBackendIsExternal(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new AlgoliaBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices([$this->index('products', 1, 'external-backend')], function() use ($backend): void {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            (new CraftSearchAdapter())->searchElements($query);

            self::assertSame([], $backend->searchCalls);
        });
    }

    public function testAutoIndexStillRefreshesCraftNativeSearchIndex(): void
    {
        $fixture = $this->findWorkingIndexAndElement();
        if ($fixture === null) {
            self::markTestSkipped('No enabled Entry index with a matching element is available.');
        }

        [, $element] = $fixture;
        $settings = SearchManager::$plugin->getSettings();
        $originalAutoIndex = $settings->autoIndex;
        $settings->autoIndex = true;

        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), ['hits' => []]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $condition = [
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
        ];
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchindex}}', $condition)
            ->execute();

        try {
            self::assertSame(0, $this->nativeSearchIndexRowCount($condition));
            self::assertTrue((new CraftSearchAdapter())->indexElementAttributes($element));
            self::assertGreaterThan(0, $this->nativeSearchIndexRowCount($condition));
        } finally {
            $settings->autoIndex = $originalAutoIndex;
        }
    }

    public function testNarrowConfigIndexIsSkippedForCatchAllDatabaseIndex(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $narrowConfig = $this->index('narrow-config', 1, null, static fn($query) => $query, 'config');
        $catchAllDb = $this->index('db-catch-all', 1);

        $scores = $this->withOnlySearchIndices([$narrowConfig, $catchAllDb], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame(['49639-1' => 12.5], $scores);
        self::assertSame('db-catch-all', $backend->searchCalls[0]['indexName'] ?? null);
    }

    public function testOnlyNarrowIndicesFallBackToNativeWithoutBackendSearch(): void
    {
        $backend = new CraftSearchAdapterRecordingBackendService(new MySqlBackend(), [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices([$this->index('narrow-db', 1, null, ['sections' => ['news']])], function() use ($backend): void {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            (new CraftSearchAdapter())->searchElements($query);

            self::assertSame([], $backend->searchCalls);
        });
    }

    /**
     * @param array<string, int> $condition
     */
    private function nativeSearchIndexRowCount(array $condition): int
    {
        return (int)(new Query())
            ->from('{{%searchindex}}')
            ->where($condition)
            ->count();
    }

    private function index(
        string $handle,
        int|array|null $siteId,
        ?string $backend = null,
        mixed $criteria = [],
        string $source = 'database',
    ): SearchIndex
    {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->name = $handle;
        $index->elementType = Entry::class;
        $index->siteId = $siteId;
        $index->backend = $backend;
        $index->criteria = $criteria;
        $index->enabled = true;
        $index->source = $source;

        return $index;
    }
}

final class CraftSearchAdapterRecordingBackendService extends BackendService
{
    /**
     * @var list<array{indexName: string, query: string, options: array<string, mixed>}>
     */
    public array $searchCalls = [];

    /**
     * @param array<string, mixed> $searchResponse
     */
    public function __construct(
        private readonly BackendInterface $resolvedBackend,
        private readonly array $searchResponse,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->resolvedBackend;
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->resolvedBackend;
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        return $this->searchResponse;
    }
}
