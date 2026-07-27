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
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\web\Request;
use craft\web\Response;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Action;

/**
 * Final autocomplete response-limit regressions for REST and GraphQL.
 *
 * @since 5.54.0
 */
final class AutocompleteFinalLimitTest extends TestCase
{
    private const LOCAL_INDEX = '__sm_pr145_local__';
    private const HOSTED_INDEX = '__sm_pr145_hosted__';

    private object $originalRequest;
    private object $originalResponse;
    private string $originalRequestMethod;
    private bool $originalRequireApiKey;
    private AutocompleteFinalLimitRecordingService $autocomplete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        SearchManager::$plugin->getSettings()->requireApiKey = false;

        $this->autocomplete = new AutocompleteFinalLimitRecordingService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $this->autocomplete);
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->requireApiKey = $this->originalRequireApiKey;
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        Craft::$app->getGql()->setActiveSchema(null);

        parent::tearDown();
    }

    public function testRestExplicitSingleIndexResponseRemainsUnchanged(): void
    {
        $index = $this->index(self::LOCAL_INDEX);
        $suggestions = ['alpha', 'beta'];
        $results = [
            $this->autocompleteResult(1, 'Alpha', 1),
            $this->autocompleteResult(2, 'Beta', 1),
        ];
        $this->autocomplete->setSource(self::LOCAL_INDEX, null, $suggestions, $results);

        $response = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $this->runRest([
                'q' => 'a',
                'indexHandles' => self::LOCAL_INDEX,
                'resultsLimit' => '5',
            ]),
        );

        self::assertSame($suggestions, $response['suggestions']);
        self::assertSame($results, $response['results']);
        self::assertSame(['suggest', 'suggestElements'], array_column($this->autocomplete->calls, 'method'));
        self::assertSame([5, 5], array_column(array_column($this->autocomplete->calls, 'options'), 'limit'));
    }

    public function testRestAllEnabledLocalAndHostedSourcesDedupeBeforeFinalSliceInStableOrder(): void
    {
        $indices = [
            $this->index(self::LOCAL_INDEX),
            $this->index(self::HOSTED_INDEX, 'hosted-recording'),
        ];
        $firstResult = $this->autocompleteResult(1, 'First ranked', 1);
        $duplicateResult = $this->autocompleteResult(1, 'Later duplicate', 1);
        $this->autocomplete->setSource(
            self::LOCAL_INDEX,
            null,
            ['alpha', 'alpha', 'beta'],
            [$firstResult, $duplicateResult, $this->autocompleteResult(2, 'Second', 1)],
        );
        $this->autocomplete->setSource(
            self::HOSTED_INDEX,
            null,
            ['gamma', 'delta', 'alpha'],
            [$this->autocompleteResult(3, 'Third', 1), $this->autocompleteResult(4, 'After boundary', 1), $duplicateResult],
        );

        $response = $this->withOnlySearchIndices(
            $indices,
            fn(): array => $this->runRest([
                'q' => 'a',
                'resultsLimit' => '3',
            ]),
        );

        self::assertSame(['alpha', 'beta', 'gamma'], $response['suggestions']);
        self::assertSame([$firstResult, $this->autocompleteResult(2, 'Second', 1), $this->autocompleteResult(3, 'Third', 1)], $response['results']);
        self::assertSame(
            [self::LOCAL_INDEX, self::LOCAL_INDEX, self::HOSTED_INDEX, self::HOSTED_INDEX],
            array_column($this->autocomplete->calls, 'indexHandle'),
        );
        self::assertNull($indices[0]->backend);
        self::assertSame('hosted-recording', $indices[1]->backend);
        self::assertLessThanOrEqual(3, count($response['suggestions']));
        self::assertLessThanOrEqual(3, count($response['results']));
    }

    #[DataProvider('onlyModeProvider')]
    public function testRestOnlyModesUseTheRequestedResponseFamily(string $only, string $method): void
    {
        $indices = [
            $this->index(self::LOCAL_INDEX),
            $this->index(self::HOSTED_INDEX, 'hosted-recording'),
        ];
        foreach ($indices as $offset => $index) {
            $this->autocomplete->setSource(
                $index->handle,
                null,
                ['shared', 'suggestion-' . $offset],
                [$this->autocompleteResult(1, 'Shared', 1), $this->autocompleteResult(10 + $offset, 'Result ' . $offset, 1)],
            );
        }

        $response = $this->withOnlySearchIndices(
            $indices,
            fn(): array => $this->runRest([
                'q' => 's',
                'indexHandles' => self::LOCAL_INDEX . ',' . self::HOSTED_INDEX,
                'resultsLimit' => '2',
                'only' => $only,
            ]),
        );

        self::assertCount(2, $response);
        self::assertSame([$method, $method], array_column($this->autocomplete->calls, 'method'));
    }

    #[DataProvider('publicLimitProvider')]
    public function testRestResolvesBoundaryLimitsAgainstEachFinalList(?string $requested, int $resolved): void
    {
        $index = $this->index(self::LOCAL_INDEX);
        $this->autocomplete->setSource(
            self::LOCAL_INDEX,
            null,
            $this->suggestionRange(120),
            $this->resultRange(120, 1),
        );
        $parameters = [
            'q' => 'item',
            'indexHandles' => self::LOCAL_INDEX,
        ];
        if ($requested !== null) {
            $parameters['resultsLimit'] = $requested;
        }

        $response = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $this->runRest($parameters),
        );

        self::assertCount($resolved, $response['suggestions']);
        self::assertCount($resolved, $response['results']);
        self::assertSame([$resolved, $resolved], array_column(array_column($this->autocomplete->calls, 'options'), 'limit'));
    }

    public function testRestApiKeyMaximumCapsEachFinalList(): void
    {
        $index = $this->index(self::LOCAL_INDEX);
        $this->autocomplete->setSource(
            self::LOCAL_INDEX,
            null,
            $this->suggestionRange(10),
            $this->resultRange(10, 1),
        );
        $key = new ApiKey([
            'allowedIndices' => [ApiKey::ALL_INDICES],
            'maxHitsPerPage' => 2,
        ]);

        $response = $this->withOnlySearchIndices(
            [$index],
            fn(): array => $this->runRest([
                'q' => 'item',
                'indexHandles' => self::LOCAL_INDEX,
                'resultsLimit' => '100',
            ], $key),
        );

        self::assertCount(2, $response['suggestions']);
        self::assertCount(2, $response['results']);
        self::assertSame([2, 2], array_column(array_column($this->autocomplete->calls, 'options'), 'limit'));
    }

    #[DataProvider('publicLimitProvider')]
    public function testGraphqlResolvesBoundaryLimitsAgainstEachFinalList(?string $requested, int $resolved): void
    {
        $site = Craft::$app->getSites()->getAllSites()[0] ?? null;
        if ($site === null) {
            $this->markTestSkipped('An active site is required for the GraphQL limit regression.');
        }

        $index = $this->index(self::LOCAL_INDEX, siteId: (int)$site->id);
        $this->autocomplete->setSource(
            self::LOCAL_INDEX,
            (int)$site->id,
            $this->suggestionRange(120),
            $this->resultRange(120, (int)$site->id),
        );
        $arguments = [
            'query' => 'item',
            'indexHandles' => [self::LOCAL_INDEX],
            'siteId' => (int)$site->id,
        ];
        if ($requested !== null) {
            $arguments['resultsLimit'] = $requested;
        }
        Craft::$app->getGql()->setActiveSchema(new GqlSchema([
            'name' => 'PR1.45 limit schema',
            'uid' => StringHelper::UUID(),
            'scope' => [
                'searchManager.all:read',
                'sites.' . $site->uid . ':read',
            ],
        ]));

        $response = $this->withOnlySearchIndices(
            [$index],
            fn(): array => SearchResolver::resolveAutocomplete(
                null,
                $arguments,
                null,
                $this->createMock(ResolveInfo::class),
            ),
        );

        self::assertCount($resolved, $response['suggestions']);
        self::assertCount($resolved, $response['results']);
        self::assertSame([$resolved, $resolved], array_column(array_column($this->autocomplete->calls, 'options'), 'limit'));
    }

    public function testGraphqlFinalizesAcrossSelectedIndicesAndSchemaSites(): void
    {
        $sites = array_slice(Craft::$app->getSites()->getAllSites(), 0, 2);
        if (count($sites) < 2) {
            $this->markTestSkipped('Two active sites are required for the GraphQL fan-out regression.');
        }

        $indices = [
            $this->index(self::LOCAL_INDEX, siteId: array_column($sites, 'id')),
            $this->index(self::HOSTED_INDEX, 'hosted-recording', array_column($sites, 'id')),
        ];
        $firstResult = $this->autocompleteResult(1, 'First ranked', (int)$sites[0]->id);
        foreach ($indices as $indexOffset => $index) {
            foreach ($sites as $siteOffset => $site) {
                $this->autocomplete->setSource(
                    $index->handle,
                    (int)$site->id,
                    $indexOffset === 0 && $siteOffset === 0
                        ? ['alpha', 'alpha', 'beta']
                        : ['gamma-' . $indexOffset . '-' . $siteOffset, 'after-boundary'],
                    $indexOffset === 0 && $siteOffset === 0
                        ? [$firstResult, $this->autocompleteResult(1, 'Later duplicate', (int)$site->id), $this->autocompleteResult(2, 'Second', (int)$site->id)]
                        : [$this->autocompleteResult(10 + ($indexOffset * 2) + $siteOffset, 'Later result', (int)$site->id)],
                );
            }
        }

        $schema = new GqlSchema([
            'name' => 'PR1.45 multi-site schema',
            'uid' => StringHelper::UUID(),
            'scope' => array_merge(
                ['searchManager.all:read'],
                array_map(static fn(object $site): string => 'sites.' . $site->uid . ':read', $sites),
            ),
        ]);
        Craft::$app->getGql()->setActiveSchema($schema);

        $response = $this->withOnlySearchIndices(
            $indices,
            fn(): array => SearchResolver::resolveAutocomplete(
                null,
                [
                    'query' => 'a',
                    'indexHandles' => [self::LOCAL_INDEX, self::HOSTED_INDEX],
                    'resultsLimit' => 3,
                ],
                null,
                $this->createMock(ResolveInfo::class),
            ),
        );

        self::assertSame(['alpha', 'beta', 'gamma-0-1'], $response['suggestions']);
        self::assertSame($firstResult, $response['results'][0]);
        self::assertCount(3, $response['results']);
        self::assertCount(8, $this->autocomplete->calls);
        self::assertLessThanOrEqual(3, count($response['suggestions']));
        self::assertLessThanOrEqual(3, count($response['results']));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function onlyModeProvider(): iterable
    {
        yield 'suggestions' => ['suggestions', 'suggest'];
        yield 'results' => ['results', 'suggestElements'];
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int}>
     */
    public static function publicLimitProvider(): iterable
    {
        yield 'zero uses default' => ['0', 10];
        yield 'negative uses default' => ['-5', 10];
        yield 'omitted uses default' => [null, 10];
        yield 'maximum' => ['100', 100];
        yield 'over maximum' => ['500', 100];
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|list<mixed>
     */
    private function runRest(array $parameters, ?ApiKey $apiKey = null): array
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setQueryParams($parameters);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());

        $controller = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($controller->beforeAction(new Action('autocomplete', $controller)));

        if ($apiKey !== null) {
            $property = new \ReflectionProperty($controller, 'authenticatedKey');
            $property->setValue($controller, $apiKey);
        }

        return $controller->actionAutocomplete()->data;
    }

    private function index(string $handle, ?string $backend = null, int|array|null $siteId = null): SearchIndex
    {
        return new SearchIndex([
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'backend' => $backend,
            'enabled' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function autocompleteResult(int $id, string $text, int $siteId): array
    {
        return [
            'id' => $id,
            'siteId' => $siteId,
            'text' => $text,
            'type' => 'entry',
        ];
    }

    /**
     * @return list<string>
     */
    private function suggestionRange(int $count): array
    {
        return array_map(static fn(int $index): string => 'suggestion-' . $index, range(1, $count));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resultRange(int $count, int $siteId): array
    {
        return array_map(fn(int $index): array => $this->autocompleteResult($index, 'Result ' . $index, $siteId), range(1, $count));
    }
}

final class AutocompleteFinalLimitRecordingService extends AutocompleteService
{
    /** @var list<array{method: string, query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{suggestions: list<string>, results: list<array<string, mixed>>}> */
    private array $sources = [];

    /**
     * @param list<string> $suggestions
     * @param list<array<string, mixed>> $results
     */
    public function setSource(string $indexHandle, ?int $siteId, array $suggestions, array $results): void
    {
        $this->sources[$this->sourceKey($indexHandle, $siteId)] = [
            'suggestions' => $suggestions,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->record('suggest', $query, $indexHandle, $options);

        return $this->sources[$this->sourceKey($indexHandle, $options['siteId'] ?? null)]['suggestions'] ?? [];
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function suggestElements(string $query, string $indexHandle, array $options = []): array
    {
        $this->record('suggestElements', $query, $indexHandle, $options);

        return $this->sources[$this->sourceKey($indexHandle, $options['siteId'] ?? null)]['results'] ?? [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function record(string $method, string $query, string $indexHandle, array $options): void
    {
        $this->calls[] = [
            'method' => $method,
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];
    }

    private function sourceKey(string $indexHandle, mixed $siteId): string
    {
        return $indexHandle . ':' . (string)($siteId ?? 'all');
    }
}
