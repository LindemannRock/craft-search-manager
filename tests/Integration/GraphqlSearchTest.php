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
use craft\events\ExecuteGqlQueryEvent;
use craft\models\GqlSchema;
use craft\services\Gql;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\helpers\SearchDebugAccessHelper;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Application as YiiApplication;
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

            $this->assertTrue($generalConfig->enableGraphqlCaching);
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
            'indices' => [$index->handle],
            'siteId' => (int)($index->getSiteIds()[0] ?? 1),
            'hitsPerPage' => 5,
            'page' => 2,
            'language' => 'en',
            'skipAnalytics' => true,
        ], null, $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class));

        $this->assertSame(1, $response['total']);
        $this->assertSame(2, $response['page']);
        $this->assertSame(5, $response['hitsPerPage']);
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
        $this->assertSame('graphql', $calls[0]['items'][0]['options']['source']);
    }

    public function testGraphqlDebugMetaUsesSharedAccessHelper(): void
    {
        $source = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');

        $this->assertStringContainsString("'includeDebugMeta' => SearchDebugAccessHelper::canExposeDebugMeta(),", $source);
        $this->assertStringNotContainsString('Craft::$app->getConfig()->getGeneral()->devMode || Craft::$app->getUser()->checkPermission(\'searchManager:viewDebug\')', $source);
        $this->assertSame(
            Craft::$app->getConfig()->getGeneral()->devMode || Craft::$app->getUser()->checkPermission('searchManager:viewDebug'),
            SearchDebugAccessHelper::canExposeDebugMeta(),
        );
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
            'indices' => [$index->handle],
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
                'indices' => [$pair[0]->handle],
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
            'indices' => [$index->handle],
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
            'indices' => [$index->handle],
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
            'indices' => ['__missing_index__'],
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
            'filters' => 'elementType:=`entry`',
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

        return ['coffee'];
    }
}
