<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * GraphQL coverage for the read-only search and autocomplete query layer.
 *
 * @since 5.53.0
 */
final class GraphqlSearchTest extends TestCase
{
    public function testSearchQueriesAreRegisteredWithoutMutations(): void
    {
        $queries = SearchQuery::getQueries(false);

        $this->assertArrayHasKey('searchManagerSearch', $queries);
        $this->assertArrayHasKey('searchManagerAutocomplete', $queries);
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
            'index' => $index->handle,
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
        $this->assertTrue($calls[0]['items'][0]['options']['skipAnalytics']);
        $this->assertSame('graphql', $calls[0]['items'][0]['options']['source']);
    }

    public function testInvalidExplicitIndexReturnsEmptyResponseWithoutFallback(): void
    {
        $stub = $this->installStubBackend();

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'coffee',
            'index' => '__missing_index__',
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
}
