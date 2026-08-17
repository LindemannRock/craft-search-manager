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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\WidgetCacheTelemetryService;
use lindemannrock\searchmanager\tests\TestCase;
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

    public function testInvalidExpiredAndUnsuitableReplayProtectionFailClosed(): void
    {
        $service = new WidgetCacheTelemetryClock();
        $service->timestamp = 1000;
        $envelope = $service->issue('query', null, ['alpha'], 1, [
            'alpha' => ['cached' => true, 'duration' => null],
        ]);
        self::assertIsString($envelope);
        self::assertNull($service->consume($envelope . 'tampered', 'query', null, ['alpha'], 1));

        $service->timestamp = 1301;
        self::assertNull($service->consume($envelope, 'query', null, ['alpha'], 1));

        $arrayCache = new ArrayCache();
        self::assertTrue($arrayCache->set('unrelated-sentinel', 'preserved'));
        Craft::$app->set('cache', $arrayCache);
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
        $backend->searchResponse = [
            'hits' => [],
            'total' => 0,
            'meta' => [
                'cached' => false,
                'took' => 0,
                'backend' => 'sensitive-backend',
                'expandedQueries' => ['secret expansion'],
                'resolvedTerms' => ['secret' => ['term']],
            ],
        ];

        $this->withOnlySearchIndices([$index], function() use ($backend): void {
            $this->installRequest('GET', [
                'q' => 'public query',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
            ]);

            $response = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('meta', $response->data);
            self::assertArrayNotHasKey('_widgetCacheOutcomes', $response->data);
            self::assertIsString($response->data['cacheTelemetry'] ?? null);
            self::assertStringNotContainsString('sensitive-backend', $response->data['cacheTelemetry']);

            $payload = $this->decodeEnvelope($response->data['cacheTelemetry']);
            self::assertSame(['alpha'], $payload['indexHandles']);
            self::assertSame(0, $payload['resultCount']);
            self::assertSame(false, $payload['outcomes'][0]['cached']);
            self::assertSame(0.01, $payload['outcomes'][0]['duration']);

            $backend->searchResponse['meta']['cached'] = true;
            $backend->searchResponse['meta']['took'] = 0;
            $this->installRequest('GET', [
                'q' => 'public query',
                'indexHandles' => 'alpha',
                'skipAnalytics' => '1',
            ]);

            $hitResponse = (new ApiController('api', SearchManager::$plugin))->actionSearch();
            self::assertArrayNotHasKey('meta', $hitResponse->data);
            self::assertIsString($hitResponse->data['cacheTelemetry'] ?? null);
            $hitPayload = $this->decodeEnvelope($hitResponse->data['cacheTelemetry']);
            self::assertSame(true, $hitPayload['outcomes'][0]['cached']);
            self::assertNull($hitPayload['outcomes'][0]['duration']);
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
                ['index' => 'alpha', 'executionTime' => 0.0],
                ['index' => 'beta', 'executionTime' => 12.5],
            ], array_map(
                static fn(array $call): array => [
                    'index' => $call['index'],
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
        $encoded = substr($envelope, strlen('v1.'));
        $padding = (4 - strlen($encoded) % 4) % 4;
        $signed = base64_decode(strtr($encoded . str_repeat('=', $padding), '-_', '+/'), true);
        self::assertIsString($signed);
        $json = Craft::$app->getSecurity()->validateData($signed);
        self::assertIsString($json);
        $payload = Json::decode($json, true);
        self::assertIsArray($payload);

        return $payload;
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

    /** @param array<string, mixed> $body */
    private function track(array $body): void
    {
        $this->installRequest('POST', $body);
        $response = (new SearchController('search', SearchManager::$plugin))->actionTrackSearch();
        self::assertSame(['success' => true, 'tracked' => true], $response->data);
    }

    /** @param array<string, mixed> $parameters */
    private function installRequest(string $method, array $parameters): void
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
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
    }
}

final class WidgetCacheTelemetryClock extends WidgetCacheTelemetryService
{
    public int $timestamp = 0;

    protected function now(): int
    {
        return $this->timestamp;
    }
}

final class WidgetTelemetryRecordingAnalyticsService extends AnalyticsService
{
    /** @var list<array{index: string, executionTime: float|null, sessionId: string|null}> */
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
            'executionTime' => $executionTime,
            'sessionId' => $sessionId,
        ];
    }
}
