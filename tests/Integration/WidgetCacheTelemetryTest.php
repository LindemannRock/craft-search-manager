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
use craft\elements\Entry;
use craft\helpers\Json;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\ApiKeyService;
use lindemannrock\searchmanager\services\WidgetCacheTelemetryService;
use lindemannrock\searchmanager\services\WidgetSearchOutcomeTelemetryService;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\caching\ArrayCache;

/**
 * @since 5.55.0
 */
final class WidgetCacheTelemetryTest extends TestCase
{
    private bool $originalEnableAnalytics;
    private bool $originalRequireApiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableAnalytics = $settings->enableAnalytics;
        $this->originalRequireApiKey = $settings->requireApiKey;
        $settings->enableAnalytics = true;
        $settings->requireApiKey = false;
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = $this->originalEnableAnalytics;
        $settings->requireApiKey = $this->originalRequireApiKey;
        parent::tearDown();
    }

    public function testEnvelopeIsSafeBoundAndConsumedOnceWithPerIndexOutcomes(): void
    {
        $service = SearchManager::$plugin->widgetCacheTelemetry;
        self::assertInstanceOf(WidgetCacheTelemetryService::class, $service);
        self::assertInstanceOf(
            WidgetSearchOutcomeTelemetryService::class,
            SearchManager::$plugin->widgetSearchOutcomeTelemetry,
        );
        self::assertNotSame($service, SearchManager::$plugin->widgetSearchOutcomeTelemetry);
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $cache = Craft::$app->getCache();
        $sentinel = '__sm_widget_telemetry_sentinel__' . bin2hex(random_bytes(6));
        self::assertTrue($cache->set($sentinel, 'preserved', 600));

        $envelope = $service->issue(
            'Sensitive   Query',
            $siteId,
            ['alpha', 'beta'],
            2,
            [
                'alpha' => ['cached' => true, 'duration' => null],
                'beta' => ['cached' => false, 'duration' => 0.0],
            ],
        );

        self::assertIsString($envelope);
        self::assertStringStartsWith('v1.', $envelope);
        self::assertStringNotContainsString('Sensitive', $envelope);
        $payload = $this->decodeEnvelope($envelope);
        self::assertSame([
            'version',
            'query',
            'siteId',
            'indexHandles',
            'resultCount',
            'issuedAt',
            'expiresAt',
            'nonce',
            'outcomes',
        ], array_keys($payload));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $payload['query']);
        self::assertSame(['alpha', 'beta'], $payload['indexHandles']);
        self::assertSame(2, $payload['resultCount']);
        self::assertLessThanOrEqual(WidgetCacheTelemetryService::ENVELOPE_TTL, $payload['expiresAt'] - $payload['issuedAt']);
        foreach (['meta', 'backend', 'rules', 'promotions', 'expandedQueries', 'resolvedTerms'] as $sensitiveKey) {
            self::assertArrayNotHasKey($sensitiveKey, $payload);
        }

        self::assertNull($service->consume($envelope, 'other query', $siteId, ['alpha', 'beta'], 2));
        self::assertNull($service->consume($envelope, 'Sensitive Query', null, ['alpha', 'beta'], 2));
        self::assertNull($service->consume($envelope, 'Sensitive Query', $siteId, ['beta', 'alpha'], 2));
        self::assertNull($service->consume($envelope, 'Sensitive Query', $siteId, ['alpha', 'beta'], 3));
        self::assertSame([
            'alpha' => 0.0,
            'beta' => 0.01,
        ], $service->consume($envelope, ' sensitive query ', $siteId, ['alpha', 'beta'], 2));
        self::assertNull($service->consume($envelope, 'Sensitive Query', $siteId, ['alpha', 'beta'], 2));
        self::assertSame('preserved', $cache->get($sentinel));
        self::assertTrue($cache->delete($sentinel));
    }

    public function testConcurrentConsumersRequireAnExclusiveReplayClaimForBothProtocols(): void
    {
        $cache = Craft::$app->getCache();
        $service = SearchManager::$plugin->widgetSearchOutcomeTelemetry;
        $bindings = ['concurrent query', null, ['alpha'], 1];

        $v2 = $service->issueSearchOutcome(...[
            ...$bindings,
            'widget-modal',
            ['alpha' => [
                'cached' => true,
                'duration' => null,
                'backend' => 'database',
                'resultsCount' => 1,
                'synonymsExpanded' => false,
                'matchedRules' => [],
                'wasRedirected' => false,
                'promotions' => [],
            ]],
        ]);
        self::assertIsString($v2);
        $v2Nonce = $this->decodeEnvelope($v2)['nonce'];
        self::assertIsString($v2Nonce);
        $v2Claim = $this->replayKey('widget-search-outcome-replay', $v2Nonce);

        $v1 = $service->issueV1CacheTelemetry(...[
            ...$bindings,
            ['alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => 1]],
        ]);
        self::assertIsString($v1);
        $v1Nonce = $this->decodeEnvelope($v1)['nonce'];
        self::assertIsString($v1Nonce);
        $v1Claim = $this->replayKey('widget-cache-telemetry-replay', $v1Nonce);

        self::assertTrue($cache->add($v2Claim, 'competing-v2-consumer', 300));
        self::assertTrue($cache->add($v1Claim, 'competing-v1-consumer', 300));

        try {
            self::assertNull($service->consumeSearchOutcome(...[
                $v2,
                ...$bindings,
                'widget-modal',
            ]));
            self::assertNull($service->consumeV1CacheTelemetry($v1, ...$bindings));
        } finally {
            self::assertTrue($cache->delete($v2Claim));
            self::assertTrue($cache->delete($v1Claim));
        }

        $v2Outcome = $service->consumeSearchOutcome(...[
            $v2,
            ...$bindings,
            'widget-modal',
        ]);
        self::assertNotNull($v2Outcome);
        self::assertSame(0.0, $v2Outcome['alpha']['executionTime']);
        self::assertSame(['alpha' => 0.0], $service->consumeV1CacheTelemetry($v1, ...$bindings));
    }

    public function testSearchOutcomeEnvelopeKeepsPerIndexPromotionTruthServerSideAndBindsSource(): void
    {
        $service = SearchManager::$plugin->widgetSearchOutcomeTelemetry;
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $envelope = $service->issueSearchOutcome(
            'Promoted Query',
            $siteId,
            ['alpha', 'beta'],
            3,
            'campaign-search',
            [
                'alpha' => [
                    'cached' => false,
                    'duration' => 8.5,
                    'backend' => 'database',
                    'resultsCount' => 4,
                    'synonymsExpanded' => true,
                    'matchedRules' => [
                        ['id' => 7, 'name' => 'Synonym rule', 'actionType' => 'synonym'],
                        ['id' => 7, 'name' => 'Duplicate snapshot', 'actionType' => 'synonym'],
                        ['id' => 9, 'name' => 'Section boost', 'actionType' => 'boost_section'],
                        ['id' => 10, 'name' => 'Category boost', 'actionType' => 'boost_category'],
                        ['id' => 11, 'name' => 'Element boost', 'actionType' => 'boost_element'],
                        ['id' => 12, 'name' => 'Redirect', 'actionType' => 'redirect'],
                    ],
                    'wasRedirected' => false,
                    'promotions' => [['id' => 17, 'elementId' => 117, 'position' => 1]],
                ],
                'beta' => [
                    'cached' => true,
                    'duration' => null,
                    'backend' => 'algolia',
                    'resultsCount' => 0,
                    'synonymsExpanded' => false,
                    'matchedRules' => [['id' => 8, 'name' => 'Redirect rule', 'actionType' => 'redirect']],
                    'wasRedirected' => true,
                    'promotions' => [],
                ],
            ],
        );

        self::assertIsString($envelope);
        self::assertStringStartsWith('v2.', $envelope);
        self::assertStringNotContainsString('Promoted', $envelope);
        $payload = $this->decodeEnvelope($envelope);
        self::assertSame([
            'version',
            'query',
            'siteId',
            'indexHandles',
            'resultCount',
            'source',
            'issuedAt',
            'expiresAt',
            'nonce',
        ], array_keys($payload));
        self::assertArrayNotHasKey('outcomes', $payload);
        self::assertArrayNotHasKey('promotions', $payload);

        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'different query',
            $siteId,
            ['alpha', 'beta'],
            3,
            'campaign-search',
        ));
        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'Promoted Query',
            null,
            ['alpha', 'beta'],
            3,
            'campaign-search',
        ));
        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'Promoted Query',
            $siteId,
            ['beta', 'alpha'],
            3,
            'campaign-search',
        ));
        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'Promoted Query',
            $siteId,
            ['alpha', 'beta'],
            2,
            'campaign-search',
        ));
        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'Promoted Query',
            $siteId,
            ['alpha', 'beta'],
            3,
            'other-source',
        ));
        self::assertSame([
            'alpha' => [
                'executionTime' => 8.5,
                'backend' => 'database',
                'resultsCount' => 4,
                'synonymsExpanded' => true,
                'rulesMatched' => 5,
                'matchedRules' => [
                    ['id' => 7, 'name' => 'Synonym rule', 'actionType' => 'synonym'],
                    ['id' => 9, 'name' => 'Section boost', 'actionType' => 'boost_section'],
                    ['id' => 10, 'name' => 'Category boost', 'actionType' => 'boost_category'],
                    ['id' => 11, 'name' => 'Element boost', 'actionType' => 'boost_element'],
                    ['id' => 12, 'name' => 'Redirect', 'actionType' => 'redirect'],
                ],
                'wasRedirected' => false,
                'promotions' => [['id' => 17, 'elementId' => 117, 'position' => 1]],
            ],
            'beta' => [
                'executionTime' => 0.0,
                'backend' => 'algolia',
                'resultsCount' => 0,
                'synonymsExpanded' => false,
                'rulesMatched' => 1,
                'matchedRules' => [['id' => 8, 'name' => 'Redirect rule', 'actionType' => 'redirect']],
                'wasRedirected' => true,
                'promotions' => [],
            ],
        ], $service->consumeSearchOutcome(
            $envelope,
            ' promoted query ',
            $siteId,
            ['alpha', 'beta'],
            3,
            'campaign-search',
        ));
        self::assertNull($service->consumeSearchOutcome(
            $envelope,
            'Promoted Query',
            $siteId,
            ['alpha', 'beta'],
            3,
            'campaign-search',
        ));
    }

    public function testV2IssuanceFailsClosedForUnrepresentableServerOutcomes(): void
    {
        $service = SearchManager::$plugin->widgetSearchOutcomeTelemetry;
        $valid = [
            'cached' => true,
            'duration' => null,
            'backend' => 'mysql',
            'resultsCount' => 1,
            'synonymsExpanded' => false,
            'matchedRules' => [],
            'wasRedirected' => false,
            'promotions' => [],
        ];
        $issue = static fn(array $outcome): ?string => $service->issueSearchOutcome(
            'bounded outcome',
            null,
            ['alpha'],
            1,
            'widget-modal',
            ['alpha' => $outcome],
        );

        self::assertNull($issue(array_merge($valid, [
            'matchedRules' => [['id' => 1, 'name' => 'Unknown', 'actionType' => 'unknown']],
        ])));
        self::assertNull($issue(array_merge($valid, [
            'matchedRules' => array_fill(0, 101, ['id' => 1, 'name' => 'Synonym', 'actionType' => 'synonym']),
        ])));
        self::assertNull($issue(array_merge($valid, [
            'promotions' => array_fill(0, 101, ['id' => 1, 'elementId' => 2, 'position' => 1]),
        ])));
        self::assertNull($issue(array_merge($valid, [
            'promotions' => [['id' => 1, 'elementId' => 0, 'position' => 1]],
        ])));
        self::assertNull($issue(array_merge($valid, [
            'backend' => str_repeat('b', 65),
        ])));
        $missingCount = $valid;
        unset($missingCount['resultsCount']);
        self::assertNull($issue($missingCount));
        self::assertNull($issue(array_merge($valid, [
            'resultsCount' => -1,
        ])));
        self::assertNull($service->issueV1CacheTelemetry(
            'bounded outcome',
            null,
            ['alpha'],
            1,
            ['alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => -1]],
        ));
        self::assertNull($service->issueV1CacheTelemetry(
            'bounded outcome',
            null,
            ['alpha'],
            1,
            [],
        ));
    }

    public function testInvalidExpiredAndUnsuitableReplayProtectionFailClosed(): void
    {
        $service = new WidgetCacheTelemetryClock();
        $service->timestamp = 1000;
        $searchEnvelope = $service->issueSearchOutcome('query', null, ['alpha'], 1, 'widget-modal', [
            'alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => 1, 'promotions' => []],
        ]);
        self::assertIsString($searchEnvelope);
        self::assertNull($service->consumeSearchOutcome(
            $searchEnvelope . 'tampered',
            'query',
            null,
            ['alpha'],
            1,
            'widget-modal',
        ));

        $envelope = $service->issueV1CacheTelemetry('query', null, ['alpha'], 1, [
            'alpha' => ['cached' => true, 'duration' => null],
        ]);
        self::assertIsString($envelope);
        self::assertNull($service->consumeV1CacheTelemetry($envelope . 'tampered', 'query', null, ['alpha'], 1));

        $service->timestamp = 1301;
        self::assertNull($service->consumeSearchOutcome(
            $searchEnvelope,
            'query',
            null,
            ['alpha'],
            1,
            'widget-modal',
        ));
        self::assertNull($service->consumeV1CacheTelemetry($envelope, 'query', null, ['alpha'], 1));

        $arrayCache = new ArrayCache();
        self::assertTrue($arrayCache->set('unrelated-sentinel', 'preserved'));
        Craft::$app->set('cache', $arrayCache);
        self::assertNull(SearchManager::$plugin->widgetSearchOutcomeTelemetry->issueSearchOutcome(
            'query',
            null,
            ['alpha'],
            1,
            'widget-modal',
            ['alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => 1, 'promotions' => []]],
        ));
        self::assertNull(SearchManager::$plugin->widgetCacheTelemetry->issue('query', null, ['alpha'], 1, [
            'alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => 1],
        ]));
        $current = SearchManager::$plugin->widgetCacheTelemetry->issue('query', null, ['alpha'], 1, [
            'alpha' => ['cached' => true, 'duration' => null],
        ]);
        self::assertIsString($current);
        self::assertNull(SearchManager::$plugin->widgetCacheTelemetry->consume($current, 'query', null, ['alpha'], 1));
        self::assertSame('preserved', $arrayCache->get('unrelated-sentinel'));
    }

    public function testPublicSearchExposesOnlyOpaqueTelemetryOutsideDebugMeta(): void
    {
        $index = $this->index('alpha');
        $backend = $this->installStubBackend();
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $backend->searchResponse = [
            'hits' => [],
            'total' => 150,
            '_searchOutcome' => [
                'cached' => false,
                'duration' => 0.01,
                'backend' => 'sensitive-backend',
                'resultsCount' => 150,
                'synonymsExpanded' => false,
                'matchedRules' => [],
                'wasRedirected' => false,
                'promotions' => [],
            ],
            'meta' => [
                'cached' => false,
                'took' => 0,
                'backend' => 'sensitive-backend',
                'expandedQueries' => ['secret expansion'],
                'resolvedTerms' => ['secret' => ['term']],
            ],
        ];

        $this->withOnlySearchIndices([$index], function() use ($backend, $analytics): void {
            $this->installRequest('GET', [
                'q' => 'public query',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
                'telemetryVersion' => '2',
            ]);

            $response = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('meta', $response->data);
            self::assertArrayNotHasKey('_searchOutcomes', $response->data);
            self::assertIsString($response->data['searchTelemetry'] ?? null);
            self::assertArrayNotHasKey('cacheTelemetry', $response->data);
            self::assertStringNotContainsString('sensitive-backend', $response->data['searchTelemetry']);

            $searchPayload = $this->decodeEnvelope($response->data['searchTelemetry']);
            self::assertSame(2, $searchPayload['version']);
            self::assertSame('widget-modal', $searchPayload['source']);
            self::assertArrayNotHasKey('outcomes', $searchPayload);

            $this->track([
                'q' => 'public query',
                'indexHandles' => 'alpha',
                'resultsCount' => '0',
                'searchTelemetry' => $response->data['searchTelemetry'],
            ]);
            self::assertSame(150, $analytics->calls[0]['resultsCount']);

            $backend->searchResponse['meta']['cached'] = true;
            $backend->searchResponse['meta']['took'] = 0;
            $backend->searchResponse['_searchOutcome']['cached'] = true;
            $backend->searchResponse['_searchOutcome']['duration'] = null;
            $this->installRequest('GET', [
                'q' => 'public query',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
            ]);

            $hitResponse = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('meta', $hitResponse->data);
            self::assertArrayNotHasKey('searchTelemetry', $hitResponse->data);
            self::assertIsString($hitResponse->data['cacheTelemetry'] ?? null);
            $hitPayload = $this->decodeEnvelope($hitResponse->data['cacheTelemetry']);
            self::assertSame(true, $hitPayload['outcomes'][0]['cached']);
            self::assertNull($hitPayload['outcomes'][0]['duration']);

            unset($backend->searchResponse['_searchOutcome']);
            $this->installRequest('GET', [
                'q' => 'handled without outcome',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
                'telemetryVersion' => '2',
            ]);

            $unverifiedResponse = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('searchTelemetry', $unverifiedResponse->data);
            self::assertArrayNotHasKey('cacheTelemetry', $unverifiedResponse->data);

            $backend->searchResponse['_searchOutcome'] = ['cached' => true, 'duration' => null];
            $this->installRequest('GET', [
                'q' => 'incomplete outcome',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
            ]);

            $incompleteResponse = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('searchTelemetry', $incompleteResponse->data);
            self::assertArrayNotHasKey('cacheTelemetry', $incompleteResponse->data);
        });
    }

    public function testTrackingClassifiesOnlyVerifiedPerIndexTelemetry(): void
    {
        $indices = [$this->index('alpha'), $this->index('beta')];
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);

        $this->withOnlySearchIndices($indices, function() use ($analytics): void {
            $service = SearchManager::$plugin->widgetCacheTelemetry;
            $envelope = $service->issue('multi query', null, ['alpha', 'beta'], 2, [
                'alpha' => ['cached' => true, 'duration' => null],
                'beta' => ['cached' => false, 'duration' => 12.5],
            ]);
            self::assertIsString($envelope);

            $this->track([
                'q' => 'multi query',
                'indexHandles' => 'alpha,beta',
                'resultsCount' => '2',
                'cacheTelemetry' => $envelope,
            ]);
            self::assertSame([
                ['index' => 'alpha', 'resultsCount' => 2, 'executionTime' => 0.0],
                ['index' => 'beta', 'resultsCount' => 2, 'executionTime' => 12.5],
            ], array_map(
                static fn(array $call): array => [
                    'index' => $call['index'],
                    'resultsCount' => $call['resultsCount'],
                    'executionTime' => $call['executionTime'],
                ],
                $analytics->calls,
            ));
            self::assertNotNull($analytics->calls[0]['sessionId']);
            self::assertSame($analytics->calls[0]['sessionId'], $analytics->calls[1]['sessionId']);

            $unknownCases = [
                'raw-only' => ['cached' => '1', 'took' => '0'],
                'replayed' => ['cacheTelemetry' => $envelope],
                'invalid' => ['cacheTelemetry' => $envelope . 'tampered'],
                'mismatched' => ['cacheTelemetry' => $service->issue('different query', null, ['alpha'], 2, [
                    'alpha' => ['cached' => true, 'duration' => null],
                ])],
            ];
            foreach ($unknownCases as $query => $telemetry) {
                $this->track(array_merge([
                    'q' => $query === 'replayed' ? 'multi query' : $query,
                    'indexHandles' => $query === 'replayed' ? 'alpha,beta' : 'alpha',
                    'resultsCount' => '2',
                ], $telemetry));
            }

            foreach (array_slice($analytics->calls, 2) as $call) {
                self::assertNull($call['executionTime']);
            }
        });
    }

    public function testInvalidV2TelemetryNeverDowngradesToValidV1Telemetry(): void
    {
        $index = $this->index('alpha');
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);

        $this->withOnlySearchIndices([$index], function() use ($analytics): void {
            $v1 = SearchManager::$plugin->widgetCacheTelemetry->issue(
                'no downgrade',
                null,
                ['alpha'],
                1,
                ['alpha' => ['cached' => true, 'duration' => null]],
            );
            self::assertIsString($v1);

            $this->track([
                'q' => 'no downgrade',
                'indexHandles' => 'alpha',
                'resultsCount' => '1',
                'searchTelemetry' => 'v2.invalid',
                'cacheTelemetry' => $v1,
            ]);
            self::assertNull($analytics->calls[0]['executionTime']);
            self::assertSame(0, $analytics->calls[0]['promotionsShown']);
            self::assertSame([], $analytics->calls[0]['matchedPromotions']);

            // The ignored v1 token remains available, proving the invalid v2
            // request neither downgraded nor consumed the v1 lifecycle.
            $this->track([
                'q' => 'no downgrade',
                'indexHandles' => 'alpha',
                'resultsCount' => '1',
                'cacheTelemetry' => $v1,
            ]);
            self::assertSame(0.0, $analytics->calls[1]['executionTime']);
            self::assertSame(0, $analytics->calls[1]['promotionsShown']);
            self::assertSame([], $analytics->calls[1]['matchedPromotions']);

            $this->track([
                'q' => 'no downgrade',
                'indexHandles' => 'alpha',
                'resultsCount' => '1',
                'cacheTelemetry' => $v1,
            ]);
            self::assertNull($analytics->calls[2]['executionTime']);
            self::assertSame([], $analytics->calls[2]['matchedPromotions']);
        });
    }

    public function testTrackingRestoresServerIssuedPerIndexPromotionOutcomes(): void
    {
        $indices = [$this->index('alpha'), $this->index('beta')];
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);

        $this->withOnlySearchIndices($indices, function() use ($analytics): void {
            $envelope = SearchManager::$plugin->widgetSearchOutcomeTelemetry->issueSearchOutcome(
                'multi promotions',
                null,
                ['alpha', 'beta'],
                2,
                'campaign-search',
                [
                    'alpha' => [
                        'cached' => true,
                        'duration' => null,
                        'backend' => 'database',
                        'resultsCount' => 4,
                        'synonymsExpanded' => true,
                        'matchedRules' => [['id' => 31, 'name' => 'Alpha synonym', 'actionType' => 'synonym']],
                        'wasRedirected' => false,
                        'promotions' => [['id' => 11, 'elementId' => 101, 'position' => 1]],
                    ],
                    'beta' => [
                        'cached' => false,
                        'duration' => 4.25,
                        'backend' => 'typesense',
                        'resultsCount' => 0,
                        'synonymsExpanded' => false,
                        'matchedRules' => [
                            ['id' => 41, 'name' => 'Beta boost', 'actionType' => 'boost_element'],
                            ['id' => 42, 'name' => 'Beta redirect', 'actionType' => 'redirect'],
                        ],
                        'wasRedirected' => true,
                        'promotions' => [['id' => 22, 'elementId' => 202, 'position' => 2]],
                    ],
                ],
            );
            self::assertIsString($envelope);

            $this->track([
                'q' => 'multi promotions',
                'indexHandles' => 'alpha,beta',
                'resultsCount' => '2',
                'analyticsSource' => 'campaign-search',
                'searchTelemetry' => $envelope,
            ]);

            self::assertSame([
                ['index' => 'alpha', 'backend' => 'database', 'resultsCount' => 4, 'executionTime' => 0.0, 'synonymsExpanded' => true, 'rulesMatched' => 1, 'ruleIds' => [31], 'wasRedirected' => false, 'promotionsShown' => 1, 'promotionIds' => [11]],
                ['index' => 'beta', 'backend' => 'typesense', 'resultsCount' => 0, 'executionTime' => 4.25, 'synonymsExpanded' => false, 'rulesMatched' => 2, 'ruleIds' => [41, 42], 'wasRedirected' => true, 'promotionsShown' => 1, 'promotionIds' => [22]],
            ], array_map(
                static fn(array $call): array => [
                    'index' => $call['index'],
                    'backend' => $call['backend'],
                    'resultsCount' => $call['resultsCount'],
                    'executionTime' => $call['executionTime'],
                    'synonymsExpanded' => $call['synonymsExpanded'],
                    'rulesMatched' => $call['rulesMatched'],
                    'ruleIds' => array_column($call['matchedRules'], 'id'),
                    'wasRedirected' => $call['wasRedirected'],
                    'promotionsShown' => $call['promotionsShown'],
                    'promotionIds' => array_column($call['matchedPromotions'], 'id'),
                ],
                $analytics->calls,
            ));
        });
    }

    public function testV1TrackingKeepsWireCompatibilityAndUsesServerOwnedPerIndexCounts(): void
    {
        $indices = [$this->index('alpha'), $this->index('beta')];
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);

        $this->withOnlySearchIndices($indices, function() use ($analytics): void {
            $envelope = SearchManager::$plugin->widgetCacheTelemetry->issue(
                'v1 native totals',
                null,
                ['alpha', 'beta'],
                2,
                [
                    'alpha' => ['cached' => true, 'duration' => null, 'resultsCount' => 4],
                    'beta' => ['cached' => false, 'duration' => 6.5, 'resultsCount' => 0],
                ],
            );
            self::assertIsString($envelope);

            $payload = $this->decodeEnvelope($envelope);
            self::assertSame([
                'version',
                'query',
                'siteId',
                'indexHandles',
                'resultCount',
                'issuedAt',
                'expiresAt',
                'nonce',
                'outcomes',
            ], array_keys($payload));
            self::assertSame([
                ['index' => 'alpha', 'cached' => true, 'duration' => null],
                ['index' => 'beta', 'cached' => false, 'duration' => 6.5],
            ], $payload['outcomes']);

            $this->track([
                'q' => 'v1 native totals',
                'indexHandles' => 'alpha,beta',
                'resultsCount' => '2',
                'cacheTelemetry' => $envelope,
            ]);

            self::assertSame([
                ['index' => 'alpha', 'resultsCount' => 4, 'executionTime' => 0.0],
                ['index' => 'beta', 'resultsCount' => 0, 'executionTime' => 6.5],
            ], array_map(
                static fn(array $call): array => [
                    'index' => $call['index'],
                    'resultsCount' => $call['resultsCount'],
                    'executionTime' => $call['executionTime'],
                ],
                $analytics->calls,
            ));
        });
    }

    public function testRestrictedKeyUnscopedV1AndV2UseTheSameCanonicalOutcomeScope(): void
    {
        $allowed = $this->index('scope-allowed');
        $disallowed = $this->index('scope-disallowed');
        $unavailable = $this->index('scope-unavailable');
        $unavailable->elementType = 'missing\\searchmanager\\ScopeElement';
        $backend = $this->installStubBackend();
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        $this->withOnlySearchIndices([$allowed, $disallowed, $unavailable], function() use (
            $allowed,
            $disallowed,
            $unavailable,
            $backend,
            $analytics,
            $siteId,
        ): void {
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
            [$key, $plaintext] = $this->seedPublicApiKey([$allowed->handle]);
            SearchManager::$plugin->getSettings()->requireApiKey = true;

            $backend->searchResponse = [
                'hits' => [[
                    'elementId' => 910001,
                    'backendId' => '910001_' . $siteId,
                    'siteId' => $siteId,
                    '_index' => $allowed->handle,
                    'title' => 'Authorized result',
                    'url' => '/authorized-result',
                    'type' => 'entry',
                ]],
                'total' => 7,
                '_searchOutcome' => [
                    'cached' => false,
                    'duration' => 4.5,
                    'backend' => 'mysql',
                    'resultsCount' => 7,
                    'synonymsExpanded' => false,
                    'matchedRules' => [],
                    'wasRedirected' => false,
                    'promotions' => [],
                ],
            ];
            $v2Response = $this->searchWithKey($plaintext, [
                'q' => 'restricted v2 scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'skipAnalytics' => '1',
                'telemetryVersion' => '2',
            ]);
            self::assertSame($allowed->handle, $v2Response['hits'][0]['index']);
            self::assertSame([$allowed->handle], $this->decodeEnvelope($v2Response['searchTelemetry'])['indexHandles']);
            $this->trackWithKey($plaintext, [
                'q' => 'restricted v2 scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'resultsCount' => '1',
                'searchTelemetry' => $v2Response['searchTelemetry'],
            ]);

            $backend->searchResponse = [
                'hits' => [],
                'total' => 0,
                '_searchOutcome' => [
                    'cached' => true,
                    'duration' => null,
                    'backend' => 'mysql',
                    'resultsCount' => 0,
                    'synonymsExpanded' => false,
                    'matchedRules' => [],
                    'wasRedirected' => false,
                    'promotions' => [],
                ],
            ];
            $v1Response = $this->searchWithKey($plaintext, [
                'q' => 'restricted v1 scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'skipAnalytics' => '1',
            ]);
            self::assertSame([$allowed->handle], $this->decodeEnvelope($v1Response['cacheTelemetry'])['indexHandles']);
            $this->trackWithKey($plaintext, [
                'q' => 'restricted v1 scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'resultsCount' => '0',
                'cacheTelemetry' => $v1Response['cacheTelemetry'],
            ]);

            // Invalid telemetry retains only the effective restricted scope and
            // cannot fall back to the complete available catalogue.
            $this->trackWithKey($plaintext, [
                'q' => 'restricted invalid scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'resultsCount' => '1',
                'searchTelemetry' => $v2Response['searchTelemetry'] . 'tampered',
            ]);

            self::assertSame([
                [$allowed->handle, 7, 4.5, $siteId, $key->id],
                [$allowed->handle, 0, 0.0, $siteId, $key->id],
                [$allowed->handle, 1, null, $siteId, $key->id],
            ], array_map(
                static fn(array $call): array => [
                    $call['index'],
                    $call['resultsCount'],
                    $call['executionTime'],
                    $call['siteId'],
                    $call['apiKeyId'],
                ],
                $analytics->calls,
            ));
            self::assertNotContains($disallowed->handle, array_column($analytics->calls, 'index'));
            self::assertNotContains($unavailable->handle, array_column($analytics->calls, 'index'));
            self::assertSame(
                [$allowed->handle, $allowed->handle],
                array_column($backend->callsFor('search'), 'indexName'),
            );
            self::assertSame([], $backend->callsFor('searchMultiple'));
        });
    }

    public function testHistoricalUnscopedV1TokenIsFilteredThroughCurrentEligibilityBeforePersistence(): void
    {
        $healthy = $this->index('rolling-healthy');
        $unavailable = $this->index('rolling-unavailable');
        $unavailable->elementType = 'missing\\searchmanager\\RollingElement';
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);
        $this->installStubBackend();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        $this->withOnlySearchIndices([$healthy, $unavailable], function() use (
            $healthy,
            $unavailable,
            $analytics,
            $siteId,
        ): void {
            SearchManager::$plugin->dependencies->clearIndexCatalogue();
            [$key, $plaintext] = $this->seedPublicApiKey([ApiKey::ALL_INDICES]);
            SearchManager::$plugin->getSettings()->requireApiKey = true;

            // This is the exact previous v1 shape: raw enabled requested scope,
            // cache-only outcomes, and no native-count server record.
            $token = SearchManager::$plugin->widgetCacheTelemetry->issue(
                'rolling v1 scope',
                $siteId,
                [$healthy->handle, $unavailable->handle],
                6,
                [
                    $healthy->handle => ['cached' => false, 'duration' => 3.25],
                    $unavailable->handle => ['cached' => true, 'duration' => null],
                ],
            );
            self::assertIsString($token);
            self::assertSame(
                [$healthy->handle, $unavailable->handle],
                $this->decodeEnvelope($token)['indexHandles'],
            );

            $this->trackWithKey($plaintext, [
                'q' => 'rolling v1 scope',
                'indexHandles' => '',
                'siteId' => (string)$siteId,
                'resultsCount' => '6',
                'cacheTelemetry' => $token,
            ]);

            self::assertCount(1, $analytics->calls);
            self::assertSame($healthy->handle, $analytics->calls[0]['index']);
            self::assertSame(6, $analytics->calls[0]['resultsCount']);
            self::assertSame(3.25, $analytics->calls[0]['executionTime']);
            self::assertSame($siteId, $analytics->calls[0]['siteId']);
            self::assertSame($key->id, $analytics->calls[0]['apiKeyId']);
            self::assertNotSame($unavailable->handle, $analytics->calls[0]['index']);
            self::assertNull(SearchManager::$plugin->widgetSearchOutcomeTelemetry->consumeV1SearchTelemetryWithHistoricalScope(
                $token,
                'rolling v1 scope',
                $siteId,
                [$healthy->handle],
                [$healthy->handle, $unavailable->handle],
                6,
            ));
        });
    }

    public function testHistoricalV1ScopeMismatchDoesNotConsumeTheCorrectAttemptAndExpiryStillWins(): void
    {
        $service = new WidgetCacheTelemetryClock();
        $service->timestamp = 1000;
        $token = $service->issueV1CacheTelemetry(
            'rolling claim',
            null,
            ['healthy', 'unavailable'],
            2,
            [
                'healthy' => ['cached' => true, 'duration' => null],
                'unavailable' => ['cached' => false, 'duration' => 2.5],
            ],
        );
        self::assertIsString($token);

        self::assertNull($service->consumeV1SearchTelemetryWithHistoricalScope(
            $token,
            'rolling claim',
            null,
            ['other'],
            ['other', 'unavailable'],
            2,
        ));
        self::assertSame([
            'healthy' => ['executionTime' => 0.0, 'resultsCount' => null],
        ], $service->consumeV1SearchTelemetryWithHistoricalScope(
            $token,
            'rolling claim',
            null,
            ['healthy'],
            ['healthy', 'unavailable'],
            2,
        ));

        $expired = $service->issueV1CacheTelemetry(
            'rolling expiry',
            null,
            ['healthy', 'unavailable'],
            2,
            [
                'healthy' => ['cached' => true, 'duration' => null],
                'unavailable' => ['cached' => true, 'duration' => null],
            ],
        );
        self::assertIsString($expired);
        $service->timestamp = 1300;
        self::assertNull($service->consumeV1SearchTelemetryWithHistoricalScope(
            $expired,
            'rolling expiry',
            null,
            ['healthy'],
            ['healthy', 'unavailable'],
            2,
        ));
    }

    public function testVerifiedTelemetryPersistsOnlyActualServerOutcomeHandles(): void
    {
        $indices = [$this->index('alpha'), $this->index('filtered')];
        $analytics = new WidgetTelemetryRecordingAnalyticsService();
        $this->swapPluginComponent('search-manager', 'analytics', $analytics);

        $this->withOnlySearchIndices($indices, function() use ($analytics): void {
            $outcome = [
                'cached' => true,
                'duration' => null,
                'backend' => 'database',
                'resultsCount' => 4,
                'synonymsExpanded' => false,
                'matchedRules' => [],
                'wasRedirected' => false,
                'promotions' => [],
            ];
            $v2 = SearchManager::$plugin->widgetSearchOutcomeTelemetry->issueSearchOutcome(
                'actual v2 outcomes',
                null,
                ['alpha', 'filtered'],
                2,
                'widget-modal',
                ['alpha' => $outcome],
            );
            self::assertIsString($v2);
            $this->track([
                'q' => 'actual v2 outcomes',
                'indexHandles' => 'alpha,filtered',
                'resultsCount' => '2',
                'searchTelemetry' => $v2,
            ]);

            $v1 = SearchManager::$plugin->widgetCacheTelemetry->issue(
                'actual v1 outcomes',
                null,
                ['alpha', 'filtered'],
                2,
                ['alpha' => $outcome],
            );
            self::assertIsString($v1);
            $this->track([
                'q' => 'actual v1 outcomes',
                'indexHandles' => 'alpha,filtered',
                'resultsCount' => '2',
                'cacheTelemetry' => $v1,
            ]);

            self::assertSame([
                ['index' => 'alpha', 'resultsCount' => 4],
                ['index' => 'alpha', 'resultsCount' => 4],
            ], array_map(
                static fn(array $call): array => [
                    'index' => $call['index'],
                    'resultsCount' => $call['resultsCount'],
                ],
                $analytics->calls,
            ));
        });
    }

    public function testVerifiedV2OutcomePersistsMainRuleAndPromotionRepresentations(): void
    {
        $this->swapPluginComponent('search-manager', 'analytics', new AnalyticsService());
        SearchManager::$plugin->getSettings()->ipHashSalt = str_repeat('a', 40);
        $handle = 'smV2Outcome' . bin2hex(random_bytes(4));
        $index = $this->index($handle);
        self::assertTrue($index->save(), Json::encode($index->getErrors()));
        $query = '__sm_v2_outcome_' . bin2hex(random_bytes(6));

        $this->withOnlySearchIndices([$index], function() use ($handle, $query): void {
            $envelope = SearchManager::$plugin->widgetSearchOutcomeTelemetry->issueSearchOutcome(
                $query,
                null,
                [$handle],
                2,
                'widget-modal',
                [
                    $handle => [
                        'cached' => false,
                        'duration' => 3.75,
                        'backend' => 'mysql',
                        'resultsCount' => 7,
                        'synonymsExpanded' => true,
                        'matchedRules' => [
                            ['id' => 710001, 'name' => 'Snapshot synonym', 'actionType' => 'synonym'],
                            ['id' => 710002, 'name' => 'Snapshot redirect', 'actionType' => 'redirect'],
                        ],
                        'wasRedirected' => true,
                        'promotions' => [['id' => 720001, 'elementId' => 730001, 'position' => 1]],
                    ],
                ],
            );
            self::assertIsString($envelope);

            $this->track([
                'q' => $query,
                'indexHandles' => $handle,
                'resultsCount' => '2',
                'trigger' => 'enter',
                'widgetType' => 'modal',
                'searchTelemetry' => $envelope,
            ]);

            $main = (new Query())
                ->from('{{%searchmanager_analytics}}')
                ->where(['query' => $query])
                ->one();
            self::assertIsArray($main);
            self::assertSame($handle, $main['indexHandle']);
            self::assertSame('mysql', $main['backend']);
            self::assertSame(3.75, (float)$main['executionTime']);
            self::assertSame(7, (int)$main['resultsCount']);
            self::assertSame(1, (int)$main['synonymsExpanded']);
            self::assertSame(2, (int)$main['rulesMatched']);
            self::assertSame(1, (int)$main['wasRedirected']);
            self::assertSame(1, (int)$main['promotionsShown']);
            self::assertSame('widget-modal', $main['source']);
            self::assertSame('enter', $main['trigger']);

            $rules = (new Query())
                ->select(['queryRuleId', 'ruleName', 'actionType', 'indexHandle', 'resultsCount'])
                ->from('{{%searchmanager_rule_analytics}}')
                ->where(['query' => $query])
                ->orderBy(['id' => SORT_ASC])
                ->all();
            self::assertSame([
                ['queryRuleId' => 710001, 'ruleName' => 'Snapshot synonym', 'actionType' => 'synonym', 'indexHandle' => $handle, 'resultsCount' => 7],
                ['queryRuleId' => 710002, 'ruleName' => 'Snapshot redirect', 'actionType' => 'redirect', 'indexHandle' => $handle, 'resultsCount' => 7],
            ], array_map(static fn(array $row): array => [
                'queryRuleId' => (int)$row['queryRuleId'],
                'ruleName' => $row['ruleName'],
                'actionType' => $row['actionType'],
                'indexHandle' => $row['indexHandle'],
                'resultsCount' => (int)$row['resultsCount'],
            ], $rules));

            $promotions = (new Query())
                ->select(['promotionId', 'elementId', 'position', 'indexHandle'])
                ->from('{{%searchmanager_promotion_analytics}}')
                ->where(['query' => $query])
                ->all();
            self::assertSame([[
                'promotionId' => 720001,
                'elementId' => 730001,
                'position' => 1,
                'indexHandle' => $handle,
            ]], array_map(static fn(array $row): array => [
                'promotionId' => (int)$row['promotionId'],
                'elementId' => (int)$row['elementId'],
                'position' => (int)$row['position'],
                'indexHandle' => $row['indexHandle'],
            ], $promotions));

            self::assertContains($query, array_column(
                SearchManager::$plugin->analytics->getRecentSearches(null, 50, null, 'last30days'),
                'query',
            ));
            $export = SearchManager::$plugin->analytics->exportAnalytics(null, 'last30days');
            self::assertContains($query, array_column($export['rows'], 'query'));
        });
    }

    public function testVerifiedRowsReachRecentAndPerformanceWhileRawOnlyStaysUnclassified(): void
    {
        $this->swapPluginComponent('search-manager', 'analytics', new AnalyticsService());
        $handle = 'smTelemetry' . bin2hex(random_bytes(5));
        $index = $this->index($handle);
        self::assertTrue($index->save(), Json::encode($index->getErrors()));
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableGeoDetection = false;
        $marker = '__sm_verified_telemetry_' . bin2hex(random_bytes(6));
        $hitQuery = $marker . '_hit';
        $missQuery = $marker . '_miss';
        $rawQuery = $marker . '_raw';

        $this->withOnlySearchIndices([$index], function() use ($handle, $hitQuery, $missQuery, $rawQuery): void {
            $service = SearchManager::$plugin->widgetCacheTelemetry;
            $before = SearchManager::$plugin->analytics->getCacheStats(null, 'last30days');

            $hitEnvelope = $service->issue($hitQuery, null, [$handle], 1, [
                $handle => ['cached' => true, 'duration' => null],
            ]);
            $missEnvelope = $service->issue($missQuery, null, [$handle], 1, [
                $handle => ['cached' => false, 'duration' => 5.5],
            ]);
            self::assertIsString($hitEnvelope);
            self::assertIsString($missEnvelope);

            $this->track([
                'q' => $hitQuery,
                'indexHandles' => $handle,
                'resultsCount' => '1',
                'cacheTelemetry' => $hitEnvelope,
            ]);
            $this->track([
                'q' => $missQuery,
                'indexHandles' => $handle,
                'resultsCount' => '1',
                'cacheTelemetry' => $missEnvelope,
            ]);
            $this->track([
                'q' => $rawQuery,
                'indexHandles' => $handle,
                'resultsCount' => '1',
                'cached' => '1',
                'took' => '0',
            ]);

            $rows = (new Query())
                ->select(['query', 'executionTime'])
                ->from('{{%searchmanager_analytics}}')
                ->where(['query' => [$hitQuery, $missQuery, $rawQuery]])
                ->indexBy('query')
                ->all();
            self::assertSame(0.0, (float)$rows[$hitQuery]['executionTime']);
            self::assertSame(5.5, (float)$rows[$missQuery]['executionTime']);
            self::assertNull($rows[$rawQuery]['executionTime']);

            $recent = SearchManager::$plugin->analytics->getRecentSearches(null, 20, null, 'last30days');
            $recentQueries = array_column($recent, 'query');
            self::assertContains($hitQuery, $recentQueries);
            self::assertContains($missQuery, $recentQueries);
            self::assertContains($rawQuery, $recentQueries);

            $after = SearchManager::$plugin->analytics->getCacheStats(null, 'last30days');
            self::assertSame($before['total'] + 2, $after['total']);
            self::assertSame($before['cacheHits'] + 1, $after['cacheHits']);
            self::assertSame($before['cacheMisses'] + 1, $after['cacheMisses']);
        });
    }

    /** @return array<string, mixed> */
    private function decodeEnvelope(string $envelope): array
    {
        $separator = strpos($envelope, '.');
        self::assertNotFalse($separator);
        $encoded = substr($envelope, $separator + 1);
        $padding = (4 - strlen($encoded) % 4) % 4;
        $signed = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        self::assertIsString($signed);
        $json = Craft::$app->getSecurity()->validateData($signed);
        self::assertIsString($json);
        $payload = Json::decode($json, true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function replayKey(string $family, string $nonce): string
    {
        return sprintf(
            'lr-cache:v1:app:%s:plugin:%s:family:%s:item:%s',
            hash('sha256', (string)Craft::$app->id),
            SearchManager::$plugin->id,
            $family,
            hash('sha256', $nonce),
        );
    }

    private function index(string $handle): SearchIndex
    {
        return new SearchIndex([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'enabled' => true,
            'source' => 'database',
        ]);
    }

    /**
     * @param list<string> $allowedIndices
     * @return array{0: ApiKey, 1: string}
     */
    private function seedPublicApiKey(array $allowedIndices): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $key = new ApiKey();
        $key->name = '__sm_widget_scope_' . bin2hex(random_bytes(6));
        $key->type = ApiKey::TYPE_PUBLIC;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = $allowedIndices;
        self::assertTrue($key->save(), Json::encode($key->getErrors()));

        return [$key, $generated['plaintext']];
    }

    /** @param array<string, mixed> $parameters */
    private function searchWithKey(string $plaintext, array $parameters): array
    {
        $this->installRequest('GET', $parameters, $plaintext);
        $controller = new ApiController('api', SearchManager::$plugin);
        self::assertTrue($controller->beforeAction(new Action('search', $controller)));
        $response = $controller->actionSearch();
        self::assertIsArray($response->data);

        return $response->data;
    }

    /** @param array<string, mixed> $parameters */
    private function trackWithKey(string $plaintext, array $parameters): void
    {
        $this->installRequest('POST', $parameters, $plaintext);
        $controller = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($controller->beforeAction(new Action('track-search', $controller)));
        self::assertSame(
            ['success' => true, 'tracked' => true],
            $controller->actionTrackSearch()->data,
        );
    }

    /** @param array<string, mixed> $body */
    private function track(array $body): void
    {
        $this->installRequest('POST', $body);
        $response = (new SearchController('search', SearchManager::$plugin))->actionTrackSearch();
        self::assertSame(['success' => true, 'tracked' => true], $response->data);
    }

    /** @param array<string, mixed> $parameters */
    private function installRequest(string $method, array $parameters, ?string $apiKey = null): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setHostInfo('https://site.example.test');
        if ($method === 'POST') {
            $request->setBodyParams($parameters);
            $request->getHeaders()->set('Accept', 'application/json');
        } else {
            $request->setQueryParams($parameters);
        }
        if ($apiKey !== null) {
            $request->getHeaders()->set(ApiKeyService::REQUEST_HEADER, $apiKey);
        }
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
    }
}

