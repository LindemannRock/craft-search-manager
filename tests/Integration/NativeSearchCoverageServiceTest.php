<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use lindemannrock\searchmanager\adapters\CraftSearchAdapter;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\NativeSearchCoverageService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(NativeSearchCoverageService::class)]
final class NativeSearchCoverageServiceTest extends TestCase
{
    private const MARKER = 'native-coverage-test-';

    public function testReportMarksOnlyLocalAllSitesUnrestrictedTypesCovered(): void
    {
        $backend = new NativeSearchCoverageBackendService([
            self::MARKER . 'entry-catch-all' => new MySqlBackend(),
            self::MARKER . 'asset-narrow' => new MySqlBackend(),
            self::MARKER . 'category-external' => new AlgoliaBackend(),
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $report = $this->withOnlySearchIndices([
            $this->index(self::MARKER . 'entry-catch-all', Entry::class, null),
            $this->index(self::MARKER . 'asset-narrow', Asset::class, [1], criteria: ['volumes' => ['uploads']]),
            $this->index(self::MARKER . 'category-external', Category::class, null),
        ], fn(): array => SearchManager::$plugin->nativeSearchCoverage->getReport());

        $byType = [];
        foreach ($report as $row) {
            $byType[$row['type']] = $row;
        }

        self::assertSame(true, $byType[Entry::class]['covered'] ?? null);
        self::assertSame(self::MARKER . 'entry-catch-all', $byType[Entry::class]['indexHandle'] ?? null);
        self::assertSame(false, $byType[Asset::class]['covered'] ?? null);
        self::assertNull($byType[Asset::class]['indexHandle'] ?? null);
        self::assertSame(false, $byType[Category::class]['covered'] ?? null);
        self::assertNull($byType[Category::class]['indexHandle'] ?? null);
    }

    public function testReportTreatsEmptySavedCriteriaValuesAsUnrestricted(): void
    {
        $backend = new NativeSearchCoverageBackendService([
            self::MARKER . 'entry-empty-criteria' => new MySqlBackend(),
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $report = $this->withOnlySearchIndices([
            $this->index(self::MARKER . 'entry-empty-criteria', Entry::class, null, criteria: [
                'sections' => '',
                'volumes' => '',
                'groups' => '',
                'sourceHandles' => '',
            ]),
        ], fn(): array => SearchManager::$plugin->nativeSearchCoverage->getReport());

        $entryRow = null;
        foreach ($report as $row) {
            if ($row['type'] === Entry::class) {
                $entryRow = $row;
                break;
            }
        }

        self::assertNotNull($entryRow);
        self::assertSame(true, $entryRow['covered']);
        self::assertSame(self::MARKER . 'entry-empty-criteria', $entryRow['indexHandle']);
    }

    public function testAdapterResolutionUsesSharedCoveragePredicate(): void
    {
        $backend = new NativeSearchCoverageBackendService([
            self::MARKER . 'entry-narrow' => new MySqlBackend(),
            self::MARKER . 'entry-catch-all' => new MySqlBackend(),
        ], [
            'hits' => [
                ['elementId' => 49639, 'siteId' => 1, 'score' => 12.5],
            ],
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $scores = $this->withOnlySearchIndices([
            $this->index(self::MARKER . 'entry-narrow', Entry::class, 1, criteria: ['sections' => ['news']]),
            $this->index(self::MARKER . 'entry-catch-all', Entry::class, 1),
        ], function(): array {
            $query = Entry::find();
            $query->search = 'classic watches';
            $query->siteId = 1;

            $serviceIndex = SearchManager::$plugin->nativeSearchCoverage->getIndexForQuery($query);

            self::assertSame(self::MARKER . 'entry-catch-all', $serviceIndex?->handle);

            return (new CraftSearchAdapter())->searchElements($query);
        });

        self::assertSame(['49639-1' => 12.5], $scores);
        self::assertSame(self::MARKER . 'entry-catch-all', $backend->searchCalls[0]['indexName'] ?? null);
    }

    private function index(
        string $handle,
        string $elementType,
        int|array|null $siteId,
        ?string $backend = null,
        mixed $criteria = [],
    ): SearchIndex {
        $index = new SearchIndex();
        $index->handle = $handle;
        $index->name = $handle;
        $index->elementType = $elementType;
        $index->siteId = $siteId;
        $index->backend = $backend;
        $index->criteria = $criteria;
        $index->enabled = true;
        $index->source = 'database';

        return $index;
    }
}

final class NativeSearchCoverageBackendService extends BackendService
{
    /**
     * @var list<array{indexName: string, query: string, options: array<string, mixed>}>
     */
    public array $searchCalls = [];

    /**
     * @param array<string, BackendInterface> $backendsByIndex
     * @param array<string, mixed> $searchResponse
     */
    public function __construct(
        private readonly array $backendsByIndex,
        private readonly array $searchResponse = ['hits' => []],
    ) {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backendsByIndex[$indexName] ?? new MySqlBackend();
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return new MySqlBackend();
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
