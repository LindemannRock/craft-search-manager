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
use craft\web\Request;
use craft\web\Response;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\gql\types\SearchHitType;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the custom field values contract for REST and GraphQL search hits.
 *
 * @since 5.53.0
 */
#[CoversClass(ApiController::class)]
#[CoversClass(SearchFieldValueHelper::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitFieldsContractTest extends TestCase
{
    public function testEnrichedHitReturnsFieldsWithoutFlatCustomKeys(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $hit = [
            'objectID' => $entry->id,
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'title' => 'Metadata title',
            'url' => 'https://example.test/metadata-url',
            'section' => 'Metadata Section',
            'score' => 42.0,
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => 'Intro field value',
                'category' => 'One Another one',
            ],
        ];

        $results = SearchManager::$plugin->enrichment->enrichResults([$hit], '', [$index->handle], ['siteId' => $entry->siteId]);

        self::assertCount(1, $results);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $results[0]['fields'] ?? null);
        self::assertArrayNotHasKey('intro', $results[0]);
        self::assertArrayNotHasKey('category', $results[0]);
        self::assertSame('Metadata title', $results[0]['title'] ?? null);
        self::assertSame('https://example.test/metadata-url', $results[0]['url'] ?? null);
        self::assertSame(42.0, $results[0]['score'] ?? null);
    }

    public function testRawRestHitReturnsFieldsAndStripsInternalFields(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [$this->rawHit($entry->id, $entry->siteId)],
            'total' => 1,
            'meta' => ['cached' => false],
        ];

        [$originalRequest, $originalResponse] = [Craft::$app->getRequest(), Craft::$app->getResponse()];
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        Craft::$app->getRequest()->setQueryParams([
            'q' => 'intro',
            'index' => $index->handle,
            'siteId' => $entry->siteId,
            'enrich' => 0,
        ]);

        try {
            $response = (new ApiController('api', SearchManager::$plugin))->actionSearch();
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
        }

        $hit = $response->data['hits'][0] ?? null;
        self::assertIsArray($hit);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
        self::assertArrayNotHasKey('intro', $hit);
        self::assertArrayNotHasKey('category', $hit);
        self::assertArrayNotHasKey('content', $hit);
        self::assertArrayNotHasKey('excerpt', $hit);
    }

    public function testReservedHandleCollisionStaysOnlyUnderFields(): void
    {
        $hit = SearchFieldValueHelper::exposeFields([
            'title' => 'Metadata title',
            'url' => 'https://example.test/metadata-url',
            'section' => 'Metadata Section',
            '_fields' => [
                'title' => 'Custom title field',
                'url' => 'Custom URL field',
                'section' => 'Custom section field',
            ],
        ]);

        self::assertSame('Metadata title', $hit['title'] ?? null);
        self::assertSame('https://example.test/metadata-url', $hit['url'] ?? null);
        self::assertSame('Metadata Section', $hit['section'] ?? null);
        self::assertSame([
            'title' => 'Custom title field',
            'url' => 'Custom URL field',
            'section' => 'Custom section field',
        ], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
    }

    public function testGraphQlFieldsResolveAsHandleValueList(): void
    {
        $type = new SearchHitType([
            'name' => 'SearchManagerSearchHitFieldsTest',
            'fields' => SearchHitType::getFieldDefinitions(),
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(ResolveInfo::class);
        $resolveInfo->fieldName = 'fields';

        $fields = $method->invoke($type, [
            '_fields' => [
                'intro' => 'Intro field value',
                'ingredients' => ['Sugar', 'Salt'],
            ],
        ], [], null, $resolveInfo);

        self::assertSame([
            [
                'handle' => 'intro',
                'value' => 'Intro field value',
                'values' => [],
            ],
            [
                'handle' => 'ingredients',
                'value' => 'Sugar Salt',
                'values' => ['Sugar', 'Salt'],
            ],
        ], $fields);
    }

    public function testGraphQlRawResolverReturnsFieldsMapForHitTypeResolver(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [$this->rawHit($entry->id, $entry->siteId)],
            'total' => 1,
        ];

        $response = SearchResolver::resolveSearch(null, [
            'query' => 'intro',
            'index' => $index->handle,
            'siteId' => $entry->siteId,
        ], null, $this->createMock(ResolveInfo::class));

        $hit = $response['hits'][0] ?? null;
        self::assertIsArray($hit);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
        self::assertArrayNotHasKey('intro', $hit);
        self::assertArrayNotHasKey('category', $hit);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawHit(int $elementId, int $siteId): array
    {
        return [
            'objectID' => $elementId,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'title' => 'Metadata title',
            'url' => 'https://example.test/metadata-url',
            'section' => 'Metadata Section',
            'score' => 42.0,
            'type' => 'entry',
            'elementType' => 'entry',
            'content' => 'Internal content must be stripped',
            'excerpt' => 'Internal excerpt must be stripped',
            '_fields' => [
                'intro' => 'Intro field value',
                'category' => 'One Another one',
            ],
        ];
    }
}
