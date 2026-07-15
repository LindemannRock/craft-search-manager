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
use craft\web\Request;
use craft\web\Response;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\types\AutocompleteResponseType;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #381.
 *
 * @since 5.53.0
 */
final class AuditItem381IndexHandlesCapTest extends TestCase
{
    private const ERROR_MESSAGE = 'The indexHandles argument accepts at most 5 indices.';

    public function testResolveRequestedIndicesReportsOverflowAndStillCapsHandles(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES + 1);

        $this->withOnlySearchIndices($indices, function () use ($indices): void {
            [$handles, $indicesProvided, $exceededMax] = SearchIndex::resolveRequestedIndices(
                implode(',', array_map(static fn(SearchIndex $index): string => $index->handle, $indices)),
            );

            self::assertTrue($indicesProvided);
            self::assertTrue($exceededMax);
            self::assertCount(SearchIndex::MAX_REQUESTED_INDICES, $handles);
            self::assertSame('audit-381-index-1', $handles[0]);
            self::assertSame('audit-381-index-5', $handles[SearchIndex::MAX_REQUESTED_INDICES - 1]);
        });
    }

    public function testGraphqlSearchRejectsTooManyIndexHandlesBeforeSearching(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES + 1);
        $stub = $this->installStubBackend();

        $this->withOnlySearchIndices($indices, function () use ($indices): void {
            $response = SearchResolver::resolveSearch(null, [
                'query' => 'coffee',
                'indexHandles' => array_map(static fn(SearchIndex $index): string => $index->handle, $indices),
            ], null, $this->createMock(ResolveInfo::class));

            self::assertSame(0, $response['total']);
            self::assertSame([], $response['hits']);
            self::assertSame(self::ERROR_MESSAGE, $response['error']);
        });

        self::assertSame([], $stub->callsFor('search'));
        self::assertSame([], $stub->callsFor('searchMultiple'));
    }

    public function testGraphqlSearchAllowsMaxIndexHandles(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES);
        $stub = $this->installStubBackend();
        $stub->searchMultipleResponse = ['hits' => [], 'total' => 0];

        $this->withOnlySearchIndices($indices, function () use ($indices): void {
            $response = SearchResolver::resolveSearch(null, [
                'query' => 'coffee',
                'indexHandles' => array_map(static fn(SearchIndex $index): string => $index->handle, $indices),
            ], null, $this->createMock(ResolveInfo::class));

            self::assertArrayNotHasKey('error', $response);
            self::assertSame(0, $response['total']);
        });

        $calls = $stub->callsFor('searchMultiple');
        self::assertCount(1, $calls);
        self::assertCount(SearchIndex::MAX_REQUESTED_INDICES, $calls[0]['items'][0]['indices']);
    }

    public function testGraphqlAutocompleteRejectsTooManyIndexHandlesWithVisibleErrorField(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES + 1);
        $fields = AutocompleteResponseType::getFieldDefinitions();

        self::assertArrayHasKey('error', $fields);

        $response = $this->withOnlySearchIndices($indices, function () use ($indices): array {
            return SearchResolver::resolveAutocomplete(null, [
                'query' => 'cof',
                'indexHandles' => array_map(static fn(SearchIndex $index): string => $index->handle, $indices),
            ], null, $this->createMock(ResolveInfo::class));
        });

        self::assertSame([], $response['suggestions']);
        self::assertSame([], $response['results']);
        self::assertSame(self::ERROR_MESSAGE, $response['error']);
    }

    public function testRestSearchRejectsTooManyIndexHandlesBeforeSearching(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES + 1);
        $stub = $this->installStubBackend();

        $response = $this->withOnlySearchIndices(
            $indices,
            fn(): Response => $this->runApiSearch(implode(',', array_map(static fn(SearchIndex $index): string => $index->handle, $indices))),
        );

        self::assertSame([], $response->data['hits']);
        self::assertSame(0, $response->data['total']);
        self::assertSame('coffee', $response->data['query']);
        self::assertSame(self::ERROR_MESSAGE, $response->data['error']);
        self::assertSame([], $stub->callsFor('search'));
        self::assertSame([], $stub->callsFor('searchMultiple'));
    }

    public function testRestSearchAllowsMaxIndexHandles(): void
    {
        $indices = $this->fakeIndices(SearchIndex::MAX_REQUESTED_INDICES);
        $stub = $this->installStubBackend();
        $stub->searchMultipleResponse = ['hits' => [], 'total' => 0];

        $response = $this->withOnlySearchIndices(
            $indices,
            fn(): Response => $this->runApiSearch(implode(',', array_map(static fn(SearchIndex $index): string => $index->handle, $indices))),
        );

        self::assertArrayNotHasKey('error', $response->data);
        self::assertSame(0, $response->data['total']);
        $calls = $stub->callsFor('searchMultiple');
        self::assertCount(1, $calls);
        self::assertCount(SearchIndex::MAX_REQUESTED_INDICES, $calls[0]['items'][0]['indices']);
    }

    /**
     * @return list<SearchIndex>
     */
    private function fakeIndices(int $count): array
    {
        $indices = [];
        for ($i = 1; $i <= $count; $i++) {
            $indices[] = new SearchIndex([
                'name' => 'Audit 381 Index ' . $i,
                'handle' => 'audit-381-index-' . $i,
                'elementType' => Entry::class,
                'enabled' => true,
            ]);
        }

        return $indices;
    }

    private function runApiSearch(string $indexHandles): Response
    {
        [$originalRequest, $originalResponse] = [Craft::$app->getRequest(), Craft::$app->getResponse()];
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        Craft::$app->getRequest()->setQueryParams([
            'q' => 'coffee',
            'indexHandles' => $indexHandles,
        ]);

        try {
            return (new ApiController('api', SearchManager::$plugin))->actionSearch();
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
        }
    }
}
