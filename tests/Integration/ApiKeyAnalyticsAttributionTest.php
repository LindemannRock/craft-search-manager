<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Action;
use yii\web\HeaderCollection;

/**
 * Slice 5 — analytics key attribution.
 *
 * Pins the contract that search analytics rows carry the API key that made the
 * request when one is present, and stay null (and backward compatible) for
 * anonymous / unkeyed traffic. Both keyed endpoints (`/api/search` and
 * `track-search`) attribute via the same two-step path:
 *
 *   1. the controller maps its authenticated key to analytics options through
 *      {@see ApiKeyService::attributionOptions()}, then
 *   2. {@see AnalyticsTrackingService::trackSearch()} writes apiKeyId /
 *      apiKeyPrefix / apiKeyType onto the row.
 *
 * Tests cover the mapping (unit), the real insert round-trip, the read-side
 * breakdown (keyed-only, snapshot-durable), the export columns, and the
 * anonymous null path. Autocomplete records no analytics and `track-click`
 * persists nothing, so neither has attribution to assert (slice 5 attributes
 * existing rows only — see `.internal/api-keys-implementation.md`).
 *
 * Direct service insert tests use a synthetic siteId no real search can ever
 * write to, plus a `backend = 'test'` marker. Controller round-trip coverage
 * uses a real Craft site ID because public tracking now discards unknown siteId
 * values; cleanup also deletes by this class's unique query markers so it never
 * deletes all analytics rows for a real site.
 *
 * @since 5.47.0
 */
