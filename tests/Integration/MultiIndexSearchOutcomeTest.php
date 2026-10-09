<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\elements\Entry;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.56.0
 */
final class MultiIndexSearchOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->enableGeoDetection = false;
        $settings->enableCache = false;
    }

    public function testUnselectedRedirectCannotTerminateAndSelectedRedirectRetainsPerIndexTruth(): void
    {
        $indices = [
            $this->index('outcome-alpha'),
            $this->index('outcome-beta'),
            $this->index('outcome-unselected'),
        ];
        $backends = [
            'outcome-alpha' => new NamedOutcomeBackend('backend-alpha'),
            'outcome-beta' => new NamedOutcomeBackend('backend-beta'),
            'outcome-unselected' => new NamedOutcomeBackend('backend-unselected'),
        ];
        $service = new MultiOutcomeBackendService($backends);
        $analytics = new MultiOutcomeRecordingAnalyticsService();
        $rules = new OutcomeQueryRuleService([
            $this->rule(101, 'Global synonym', null, QueryRule::ACTION_SYNONYM, 8),
            $this->rule(102, 'Alpha boost', 'outcome-alpha', QueryRule::ACTION_BOOST_SECTION, 7),
            $this->rule(103, 'Unselected redirect', 'outcome-unselected', QueryRule::ACTION_REDIRECT, 10, '/wrong'),
            $this->rule(104, 'Selected redirect', 'outcome-beta', QueryRule::ACTION_REDIRECT, 6, '/selected'),
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $this->swapPluginComponent('search-manager', 'queryRules', $rules);

        $this->withOnlySearchIndices($indices, function() use ($service, $analytics): void {
            $results = $service->searchMultiple(['outcome-alpha', 'outcome-beta'], 'redirect query', [
                'source' => 'aggregate-custom',
            ]);

            self::assertSame('/selected', $results['redirect'] ?? null);
            self::assertSame(0, $service->searchCalls);
            self::assertCount(2, $analytics->calls);
            self::assertSame([
                [
                    'index' => 'outcome-alpha',
                    'backend' => 'backend-alpha',
                    'rulesMatched' => 2,
                    'ruleIds' => [101, 102],
                    'wasRedirected' => false,
                ],
                [
                    'index' => 'outcome-beta',
                    'backend' => 'backend-beta',
                    'rulesMatched' => 2,
                    'ruleIds' => [101, 104],
                    'wasRedirected' => true,
                ],
            ], array_map(static fn(array $call): array => [
                'index' => $call['index'],
                'backend' => $call['backend'],
                'rulesMatched' => $call['rulesMatched'],
                'ruleIds' => array_column($call['matchedRules'], 'id'),
                'wasRedirected' => $call['wasRedirected'],
            ], $analytics->calls));
            self::assertSame('aggregate-custom', $analytics->calls[0]['source']);
            self::assertNotNull($analytics->calls[0]['sessionId']);
            self::assertSame($analytics->calls[0]['sessionId'], $analytics->calls[1]['sessionId']);
        });
    }

    public function testFinalGlobalSliceOwnsAutomaticPromotionWritesWithoutLosingExecutionTruth(): void
    {
        $indices = [$this->index('outcome-alpha'), $this->index('outcome-beta')];
        $backends = [
            'outcome-alpha' => new NamedOutcomeBackend('backend-alpha'),
            'outcome-beta' => new NamedOutcomeBackend('backend-beta'),
        ];
        $service = new AggregateFixtureBackendService($backends, [
            'outcome-alpha' => [
                'hits' => [['elementId' => 201, 'siteId' => 1, 'score' => 100.0, 'promoted' => true, 'position' => 1]],
                'total' => 4,
                '_searchOutcome' => [
                    'cached' => true,
                    'duration' => null,
                    'backend' => 'backend-alpha',
                    'resultsCount' => 4,
                    'synonymsExpanded' => true,
                    'matchedRules' => [['id' => 201, 'name' => 'Alpha synonym', 'actionType' => 'synonym']],
                    'wasRedirected' => false,
                    'promotions' => [
                        ['id' => 301, 'elementId' => 201, 'position' => 1],
                        ['id' => 301, 'elementId' => 201, 'position' => 1],
                    ],
                ],
            ],
            'outcome-beta' => [
                'hits' => [['elementId' => 202, 'siteId' => 1, 'score' => 90.0, 'promoted' => true, 'position' => 1]],
                'total' => 3,
                '_searchOutcome' => [
                    'cached' => false,
                    'duration' => 6.5,
                    'backend' => 'backend-beta',
                    'resultsCount' => 3,
                    'synonymsExpanded' => false,
                    'matchedRules' => [['id' => 202, 'name' => 'Beta boost', 'actionType' => 'boost_element']],
                    'wasRedirected' => false,
                    'promotions' => [['id' => 302, 'elementId' => 202, 'position' => 1]],
                ],
            ],
        ]);
        $analytics = new MultiOutcomeRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $this->swapPluginComponent('search-manager', 'queryRules', new OutcomeQueryRuleService([]));

        $this->withOnlySearchIndices($indices, function() use ($service, $analytics): void {
            $results = $service->searchMultiple(['outcome-alpha', 'outcome-beta'], 'limited query', [
                'limit' => 1,
                'sourceDefault' => 'graphql',
            ]);

            self::assertSame([201], array_column($results['hits'], 'elementId'));
            self::assertSame(['outcome-alpha'], array_column($results['hits'], '_index'));
            self::assertSame([
                ['skipAnalytics' => true, 'capture' => true],
                ['skipAnalytics' => true, 'capture' => true],
            ], $service->childAuthority);
            self::assertCount(2, $analytics->calls);
            self::assertSame([
                ['backend' => 'backend-alpha', 'executionTime' => 0.0, 'synonymsExpanded' => true, 'ruleIds' => [201], 'promotionIds' => [301]],
                ['backend' => 'backend-beta', 'executionTime' => 6.5, 'synonymsExpanded' => false, 'ruleIds' => [202], 'promotionIds' => []],
            ], array_map(static fn(array $call): array => [
                'backend' => $call['backend'],
                'executionTime' => $call['executionTime'],
                'synonymsExpanded' => $call['synonymsExpanded'],
                'ruleIds' => array_column($call['matchedRules'], 'id'),
                'promotionIds' => array_column($call['matchedPromotions'], 'id'),
            ], $analytics->calls));
            self::assertSame($analytics->calls[0]['sessionId'], $analytics->calls[1]['sessionId']);
        });
    }

    public function testPromotionCollisionsUseCreationOrderBeforeFinalSliceAndAnalytics(): void
    {
        $indices = [$this->index('outcome-alpha'), $this->index('outcome-beta')];
        $responses = [];
        $backends = [];
        foreach (['outcome-alpha' => 302, 'outcome-beta' => 301] as $handle => $promotionId) {
            $backends[$handle] = new NamedOutcomeBackend($handle);
            $responses[$handle] = [
                'hits' => [
                    ['elementId' => $promotionId, 'siteId' => 1, 'score' => null, 'promoted' => true, 'position' => 2],
                    ['elementId' => 901, 'siteId' => 1, 'score' => 20.5],
                ],
                'total' => 1,
                '_searchOutcome' => [
                    'cached' => false,
                    'duration' => 1.0,
                    'backend' => $handle,
                    'resultsCount' => 1,
                    'synonymsExpanded' => false,
                    'matchedRules' => [],
                    'wasRedirected' => false,
                    'promotions' => [['id' => $promotionId, 'elementId' => $promotionId, 'position' => 2]],
                ],
            ];
        }
        $service = new AggregateFixtureBackendService($backends, $responses);
        $analytics = new MultiOutcomeRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $this->swapPluginComponent('search-manager', 'queryRules', new OutcomeQueryRuleService([]));

        $this->withOnlySearchIndices($indices, function() use ($service, $analytics): void {
            $handles = ['outcome-alpha', 'outcome-beta'];
            $full = $service->searchMultiple($handles, 'promotion collision', ['limit' => 0, 'skipAnalytics' => true]);
            self::assertSame([901, 301, 302, 901], array_column($full['hits'], 'elementId'));
            self::assertSame(['outcome-alpha', 'outcome-beta', 'outcome-alpha', 'outcome-beta'], array_column($full['hits'], '_index'));
            $page = $service->searchMultiple($handles, 'promotion collision', ['offset' => 1, 'limit' => 1]);
            self::assertSame([301], array_column($page['hits'], 'elementId'));
            self::assertSame(2, $page['total']);
            self::assertSame([[], [301]], array_map(static fn(array $call): array => array_column($call['matchedPromotions'], 'id'), $analytics->calls));
        });
    }

    public function testFreshAndCachedSearchesCaptureTheSameExactRuleAndSynonymTruth(): void
    {
        $handle = 'outcome-cache';
        $index = $this->index($handle);
        $backend = new NamedOutcomeBackend('backend-cache');
        $service = new MultiOutcomeBackendService([$handle => $backend]);
        $rules = new OutcomeQueryRuleService([
            $this->rule(401, 'Global synonym', null, QueryRule::ACTION_SYNONYM, 8),
            $this->rule(402, 'Section boost', $handle, QueryRule::ACTION_BOOST_SECTION, 7),
            $this->rule(403, 'Category boost', $handle, QueryRule::ACTION_BOOST_CATEGORY, 6),
            $this->rule(404, 'Element boost', $handle, QueryRule::ACTION_BOOST_ELEMENT, 5),
        ]);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->swapPluginComponent('search-manager', 'queryRules', $rules);
        SearchManager::$plugin->getSettings()->enableCache = true;

        $this->withOnlySearchIndices([$index], function() use ($handle, $service): void {
            $service->clearSearchCache($handle);
            try {
                $options = ['skipAnalytics' => true, '_captureSearchOutcome' => true];
                $fresh = $service->search($handle, 'cache query', $options)['_searchOutcome'];
                $cached = $service->search($handle, 'cache query', $options)['_searchOutcome'];

                self::assertFalse($fresh['cached']);
                self::assertGreaterThan(0.0, $fresh['duration']);
                self::assertTrue($cached['cached']);
                self::assertNull($cached['duration']);
                foreach ([$fresh, $cached] as $outcome) {
                    self::assertSame('backend-cache', $outcome['backend']);
                    self::assertTrue($outcome['synonymsExpanded']);
                    self::assertSame([401, 402, 403, 404], array_column($outcome['matchedRules'], 'id'));
                    self::assertFalse($outcome['wasRedirected']);
                    self::assertSame([], $outcome['promotions']);
                }
            } finally {
                $service->clearSearchCache($handle);
            }
        });
    }

    private function index(string $handle): SearchIndex
    {
        return new SearchIndex([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'elementType' => Entry::class,
            'source' => 'database',
            'enabled' => true,
            'enableAnalytics' => true,
        ]);
    }

    private function rule(
        int $id,
        string $name,
        ?string $indexHandle,
        string $actionType,
        int $priority,
        ?string $url = null,
    ): QueryRule {
        $actionValue = match ($actionType) {
            QueryRule::ACTION_SYNONYM => ['terms' => ['alias']],
            QueryRule::ACTION_BOOST_SECTION => ['sectionHandle' => 'news', 'multiplier' => 2.0],
            QueryRule::ACTION_BOOST_CATEGORY => ['categoryId' => 1, 'multiplier' => 2.0],
            QueryRule::ACTION_BOOST_ELEMENT => ['elementId' => 1, 'multiplier' => 2.0],
            QueryRule::ACTION_REDIRECT => ['url' => $url ?? '/redirect'],
            default => [],
        };

        return new QueryRule([
            'id' => $id,
            'name' => $name,
            'indexHandle' => $indexHandle,
            'actionType' => $actionType,
            'actionValue' => $actionValue,
            'priority' => $priority,
        ]);
    }
}

/** @since 5.56.0 */
final class OutcomeQueryRuleService extends QueryRuleService
{
    /** @param list<QueryRule> $rules */
    public function __construct(private readonly array $rules, array $config = [])
    {
        parent::__construct($config);
    }

    public function getMatchingRules(string $query, ?string $indexHandle = null, ?int $siteId = null): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(QueryRule $rule): bool => $indexHandle === null
                || $rule->indexHandle === null
                || $rule->indexHandle === $indexHandle,
        ));
    }
}

