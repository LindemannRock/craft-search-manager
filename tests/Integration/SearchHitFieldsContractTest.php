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
use lindemannrock\searchmanager\helpers\SearchDebugAccessHelper;
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
#[CoversClass(SearchDebugAccessHelper::class)]
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
            '_index' => 'metadata-index',
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => 'Intro field value',
                'category' => 'One Another one',
                'description' => 'Metadata snippet value',
                '_internalNote' => 'Private field value',
            ],
            '_headings' => [
                [
                    'text' => 'Overview',
                    'id' => 'overview',
                    'level' => 2,
                    'description' => 'Metadata heading snippet',
                ],
            ],
            'matchedTerms' => [
                'title' => ['metadata'],
                'content' => ['snippet'],
            ],
        ];

        $results = SearchManager::$plugin->enrichment->enrichResults([$hit], 'metadata snippet', [$index->handle], ['siteId' => $entry->siteId]);

        self::assertCount(1, $results);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
            'description' => 'Metadata snippet value',
        ], $results[0]['fields'] ?? null);
        self::assertSame('Metadata snippet value', $results[0]['snippet'] ?? null);
        self::assertArrayNotHasKey('highlights', $results[0]);
        self::assertSame([
            [
                'title' => 'Overview',
                'id' => 'overview',
                'level' => 2,
                'url' => 'https://example.test/metadata-url#overview',
                'snippet' => null,
            ],
        ], $results[0]['headings'] ?? null);
        self::assertArrayNotHasKey('_headings', $results[0]);
        self::assertArrayNotHasKey('_matchedHeadings', $results[0]);
        self::assertArrayNotHasKey('description', $results[0]);
        self::assertArrayNotHasKey('intro', $results[0]);
        self::assertArrayNotHasKey('category', $results[0]);
        self::assertArrayNotHasKey('_internalNote', $results[0]['fields'] ?? []);
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
            'indices' => $index->handle,
            'siteId' => $entry->siteId,
            'enrich' => 0,
        ]);

        try {
            $response = (new ApiController('api', SearchManager::$plugin))->actionSearch();
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
        }

        self::assertArrayNotHasKey('meta', $response->data);
        $hit = $response->data['hits'][0] ?? null;
        self::assertIsArray($hit);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
        self::assertSame('metadata-index', $hit['index'] ?? null);
        self::assertArrayNotHasKey('_index', $hit);
        self::assertArrayNotHasKey('intro', $hit);
        self::assertSame('metadata', $hit['category'] ?? null);
        self::assertArrayNotHasKey('content', $hit);
        self::assertArrayNotHasKey('excerpt', $hit);
        self::assertArrayNotHasKey('description', $hit);
        self::assertArrayNotHasKey('_bodyClean', $hit);
        self::assertArrayNotHasKey('_contentClean', $hit);
        self::assertArrayNotHasKey('_elementType', $hit);
        self::assertArrayHasKey('snippet', $hit);
        self::assertSame('Intro field value', $hit['snippet']);
        self::assertArrayNotHasKey('highlights', $hit);
        self::assertSame([], $hit['headings'] ?? null);
        self::assertArrayNotHasKey('_headings', $hit);
        self::assertArrayNotHasKey('_matchedHeadings', $hit);
    }

    public function testRestDebugReturnsBackendMetaWhenAllowed(): void
    {
        if (!SearchDebugAccessHelper::canExposeDebugMeta()) {
            $this->markTestSkipped('REST debug meta requires devMode or searchManager:viewDebug.');
        }

        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $stub = $this->installStubBackend();
        $stub->searchResponse = [
            'hits' => [$this->rawHit($entry->id, $entry->siteId)],
            'total' => 1,
            'meta' => [
                'cached' => false,
                'cacheEnabled' => true,
                'cacheDriver' => 'file',
                'took' => 12.5,
                'indices' => [$index->handle],
                'rulesMatched' => [],
                'promotionsMatched' => [],
            ],
        ];

        $response = $this->runApiSearch($index->handle, $entry->siteId, null, 'intro', [
            'debug' => 1,
        ]);

        self::assertSame($stub->searchResponse['meta'], $response->data['meta'] ?? null);
        self::assertArrayNotHasKey('_fields', $response->data['hits'][0] ?? []);
        self::assertArrayNotHasKey('_index', $response->data['hits'][0] ?? []);
    }

    public function testRestIgnoresEnrichAndReturnsCanonicalHitShape(): void
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
            'slug' => 'metadata-slug',
            'url' => 'https://example.test/metadata-url',
            'dateCreated' => 1771542126,
            'dateUpdated' => 1783631400,
            'section' => 'Metadata Section',
            'category' => 'metadata',
            'sourceId' => 5,
            '_index' => 'metadata-index',
            'score' => 42.0,
            'type' => 'entry',
            'elementType' => 'entry',
            '_elementType' => 'craft\\elements\\Entry',
            '_fields' => [
                'description' => 'Metadata snippet value',
            ],
            '_headings' => [
                [
                    'text' => 'Overview',
                    'id' => 'overview',
                    'level' => 2,
                    'description' => 'Metadata heading snippet',
                ],
            ],
            'matchedTerms' => [
                'title' => ['metadata'],
                'content' => ['snippet'],
            ],
        ];
        $stub = $this->installStubBackend();

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $absent = $this->runApiSearch($index->handle, $entry->siteId, null, 'metadata snippet')->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $raw = $this->runApiSearch($index->handle, $entry->siteId, 0, 'metadata snippet')->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $enriched = $this->runApiSearch($index->handle, $entry->siteId, 1, 'metadata snippet')->data['hits'][0] ?? [];
        $site = Craft::$app->getSites()->getSiteById($entry->siteId);

        self::assertSame($absent, $raw);
        self::assertSame($raw, $enriched);
        self::assertSame('Metadata snippet value', $raw['snippet'] ?? null);
        self::assertSame('metadata-slug', $raw['slug'] ?? null);
        self::assertSame(1771542126, $raw['dateCreated'] ?? null);
        self::assertSame(1783631400, $raw['dateUpdated'] ?? null);
        self::assertSame('Metadata Section', $raw['section'] ?? null);
        self::assertSame('metadata', $raw['category'] ?? null);
        self::assertSame(5, $raw['sourceId'] ?? null);
        self::assertSame('metadata-index', $raw['index'] ?? null);
        self::assertArrayNotHasKey('_index', $raw);
        self::assertSame($entry->siteId, $raw['siteId'] ?? null);
        self::assertSame($site?->handle, $raw['site'] ?? null);
        self::assertSame($site?->language, $raw['language'] ?? null);
        self::assertArrayNotHasKey('highlights', $raw);
        self::assertStringNotContainsString('<mark', (string)($raw['snippet'] ?? ''));
        self::assertArrayNotHasKey('_bodyClean', $raw);
        self::assertArrayNotHasKey('content', $raw);
        self::assertArrayNotHasKey('excerpt', $raw);
        self::assertArrayNotHasKey('description', $raw);
    }

    public function testRawSnippetSettingsMatchEnrichedSnippetSettings(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $content = 'Intro **needle** value and [documentation link](https://example.test/docs) with enough trailing words to force a shorter excerpt when the configured snippet length is clamped to fifty characters.';
        $bodyClean = 'Needle Section ' . $content;
        $hit = [
            'objectID' => $entry->id,
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'type' => 'entry',
            'elementType' => 'entry',
            '_elementType' => 'craft\\elements\\Entry',
            '_bodyClean' => $bodyClean,
            '_headings' => [
                [
                    'text' => 'Needle Section',
                    'id' => 'needle-section',
                    'level' => 2,
                    'description' => $content,
                ],
            ],
            'matchedTerms' => [
                'title' => ['needle'],
                'content' => ['needle'],
            ],
        ];
        $stub = $this->installStubBackend();

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $rawShort = $this->runApiSearch($index->handle, $entry->siteId, 0, 'needle', [
            'snippetLength' => 50,
            'parseMarkdownSnippets' => true,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $enrichedShort = $this->runApiSearch($index->handle, $entry->siteId, 1, 'needle', [
            'snippetLength' => 50,
            'parseMarkdownSnippets' => true,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $rawLongUnparsed = $this->runApiSearch($index->handle, $entry->siteId, 0, 'needle', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $enrichedLongUnparsed = $this->runApiSearch($index->handle, $entry->siteId, 1, 'needle', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ])->data['hits'][0] ?? [];

        self::assertSame($rawShort['snippet'] ?? null, $enrichedShort['snippet'] ?? null);
        self::assertSame($rawShort['headings'] ?? null, $enrichedShort['headings'] ?? null);
        self::assertSame($rawLongUnparsed['snippet'] ?? null, $enrichedLongUnparsed['snippet'] ?? null);
        self::assertSame($rawLongUnparsed['headings'] ?? null, $enrichedLongUnparsed['headings'] ?? null);
        self::assertLessThan(mb_strlen((string)($rawLongUnparsed['snippet'] ?? '')), mb_strlen((string)($rawShort['snippet'] ?? '')));
        self::assertStringContainsString('**needle**', (string)($rawLongUnparsed['snippet'] ?? ''));
        self::assertStringNotContainsString('**needle**', (string)($rawShort['snippet'] ?? ''));
    }

    public function testSourceDocContentMatchReturnsBodySnippetAndFullHeadings(): void
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
            'title' => 'Quickstart',
            'url' => 'https://example.test/docs/quickstart',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Install Run Composer install before deployment. Continue with setup. Deploy Deploy the rebuilt index.',
            '_headings' => [
                [
                    'text' => 'Install',
                    'id' => 'install',
                    'level' => 2,
                    'description' => 'Start here.',
                ],
                [
                    'text' => 'Deploy',
                    'id' => 'deploy',
                    'level' => 3,
                    'description' => 'Deploy the rebuilt index.',
                ],
            ],
            'matchedIn' => ['content'],
            'matchedTerms' => [
                'title' => [],
                'content' => ['composer'],
            ],
        ];

        $results = SearchManager::$plugin->enrichment->enrichResults([$hit], 'composer', [$index->handle], [
            'siteId' => $entry->siteId,
            'snippetLength' => 150,
        ]);

        self::assertCount(1, $results);
        self::assertSame('Install Run Composer install before deployment. Continue with setup. Deploy Deploy the rebuilt index.', $results[0]['snippet'] ?? null);
        self::assertArrayNotHasKey('highlights', $results[0]);
        self::assertSame([
            [
                'title' => 'Install',
                'id' => 'install',
                'level' => 2,
                'url' => 'https://example.test/docs/quickstart#install',
                'snippet' => 'Run Composer install before deployment. Continue with setup.',
            ],
            [
                'title' => 'Deploy',
                'id' => 'deploy',
                'level' => 3,
                'url' => 'https://example.test/docs/quickstart#deploy',
                'snippet' => null,
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testEnrichedHeadingSnippetsUseSnippetSettingsLikeMainSnippet(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        $index = $pair[0];
        $content = 'Intro **needle** value and [documentation link](https://example.test/docs) with enough trailing words to force a shorter excerpt when the configured snippet length is clamped to fifty characters.';
        $bodyClean = 'Needle Section ' . $content;
        $hit = [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => $bodyClean,
            '_headings' => [
                [
                    'text' => 'Needle Section',
                    'id' => 'needle-section',
                    'level' => 2,
                    'description' => $content,
                ],
            ],
            'matchedIn' => ['content'],
            'matchedTerms' => [
                'title' => ['needle'],
                'content' => ['needle'],
            ],
        ];

        $short = SearchManager::$plugin->enrichment->prepareHitSnippets($hit, 'needle', $index->handle, [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'documentType' => 'source-doc',
            'snippetLength' => 50,
            'parseMarkdownSnippets' => true,
        ]);
        $long = SearchManager::$plugin->enrichment->prepareHitSnippets($hit, 'needle', $index->handle, [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'documentType' => 'source-doc',
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => true,
        ]);
        $unparsed = SearchManager::$plugin->enrichment->prepareHitSnippets($hit, 'needle', $index->handle, [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'documentType' => 'source-doc',
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ]);

        $shortMain = (string)($short['snippet'] ?? '');
        $shortHeading = (string)($short['headings'][0]['snippet'] ?? '');
        $longMain = (string)($long['snippet'] ?? '');
        $longHeading = (string)($long['headings'][0]['snippet'] ?? '');
        $unparsedMain = (string)($unparsed['snippet'] ?? '');
        $unparsedHeading = (string)($unparsed['headings'][0]['snippet'] ?? '');

        self::assertLessThan(mb_strlen($longMain), mb_strlen($shortMain));
        self::assertLessThan(mb_strlen($longHeading), mb_strlen($shortHeading));
        self::assertStringContainsString('needle', $shortMain);
        self::assertStringContainsString('needle', $shortHeading);
        self::assertStringNotContainsString('**needle**', $longMain);
        self::assertStringNotContainsString('**needle**', $longHeading);
        self::assertStringContainsString('**needle**', $unparsedMain);
        self::assertStringContainsString('**needle**', $unparsedHeading);
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

    public function testGraphQlHitTypeExposesSnippetWithoutDescriptionAlias(): void
    {
        $fields = SearchHitType::getFieldDefinitions();

        self::assertArrayHasKey('snippet', $fields);
        self::assertSame('snippet', $fields['snippet']['name'] ?? null);
        self::assertSame('The query-centered match snippet, when there is a match to excerpt.', $fields['snippet']['description'] ?? null);
        self::assertArrayNotHasKey('description', $fields);
        self::assertArrayNotHasKey('highlights', $fields);

        $type = new SearchHitType([
            'name' => 'SearchManagerSearchHitSnippetTest',
            'fields' => $fields,
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(ResolveInfo::class);
        $resolveInfo->fieldName = 'snippet';

        self::assertSame('Snippet text', $method->invoke($type, [
            'snippet' => 'Snippet text',
            'description' => 'Legacy description text',
        ], [], null, $resolveInfo));
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
            'indices' => [$index->handle],
            'siteId' => $entry->siteId,
        ], null, $this->createMock(ResolveInfo::class));

        $hit = $response['hits'][0] ?? null;
        self::assertIsArray($hit);
        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
        self::assertSame([
            [
                'title' => 'Matched Raw Heading',
                'id' => 'matched-raw-heading',
                'level' => 2,
                'url' => 'https://example.test/metadata-url#matched-raw-heading',
                'snippet' => null,
            ],
        ], $hit['headings'] ?? null);
        self::assertArrayNotHasKey('_headings', $hit);
        self::assertArrayNotHasKey('_matchedHeadings', $hit);
        self::assertArrayNotHasKey('_bodyClean', $hit);
        self::assertArrayNotHasKey('_contentClean', $hit);
        self::assertArrayNotHasKey('_elementType', $hit);
        self::assertArrayNotHasKey('intro', $hit);
        self::assertSame('metadata', $hit['category'] ?? null);
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
            'slug' => 'metadata-slug',
            'url' => 'https://example.test/metadata-url',
            'dateCreated' => 1771542126,
            'dateUpdated' => 1783631400,
            'section' => 'Metadata Section',
            'category' => 'metadata',
            'sourceId' => 5,
            '_index' => 'metadata-index',
            'score' => 42.0,
            'type' => 'entry',
            'elementType' => 'entry',
            '_elementType' => 'craft\\elements\\Entry',
            'content' => 'Internal content must be stripped',
            'description' => 'Internal description must be stripped',
            'excerpt' => 'Internal excerpt must be stripped',
            '_bodyClean' => 'Internal clean body must be stripped',
            '_contentClean' => 'Internal clean content must be stripped',
            '_headings' => [
                [
                    'text' => 'Raw Heading',
                    'id' => 'raw-heading',
                    'level' => 2,
                    'description' => 'Raw heading snippet',
                ],
            ],
            '_matchedHeadings' => [
                [
                    'text' => 'Matched Raw Heading',
                    'id' => 'matched-raw-heading',
                    'level' => 2,
                    'description' => 'Matched raw heading snippet',
                ],
            ],
            '_fields' => [
                'intro' => 'Intro field value',
                'category' => 'One Another one',
                '_private' => 'Private value',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extraParams
     */
    private function runApiSearch(string $indexHandle, int $siteId, ?int $enrich, string $query, array $extraParams = []): Response
    {
        [$originalRequest, $originalResponse] = [Craft::$app->getRequest(), Craft::$app->getResponse()];
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $params = [
            'q' => $query,
            'indices' => $indexHandle,
            'siteId' => $siteId,
        ];
        if ($enrich !== null) {
            $params['enrich'] = $enrich;
        }
        Craft::$app->getRequest()->setQueryParams(array_merge($params, $extraParams));

        try {
            return (new ApiController('api', SearchManager::$plugin))->actionSearch();
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
        }
    }
}
