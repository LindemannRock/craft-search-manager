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
use craft\events\ExecuteGqlQueryEvent;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\services\Gql;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Application as YiiApplication;
use yii\caching\ArrayCache;
use yii\web\ForbiddenHttpException;

/**
 * GraphQL coverage for the read-only search and autocomplete query layer.
 *
 * @since 5.53.0
 */
final class GraphqlSearchTest extends TestCase
{
    protected function tearDown(): void
    {
        Craft::$app->getGql()->setActiveSchema(null);

        parent::tearDown();
    }

    public function testSearchQueriesAreRegisteredWithoutMutations(): void
    {
        $queries = SearchQuery::getQueries(false);

        $this->assertArrayHasKey('searchManagerSearch', $queries);
        $this->assertArrayHasKey('searchManagerAutocomplete', $queries);
    }

    public function testGraphqlSearchCacheToggleRestoresOnSkippedAfterEvent(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $generalConfig->enableGraphqlCaching = true;

        try {
            Craft::$app->getGql()->trigger(
                Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY,
                new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]),
            );

            $this->assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);

            $this->assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    #[DataProvider('searchManagerOperationProvider')]
    public function testGraphqlOuterCacheDetectionHonorsSelectedFields(
        string $query,
        ?string $operationName,
        bool $shouldBypass,
    ): void {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $generalConfig->enableGraphqlCaching = true;
        $event = new ExecuteGqlQueryEvent([
            'query' => $query,
            'operationName' => $operationName,
        ]);

        try {
            Craft::$app->getGql()->trigger(Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY, $event);

            self::assertSame(!$shouldBypass, $generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(Gql::EVENT_AFTER_EXECUTE_GQL_QUERY, $event);
            self::assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    public function testGraphqlSearchCacheToggleDoesNotOverwriteOriginalBeforeRestore(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $generalConfig->enableGraphqlCaching = true;

        try {
            Craft::$app->getGql()->trigger(
                Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY,
                new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]),
            );
            Craft::$app->getGql()->trigger(
                Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY,
                new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]),
            );

            $this->assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(
                Gql::EVENT_AFTER_EXECUTE_GQL_QUERY,
                new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]),
            );

            $this->assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(
                Gql::EVENT_AFTER_EXECUTE_GQL_QUERY,
                new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]),
            );

            $this->assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    public function testGraphqlCacheToggleIgnoresNestedUnrelatedOperation(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $searchEvent = new ExecuteGqlQueryEvent(['query' => $this->searchQuery()]);
        $unrelatedEvent = new ExecuteGqlQueryEvent(['query' => 'query { entries { id } }']);
        $generalConfig->enableGraphqlCaching = true;

        try {
            Craft::$app->getGql()->trigger(Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY, $searchEvent);
            Craft::$app->getGql()->trigger(Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY, $unrelatedEvent);
            self::assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(Gql::EVENT_AFTER_EXECUTE_GQL_QUERY, $unrelatedEvent);
            self::assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(Gql::EVENT_AFTER_EXECUTE_GQL_QUERY, $searchEvent);
            self::assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    public function testGraphqlCacheTogglePreservesInitiallyDisabledSetting(): void
    {
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $generalConfig->enableGraphqlCaching = false;
        $event = new ExecuteGqlQueryEvent(['query' => $this->autocompleteQuery()]);

        try {
            Craft::$app->getGql()->trigger(Gql::EVENT_BEFORE_EXECUTE_GQL_QUERY, $event);
            self::assertFalse($generalConfig->enableGraphqlCaching);

            Craft::$app->getGql()->trigger(Gql::EVENT_AFTER_EXECUTE_GQL_QUERY, $event);
            self::assertFalse($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    public function testGraphqlAutocompleteBypassesStaleOuterResultCache(): void
    {
        $site = Craft::$app->getSites()->getAllSites()[0] ?? null;
        if ($site === null) {
            $this->markTestSkipped('No site available.');
        }
        $index = $this->recordingIndex((int)$site->id);

        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalGraphqlCaching = $generalConfig->enableGraphqlCaching;
        $originalCache = Craft::$app->getCache();
        $autocomplete = new GraphqlSearchRecordingAutocompleteService();
        $autocomplete->suggestResponses = [['first-state'], ['updated-state']];
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);
        $schema = $this->schemaForSites([$site->uid]);
        $query = sprintf(
            'query AutocompleteCache { searchManagerAutocomplete(query: "pr144-cache", indexHandles: ["%s"], siteId: %d, only: "suggestions") { suggestions } }',
            $index->handle,
            $site->id,
        );

        try {
            $cache = new ArrayCache();
            Craft::$app->set('cache', $cache);
            $generalConfig->enableGraphqlCaching = true;
            $cacheKey = $this->graphqlCacheKey($schema, $query, 'AutocompleteCache');
            $staleResult = [
                'data' => [
                    'searchManagerAutocomplete' => [
                        'suggestions' => ['stale-outer-state'],
                    ],
                ],
            ];
            Craft::$app->getGql()->setCachedResult($cacheKey, $staleResult);

            [$first, $second] = $this->withOnlySearchIndices(
                [$index],
                static fn(): array => [
                    Craft::$app->getGql()->executeQuery($schema, $query, operationName: 'AutocompleteCache'),
                    Craft::$app->getGql()->executeQuery($schema, $query, operationName: 'AutocompleteCache'),
                ],
            );

            self::assertSame(['first-state'], $first['data']['searchManagerAutocomplete']['suggestions'] ?? null);
            self::assertSame(['updated-state'], $second['data']['searchManagerAutocomplete']['suggestions'] ?? null);
            self::assertCount(2, $autocomplete->suggestCalls);
            self::assertTrue($generalConfig->enableGraphqlCaching);
            self::assertSame($staleResult, Craft::$app->getGql()->getCachedResult($cacheKey));
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            Craft::$app->set('cache', $originalCache);
            $generalConfig->enableGraphqlCaching = $originalGraphqlCaching;
        }
    }

    public function testUnrelatedGraphqlOperationRemainsOuterCacheable(): void
    {
        $site = Craft::$app->getSites()->getAllSites()[0] ?? null;
        if ($site === null) {
            $this->markTestSkipped('No site available.');
        }

        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalGraphqlCaching = $generalConfig->enableGraphqlCaching;
        $originalCache = Craft::$app->getCache();
        $schema = $this->schemaForSites([$site->uid]);
        $query = 'query Unrelated { __typename }';
        $cacheKey = $this->graphqlCacheKey($schema, $query, 'Unrelated');

        try {
            Craft::$app->set('cache', new ArrayCache());
            $generalConfig->enableGraphqlCaching = true;

            $response = Craft::$app->getGql()->executeQuery($schema, $query, operationName: 'Unrelated');

            self::assertSame($response, Craft::$app->getGql()->getCachedResult($cacheKey));
            self::assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            Craft::$app->set('cache', $originalCache);
            $generalConfig->enableGraphqlCaching = $originalGraphqlCaching;
        }
    }

    public function testGraphqlAutocompleteResolverExceptionRestoresOuterCacheSetting(): void
    {
        $site = Craft::$app->getSites()->getAllSites()[0] ?? null;
        if ($site === null) {
            $this->markTestSkipped('No site available.');
        }
        $index = $this->recordingIndex((int)$site->id);

        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $original = $generalConfig->enableGraphqlCaching;
        $autocomplete = new GraphqlSearchRecordingAutocompleteService();
        $autocomplete->throwOnSuggest = true;
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);
        $query = sprintf(
            'query AutocompleteFailure { searchManagerAutocomplete(query: "pr144-error", indexHandles: ["%s"], siteId: %d, only: "suggestions") { suggestions } }',
            $index->handle,
            $site->id,
        );

        try {
            $generalConfig->enableGraphqlCaching = true;
            $response = $this->withOnlySearchIndices(
                [$index],
                fn(): array => Craft::$app->getGql()->executeQuery(
                    $this->schemaForSites([$site->uid]),
                    $query,
                    operationName: 'AutocompleteFailure',
                ),
            );

            self::assertArrayHasKey('errors', $response);
            self::assertTrue($generalConfig->enableGraphqlCaching);
        } finally {
            Craft::$app->trigger(YiiApplication::EVENT_AFTER_REQUEST);
            $generalConfig->enableGraphqlCaching = $original;
        }
    }

    public function testSearchResolverDelegatesToBackendWithGraphqlOptions(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $index = $pair[0];
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [
                [
                    'objectID' => 123,
                    'score' => 42.5,
                    'content' => 'internal content must be stripped',
                ],
            ],
            'total' => 1,
            'meta' => ['cached' => false],
        ];

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'indexHandles' => [$index->handle],
            'siteId' => (int)($index->getSiteIds()[0] ?? 1),
            'resultsLimit' => 5,
            'page' => 2,
            'language' => 'en',
            'skipAnalytics' => true,
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(1, $response['total']);
        $this->assertSame(2, $response['page']);
        $this->assertSame(5, $response['resultsLimit']);
        $this->assertSame(1, $response['totalPages']);
        $this->assertArrayNotHasKey('meta', $response);
        $this->assertArrayNotHasKey('content', $response['hits'][0]);

        $calls = $stub->callsFor('search');
        $this->assertCount(1, $calls);
        $this->assertSame($index->handle, $calls[0]['indexName']);
        $this->assertSame('coffee', $calls[0]['items'][0]['query']);
        $this->assertSame(5, $calls[0]['items'][0]['options']['limit']);
        $this->assertSame(10, $calls[0]['items'][0]['options']['offset']);
        $this->assertSame((int)($index->getSiteIds()[0] ?? 1), $calls[0]['items'][0]['options']['siteId']);
        $this->assertTrue($calls[0]['items'][0]['options']['skipAnalytics']);
        $this->assertNull($calls[0]['items'][0]['options']['source']);
        $this->assertSame('graphql', $calls[0]['items'][0]['options']['sourceDefault']);
    }

    public function testGraphqlLocalSearchPreservesZeroQuery(): void
    {
        $index = $this->recordingIndex(1);
        $stub = $this->installStubBackend();
        $stub->searchResponse = ['hits' => [], 'total' => 0];
        $resolveInfo = $this->createStub(\GraphQL\Type\Definition\ResolveInfo::class);

        $response = $this->withOnlySearchIndices([$index], static fn(): array => SearchResolver::resolveSearch(null, [
            'query' => '0',
            'indexHandles' => [$index->handle],
            'siteId' => 1,
            'resultsLimit' => 0,
            'skipAnalytics' => false,
        ], null, $resolveInfo));

        self::assertSame('0', $response['query']);
        $call = $stub->callsFor('search')[0]['items'][0];
        self::assertSame('0', $call['query']);
        self::assertSame(20, $call['options']['limit']);
        self::assertFalse($call['options']['skipAnalytics']);
    }

    public function testGraphqlSearchSchemaDoesNotExposeEnrichArgument(): void
    {
        $queries = SearchQuery::getQueries(false);
        $args = $queries['searchManagerSearch']['args'] ?? [];
        $source = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');

        $this->assertArrayNotHasKey('enrich', $args);
        $this->assertStringNotContainsString('enrichResults(', $source);
        $this->assertStringNotContainsString('SearchManager::$plugin->enrichment', $source);
    }

    public function testGraphqlSearchExposesDebugMetaOnlyWhenRequestedWithAccess(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $queries = SearchQuery::getQueries(false);
        $this->assertArrayHasKey('debugEnabled', $queries['searchManagerSearch']['args'] ?? []);

        $index = $pair[0];
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [],
            'total' => 0,
            'meta' => ['cached' => false, 'backend' => 'test'],
        ];
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalDevMode = $generalConfig->devMode;
        $generalConfig->devMode = true;

        try {
            $withoutDebugRequest = SearchResolver::resolveSearch(null, [
                'query' => 'coffee',
                'indexHandles' => [$index->handle],
                'siteId' => (int)($index->getSiteIds()[0] ?? 1),
            ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));
            $response = SearchResolver::resolveSearch(null, [
                'query' => 'coffee',
                'indexHandles' => [$index->handle],
                'siteId' => (int)($index->getSiteIds()[0] ?? 1),
                'debugEnabled' => true,
            ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));
        } finally {
            $generalConfig->devMode = $originalDevMode;
        }

        $this->assertArrayNotHasKey('meta', $withoutDebugRequest);
        $this->assertSame(['cached' => false, 'backend' => 'test'], $response['meta'] ?? null);
    }

    public function testGraphqlSearchExposesAndAppliesRetrievableFieldsArgument(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $queries = SearchQuery::getQueries(false);
        $this->assertArrayHasKey('retrievableFields', $queries['searchManagerSearch']['args'] ?? []);

        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [[
                'objectID' => $entry->id,
                'elementId' => $entry->id,
                'siteId' => $entry->siteId,
                'title' => 'Metadata title',
                'type' => 'entry',
                '_index' => $index->handle,
                '_fields' => [
                    'intro' => 'Intro field value',
                    'category' => 'Category field value',
                ],
            ]],
            'total' => 1,
        ];

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'intro',
            'indexHandles' => [$index->handle],
            'siteId' => $entry->siteId,
            'retrievableFields' => ['intro'],
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(['intro' => 'Intro field value'], $response['hits'][0]['fields'] ?? null);
    }

    public function testGraphqlSearchRetrievableFieldsArgumentSupportsWildcardExclusions(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [[
                'objectID' => $entry->id,
                'elementId' => $entry->id,
                'siteId' => $entry->siteId,
                'title' => 'Metadata title',
                'type' => 'entry',
                '_index' => $index->handle,
                '_fields' => [
                    'intro' => 'Intro field value',
                    'category' => 'Category field value',
                ],
            ]],
            'total' => 1,
        ];

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'intro',
            'indexHandles' => [$index->handle],
            'siteId' => $entry->siteId,
            'retrievableFields' => ['*', '-category'],
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(['intro' => 'Intro field value'], $response['hits'][0]['fields'] ?? null);
    }

    public function testSearchAllowsExplicitSiteInsideActiveGraphqlSchema(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $index = $pair[0];
        $site = Craft::$app->getSites()->getSiteById((int)($index->getSiteIds()[0] ?? 0));
        if ($site === null) {
            $this->markTestSkipped('No index site available.');
        }

        Craft::$app->getGql()->setActiveSchema($this->schemaForSites([$site->uid]));
        $stub = $this->installStubBackend();

        SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'indexHandles' => [$index->handle],
            'siteId' => $site->id,
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $calls = $stub->callsFor('search');
        $this->assertCount(1, $calls);
        $this->assertSame($site->id, $calls[0]['items'][0]['options']['siteId']);
    }

    public function testSearchRejectsExplicitSiteOutsideActiveGraphqlSchema(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $sites = Craft::$app->getSites()->getAllSites();
        if (count($sites) < 2) {
            $this->markTestSkipped('Need at least two sites to verify GraphQL denied-site scope.');
        }

        $allowedSite = $sites[0];
        $deniedSite = $sites[1];
        Craft::$app->getGql()->setActiveSchema($this->schemaForSites([$allowedSite->uid]));
        $stub = $this->installStubBackend();

        $this->expectException(ForbiddenHttpException::class);

        try {
            SearchResolver::resolveSearch(null, [
                'query' => 'coffee',
                'indexHandles' => [$pair[0]->handle],
                'siteId' => $deniedSite->id,
            ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));
        } finally {
            $this->assertSame([], $stub->callsFor('search'));
            $this->assertSame([], $stub->callsFor('searchMultiple'));
        }
    }

    public function testSearchWithoutSiteDefaultsToActiveGraphqlSchemaSites(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $index = $pair[0];
        $site = Craft::$app->getSites()->getSiteById((int)($index->getSiteIds()[0] ?? 0));
        if ($site === null) {
            $this->markTestSkipped('No index site available.');
        }

        Craft::$app->getGql()->setActiveSchema($this->schemaForSites([$site->uid]));
        $stub = $this->installStubBackend();

        SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'indexHandles' => [$index->handle],
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $calls = $stub->callsFor('search');
        $this->assertCount(1, $calls);
        $this->assertSame($site->id, $calls[0]['items'][0]['options']['siteId']);
    }

    public function testAutocompleteWithoutSiteDefaultsToActiveGraphqlSchemaSites(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $index = $pair[0];
        $site = Craft::$app->getSites()->getSiteById((int)($index->getSiteIds()[0] ?? 0));
        if ($site === null) {
            $this->markTestSkipped('No index site available.');
        }

        Craft::$app->getGql()->setActiveSchema($this->schemaForSites([$site->uid]));
        $autocomplete = new GraphqlSearchRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        SearchResolver::resolveAutocomplete(null, [
            'query' => 'coffee',
            'indexHandles' => [$index->handle],
            'only' => 'suggestions',
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertCount(1, $autocomplete->suggestCalls);
        $this->assertSame($site->id, $autocomplete->suggestCalls[0]['options']['siteId']);
    }

    public function testInvalidExplicitIndicesReturnEmptyResponseWithoutFallback(): void
    {
        $stub = $this->installStubBackend();

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'indexHandles' => ['__missing_index__'],
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(0, $response['total']);
        $this->assertSame([], $response['hits']);
        $this->assertSame([], $stub->callsFor('search'));
        $this->assertSame([], $stub->callsFor('searchMultiple'));
    }

    public function testFiltersRequireSingleIndex(): void
    {
        $stub = $this->installStubBackend();

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'filters' => 'type:=`entry`',
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(0, $response['total']);
        $this->assertSame('The filters argument requires a single index.', $response['error']);
        $this->assertSame([], $stub->callsFor('search'));
        $this->assertSame([], $stub->callsFor('searchMultiple'));
    }

    /**
     * @param array<int, string> $siteUids
     */
    private function schemaForSites(array $siteUids): GqlSchema
    {
        return new GqlSchema([
            'name' => 'Search Manager test schema',
            'uid' => StringHelper::UUID(),
            'scope' => array_merge(
                ['searchManager.all:read'],
                array_map(static fn(string $uid): string => 'sites.' . $uid . ':read', $siteUids),
            ),
        ]);
    }

    private function searchQuery(): string
    {
        return 'query { searchManagerSearch(query: "coffee") { total } }';
    }

    private function autocompleteQuery(): string
    {
        return 'query { searchManagerAutocomplete(query: "cof") { suggestions } }';
    }

    private function recordingIndex(int $siteId): SearchIndex
    {
        return new SearchIndex([
            'name' => 'PR1.44 recording index',
            'handle' => '__sm_pr144_recording__',
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'enabled' => true,
        ]);
    }

    private function graphqlCacheKey(GqlSchema $schema, string $query, string $operationName): string
    {
        return Gql::CACHE_TAG
            . '::' . Craft::$app->getSites()->getCurrentSite()->id
            . '::' . $schema->uid
            . '::' . md5($query)
            . '::' . serialize(null)
            . '::' . Craft::$app->getInfo()->configVersion
            . '::' . serialize(null)
            . '::' . $operationName;
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function searchManagerOperationProvider(): iterable
    {
        yield 'direct search' => [
            'query { searchManagerSearch(query: "coffee") { total } }',
            null,
            true,
        ];
        yield 'direct autocomplete' => [
            'query { searchManagerAutocomplete(query: "cof") { suggestions } }',
            null,
            true,
        ];
        yield 'aliased autocomplete' => [
            'query { completions: searchManagerAutocomplete(query: "cof") { suggestions } }',
            null,
            true,
        ];
        yield 'named fragment' => [
            'query Lookup { ...AutocompleteFields } fragment AutocompleteFields on Query { searchManagerAutocomplete(query: "cof") { suggestions } }',
            'Lookup',
            true,
        ];
        yield 'inline fragment' => [
            'query Lookup { ... on Query { searchManagerAutocomplete(query: "cof") { suggestions } } }',
            'Lookup',
            true,
        ];
        yield 'combined search-manager and unrelated fields' => [
            'query { searchManagerAutocomplete(query: "cof") { suggestions } entries { id } }',
            null,
            true,
        ];
        yield 'unrelated only' => [
            'query { entries { id } }',
            null,
            false,
        ];
        yield 'alias named like autocomplete is unrelated' => [
            'query { searchManagerAutocomplete: entries { id } }',
            null,
            false,
        ];
        yield 'selected autocomplete operation' => [
            'query Unrelated { entries { id } } query Autocomplete { searchManagerAutocomplete(query: "cof") { suggestions } }',
            'Autocomplete',
            true,
        ];
        yield 'selected unrelated operation' => [
            'query Unrelated { entries { id } } query Autocomplete { searchManagerAutocomplete(query: "cof") { suggestions } }',
            'Unrelated',
            false,
        ];
    }

    private function readPluginFile(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($content);

        return $content;
    }
}

final class GraphqlSearchRecordingAutocompleteService extends AutocompleteService
{
    /** @var list<array{query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $suggestCalls = [];

    /** @var list<list<string>> */
    public array $suggestResponses = [['coffee']];

    public bool $throwOnSuggest = false;

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->suggestCalls[] = [
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        if ($this->throwOnSuggest) {
            throw new \RuntimeException('Recording autocomplete resolver failure.');
        }

        return array_shift($this->suggestResponses) ?? [];
    }
}