final class WidgetCacheTelemetryClock extends WidgetSearchOutcomeTelemetryService
{
    public int $timestamp = 0;

    protected function now(): int
    {
        return $this->timestamp;
    }
}

final class WidgetTelemetryRecordingAnalyticsService extends AnalyticsService
{
    /** @var list<array{index: string, backend: string, resultsCount: int, executionTime: float|null, siteId: int|null, apiKeyId: int|null, sessionId: string|null, synonymsExpanded: bool, rulesMatched: int, matchedRules: array, wasRedirected: bool, promotionsShown: int, matchedPromotions: array}> */
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
            'siteId' => $siteId,
            'apiKeyId' => isset($analyticsOptions['apiKeyId']) ? (int)$analyticsOptions['apiKeyId'] : null,
            'sessionId' => $sessionId,
            'synonymsExpanded' => (bool)($analyticsOptions['synonymsExpanded'] ?? false),
            'rulesMatched' => (int)($analyticsOptions['rulesMatched'] ?? 0),
            'matchedRules' => is_array($analyticsOptions['matchedRules'] ?? null)
                ? $analyticsOptions['matchedRules']
                : [],
            'wasRedirected' => (bool)($analyticsOptions['wasRedirected'] ?? false),
            'promotionsShown' => (int)($analyticsOptions['promotionsShown'] ?? 0),
            'matchedPromotions' => is_array($analyticsOptions['matchedPromotions'] ?? null)
                ? $analyticsOptions['matchedPromotions']
                : [],
        ];
    }
}