/** @since 5.56.0 */
class NamedOutcomeBackend extends MySqlBackend
{
    public int $searchCalls = 0;

    public function __construct(private readonly string $backendName, array $config = [])
    {
        parent::__construct($config);
    }

    public function getName(): string
    {
        return $this->backendName;
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;
        return ['hits' => [], 'total' => 0];
    }
}

/** @since 5.56.0 */
class MultiOutcomeBackendService extends BackendService
{
    public int $searchCalls = 0;

    /** @param array<string, NamedOutcomeBackend> $backends */
    public function __construct(protected readonly array $backends, array $config = [])
    {
        parent::__construct($config);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        $first = array_key_first($this->backends);
        return $first !== null ? $this->backends[$first] : null;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backends[$indexName] ?? null;
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;
        return parent::search($indexName, $query, $options);
    }
}

/** @since 5.56.0 */
final class AggregateFixtureBackendService extends MultiOutcomeBackendService
{
    /** @var list<array{skipAnalytics: bool, capture: bool}> */
    public array $childAuthority = [];

    /**
     * @param array<string, NamedOutcomeBackend> $backends
     * @param array<string, array<string, mixed>> $responses
     */
    public function __construct(array $backends, private readonly array $responses, array $config = [])
    {
        parent::__construct($backends, $config);
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;
        $this->childAuthority[] = [
            'skipAnalytics' => ($options['skipAnalytics'] ?? false) === true,
            'capture' => ($options['_captureSearchOutcome'] ?? false) === true,
        ];
        return $this->responses[$indexName];
    }
}

