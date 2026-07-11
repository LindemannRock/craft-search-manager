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
use lindemannrock\searchmanager\helpers\CanonicalHitPipeline;
use lindemannrock\searchmanager\helpers\SearchDebugAccessHelper;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexedSnippetService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the custom field values contract for REST and GraphQL search hits.
 *
 * @since 5.53.0
 */
#[CoversClass(ApiController::class)]
#[CoversClass(CanonicalHitPipeline::class)]
#[CoversClass(SearchFieldValueHelper::class)]
#[CoversClass(SearchDebugAccessHelper::class)]
#[CoversClass(IndexedSnippetService::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitFieldsContractTest extends TestCase
{
    public function testCanonicalHitReturnsFieldsWithoutFlatCustomKeys(): void
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

        $results = CanonicalHitPipeline::presentHits([$hit], 'metadata snippet', [$index->handle], [
            'snippetMode' => 'balanced',
            'snippetLength' => 150,
            'showCodeSnippets' => false,
            'parseMarkdownSnippets' => false,
            'hideResultsWithoutUrl' => false,
        ]);

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

    public function testRetrievableFieldsWildcardReturnsAllPublicFields(): void
    {
        $hit = $this->rawHit(101, 1);

        $result = CanonicalHitPipeline::presentHits([$hit], 'intro', ['metadata-index'], [
            'retrievableFieldsByIndex' => ['metadata-index' => ['*']],
        ])[0] ?? [];

        self::assertSame([
            'intro' => 'Intro field value',
            'category' => 'One Another one',
        ], $result['fields'] ?? null);
    }

    public function testRetrievableFieldsEmptyListReturnsNoPublicFields(): void
    {
        $hit = $this->rawHit(101, 1);

        $result = CanonicalHitPipeline::presentHits([$hit], 'intro', ['metadata-index'], [
            'retrievableFieldsByIndex' => ['metadata-index' => []],
        ])[0] ?? [];

        self::assertSame([], $result['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $result);
    }

    public function testRetrievableFieldsAllowlistReturnsOnlyAllowedHandles(): void
    {
        $hit = $this->rawHit(101, 1);

        $result = CanonicalHitPipeline::presentHits([$hit], 'intro', ['metadata-index'], [
            'retrievableFieldsByIndex' => ['metadata-index' => ['category']],
        ])[0] ?? [];

        self::assertSame(['category' => 'One Another one'], $result['fields'] ?? null);
    }

    public function testRequestRetrievableFieldsNarrowIndexAllowlistWithoutWidening(): void
    {
        self::assertSame(['intro'], SearchIndex::narrowRetrievableFields(['intro', 'category'], ['intro']));
        self::assertSame(['category'], SearchIndex::narrowRetrievableFields(['category'], ['intro', 'category']));
        self::assertSame(['category'], SearchIndex::narrowRetrievableFields(['category'], ['*']));
        self::assertSame(['intro'], SearchIndex::narrowRetrievableFields(['*'], ['intro']));
        self::assertSame([], SearchIndex::narrowRetrievableFields([], ['intro']));
    }

    public function testRetrievableFieldNarrowingDoesNotAffectSnippetSources(): void
    {
        $hit = $this->rawHit(101, 1);
        $hit['_fields']['hiddenBody'] = 'Needle phrase only in hidden body';

        $result = CanonicalHitPipeline::presentHits([$hit], 'needle phrase', ['metadata-index'], [
            'retrievableFieldsByIndex' => ['metadata-index' => []],
        ])[0] ?? [];

        self::assertSame([], $result['fields'] ?? null);
        self::assertSame('Needle phrase only in hidden body', $result['snippet'] ?? null);
    }

    public function testSnippetFieldsRemainPrivateAndPowerSnippets(): void
    {
        $hit = $this->rawHit(101, 1);
        unset($hit['_fields']);
        $hit['fields'] = [];
        $hit['_snippetFields'] = [
            'hiddenBody' => 'Needle phrase only in private snippet fields',
        ];

        $result = CanonicalHitPipeline::presentHits([$hit], 'needle phrase', ['metadata-index'], [
            'retrievableFieldsByIndex' => ['metadata-index' => []],
        ])[0] ?? [];

        self::assertSame([], $result['fields'] ?? null);
        self::assertSame('Needle phrase only in private snippet fields', $result['snippet'] ?? null);
        self::assertArrayNotHasKey('_snippetFields', $result);
    }

    public function testPresenterStillStripsInternalsAfterRetrievableFieldFiltering(): void
    {
        $hit = SearchHitPresenter::present($this->rawHit(101, 1), false, []);

        self::assertSame([], $hit['fields'] ?? null);
        self::assertArrayNotHasKey('_fields', $hit);
        self::assertArrayNotHasKey('_index', $hit);
        self::assertArrayNotHasKey('_elementType', $hit);
        self::assertArrayNotHasKey('content', $hit);
        self::assertArrayNotHasKey('description', $hit);
    }

    public function testCanonicalPipelineFiltersOnlyIndexedUrlsWhenRequested(): void
    {
        $hits = [
            [
                'id' => 1001,
                'title' => 'Has Indexed URL',
                'url' => 'https://example.test/indexed',
                'type' => 'entry',
            ],
            [
                'id' => 1002,
                'title' => 'No Indexed URL',
                'url' => '',
                'type' => 'entry',
            ],
        ];

        $visible = CanonicalHitPipeline::presentHits($hits, '', ['pages'], [
            'hideResultsWithoutUrl' => false,
        ]);
        $filtered = CanonicalHitPipeline::presentHits($hits, '', ['pages'], [
            'hideResultsWithoutUrl' => true,
        ]);

        self::assertCount(2, $visible);
        self::assertCount(1, $filtered);
        self::assertSame('Has Indexed URL', $filtered[0]['title'] ?? null);
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
        self::assertArrayNotHasKey('_bodyWithCode', $hit);
        self::assertArrayNotHasKey('_contentClean', $hit);
        self::assertArrayNotHasKey('_sectionBodyWithCode', $hit);
        self::assertArrayNotHasKey('_elementType', $hit);
        self::assertArrayHasKey('snippet', $hit);
        self::assertSame('Intro field value', $hit['snippet']);
        self::assertArrayNotHasKey('highlights', $hit);
        self::assertSame([], $hit['headings'] ?? null);
        self::assertArrayNotHasKey('_headings', $hit);
        self::assertArrayNotHasKey('_matchedHeadings', $hit);
    }

    public function testRestRetrievableFieldsRequestNarrowsPublicFields(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $stub = $this->installStubBackend();
        $hit = $this->rawHit($entry->id, $entry->siteId);
        $hit['_index'] = $index->handle;
        $stub->searchResponse = [
            'hits' => [$hit],
            'total' => 1,
        ];

        $response = $this->runApiSearch($index->handle, $entry->siteId, null, 'intro', [
            'retrievableFields' => 'intro',
        ]);

        self::assertSame(['intro' => 'Intro field value'], $response->data['hits'][0]['fields'] ?? null);
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
            'site' => 'indexed-site',
            'language' => 'de-CH',
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
        self::assertSame('indexed-site', $raw['site'] ?? null);
        self::assertSame('de-CH', $raw['language'] ?? null);
        self::assertArrayNotHasKey('highlights', $raw);
        self::assertArrayNotHasKey('thumbnail', $raw);
        self::assertStringNotContainsString('<mark', (string)($raw['snippet'] ?? ''));
        self::assertArrayNotHasKey('_bodyClean', $raw);
        self::assertArrayNotHasKey('content', $raw);
        self::assertArrayNotHasKey('excerpt', $raw);
        self::assertArrayNotHasKey('description', $raw);
    }

    public function testRestDoesNotHydrateMissingTitleUrlSiteOrLanguage(): void
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
                'type' => 'entry',
                'elementType' => 'entry',
                '_fields' => ['intro' => 'Indexed intro text'],
            ]],
            'total' => 1,
        ];

        $hit = $this->runApiSearch($index->handle, $entry->siteId, 1, 'intro')->data['hits'][0] ?? [];

        self::assertArrayNotHasKey('title', $hit);
        self::assertArrayNotHasKey('url', $hit);
        self::assertArrayNotHasKey('site', $hit);
        self::assertArrayNotHasKey('language', $hit);
        self::assertSame('Indexed intro text', $hit['snippet'] ?? null);
    }

    public function testRestSnippetSettingsAreStableWhenLegacyEnrichParamIsPresent(): void
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
        $legacyEnrichShort = $this->runApiSearch($index->handle, $entry->siteId, 1, 'needle', [
            'snippetLength' => 50,
            'parseMarkdownSnippets' => true,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $rawLongUnparsed = $this->runApiSearch($index->handle, $entry->siteId, 0, 'needle', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $legacyEnrichLongUnparsed = $this->runApiSearch($index->handle, $entry->siteId, 1, 'needle', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ])->data['hits'][0] ?? [];

        self::assertSame($rawShort['snippet'] ?? null, $legacyEnrichShort['snippet'] ?? null);
        self::assertSame($rawShort['headings'] ?? null, $legacyEnrichShort['headings'] ?? null);
        self::assertSame($rawLongUnparsed['snippet'] ?? null, $legacyEnrichLongUnparsed['snippet'] ?? null);
        self::assertSame($rawLongUnparsed['headings'] ?? null, $legacyEnrichLongUnparsed['headings'] ?? null);
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

        $results = CanonicalHitPipeline::presentHits([$hit], 'composer', [$index->handle], [
            'snippetMode' => 'balanced',
            'snippetLength' => 150,
            'showCodeSnippets' => false,
            'parseMarkdownSnippets' => false,
            'hideResultsWithoutUrl' => false,
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
                'snippet' => 'Deploy the rebuilt index.',
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testHeadingTermContextSnippetStaysQueryCenteredAndFallbackUsesSectionOpening(): void
    {
        $hit = [
            'title' => 'Release Notes',
            'url' => 'https://example.test/docs/release-notes',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Install Read the setup checklist before running Composer install in production. Deploy Push the rebuilt index after deployment and confirm analytics are writing again.',
            '_headings' => [
                [
                    'text' => 'Install',
                    'id' => 'install',
                    'level' => 2,
                ],
                [
                    'text' => 'Deploy',
                    'id' => 'deploy',
                    'level' => 2,
                ],
            ],
            'matchedTerms' => [
                'title' => ['release'],
                'content' => ['composer'],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'release', ['docs'], [
            'snippetLength' => 70,
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertSame([
            [
                'title' => 'Install',
                'id' => 'install',
                'level' => 2,
                'url' => 'https://example.test/docs/release-notes#install',
                'snippet' => 'Read the setup checklist before running Composer install in production...',
            ],
            [
                'title' => 'Deploy',
                'id' => 'deploy',
                'level' => 2,
                'url' => 'https://example.test/docs/release-notes#deploy',
                'snippet' => 'Push the rebuilt index after deployment and confirm analytics are...',
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testHeadingWithEmptySectionKeepsNullSnippet(): void
    {
        $hit = [
            'title' => 'Release Notes',
            'url' => 'https://example.test/docs/release-notes',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Overview Details The details section has body text.',
            '_headings' => [
                [
                    'text' => 'Overview',
                    'id' => 'overview',
                    'level' => 2,
                ],
                [
                    'text' => 'Details',
                    'id' => 'details',
                    'level' => 3,
                ],
            ],
            'matchedTerms' => [
                'title' => ['release'],
                'content' => [],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'release', ['docs'], [
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertSame([
            [
                'title' => 'Overview',
                'id' => 'overview',
                'level' => 2,
                'url' => 'https://example.test/docs/release-notes#overview',
                'snippet' => null,
            ],
            [
                'title' => 'Details',
                'id' => 'details',
                'level' => 3,
                'url' => 'https://example.test/docs/release-notes#details',
                'snippet' => 'The details section has body text.',
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testHeadingMatchFilteredListUsesFallbackSectionOpening(): void
    {
        $hit = [
            'title' => 'Operations Guide',
            'url' => 'https://example.test/docs/operations',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Publish Push the rebuilt index after deployment and confirm analytics are writing again. Monitor Check the queue and logs after launch.',
            '_headings' => [
                [
                    'text' => 'Publish',
                    'id' => 'publish',
                    'level' => 2,
                ],
                [
                    'text' => 'Monitor',
                    'id' => 'monitor',
                    'level' => 2,
                ],
            ],
            'matchedTerms' => [
                'title' => [],
                'content' => ['publish'],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'publish', ['docs'], [
            'snippetLength' => 70,
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertSame([
            [
                'title' => 'Publish',
                'id' => 'publish',
                'level' => 2,
                'url' => 'https://example.test/docs/operations#publish',
                'snippet' => 'Push the rebuilt index after deployment and confirm analytics are...',
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testSectionHitWithoutBodyTermContextUsesLeadingSectionBodySnippet(): void
    {
        $hit = [
            'id' => 901,
            'elementId' => 901,
            'siteId' => 1,
            'backendId' => '901_1_install',
            'title' => 'Release Guide',
            'url' => 'https://example.test/docs/release',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'sectionType' => 'heading',
            'sectionId' => 'install',
            'sectionTitle' => 'Install',
            'sectionLevel' => 2,
            'sectionUrl' => 'https://example.test/docs/release#install',
            'sectionIndex' => 1,
            '_bodyClean' => 'This section opens with deployment context and continues with practical setup steps for production teams.',
            'matchedTerms' => [
                'title' => ['release'],
                'content' => [],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'release', ['docs'], [
            'snippetLength' => 70,
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertStringStartsWith('This section opens with deployment context', (string)($results[0]['snippet'] ?? ''));
        self::assertStringEndsWith('...', (string)($results[0]['snippet'] ?? ''));
    }

    public function testSectionHitWithTermContextKeepsQueryCenteredSnippet(): void
    {
        $hit = [
            'id' => 902,
            'elementId' => 902,
            'siteId' => 1,
            'backendId' => '902_1_install',
            'title' => 'Release Guide',
            'url' => 'https://example.test/docs/release',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'sectionType' => 'heading',
            'sectionId' => 'install',
            'sectionTitle' => 'Install',
            'sectionLevel' => 2,
            'sectionUrl' => 'https://example.test/docs/release#install',
            'sectionIndex' => 1,
            '_bodyClean' => 'Opening fallback prose is intentionally different. Later the composer command appears near deployment instructions for teams.',
            'matchedTerms' => [
                'title' => [],
                'content' => ['composer'],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'composer', ['docs'], [
            'snippetLength' => 70,
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertStringContainsString('composer', (string)($results[0]['snippet'] ?? ''));
        self::assertFalse(str_starts_with((string)($results[0]['snippet'] ?? ''), 'Opening fallback prose'));
    }

    public function testSourceDocCodeIncludedBodyIsSelectedOnlyWhenCodeSnippetsAreEnabled(): void
    {
        $hit = [
            'id' => 904,
            'elementId' => 904,
            'siteId' => 1,
            'title' => 'ShortLink Installation',
            'url' => 'https://example.test/docs/shortlink-manager/get-started/installation',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Install ShortLink Manager before configuring your project.',
            '_bodyWithCode' => 'Install ShortLink Manager before configuring your project. ddev composer require lindemannrock/craft-shortlink-manager',
            '_headings' => [
                [
                    'text' => 'Install',
                    'id' => 'install',
                    'level' => 2,
                    'description' => 'Install ShortLink Manager before configuring your project.',
                ],
            ],
            'matchedIn' => ['content'],
            'matchedTerms' => [
                'title' => [],
                'content' => ['ddev'],
            ],
        ];

        $off = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'ddev', 'docs', [
            'snippetLength' => 120,
            'showCodeSnippets' => false,
        ]);
        $on = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'ddev', 'docs', [
            'snippetLength' => 120,
            'showCodeSnippets' => true,
        ]);

        self::assertNull($off['snippet']);
        self::assertStringNotContainsString('ddev composer require', (string)($off['headings'][0]['snippet'] ?? ''));
        self::assertStringContainsString('ddev composer require lindemannrock/craft-shortlink-manager', (string)$on['snippet']);
        self::assertStringContainsString('ddev composer require lindemannrock/craft-shortlink-manager', (string)($on['headings'][0]['snippet'] ?? ''));
    }

    public function testSplitSourceDocCodeIncludedSectionBodyIsSelectedOnlyWhenCodeSnippetsAreEnabled(): void
    {
        $hit = [
            'id' => 905,
            'elementId' => 905,
            'siteId' => 1,
            'backendId' => '905_1_install',
            'title' => 'ShortLink Installation',
            'url' => 'https://example.test/docs/shortlink-manager/get-started/installation',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'sectionType' => 'heading',
            'sectionId' => 'install',
            'sectionTitle' => 'Install',
            'sectionLevel' => 2,
            'sectionUrl' => 'https://example.test/docs/shortlink-manager/get-started/installation#install',
            'sectionIndex' => 1,
            '_bodyClean' => 'Install ShortLink Manager before configuring your project.',
            '_sectionBodyWithCode' => 'Install ShortLink Manager before configuring your project. ddev composer require lindemannrock/craft-shortlink-manager',
            'matchedTerms' => [
                'title' => [],
                'content' => ['ddev'],
            ],
        ];

        $off = CanonicalHitPipeline::presentHits([$hit], 'ddev', ['docs'], [
            'snippetLength' => 120,
            'showCodeSnippets' => false,
            'hideResultsWithoutUrl' => false,
        ]);
        $on = CanonicalHitPipeline::presentHits([$hit], 'ddev', ['docs'], [
            'snippetLength' => 120,
            'showCodeSnippets' => true,
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertStringContainsString('Install ShortLink Manager before configuring your project.', (string)($off[0]['snippet'] ?? ''));
        self::assertStringNotContainsString('ddev composer require', (string)($off[0]['snippet'] ?? ''));
        self::assertStringContainsString('ddev composer require lindemannrock/craft-shortlink-manager', (string)($on[0]['snippet'] ?? ''));
        self::assertArrayNotHasKey('_sectionBodyWithCode', $on[0]);
        self::assertArrayNotHasKey('sectionBody', $on[0]);
    }

    public function testSectionHitWithEmptyBodyKeepsNullSnippet(): void
    {
        $hit = [
            'id' => 903,
            'elementId' => 903,
            'siteId' => 1,
            'backendId' => '903_1_empty',
            'title' => 'Release Guide',
            'url' => 'https://example.test/docs/release',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'sectionType' => 'heading',
            'sectionId' => 'empty',
            'sectionTitle' => 'Empty',
            'sectionLevel' => 2,
            'sectionUrl' => 'https://example.test/docs/release#empty',
            'sectionIndex' => 1,
            'sectionBody' => '',
            'matchedTerms' => [
                'title' => ['release'],
                'content' => [],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'release', ['docs'], [
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertNull($results[0]['snippet'] ?? null);
    }

    public function testHeadingSnippetsStayNullWithoutIndexedBodyText(): void
    {
        $hit = [
            'title' => 'Release Notes',
            'url' => 'https://example.test/docs/release-notes',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_headings' => [
                [
                    'text' => 'Install',
                    'id' => 'install',
                    'level' => 2,
                    'description' => 'Legacy metadata description must not be used.',
                ],
            ],
            'matchedTerms' => [
                'title' => ['release'],
                'content' => [],
            ],
        ];

        $results = CanonicalHitPipeline::presentHits([$hit], 'release', ['docs'], [
            'hideResultsWithoutUrl' => false,
        ]);

        self::assertSame([
            [
                'title' => 'Install',
                'id' => 'install',
                'level' => 2,
                'url' => 'https://example.test/docs/release-notes#install',
                'snippet' => null,
            ],
        ], $results[0]['headings'] ?? null);
    }

    public function testRestAndGraphQlReturnSameFallbackHeadingPreview(): void
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
            'title' => 'Release Notes',
            'url' => 'https://example.test/docs/release-notes',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            '_bodyClean' => 'Deploy Push the rebuilt index after deployment and confirm analytics are writing again.',
            '_headings' => [
                [
                    'text' => 'Deploy',
                    'id' => 'deploy',
                    'level' => 2,
                ],
            ],
            'matchedTerms' => [
                'title' => ['release'],
                'content' => [],
            ],
        ];
        $stub = $this->installStubBackend();

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $restHit = $this->runApiSearch($index->handle, $entry->siteId, 0, 'release', [
            'snippetLength' => 70,
        ])->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $graphql = SearchResolver::resolveSearch(null, [
            'query' => 'release',
            'indices' => [$index->handle],
            'siteId' => $entry->siteId,
            'snippetLength' => 70,
        ], null, $this->createMock(ResolveInfo::class));
        $graphqlHit = $graphql['hits'][0] ?? [];

        self::assertSame($restHit, $graphqlHit);
        self::assertSame('Push the rebuilt index after deployment and confirm analytics are...', $restHit['headings'][0]['snippet'] ?? null);
    }

    public function testIndexedHeadingSnippetsUseSnippetSettingsLikeMainSnippet(): void
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

        $short = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'needle', $index->handle, [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'documentType' => 'source-doc',
            'snippetLength' => 50,
            'parseMarkdownSnippets' => true,
        ]);
        $long = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'needle', $index->handle, [
            'title' => 'Needle Guide',
            'url' => 'https://example.test/docs/needle',
            'documentType' => 'source-doc',
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => true,
        ]);
        $unparsed = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'needle', $index->handle, [
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

    public function testMarkdownSnippetDisplayCleanupMatrixKeepsInlineCodeAndControlsBlockCode(): void
    {
        $hit = [
            'title' => 'DateRangeHelper Guide',
            'url' => 'https://example.test/docs/daterangehelper',
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => '## DateRangeHelper Intro --- Use `inline daterangehelper` and **strong daterangehelper** in prose. ```php block daterangehelper code ``` ### More daterangehelper notes.',
            ],
            'matchedTerms' => [
                'content' => ['daterangehelper'],
            ],
        ];

        $cleanCodeOff = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'showCodeSnippets' => false,
            'parseMarkdownSnippets' => true,
        ]);
        $cleanCodeOn = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'showCodeSnippets' => true,
            'parseMarkdownSnippets' => true,
        ]);
        $rawCodeOff = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'showCodeSnippets' => false,
            'parseMarkdownSnippets' => false,
        ]);
        $rawCodeOn = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'showCodeSnippets' => true,
            'parseMarkdownSnippets' => false,
        ]);

        $cleanOffSnippet = (string)($cleanCodeOff['snippet'] ?? '');
        $cleanOnSnippet = (string)($cleanCodeOn['snippet'] ?? '');
        $rawOffSnippet = (string)($rawCodeOff['snippet'] ?? '');
        $rawOnSnippet = (string)($rawCodeOn['snippet'] ?? '');

        self::assertStringContainsString('DateRangeHelper Intro Use inline daterangehelper and strong daterangehelper in prose.', $cleanOffSnippet);
        self::assertStringContainsString('inline daterangehelper', $cleanOffSnippet);
        self::assertStringContainsString('daterangehelper', $cleanOffSnippet);
        self::assertStringNotContainsString('block daterangehelper code', $cleanOffSnippet);
        self::assertStringNotContainsString('##', $cleanOffSnippet);
        self::assertStringNotContainsString('###', $cleanOffSnippet);
        self::assertStringNotContainsString('---', $cleanOffSnippet);
        self::assertStringNotContainsString('**', $cleanOffSnippet);
        self::assertStringNotContainsString('`', $cleanOffSnippet);

        self::assertStringContainsString('block daterangehelper code', $cleanOnSnippet);
        self::assertStringContainsString('inline daterangehelper', $cleanOnSnippet);
        self::assertStringNotContainsString('##', $cleanOnSnippet);
        self::assertStringNotContainsString('###', $cleanOnSnippet);
        self::assertStringNotContainsString('---', $cleanOnSnippet);
        self::assertStringNotContainsString('**', $cleanOnSnippet);
        self::assertStringNotContainsString('```', $cleanOnSnippet);
        self::assertStringNotContainsString('`inline', $cleanOnSnippet);

        self::assertStringContainsString('## DateRangeHelper', $rawOffSnippet);
        self::assertStringContainsString('**strong daterangehelper**', $rawOffSnippet);
        self::assertStringContainsString('`inline daterangehelper`', $rawOffSnippet);
        self::assertStringNotContainsString('block daterangehelper code', $rawOffSnippet);

        self::assertStringContainsString('## DateRangeHelper', $rawOnSnippet);
        self::assertStringContainsString('**strong daterangehelper**', $rawOnSnippet);
        self::assertStringContainsString('`inline daterangehelper`', $rawOnSnippet);
        self::assertStringContainsString('```php block daterangehelper code ```', $rawOnSnippet);
    }

    public function testMarkdownListMarkerCleanupDoesNotEatProseSymbolsOrSentenceNumbers(): void
    {
        $hit = [
            'title' => 'DateRangeHelper Notes',
            'url' => 'https://example.test/docs/daterangehelper-notes',
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => '### 1) Item daterangehelper for Campaign Manager + Next Plugins, kept consistent + correct. They developed it in 1966. No matter what.',
            ],
            'matchedTerms' => [
                'content' => ['daterangehelper'],
            ],
        ];

        $result = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'showCodeSnippets' => false,
            'parseMarkdownSnippets' => true,
        ]);

        $snippet = (string)($result['snippet'] ?? '');

        self::assertStringContainsString('Item daterangehelper for Campaign Manager + Next Plugins, kept consistent + correct.', $snippet);
        self::assertStringContainsString('They developed it in 1966. No matter what.', $snippet);
        self::assertStringNotContainsString('###', $snippet);
        self::assertStringNotContainsString('1) Item', $snippet);
    }

    public function testHtmlRichTextFieldSnippetsAreNotMarkdownParsed(): void
    {
        $hit = [
            'title' => 'DateRangeHelper Rich Text',
            'url' => 'https://example.test/docs/rich-text',
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => '<h2>Discovery **daterangehelper**</h2><p>[docs](https://example.test) paragraph.</p>',
            ],
            'matchedTerms' => [
                'content' => ['daterangehelper'],
            ],
        ];

        $parsed = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => true,
        ]);
        $raw = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => false,
        ]);

        $snippet = (string)($parsed['snippet'] ?? '');

        self::assertStringContainsString('Discovery **daterangehelper** [docs](https://example.test) paragraph.', $snippet);
        self::assertSame($raw['snippet'] ?? null, $parsed['snippet'] ?? null);
    }

    public function testPredominantlyMarkdownFieldWithIncidentalInlineHtmlStillParses(): void
    {
        $hit = [
            'title' => 'DateRangeHelper Mixed Markdown',
            'url' => 'https://example.test/docs/mixed-markdown',
            'type' => 'entry',
            'elementType' => 'entry',
            '_fields' => [
                'intro' => "# DateRangeHelper\n\nUse <span>inline</span> **daterangehelper** marker.",
            ],
            'matchedTerms' => [
                'content' => ['daterangehelper'],
            ],
        ];

        $result = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($hit, 'daterangehelper', '', [
            'snippetLength' => 1000,
            'parseMarkdownSnippets' => true,
        ]);

        $snippet = (string)($result['snippet'] ?? '');

        self::assertStringContainsString('DateRangeHelper Use inline daterangehelper marker.', $snippet);
        self::assertStringNotContainsString('<span>', $snippet);
        self::assertStringNotContainsString('**daterangehelper**', $snippet);
        self::assertStringNotContainsString('# DateRangeHelper', $snippet);
    }

    public function testMainSnippetComesFromIndexedFieldWhenBodyDoesNotMatch(): void
    {
        $hit = [
            '_fields' => [
                'description' => 'The fieldneedle phrase lives only inside this indexed custom field.',
            ],
            '_bodyClean' => 'The body has unrelated prose without the searched phrase.',
            'matchedTerms' => [
                'content' => ['fieldneedle'],
            ],
        ];
        $debugMeta = [];

        $result = SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
            $hit,
            'fieldneedle',
            '',
            [],
            $debugMeta,
        );

        self::assertSame('The fieldneedle phrase lives only inside this indexed custom field.', $result['snippet'] ?? null);
        self::assertSame('fields', $debugMeta['snippetSource'] ?? null);
        self::assertSame('description', $debugMeta['snippetFrom'] ?? null);
    }

    public function testMainSnippetComesFromBodyWhenFieldDoesNotMatch(): void
    {
        $hit = [
            '_fields' => [
                'description' => 'This indexed field has searchable prose but not the requested term.',
            ],
            '_bodyClean' => 'The bodyneedle phrase lives only inside the indexed body text.',
            'matchedTerms' => [
                'content' => ['bodyneedle'],
            ],
        ];
        $debugMeta = [];

        $result = SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
            $hit,
            'bodyneedle',
            '',
            [],
            $debugMeta,
        );

        self::assertSame('The bodyneedle phrase lives only inside the indexed body text.', $result['snippet'] ?? null);
        self::assertSame('body', $debugMeta['snippetSource'] ?? null);
        self::assertSame('body', $debugMeta['snippetFrom'] ?? null);
    }

    public function testMainSnippetCompetitionKeepsCurrentTieWinner(): void
    {
        $hit = [
            '_fields' => [
                'description' => 'sharedneedle appears first in the preferred indexed field.',
            ],
            '_bodyClean' => 'sharedneedle appears first in the indexed body.',
            'matchedTerms' => [
                'content' => ['sharedneedle'],
            ],
        ];
        $debugMeta = [];

        $result = SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
            $hit,
            'sharedneedle',
            '',
            [],
            $debugMeta,
        );

        self::assertSame('sharedneedle appears first in the preferred indexed field.', $result['snippet'] ?? null);
        self::assertSame('fields', $debugMeta['snippetSource'] ?? null);
        self::assertSame('description', $debugMeta['snippetFrom'] ?? null);
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
        self::assertArrayNotHasKey('thumbnail', $fields);

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
        self::assertSame([], $hit['headings'] ?? null);
        self::assertArrayNotHasKey('_headings', $hit);
        self::assertArrayNotHasKey('_matchedHeadings', $hit);
        self::assertArrayNotHasKey('_bodyClean', $hit);
        self::assertArrayNotHasKey('_bodyWithCode', $hit);
        self::assertArrayNotHasKey('_contentClean', $hit);
        self::assertArrayNotHasKey('_sectionBodyWithCode', $hit);
        self::assertArrayNotHasKey('_elementType', $hit);
        self::assertArrayNotHasKey('intro', $hit);
        self::assertSame('metadata', $hit['category'] ?? null);
    }

    public function testGraphQlUsesCanonicalIndexOnlyHitShapeMatchingRest(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            $this->markTestSkipped('No enabled entry index available.');
        }

        [$index, $entry] = $pair;
        $hit = $this->rawHit($entry->id, $entry->siteId);
        $hit['thumbnail'] = 'https://example.test/thumb.jpg';
        $stub = $this->installStubBackend();

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $restHit = $this->runApiSearch($index->handle, $entry->siteId, 1, 'intro')->data['hits'][0] ?? [];

        $stub->searchResponse = ['hits' => [$hit], 'total' => 1];
        $graphql = SearchResolver::resolveSearch(null, [
            'query' => 'intro',
            'indices' => [$index->handle],
            'siteId' => $entry->siteId,
        ], null, $this->createMock(ResolveInfo::class));
        $graphqlHit = $graphql['hits'][0] ?? [];

        self::assertSame($restHit, $graphqlHit);
        self::assertSame('indexed-site', $graphqlHit['site'] ?? null);
        self::assertSame('de-CH', $graphqlHit['language'] ?? null);
        self::assertArrayNotHasKey('thumbnail', $graphqlHit);
    }

    public function testPublicSearchResponsePathsDoNotUseLiveElementHydration(): void
    {
        $api = $this->readPluginFile('src/controllers/ApiController.php');
        $resolver = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');
        $settings = $this->readPluginFile('src/controllers/SettingsController.php');
        $query = $this->readPluginFile('src/gql/queries/SearchQuery.php');
        $hitType = $this->readPluginFile('src/gql/types/SearchHitType.php');

        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $api);
        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $resolver);
        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $settings);
        self::assertStringNotContainsString('getElementById', $api);
        self::assertStringNotContainsString('getSiteById', $api);
        self::assertStringNotContainsString('withLiveTitleUrlFallback', $api);
        self::assertStringNotContainsString('enrichResults(', $resolver);
        self::assertStringNotContainsString('SearchManager::$plugin->liveComparison', $api);
        self::assertStringNotContainsString('SearchManager::$plugin->liveComparison', $resolver);
        self::assertStringNotContainsString("'enrich'", $query);
        self::assertStringNotContainsString('GqlHelper::siteHandle', $hitType);
    }

    public function testTestToolCanonicalDefaultsMatchRestDefaultsByConstruction(): void
    {
        $api = $this->readPluginFile('src/controllers/ApiController.php');
        $settings = $this->readPluginFile('src/controllers/SettingsController.php');

        foreach ([
            "'snippetMode' => (string) \$request->getBodyParam('snippetMode', 'balanced')",
            "'snippetLength' => (int) \$request->getBodyParam('snippetLength', 150)",
            "'showCodeSnippets' => (bool) \$request->getBodyParam('showCodeSnippets', false)",
            "'parseMarkdownSnippets' => (bool) \$request->getBodyParam('parseMarkdownSnippets', false)",
        ] as $needle) {
            self::assertStringContainsString($needle, $settings);
        }

        foreach ([
            "'snippetMode' => (string) \$request->getParam('snippetMode', 'balanced')",
            "'snippetLength' => (int) \$request->getParam('snippetLength', 150)",
            "'showCodeSnippets' => (bool) \$request->getParam('showCodeSnippets', false)",
            "'parseMarkdownSnippets' => (bool) \$request->getParam('parseMarkdownSnippets', false)",
        ] as $needle) {
            self::assertStringContainsString($needle, $api);
        }

        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $api);
        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $settings);
        self::assertStringNotContainsString("getBodyParam('snippetLength', 200)", $settings);
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
            'site' => 'indexed-site',
            'language' => 'de-CH',
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
            '_bodyWithCode' => 'Internal code body must be stripped',
            '_contentClean' => 'Internal clean content must be stripped',
            '_sectionBodyWithCode' => 'Internal section code body must be stripped',
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

    private function readPluginFile(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($path, '/'));
        self::assertIsString($content);

        return $content;
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