final class ApiKeyAnalyticsAttributionTest extends TestCase
{
    private const TEST_SITE_ID = 999999;
    private const TEST_KEY_NAME_PREFIX = '__sm_attr_test__';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->truncateAnalytics();
        $this->purgeTestKeys();
    }

    protected function tearDown(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        $this->truncateAnalytics();
        $this->purgeTestKeys();
        parent::tearDown();
    }

    // ---- attributionOptions() — the controller-side mapping -----------------

    public function testAttributionOptionsReturnsEmptyForAnonymous(): void
    {
        $this->assertSame([], SearchManager::$plugin->apiKeys->attributionOptions(null));
    }

    public function testAttributionOptionsSnapshotsKeyFields(): void
    {
        $key = $this->seedKey(ApiKey::TYPE_SERVER);

        $this->assertSame(
            [
                'apiKeyId' => $key->id,
                'apiKeyPrefix' => $key->keyPrefix,
                'apiKeyType' => ApiKey::TYPE_SERVER,
            ],
            SearchManager::$plugin->apiKeys->attributionOptions($key),
        );
    }

    // ---- Real insert round-trip (covers /api/search + track-search) ---------

    public function testTrackSearchPersistsKeyAttribution(): void
    {
        $handle = $this->resolveAnalyticsIndexHandle();
        $this->swapStubRequest();
        $key = $this->seedKey(ApiKey::TYPE_PUBLIC);
        // 'source' set so trackSearch skips the web-only _detectSource() branch;
        // both controllers always pass a source in production.
        $options = array_merge(
            ['source' => 'test'],
            SearchManager::$plugin->apiKeys->attributionOptions($key),
        );

        SearchManager::$plugin->analytics->trackSearch(
            $handle,
            '__sm_attr_probe',
            1,
            1.0,
            'test',
            self::TEST_SITE_ID,
            $options,
            null,
        );

        $row = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['siteId' => self::TEST_SITE_ID, 'backend' => 'test'])
            ->one();

        $this->assertNotNull($row, 'trackSearch should write one row for the enabled analytics index.');
        $this->assertSame($key->id, (int) $row['apiKeyId']);
        $this->assertSame($key->keyPrefix, $row['apiKeyPrefix']);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $row['apiKeyType']);
    }

    public function testTrackSearchLeavesAttributionNullForAnonymous(): void
    {
        $handle = $this->resolveAnalyticsIndexHandle();
        $this->swapStubRequest();

        // No attribution keys — the anonymous / requireApiKey=false path. 'source'
        // set so trackSearch skips the web-only _detectSource() branch.
        SearchManager::$plugin->analytics->trackSearch(
            $handle,
            '__sm_attr_probe_anon',
            0,
            null,
            'test',
            self::TEST_SITE_ID,
            ['source' => 'test'],
            null,
        );

        $row = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['siteId' => self::TEST_SITE_ID, 'backend' => 'test'])
            ->one();

        $this->assertNotNull($row);
        $this->assertNull($row['apiKeyId']);
        $this->assertNull($row['apiKeyPrefix']);
        $this->assertNull($row['apiKeyType']);
    }

    public function testTrackSearchControllerPersistsAuthenticatedKeyAttribution(): void
    {
        $handle = $this->resolveAnalyticsIndexHandle();
        [$key, $plaintext] = $this->seedKeyWithPlaintext(ApiKey::TYPE_PUBLIC);
        $this->installTrackingRequest($plaintext, [
            'q' => '__sm_attr_controller',
            'indices' => $handle,
            'resultsCount' => '3',
            'trigger' => 'enter',
            'source' => 'test-widget',
            'siteId' => (string) $this->realTestSiteId(),
        ]);

        $originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        SearchManager::$plugin->getSettings()->requireApiKey = true;

        try {
            $controller = new SearchController('search', Craft::$app);
            $action = new Action('track-search', $controller);
            $this->assertTrue($controller->beforeAction($action));

            $response = $controller->actionTrackSearch();
        } finally {
            SearchManager::$plugin->getSettings()->requireApiKey = $originalRequireApiKey;
        }

        $this->assertSame(['success' => true, 'tracked' => true], $response->data);

        $row = (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where([
                'siteId' => $this->realTestSiteId(),
                'query' => '__sm_attr_controller',
                'source' => 'test-widget',
            ])
            // Belt-and-suspenders: prefer this run's freshly inserted row so a
            // lingering older marker row can never shadow the current key.
            ->orderBy(['id' => SORT_DESC])
            ->one();

        $this->assertNotNull($row, 'track-search controller should persist an analytics row.');
        $this->assertSame($key->id, (int) $row['apiKeyId']);
        $this->assertSame($key->keyPrefix, $row['apiKeyPrefix']);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $row['apiKeyType']);
    }

    public function testTrackClickControllerDoesNotPersistAnalyticsRow(): void
    {
        $handle = $this->resolveAnalyticsIndexHandle();
        [, $plaintext] = $this->seedKeyWithPlaintext(ApiKey::TYPE_PUBLIC);
        $this->installTrackingRequest($plaintext, [
            'elementId' => '12345',
            'query' => '__sm_attr_click',
            'index' => $handle,
            'position' => '1',
        ]);

        $originalRequireApiKey = SearchManager::$plugin->getSettings()->requireApiKey;
        SearchManager::$plugin->getSettings()->requireApiKey = true;

        try {
            $controller = new SearchController('search', Craft::$app);
            $action = new Action('track-click', $controller);
            $this->assertTrue($controller->beforeAction($action));

            $response = $controller->actionTrackClick();
        } finally {
            SearchManager::$plugin->getSettings()->requireApiKey = $originalRequireApiKey;
        }

        $this->assertSame(['success' => true], $response->data);
        $this->assertSame(
            0,
            (int) (new Query())
                ->from('{{%searchmanager_analytics}}')
                ->where(['query' => '__sm_attr_click'])
                ->count(),
            'track-click is still log-only and must not create a search analytics row.',
        );
    }

    // ---- getApiKeyBreakdown() — keyed-only, grouped, snapshot-durable -------

    public function testBreakdownExcludesAnonymousTraffic(): void
    {
        $this->seedRow(apiKeyId: 5, apiKeyPrefix: 'sm_pub_aaaa1111', apiKeyType: ApiKey::TYPE_PUBLIC);
        $this->seedRow(apiKeyId: null); // anonymous — must not appear

        $breakdown = SearchManager::$plugin->analytics->getApiKeyBreakdown(self::TEST_SITE_ID, 'last30days');

        $this->assertCount(1, $breakdown['data'], 'Only the keyed row should be counted.');
        $this->assertSame('sm_pub_aaaa1111', $breakdown['data'][0]['apiKeyPrefix']);
        $this->assertSame(1, $breakdown['data'][0]['count']);
        $this->assertSame(100.0, $breakdown['data'][0]['percentage']);
    }

    public function testBreakdownGroupsByKeyWithPercentages(): void
    {
        // Three actions for key A, one for key B (distinct null-session rows
        // each count as one action).
        $this->seedRow(apiKeyId: 1, apiKeyPrefix: 'sm_pub_keya0001', apiKeyType: ApiKey::TYPE_PUBLIC);
        $this->seedRow(apiKeyId: 1, apiKeyPrefix: 'sm_pub_keya0001', apiKeyType: ApiKey::TYPE_PUBLIC);
        $this->seedRow(apiKeyId: 1, apiKeyPrefix: 'sm_pub_keya0001', apiKeyType: ApiKey::TYPE_PUBLIC);
        $this->seedRow(apiKeyId: 2, apiKeyPrefix: 'sm_srv_keyb0002', apiKeyType: ApiKey::TYPE_SERVER);

        $breakdown = SearchManager::$plugin->analytics->getApiKeyBreakdown(self::TEST_SITE_ID, 'last30days');
        $byPrefix = [];
        foreach ($breakdown['data'] as $row) {
            $byPrefix[$row['apiKeyPrefix']] = $row;
        }

        $this->assertSame(3, $byPrefix['sm_pub_keya0001']['count']);
        $this->assertSame(75.0, $byPrefix['sm_pub_keya0001']['percentage']);
        $this->assertSame(1, $byPrefix['sm_srv_keyb0002']['count']);
        $this->assertSame(25.0, $byPrefix['sm_srv_keyb0002']['percentage']);
    }

    public function testBreakdownLabelsByPrefixSurviveWithoutLiveKey(): void
    {
        // apiKeyId 424242 matches no live key row — simulates a revoked/deleted
        // key. The prefix/type snapshots keep the row readable.
        $this->seedRow(apiKeyId: 424242, apiKeyPrefix: 'sm_pub_gone9999', apiKeyType: ApiKey::TYPE_PUBLIC);

        $breakdown = SearchManager::$plugin->analytics->getApiKeyBreakdown(self::TEST_SITE_ID, 'last30days');

        $this->assertCount(1, $breakdown['data']);
        $this->assertSame('sm_pub_gone9999', $breakdown['data'][0]['label']);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $breakdown['data'][0]['apiKeyType']);
    }

    // ---- Export columns -----------------------------------------------------

    public function testExportIncludesApiKeyColumns(): void
    {
        $this->seedRow(apiKeyId: 7, apiKeyPrefix: 'sm_pub_export01', apiKeyType: ApiKey::TYPE_PUBLIC);

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::TEST_SITE_ID, 'last30days');

        $this->assertContains('API Key', $export['headers']);
        $this->assertContains('API Key Type', $export['headers']);

        $row = $export['rows'][0];
        $this->assertSame('sm_pub_export01', $row['api_key']);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $row['api_key_type']);

        // JSON export mirrors the same snapshots.
        $jsonItem = $export['jsonData']['data'][0];
        $this->assertSame('sm_pub_export01', $jsonItem['apiKey']);
        $this->assertSame(ApiKey::TYPE_PUBLIC, $jsonItem['apiKeyType']);
    }

    public function testJsonExportPreservesZeroExecutionTime(): void
    {
        $this->seedRow(apiKeyId: null, executionTime: 0.0);

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::TEST_SITE_ID, 'last30days');

        $this->assertSame(0.0, $export['jsonData']['data'][0]['executionTime']);
    }

    public function testRecentSearchesExportUsesLogicalColumnOrder(): void
    {
        $this->seedRow(apiKeyId: 7, apiKeyPrefix: 'sm_pub_order01', apiKeyType: ApiKey::TYPE_PUBLIC);

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::TEST_SITE_ID, 'last30days');

        $expectedHeaders = [
            'Date',
            'Time',
            'Query',
            'Site',
            'Hits',
            'Synonyms',
            'Rules Matched',
            'Promotions',
            'Redirected',
            'Index',
            'Backend',
            'Intent',
            'Source',
            'Platform',
            'App Version',
            'Execution Time (ms)',
            'API Key',
            'API Key Type',
            'Referrer',
        ];
        $this->assertSame($expectedHeaders, array_slice($export['headers'], 0, count($expectedHeaders)));

        $expectedKeys = [
            'date',
            'time',
            'query',
            'site',
            'hits',
            'synonyms',
            'rules',
            'promotions',
            'redirected',
            'index',
            'backend',
            'intent',
            'source',
            'platform',
            'app_version',
            'execution_time_ms',
            'api_key',
            'api_key_type',
            'referrer',
        ];
        $this->assertSame($expectedKeys, array_slice(array_keys($export['rows'][0]), 0, count($expectedKeys)));
    }

    public function testExportIncludesDetectedLanguageAndTrafficMetadata(): void
    {
        foreach (['trafficType', 'isSystemAgent', 'botCategory', 'botProducerName'] as $column) {
            if (!$this->analyticsColumnExists($column)) {
                $this->markTestSkipped("The searchmanager_analytics.$column column is not installed in this test database.");
            }
        }

        $this->seedMetadataRow();

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::TEST_SITE_ID, 'last30days');

        foreach ([
            'Browser Engine',
            'Detected Language',
            'Traffic Type',
            'System Agent',
            'Bot Category',
            'Bot Producer',
        ] as $header) {
            $this->assertContains($header, $export['headers']);
        }

        $row = $export['rows'][0];
        $this->assertSame('Blink', $row['browser_engine']);
        $this->assertSame('en', $row['language']);
        $this->assertSame('system', $row['traffic_type']);
        $this->assertSame('Yes', $row['system_agent']);
        $this->assertSame('Yes', $row['is_bot']);
        $this->assertSame('Cache Manager', $row['bot_name']);
        $this->assertSame('Service Agent', $row['bot_category']);
        $this->assertSame('LindemannRock', $row['bot_producer']);

        $jsonItem = $export['jsonData']['data'][0];
        $this->assertSame('Blink', $jsonItem['browser']['engine']);
        $this->assertSame('en', $jsonItem['language']);
        $this->assertSame('en', $jsonItem['detectedLanguage']);
        $this->assertSame('system', $jsonItem['trafficType']);
        $this->assertTrue($jsonItem['isSystemAgent']);
        $this->assertTrue($jsonItem['isBot']);
        $this->assertSame('Cache Manager', $jsonItem['botName']);
        $this->assertSame('Service Agent', $jsonItem['botCategory']);
        $this->assertSame('LindemannRock', $jsonItem['botProducerName']);
        $this->assertSame([
            'name' => 'Cache Manager',
            'category' => 'Service Agent',
            'producer' => 'LindemannRock',
        ], $jsonItem['bot']);
    }

    // ---- Helpers ------------------------------------------------------------

    private function seedRow(
        ?int $apiKeyId,
        ?string $apiKeyPrefix = null,
        ?string $apiKeyType = null,
        float|null $executionTime = 1.0,
    ): void {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => 'attr-test',
            'resultsCount' => 1,
            'executionTime' => $executionTime,
            'backend' => 'test',
            'siteId' => self::TEST_SITE_ID,
            'sessionId' => null,
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'apiKeyId' => $apiKeyId,
            'apiKeyPrefix' => $apiKeyPrefix,
            'apiKeyType' => $apiKeyType,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function seedMetadataRow(): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'test-index',
            'query' => 'metadata-test',
            'resultsCount' => 1,
            'executionTime' => 12.3,
            'backend' => 'test',
            'siteId' => self::TEST_SITE_ID,
            'sessionId' => null,
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'deviceType' => 'desktop',
            'deviceBrand' => 'Apple',
            'deviceModel' => '',
            'osName' => 'Mac',
            'osVersion' => '10.15',
            'browser' => 'Chrome',
            'browserVersion' => '149',
            'browserEngine' => 'Blink',
            'language' => 'en',
            'isRobot' => 1,
            'botName' => 'Cache Manager',
            'botCategory' => 'Service Agent',
            'botProducerName' => 'LindemannRock',
            'isSystemAgent' => 1,
            'trafficType' => 'system',
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function analyticsColumnExists(string $column): bool
    {
        $schema = Craft::$app->getDb()->getTableSchema('{{%searchmanager_analytics}}');

        return $schema !== null && isset($schema->columns[$column]);
    }

    private function seedKey(string $type): ApiKey
    {
        return $this->seedKeyWithPlaintext($type)[0];
    }

    /**
     * @return array{0: ApiKey, 1: string}
     */
    private function seedKeyWithPlaintext(string $type): array
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey($type);
        $key = new ApiKey();
        $key->name = self::TEST_KEY_NAME_PREFIX . StringHelper::UUID();
        $key->type = $type;
        $key->keyHash = $generated['hash'];
        $key->keyPrefix = $generated['prefix'];
        $key->allowedIndices = [ApiKey::ALL_INDICES];
        $key->allowedReferrers = [];
        $this->assertTrue($key->save(), 'Seeded key save() must succeed: ' . implode(', ', $key->getFirstErrors()));

        return [$key, $generated['plaintext']];
    }

    /**
     * @param array<string,string> $params
     */
    private function installTrackingRequest(string $apiKey, array $params): void
    {
        Craft::$app->set('response', new \craft\web\Response());
        Craft::$app->set('request', new class($apiKey, $params) extends \craft\console\Request {
            private HeaderCollection $headers;

            /** @param array<string,string> $params */
            public function __construct(string $apiKey, private array $params)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
                $this->headers->set('X-Search-Manager-Key', $apiKey);
                $this->headers->set('Referer', 'https://example.com/search');
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function getParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getQueryParam($name, $defaultValue = null)
            {
                return $defaultValue;
            }

            public function getQueryParams(): array
            {
                return [];
            }

            public function getIsPost(): bool
            {
                return true;
            }

            public function getAcceptsJson(): bool
            {
                return true;
            }

            public function getIsOptions(): bool
            {
                return false;
            }

            public function getReferrer(): ?string
            {
                return $this->headers->get('Referer');
            }

            public function getUserAgent(): string
            {
                return 'SearchManagerApiKeyAttributionTest/1.0';
            }

            public function getUserIP(): string
            {
                return '127.0.0.1';
            }

            public function validateCsrfToken($clientSuppliedToken = null): bool
            {
                return true;
            }

            public function hasValidSiteToken(): bool
            {
                return false;
            }
        });
    }

    /**
     * Return the handle of an existing enabled, analytics-enabled index so the
     * real trackSearch() insert path runs. The seeded row is isolated by
     * TEST_SITE_ID + backend='test' regardless of which index it names. Skips
     * (rather than fails) on the rare install with no analytics index.
     */
    private function resolveAnalyticsIndexHandle(): string
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled && $index->enableAnalytics) {
                return $index->handle;
            }
        }

        $this->markTestSkipped('No enabled, analytics-enabled index available to exercise trackSearch().');
    }

    private function truncateAnalytics(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_analytics}}', [
                'or',
                ['and', ['siteId' => self::TEST_SITE_ID], ['backend' => 'test']],
                // Match every test-owned marker query (`__sm_attr_*`). Letting Yii
                // escape + wrap is required: the `, false` form built a fixed-length
                // `LIKE '__sm_attr_'` that never matched the longer real markers, so
                // controller rows on the real site survived cleanup and leaked.
                ['like', 'query', '__sm_attr_'],
            ])
            ->execute();
    }

    private function realTestSiteId(): int
    {
        return Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Swap in a web request so trackSearch()'s web-only reads (referer /
     * user-agent / IP / query params, plus device detection) resolve. The
     * console request the test harness boots with lacks these. A standalone
     * web Request returns null/empty for each in this context.
     */
    private function swapStubRequest(): void
    {
        Craft::$app->set('request', new \craft\web\Request());
    }

    private function purgeTestKeys(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::TEST_KEY_NAME_PREFIX . '%', false])
            ->execute();
    }
}
