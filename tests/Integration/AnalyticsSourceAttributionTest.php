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
use craft\web\Request;
use craft\web\Response;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\Support\OwnedAnalyticsTracker;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Analytics-source attribution contracts across supported entry points.
 *
 * @since 5.54.0
 */
final class AnalyticsSourceAttributionTest extends TestCase
{
    private const QUERY_PREFIX = '__sm_pr1_debt9__';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?OwnedAnalyticsTracker $analyticsTracker = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->analyticsTracker = OwnedAnalyticsTracker::forQueryPrefix(self::QUERY_PREFIX);
    }

    protected function tearDown(): void
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
        parent::tearDown();
    }

    protected function cleanupExternalState(): void
    {
        $this->analyticsTracker?->cleanupOwnedRows();
    }

    #[DataProvider('sourceResolutionProvider')]
    public function testFinalWriterAppliesTheCompleteDefaultAndOverrideMatrix(
        string $case,
        mixed $explicitSource,
        string $defaultSource,
        string $expectedSource,
    ): void {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'matrix-' . $case;
        $this->installWebRequest([], 'https://search.example.test/results');

        SearchManager::$plugin->analytics->trackSearch(
            $handle,
            $query,
            1,
            5.0,
            'test',
            $this->testSiteId(),
            [
                'source' => $explicitSource,
                'sourceDefault' => $defaultSource,
            ],
        );

        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame($expectedSource, $row['source']);
        self::assertNotContains($row['source'], ['frontend', 'cp']);
    }

    public function testDirectTrackingDefaultsToUnknownWithoutRefererInference(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'direct';
        $referer = 'https://search.example.test/results';
        $this->installWebRequest([], $referer);

        SearchManager::$plugin->analytics->trackSearch(
            $handle,
            $query,
            1,
            5.0,
            'test',
            $this->testSiteId(),
        );

        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame('unknown', $row['source']);
        self::assertSame($referer, $row['referer'], 'Referer capture remains legitimate stored metadata.');
    }

    public function testWidgetTrackingDefaultsToModalAtTheControllerBoundary(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'widget-modal';
        $this->installWebRequest([
            'q' => $query,
            'indexHandles' => $handle,
            'resultsCount' => '3',
            'trigger' => 'enter',
            'siteId' => (string)$this->testSiteId(),
        ], 'https://search.example.test/results');

        $response = (new SearchController('search', Craft::$app))->actionTrackSearch();

        self::assertSame(['success' => true, 'tracked' => true], $response->data);
        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame('widget-modal', $row['source']);
    }

    #[DataProvider('widgetBoundaryProvider')]
    public function testWidgetBoundaryDefaultsAndCustomOverridesReachTheFinalWrite(
        string $case,
        string $widgetType,
        ?string $analyticsSource,
        string $expectedSource,
    ): void {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'widget-' . $case;
        $params = [
            'q' => $query,
            'indexHandles' => $handle,
            'resultsCount' => '3',
            'trigger' => 'enter',
            'widgetType' => $widgetType,
            'siteId' => (string)$this->testSiteId(),
        ];
        if ($analyticsSource !== null) {
            $params['analyticsSource'] = $analyticsSource;
        }
        $this->installWebRequest($params, 'https://search.example.test/results');

        (new SearchController('search', Craft::$app))->actionTrackSearch();

        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame($expectedSource, $row['source']);
    }

    #[DataProvider('serviceBoundaryProvider')]
    public function testRestGraphqlAndTwigBoundariesReachTheFinalWrite(
        string $boundary,
        ?string $analyticsSource,
        string $expectedSource,
    ): void {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . $boundary . '-' . ($analyticsSource === null ? 'default' : 'custom');

        match ($boundary) {
            'rest' => $this->runRestSearch($handle, $query, $analyticsSource),
            'graphql' => $this->runGraphqlSearch($handle, $query, $analyticsSource),
            'twig' => $this->runTwigSearch($handle, $query, $analyticsSource),
            default => throw new \InvalidArgumentException("Unknown analytics boundary: {$boundary}"),
        };

        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame($expectedSource, $row['source']);
    }

    public function testCpTestBoundaryReachesTheFinalWrite(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'cp-test-default';
        $this->installPostJsonRequest([
            'query' => $query,
            'indexHandle' => $handle,
        ]);
        $user = $this->createTestUser('__sm_pr1_debt9_cp_', ['admin' => true]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);

        (new SettingsController('settings', SearchManager::$plugin))->actionTestSearch();

        $row = $this->analyticsRow($query);
        self::assertNotNull($row);
        self::assertSame('cp-test', $row['source']);
    }

    public function testOrdinaryAndCacheHitSearchesPreserveResolvedAttribution(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $query = self::QUERY_PREFIX . 'ordinary-cache-' . bin2hex(random_bytes(4));
        $backend = new AnalyticsAttributionBackend();
        $service = $this->installOrchestratedBackend($backend);
        $service->clearSearchCache($handle);

        try {
            $first = $service->search($handle, $query, [
                'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
            ]);
            $second = $service->search($handle, $query, [
                'source' => ' cache custom! ',
                'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
            ]);

            self::assertFalse($first['meta']['cached']);
            self::assertTrue($second['meta']['cached']);
            self::assertSame(1, $backend->searchCalls);
            self::assertSame(
                ['rest', 'cachecustom'],
                array_column($this->analyticsRows($query), 'source'),
            );
        } finally {
            $service->clearSearchCache($handle);
        }
    }

    public function testFailedSearchesRetainTrackingAndSkipAnalyticsStillPreventsWrites(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $failedQuery = self::QUERY_PREFIX . 'failed';
        $skippedQuery = self::QUERY_PREFIX . 'skipped';
        $backend = new AnalyticsAttributionBackend();
        $backend->response = ['hits' => [], 'total' => 0, '_failed' => true];
        $service = $this->installOrchestratedBackend($backend);

        $results = $service->search($handle, $failedQuery, [
            'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
        ]);
        $service->search($handle, $skippedQuery, [
            'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
            'skipAnalytics' => true,
        ]);

        self::assertArrayNotHasKey('_failed', $results);
        self::assertSame('rest', $this->analyticsRow($failedQuery)['source'] ?? null);
        self::assertNull($this->analyticsRow($skippedQuery));
    }

    public function testOrdinaryMultiIndexSearchCarriesOneResolvedSourceAndSessionToEveryWrite(): void
    {
        $handles = $this->requireAnalyticsIndexHandles(2);
        $query = self::QUERY_PREFIX . 'multi-ordinary';
        $backend = new AnalyticsAttributionBackend();
        $service = $this->installOrchestratedBackend($backend);

        $service->searchMultiple($handles, $query, [
            'source' => ' multi custom! ',
            'sourceDefault' => TrackingMetadataHelper::SOURCE_GRAPHQL,
        ]);

        $rows = $this->analyticsRows($query);
        self::assertCount(2, $rows);
        self::assertSame(['multicustom', 'multicustom'], array_column($rows, 'source'));
        self::assertNotEmpty($rows[0]['sessionId']);
        self::assertSame($rows[0]['sessionId'], $rows[1]['sessionId']);
    }

    #[DataProvider('multiIndexPaginationModeProvider')]
    public function testDirectPhpUnboundedMultiIndexSearchOwnsOffset(
        string $backendStyle,
        string $paginationMode,
    ): void {
        $handles = $this->requireAnalyticsIndexHandles(2);
        $query = self::QUERY_PREFIX . 'multi-unbounded-' . $backendStyle;
        $siteId = $this->testSiteId();
        $backend = new AnalyticsAttributionBackend();
        $backend->paginationMode = $paginationMode;
        $backend->hitPoolsByIndex = [
            $handles[0] => [
                ['elementId' => 101, 'siteId' => $siteId, 'score' => 100.0],
                ['elementId' => 102, 'siteId' => $siteId, 'score' => 70.0],
                ['elementId' => 103, 'siteId' => $siteId, 'score' => 50.0],
            ],
            $handles[1] => [
                ['elementId' => 201, 'siteId' => $siteId, 'score' => 90.0],
                ['elementId' => 202, 'siteId' => $siteId, 'score' => 80.0],
                ['elementId' => 203, 'siteId' => $siteId, 'score' => 60.0],
            ],
        ];
        $service = $this->installOrchestratedBackend($backend);

        $results = $service->searchMultiple($handles, $query, [
            'limit' => 0,
            'offset' => 2,
            'page' => 3,
            'siteId' => $siteId,
            'source' => ' direct pagination! ',
            'sourceDefault' => TrackingMetadataHelper::SOURCE_GRAPHQL,
            'includeQueryRuleDebug' => true,
        ]);

        self::assertSame([202, 102, 203, 103], array_column($results['hits'], 'elementId'), $backendStyle);
        self::assertSame(6, $results['total'], $backendStyle);
        self::assertSame([$handles[0] => 3, $handles[1] => 3], $results['indices'], $backendStyle);
        self::assertSame(
            [$handles[1], $handles[0], $handles[1], $handles[0]],
            array_column($results['hits'], '_index'),
            $backendStyle,
        );
        self::assertFalse($results['meta']['cached'], $backendStyle);
        self::assertFalse($results['meta']['synonymsExpanded'], $backendStyle);
        self::assertSame([], $results['meta']['rulesMatched'], $backendStyle);
        self::assertSame([], $results['meta']['promotionsMatched'], $backendStyle);

        self::assertCount(2, $backend->searchCallRecords, $backendStyle);
        foreach ($backend->searchCallRecords as $call) {
            self::assertSame(0, $call['options']['limit'], $backendStyle);
            self::assertSame(0, $call['options']['offset'], $backendStyle);
            self::assertSame(0, $call['options']['page'], $backendStyle);
        }

        $rows = $this->analyticsRows($query);
        self::assertCount(2, $rows, $backendStyle);
        self::assertSame(['directpagination', 'directpagination'], array_column($rows, 'source'), $backendStyle);
        self::assertNotEmpty($rows[0]['sessionId'], $backendStyle);
        self::assertSame($rows[0]['sessionId'], $rows[1]['sessionId'], $backendStyle);
    }

    public function testDirectPhpBoundedMultiIndexSearchRetainsCandidateHeadroom(): void
    {
        $handles = $this->requireAnalyticsIndexHandles(2);
        $query = self::QUERY_PREFIX . 'multi-bounded';
        $siteId = $this->testSiteId();
        $backend = new AnalyticsAttributionBackend();
        $backend->paginationMode = 'page';
        $backend->hitPoolsByIndex = [
            $handles[0] => [
                ['elementId' => 101, 'siteId' => $siteId, 'score' => 100.0],
                ['elementId' => 102, 'siteId' => $siteId, 'score' => 70.0],
                ['elementId' => 103, 'siteId' => $siteId, 'score' => 50.0],
            ],
            $handles[1] => [
                ['elementId' => 201, 'siteId' => $siteId, 'score' => 90.0],
                ['elementId' => 202, 'siteId' => $siteId, 'score' => 80.0],
                ['elementId' => 203, 'siteId' => $siteId, 'score' => 60.0],
            ],
        ];
        $service = $this->installOrchestratedBackend($backend);

        $results = $service->searchMultiple($handles, $query, [
            'limit' => 2,
            'offset' => 2,
            'page' => 4,
            'siteId' => $siteId,
            'skipAnalytics' => true,
            'includeQueryRuleDebug' => true,
        ]);

        self::assertSame([202, 102], array_column($results['hits'], 'elementId'));
        foreach ($backend->searchCallRecords as $call) {
            self::assertSame(4, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    public function testUnboundedMultiIndexAndSynonymAggregatesEachOwnTheirPagination(): void
    {
        $handles = $this->requireAnalyticsIndexHandles(2);
        $query = self::QUERY_PREFIX . 'multi-synonym';
        $synonymQuery = $query . '-expanded';
        $siteId = $this->testSiteId();
        $backend = new AnalyticsAttributionBackend();
        $backend->paginationMode = 'offset';
        $backend->hitPoolsByIndexAndQuery = [
            $handles[0] => [
                $query => [
                    ['elementId' => 101, 'siteId' => $siteId, 'score' => 100.0],
                    ['elementId' => 102, 'siteId' => $siteId, 'score' => 70.0],
                ],
                $synonymQuery => [
                    ['elementId' => 101, 'siteId' => $siteId, 'score' => 110.0],
                    ['elementId' => 103, 'siteId' => $siteId, 'score' => 50.0],
                ],
            ],
            $handles[1] => [
                $query => [
                    ['elementId' => 201, 'siteId' => $siteId, 'score' => 90.0],
                    ['elementId' => 202, 'siteId' => $siteId, 'score' => 80.0],
                ],
                $synonymQuery => [
                    ['elementId' => 202, 'siteId' => $siteId, 'score' => 85.0],
                    ['elementId' => 203, 'siteId' => $siteId, 'score' => 60.0],
                ],
            ],
        ];
        $service = $this->installOrchestratedBackend($backend);
        $this->swapPluginComponent('search-manager', 'queryRules', new AnalyticsAttributionSynonymQueryRuleService());

        $results = $service->searchMultiple($handles, $query, [
            'limit' => 0,
            'offset' => 2,
            'page' => 5,
            'siteId' => $siteId,
            'skipAnalytics' => true,
            'includeQueryRuleDebug' => true,
        ]);

        self::assertSame([202, 102, 203, 103], array_column($results['hits'], 'elementId'));
        self::assertSame(6, $results['total']);
        self::assertTrue($results['meta']['synonymsExpanded']);
        self::assertSame([$query, $synonymQuery], $results['meta']['expandedQueries']);
        self::assertCount(4, $backend->searchCallRecords);
        foreach ($backend->searchCallRecords as $call) {
            self::assertSame(0, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    public function testSingleAndMultiIndexRedirectsPreserveAttributionWithoutSearching(): void
    {
        $handles = $this->requireAnalyticsIndexHandles(2);
        $singleQuery = self::QUERY_PREFIX . 'redirect-single';
        $multiQuery = self::QUERY_PREFIX . 'redirect-multi';
        $backend = new AnalyticsAttributionBackend();
        $service = $this->installOrchestratedBackend($backend);
        $this->swapPluginComponent('search-manager', 'queryRules', new AnalyticsAttributionRedirectQueryRuleService());

        $single = $service->search($handles[0], $singleQuery, [
            'sourceDefault' => TrackingMetadataHelper::SOURCE_TWIG,
        ]);
        $multi = $service->searchMultiple($handles, $multiQuery, [
            'source' => ' redirect custom! ',
            'sourceDefault' => TrackingMetadataHelper::SOURCE_REST,
        ]);

        self::assertSame('https://example.test/search-redirect', $single['redirect']);
        self::assertSame('twig', $this->analyticsRow($singleQuery)['source'] ?? null);
        self::assertSame(1, (int)($this->analyticsRow($singleQuery)['wasRedirected'] ?? 0));
        self::assertSame('https://example.test/search-redirect', $multi['redirect']);
        $multiRows = $this->analyticsRows($multiQuery);
        self::assertCount(2, $multiRows);
        self::assertSame(['redirectcustom', 'redirectcustom'], array_column($multiRows, 'source'));
        self::assertSame([1, 1], array_map('intval', array_column($multiRows, 'wasRedirected')));
        self::assertNotEmpty($multiRows[0]['sessionId']);
        self::assertSame($multiRows[0]['sessionId'], $multiRows[1]['sessionId']);
        self::assertSame(0, $backend->searchCalls);
    }

    public function testHistoricalSourcesRemainReadableInBreakdownsAndExportsWithoutMutation(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $this->installWebRequest([], 'https://legacy.example.test/search');
        foreach (['frontend', 'cp', 'api'] as $source) {
            SearchManager::$plugin->analytics->trackSearch(
                $handle,
                self::QUERY_PREFIX . 'legacy-' . $source,
                1,
                1.0,
                'test',
                $this->testSiteId(),
                ['source' => $source],
            );
        }

        $before = array_column($this->analyticsRowsLike(self::QUERY_PREFIX . 'legacy-'), 'source');
        $breakdown = SearchManager::$plugin->analytics->getSourceBreakdown($this->testSiteId(), 'all');
        $export = SearchManager::$plugin->analytics->exportAnalytics($this->testSiteId(), 'all');
        $exportedSources = [];
        foreach ($export['rows'] as $row) {
            if (str_starts_with((string)$row['query'], self::QUERY_PREFIX . 'legacy-')) {
                $exportedSources[] = $row['source'];
            }
        }
        $after = array_column($this->analyticsRowsLike(self::QUERY_PREFIX . 'legacy-'), 'source');

        self::assertSame(['frontend', 'cp', 'api'], $before);
        self::assertEqualsCanonicalizing(
            ['frontend', 'cp', 'api'],
            array_values(array_intersect(array_column($breakdown['data'], 'source'), ['frontend', 'cp', 'api'])),
        );
        self::assertEqualsCanonicalizing(['frontend', 'cp', 'api'], $exportedSources);
        self::assertSame($before, $after);
    }

    public function testCanonicalHistoricalAndCustomSourcesKeepRawIdentityWhileChartsUseLabels(): void
    {
        $handle = $this->requireAnalyticsIndexHandle();
        $this->installWebRequest([], 'https://sources.example.test/search');
        $expectedLabels = [
            'widget-modal' => 'Modal Widget',
            'widget-page' => 'Page Widget',
            'widget-inline' => 'Inline Widget',
            'twig' => 'Twig',
            'rest' => 'REST',
            'graphql' => 'GraphQL',
            'cp-test' => 'Control Panel Test',
            'unknown' => 'Unknown',
            'frontend' => 'Frontend',
            'cp' => 'Control Panel',
            'api' => 'API',
            'customsource' => 'Customsource',
        ];

        foreach (array_keys($expectedLabels) as $source) {
            SearchManager::$plugin->analytics->trackSearch(
                $handle,
                self::QUERY_PREFIX . 'presentation-' . $source,
                1,
                1.0,
                'test',
                $this->testSiteId(),
                ['source' => $source],
            );
        }

        $before = array_column($this->analyticsRowsLike(self::QUERY_PREFIX . 'presentation-'), 'source');
        $breakdown = SearchManager::$plugin->analytics->getSourceBreakdown($this->testSiteId(), 'all');
        $labelsBySource = array_column($breakdown['data'], 'label', 'source');
        $export = SearchManager::$plugin->analytics->exportAnalytics($this->testSiteId(), 'all');
        $exportedSources = [];
        foreach ($export['rows'] as $row) {
            if (str_starts_with((string)$row['query'], self::QUERY_PREFIX . 'presentation-')) {
                $exportedSources[] = $row['source'];
            }
        }
        $after = array_column($this->analyticsRowsLike(self::QUERY_PREFIX . 'presentation-'), 'source');

        foreach ($expectedLabels as $source => $label) {
            self::assertSame($label, $labelsBySource[$source] ?? null, $source);
        }
        self::assertSame(array_column($breakdown['data'], 'label'), $breakdown['labels']);
        self::assertSame(array_map('intval', array_column($breakdown['data'], 'count')), $breakdown['values']);
        self::assertEqualsCanonicalizing(array_keys($expectedLabels), $before);
        self::assertEqualsCanonicalizing(array_keys($expectedLabels), $exportedSources);
        self::assertSame($before, $after);

        $chartSource = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/analytics/src/analytics.js');
        $this->assertIsString($chartSource);
        self::assertStringContainsString('function renderSourceChart(data)', $chartSource);
        self::assertStringContainsString('labels: data.labels', $chartSource);
        self::assertStringContainsString('data: data.values', $chartSource);
    }

    /**
     * @return iterable<string, array{string, mixed, string, string}>
     */
    public static function sourceResolutionProvider(): iterable
    {
        $defaults = [
            'widget-modal' => TrackingMetadataHelper::SOURCE_WIDGET_MODAL,
            'widget-page' => TrackingMetadataHelper::SOURCE_WIDGET_PAGE,
            'widget-inline' => TrackingMetadataHelper::SOURCE_WIDGET_INLINE,
            'twig' => TrackingMetadataHelper::SOURCE_TWIG,
            'rest' => TrackingMetadataHelper::SOURCE_REST,
            'graphql' => TrackingMetadataHelper::SOURCE_GRAPHQL,
            'cp-test' => TrackingMetadataHelper::SOURCE_CP_TEST,
            'unknown' => TrackingMetadataHelper::SOURCE_UNKNOWN,
        ];

        foreach ($defaults as $case => $default) {
            yield $case . '-default' => [$case . '-default', null, $default, $default];
            yield $case . '-custom' => [$case . '-custom', ' custom source! ', $default, 'customsource'];
            yield $case . '-whitespace' => [$case . '-whitespace', '   ', $default, $default];
        }
    }

    /**
     * @return iterable<string, array{string, string, string|null, string}>
     */
    public static function widgetBoundaryProvider(): iterable
    {
        yield 'modal default' => ['modal-default', 'modal', null, 'widget-modal'];
        yield 'page default' => ['page-default', 'page', null, 'widget-page'];
        yield 'inline default' => ['inline-default', 'inline', null, 'widget-inline'];
        yield 'modal custom' => ['modal-custom', 'modal', ' modal custom! ', 'modalcustom'];
        yield 'page custom' => ['page-custom', 'page', ' page custom! ', 'pagecustom'];
        yield 'inline custom' => ['inline-custom', 'inline', ' inline custom! ', 'inlinecustom'];
        yield 'modal whitespace' => ['modal-whitespace', 'modal', '   ', 'widget-modal'];
        yield 'page whitespace' => ['page-whitespace', 'page', '   ', 'widget-page'];
        yield 'inline whitespace' => ['inline-whitespace', 'inline', '   ', 'widget-inline'];
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function serviceBoundaryProvider(): iterable
    {
        foreach (['rest', 'graphql', 'twig'] as $boundary) {
            yield $boundary . ' default' => [$boundary, null, $boundary];
            yield $boundary . ' custom' => [$boundary, ' custom source! ', 'customsource'];
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function multiIndexPaginationModeProvider(): iterable
    {
        yield 'local/Meilisearch offset consumer' => ['offset', 'offset'];
        yield 'Algolia/Typesense page consumer' => ['page', 'page'];
    }

    /**
     * @param array<string, string> $params
     */
    private function installWebRequest(array $params, ?string $referer): void
    {
        Craft::$app->set('response', new Response());
        Craft::$app->set('request', new class($params, $referer) extends Request {
            /** @param array<string, string> $params */
            public function __construct(
                private array $params,
                private ?string $testReferer,
            ) {
                parent::__construct();
            }

            public function getParam($name, $defaultValue = null): mixed
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getQueryParam($name, $defaultValue = null): mixed
            {
                return $defaultValue;
            }

            public function getQueryParams(): array
            {
                return [];
            }

            public function getIsPost(): bool
            {
                return true;
            }

            public function getAcceptsJson(): bool
            {
                return true;
            }

            public function getReferrer(): ?string
            {
                return $this->testReferer;
            }

            public function getHostName(): string
            {
                return 'search.example.test';
            }

            public function getIsCpRequest(): bool
            {
                return false;
            }

            public function getUserAgent(): string
            {
                return 'SearchManagerAnalyticsAttributionTest/1.0';
            }

            public function getUserIP(int $filterOptions = 0): ?string
            {
                return '127.0.0.1';
            }

            public function validateCsrfToken($clientSuppliedToken = null): bool
            {
                return true;
            }

            public function hasValidSiteToken(): bool
            {
                return false;
            }
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    private function installPostJsonRequest(array $params): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams($params);
        $request->getHeaders()->set('Accept', 'application/json');
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
    }

    private function runRestSearch(string $handle, string $query, ?string $analyticsSource): void
    {
        $params = [
            'q' => $query,
            'indexHandles' => $handle,
            'siteId' => (string)$this->testSiteId(),
        ];
        if ($analyticsSource !== null) {
            $params['analyticsSource'] = $analyticsSource;
        }
        $this->installWebRequest($params, 'https://search.example.test/results');

        (new ApiController('api', SearchManager::$plugin))->actionSearch();
    }

    private function runGraphqlSearch(string $handle, string $query, ?string $analyticsSource): void
    {
        $arguments = [
            'query' => $query,
            'indexHandles' => [$handle],
            'siteId' => $this->testSiteId(),
        ];
        if ($analyticsSource !== null) {
            $arguments['analyticsSource'] = $analyticsSource;
        }
        $this->installWebRequest([], 'https://search.example.test/results');

        SearchResolver::resolveSearch(
            null,
            $arguments,
            null,
            $this->createMock(ResolveInfo::class),
        );
    }

    private function runTwigSearch(string $handle, string $query, ?string $analyticsSource): void
    {
        $options = ['siteId' => $this->testSiteId()];
        if ($analyticsSource !== null) {
            $options['analyticsSource'] = $analyticsSource;
        }
        $this->installWebRequest([], 'https://search.example.test/results');

        (new SearchManagerVariable())->search($handle, $query, $options);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function analyticsRow(string $query): ?array
    {
        $row = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['query' => $query])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsRows(string $query): array
    {
        return (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['query' => $query])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function analyticsRowsLike(string $queryPrefix): array
    {
        return (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['like', 'query', $queryPrefix . '%', false])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function requireAnalyticsIndexHandle(): string
    {
        foreach (SearchIndex::findAll() as $index) {
            if (
                $index->enableAnalytics
                && SearchManager::$plugin->dependencies->isIndexAvailable($index->handle)
            ) {
                return $index->handle;
            }
        }

        self::markTestSkipped('No enabled, analytics-enabled index is available for analytics source-attribution coverage.');
    }

    /**
     * @return list<string>
     */
    private function requireAnalyticsIndexHandles(int $count): array
    {
        $handles = [];
        foreach (SearchIndex::findAll() as $index) {
            if (
                $index->enableAnalytics
                && SearchManager::$plugin->dependencies->isIndexAvailable($index->handle)
            ) {
                $handles[] = $index->handle;
            }
            if (count($handles) === $count) {
                return $handles;
            }
        }

        self::markTestSkipped("Fewer than {$count} analytics-enabled indices are available for analytics source-attribution coverage.");
    }

    private function installOrchestratedBackend(AnalyticsAttributionBackend $backend): AnalyticsAttributionBackendService
    {
        $this->installWebRequest([], 'https://search.example.test/results');
        $service = new AnalyticsAttributionBackendService($backend);
        $this->swapPluginComponent('search-manager', 'backend', $service);

        return $service;
    }

    private function testSiteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }
}

/**
 * @since 5.54.0
 */
final class AnalyticsAttributionBackend extends MySqlBackend
{
    public int $searchCalls = 0;

    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $searchCallRecords = [];

    /** @var array<string, mixed> */
    public array $response = ['hits' => [], 'total' => 0];

    /** @var array<string, list<array<string, mixed>>> */
    public array $hitPoolsByIndex = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    public array $hitPoolsByIndexAndQuery = [];

    public string $paginationMode = 'none';

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;
        $this->searchCallRecords[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        $hits = $this->hitPoolsByIndexAndQuery[$indexName][$query]
            ?? $this->hitPoolsByIndex[$indexName]
            ?? null;
        if ($hits !== null) {
            $total = count($hits);
            $limit = (int)($options['limit'] ?? 0);
            $offset = (int)($options['offset'] ?? 0);
            if ($this->paginationMode === 'page' && $limit > 0) {
                $offset = (int)($options['page'] ?? 0) * $limit;
            }
            if ($limit > 0) {
                $hits = array_slice($hits, $offset, $limit);
            } elseif ($offset > 0) {
                $hits = array_slice($hits, $offset);
            }

            return ['hits' => array_values($hits), 'total' => $total];
        }

        return $this->response;
    }
}

/**
 * @since 5.54.0
 */
final class AnalyticsAttributionBackendService extends BackendService
{
    public function __construct(
        private readonly BackendInterface $testBackend,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->testBackend;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->testBackend;
    }
}

/**
 * @since 5.54.0
 */
final class AnalyticsAttributionRedirectQueryRuleService extends QueryRuleService
{
    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return [];
    }

    public function getRedirectUrl(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): ?string {
        return 'https://example.test/search-redirect';
    }
}

/**
 * @since 5.54.0
 */
final class AnalyticsAttributionSynonymQueryRuleService extends QueryRuleService
{
    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return [];
    }

    public function expandWithSynonyms(
        string $query,
        ?string $indexHandle = null,
        ?int $siteId = null,
        ?array $matchedRules = null,
    ): array {
        return [$query, $query . '-expanded'];
    }
}