/** @since 5.56.0 */
final class MultiOutcomeRecordingAnalyticsService extends AnalyticsService
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function trackSearch(
        string $indexHandle,
        string $query,
        int $resultsCount,
        ?float $executionTime,
        string $backend,
        ?int $siteId = null,
        array $analyticsOptions = [],
        ?string $sessionId = null,
    ): void {
        $this->calls[] = [
            'index' => $indexHandle,
            'backend' => $backend,
            'resultsCount' => $resultsCount,
            'executionTime' => $executionTime,
            'source' => \lindemannrock\searchmanager\helpers\TrackingMetadataHelper::resolveSource(
                $analyticsOptions['source'] ?? null,
                $analyticsOptions['sourceDefault'] ?? null,
            ),
            'synonymsExpanded' => ($analyticsOptions['synonymsExpanded'] ?? false) === true,
            'rulesMatched' => (int)($analyticsOptions['rulesMatched'] ?? 0),
            'matchedRules' => $analyticsOptions['matchedRules'] ?? [],
            'wasRedirected' => ($analyticsOptions['wasRedirected'] ?? false) === true,
            'promotionsShown' => (int)($analyticsOptions['promotionsShown'] ?? 0),
            'matchedPromotions' => $analyticsOptions['matchedPromotions'] ?? [],
            'sessionId' => $sessionId,
        ];
    }
}
