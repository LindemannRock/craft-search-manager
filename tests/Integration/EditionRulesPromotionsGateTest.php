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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
use yii\base\Action;

/**
 * @since 5.54.0
 */
final class EditionRulesPromotionsGateTest extends TestCase
{
    private const PREFIX = '__sm_edition_rules_';
    private const SEARCH_QUERY = self::PREFIX . 'search';
    private const SYNONYM_QUERY = self::PREFIX . 'synonym';
    private const REDIRECT_QUERY = self::PREFIX . 'redirect';
    private const SITE_ID = 1;

    private string $indexHandle;
    private bool $originalEnableCache;
    private bool $originalEnableAnalytics;
    private bool $originalIndexAnalytics;
    private ?object $originalRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $index = $this->firstEnabledIndex();
        if ($index === null) {
            self::markTestSkipped('No enabled search index is available for edition gate coverage.');
        }

        $this->indexHandle = $index->handle;
        $this->originalIndexAnalytics = $index->enableAnalytics;
        $this->purgeFixtures();
        $this->purgeAnalyticsRows();
        $this->originalRequest = Craft::$app->getRequest();
        Craft::$app->set('request', new \craft\web\Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = $settings->enableCache;
        $this->originalEnableAnalytics = $settings->enableAnalytics;
        $settings->enableCache = true;
        $settings->enableAnalytics = false;

        $backend = $this->createMock(BackendInterface::class);
        $backend->method('getName')->willReturn('edition-pipeline');
        $backend->method('search')->willReturnCallback(
            fn(string $indexName, string $query, array $options = []): array => $this->backendSearchResponse($query),
        );
        $backend->method('getDocumentsByElementIds')->willReturnCallback(
            fn(string $indexName, array $elementIds, ?int $siteId = null): array => $this->promotionDocuments($elementIds, $siteId),
        );

        $this->swapPluginComponent(
            'search-manager',
            'backend',
            new EditionPipelineBackendService($backend),
        );
        SearchManager::$plugin->backend->clearAllSearchCache();

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        try {
            SearchManager::$plugin->backend->clearAllSearchCache();
            SearchManager::$plugin->getSettings()->enableCache = $this->originalEnableCache;
            SearchManager::$plugin->getSettings()->enableAnalytics = $this->originalEnableAnalytics;
            Craft::$app->getDb()->createCommand()->update(
                '{{%searchmanager_indices}}',
                ['enableAnalytics' => (int)$this->originalIndexAnalytics],
                ['handle' => $this->indexHandle],
            )->execute();
            SearchIndex::clearCache();
            $this->purgeFixtures();
            $this->purgeAnalyticsRows();
            if ($this->originalRequest !== null) {
                Craft::$app->set('request', $this->originalRequest);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFullSearchPathIsInertInStandardAndRestoresOnReupgrade(): void
    {
        $options = [
            'limit' => 10,
            'siteId' => self::SITE_ID,
            'skipAnalytics' => true,
            'editionTestNonce' => StringHelper::UUID(),
        ];

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        SearchManager::$plugin->backend->clearAllSearchCache();
        $proFresh = SearchManager::$plugin->backend->search($this->indexHandle, self::SEARCH_QUERY, $options);

        self::assertFalse($proFresh['meta']['cached']);
        self::assertTrue($proFresh['meta']['synonymsExpanded']);
        self::assertCount(2, $proFresh['meta']['rulesMatched']);
        self::assertCount(1, $proFresh['meta']['promotionsMatched']);
        self::assertSame(303, $proFresh['hits'][0]['elementId']);
        self::assertTrue($proFresh['hits'][0]['promoted']);
        self::assertSame(101, $proFresh['hits'][1]['elementId']);
        self::assertTrue($proFresh['hits'][1]['boosted']);

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardFresh = SearchManager::$plugin->backend->search($this->indexHandle, self::SEARCH_QUERY, $options);
        $standardCached = SearchManager::$plugin->backend->search($this->indexHandle, self::SEARCH_QUERY, $options);

        self::assertFalse($standardFresh['meta']['cached']);
        self::assertTrue($standardCached['meta']['cached']);
        self::assertFalse($standardCached['meta']['synonymsExpanded']);
        self::assertSame([], $standardCached['meta']['rulesMatched']);
        self::assertSame([], $standardCached['meta']['promotionsMatched']);
        self::assertSame([101, 202], array_column($standardCached['hits'], 'elementId'));
        foreach ($standardCached['hits'] as $hit) {
            self::assertArrayNotHasKey('promoted', $hit);
            self::assertArrayNotHasKey('boosted', $hit);
        }

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proRestored = SearchManager::$plugin->backend->search($this->indexHandle, self::SEARCH_QUERY, $options);

        self::assertTrue($proRestored['meta']['cached']);
        self::assertTrue($proRestored['meta']['synonymsExpanded']);
        self::assertSame(303, $proRestored['hits'][0]['elementId']);
        self::assertTrue($proRestored['hits'][0]['promoted']);
        self::assertSame(101, $proRestored['hits'][1]['elementId']);
        self::assertTrue($proRestored['hits'][1]['boosted']);
    }

    public function testRedirectAndSearchMultipleFollowTheModelWall(): void
    {
        $options = ['siteId' => self::SITE_ID, 'skipAnalytics' => true];

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardSingle = SearchManager::$plugin->backend->search($this->indexHandle, self::REDIRECT_QUERY, $options);
        $standardMultiple = SearchManager::$plugin->backend->searchMultiple([$this->indexHandle], self::REDIRECT_QUERY, $options);

        self::assertArrayNotHasKey('redirect', $standardSingle);
        self::assertArrayNotHasKey('redirect', $standardMultiple);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proSingle = SearchManager::$plugin->backend->search($this->indexHandle, self::REDIRECT_QUERY, $options);
        $proMultiple = SearchManager::$plugin->backend->searchMultiple([$this->indexHandle], self::REDIRECT_QUERY, $options);

        self::assertSame('/edition-pro-destination', $proSingle['redirect']);
        self::assertSame('/edition-pro-destination', $proMultiple['redirect']);
    }

    public function testGraphqlAndTwigSearchUseTheEditionGatedBackendPath(): void
    {
        $resolveInfo = $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
        $arguments = [
            'query' => self::SEARCH_QUERY,
            'indexHandles' => [$this->indexHandle],
            'siteId' => self::SITE_ID,
            'resultsLimit' => 10,
            'skipAnalytics' => true,
        ];
        $twig = new SearchManagerVariable();

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardGraphql = SearchResolver::resolveSearch(null, $arguments, null, $resolveInfo);
        $standardTwig = $twig->search($this->indexHandle, self::SEARCH_QUERY, [
            'raw' => true,
            'siteId' => self::SITE_ID,
            'skipAnalytics' => true,
        ]);

        self::assertSame([101, 202], array_column($standardGraphql['hits'], 'elementId'));
        self::assertSame([101, 202], array_column($standardTwig['hits'], 'elementId'));

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proGraphql = SearchResolver::resolveSearch(null, $arguments, null, $resolveInfo);
        $proTwig = $twig->search($this->indexHandle, self::SEARCH_QUERY, [
            'raw' => true,
            'siteId' => self::SITE_ID,
            'skipAnalytics' => true,
        ]);

        self::assertSame(303, $proGraphql['hits'][0]['elementId']);
        self::assertTrue($proGraphql['hits'][0]['promoted']);
        self::assertSame(303, $proTwig['hits'][0]['elementId']);
        self::assertTrue($proTwig['hits'][0]['promoted']);
    }

    public function testCacheIdentityIncludesTheActiveEdition(): void
    {
        $method = new \ReflectionMethod(BackendService::class, '_generateCacheKey');
        $method->setAccessible(true);
        $options = ['limit' => 10, 'siteId' => self::SITE_ID];

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardKey = $method->invoke(SearchManager::$plugin->backend, $this->indexHandle, self::SEARCH_QUERY, $options);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proKey = $method->invoke(SearchManager::$plugin->backend, $this->indexHandle, self::SEARCH_QUERY, $options);

        self::assertNotSame($standardKey, $proKey);
    }

    public function testCpNavigationControllersAndTestToolsRespectTheEdition(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        self::assertFalse($this->cpSection('query-rules')['when']);
        self::assertFalse($this->cpSection('promotions')['when']);

        foreach ([
            new QueryRulesController('query-rules', SearchManager::$plugin),
            new PromotionsController('promotions', SearchManager::$plugin),
        ] as $controller) {
            $this->assertForbidden(static fn() => $controller->beforeAction(new Action('index', $controller)));
        }

        $settingsController = new SettingsController('settings', SearchManager::$plugin);
        $this->assertForbidden(static fn() => $settingsController->actionTestQueryRules());
        $this->assertForbidden(static fn() => $settingsController->actionTestPromotions());

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $hasBackends = ConfiguredBackend::findAllEnabled() !== [];
        self::assertSame($hasBackends, $this->cpSection('query-rules')['when']);
        self::assertSame($hasBackends, $this->cpSection('promotions')['when']);
    }

    public function testSearchTestPageHidesProSurfacesInStandardAndShowsThemInPro(): void
    {
        $proSurfaceIds = [
            'id="showPromotions"',
            'id="showQueryRules"',
            'id="promotions-section"',
            'id="queryrules-section"',
        ];

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardHtml = $this->renderSearchTestPartial();

        foreach ($proSurfaceIds as $surfaceId) {
            self::assertStringNotContainsString($surfaceId, $standardHtml);
        }

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proHtml = $this->renderSearchTestPartial();

        foreach ($proSurfaceIds as $surfaceId) {
            self::assertStringContainsString($surfaceId, $proHtml);
        }
    }

    public function testMultiIndexRedirectHonorsSkipAnalyticsInPro(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        SearchManager::$plugin->getSettings()->enableAnalytics = true;
        $this->purgeAnalyticsRows();

        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_indices}}',
            ['enableAnalytics' => 1],
            ['handle' => $this->indexHandle],
        )->execute();
        SearchIndex::clearCache();

        $withOptOut = SearchManager::$plugin->backend->searchMultiple(
            [$this->indexHandle],
            self::REDIRECT_QUERY,
            ['siteId' => self::SITE_ID, 'skipAnalytics' => true],
        );

        self::assertSame('/edition-pro-destination', $withOptOut['redirect']);
        self::assertSame(0, $this->analyticsRowCount());

        $withoutOptOut = SearchManager::$plugin->backend->searchMultiple(
            [$this->indexHandle],
            self::REDIRECT_QUERY,
            ['siteId' => self::SITE_ID],
        );

        self::assertSame('/edition-pro-destination', $withoutOptOut['redirect']);
        self::assertSame(1, $this->analyticsRowCount());
    }

    public function testCrudReadsStayAvailableWhileWritesThrowInStandard(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $rule = SearchManager::$plugin->queryRules->getAll($this->indexHandle)[0] ?? null;
        $promotion = SearchManager::$plugin->promotions->getAll($this->indexHandle)[0] ?? null;

        self::assertInstanceOf(QueryRule::class, $rule);
        self::assertInstanceOf(Promotion::class, $promotion);
        $this->assertForbidden(static fn() => SearchManager::$plugin->queryRules->save(new QueryRule()));
        $this->assertForbidden(static fn() => SearchManager::$plugin->queryRules->delete($rule));
        $this->assertForbidden(static fn() => SearchManager::$plugin->queryRules->deleteById((int)$rule->id));
        $this->assertForbidden(static fn() => SearchManager::$plugin->promotions->save(new Promotion()));
        $this->assertForbidden(static fn() => SearchManager::$plugin->promotions->delete($promotion));
        $this->assertForbidden(static fn() => SearchManager::$plugin->promotions->deleteById((int)$promotion->id));

        self::assertNotNull(QueryRule::findById((int)$rule->id));
        self::assertNotNull(Promotion::findById((int)$promotion->id));

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        self::assertTrue(SearchManager::$plugin->queryRules->delete($rule));
        self::assertTrue(SearchManager::$plugin->promotions->delete($promotion));
    }

    private function firstEnabledIndex(): ?SearchIndex
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled) {
                return $index;
            }
        }

        return null;
    }

    private function renderSearchTestPartial(): string
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalResponse = Craft::$app->getResponse();
        Craft::$app->set('response', new Response());

        try {
            return Craft::$app->getView()->renderTemplate(
                'search-manager/settings/test/_partials/search',
                [
                    'settings' => $settings,
                    'cacheEnabled' => $settings->enableCache,
                    'snippetOptions' => SnippetOptionsHelper::widgetDefaults(),
                ],
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            Craft::$app->set('response', $originalResponse);
        }
    }

    private function analyticsRowCount(): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['like', 'query', self::PREFIX . '%', false])
            ->count();
    }

    private function purgeAnalyticsRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['like', 'query', self::PREFIX . '%', false])
            ->execute();
    }

    private function seedFixtures(): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $rules = [
            [
                'name' => self::PREFIX . 'synonym-rule',
                'matchValue' => self::SEARCH_QUERY,
                'actionType' => QueryRule::ACTION_SYNONYM,
                'actionValue' => ['terms' => [self::SYNONYM_QUERY]],
                'priority' => 10,
            ],
            [
                'name' => self::PREFIX . 'boost-rule',
                'matchValue' => self::SEARCH_QUERY,
                'actionType' => QueryRule::ACTION_BOOST_ELEMENT,
                'actionValue' => ['elementId' => 101, 'multiplier' => 10],
                'priority' => 9,
            ],
            [
                'name' => self::PREFIX . 'redirect-rule',
                'matchValue' => self::REDIRECT_QUERY,
                'actionType' => QueryRule::ACTION_REDIRECT,
                'actionValue' => ['url' => '/edition-pro-destination'],
                'priority' => 8,
            ],
        ];

        foreach ($rules as $rule) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
                'name' => $rule['name'],
                'indexHandle' => $this->indexHandle,
                'matchType' => QueryRule::MATCH_EXACT,
                'matchValue' => $rule['matchValue'],
                'actionType' => $rule['actionType'],
                'actionValue' => json_encode($rule['actionValue'], JSON_THROW_ON_ERROR),
                'priority' => $rule['priority'],
                'siteId' => null,
                'enabled' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => $this->indexHandle,
            'title' => self::PREFIX . 'promotion',
            'query' => self::SEARCH_QUERY,
            'matchType' => 'exact',
            'elementId' => 303,
            'elementType' => null,
            'position' => 1,
            'siteId' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function purgeFixtures(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'name', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'title', self::PREFIX . '%', false])
            ->execute();
    }

    /**
     * @return array{hits: list<array<string, mixed>>, total: int}
     */
    private function backendSearchResponse(string $query): array
    {
        if ($query === self::SYNONYM_QUERY) {
            return [
                'hits' => [[
                    'id' => 303,
                    'elementId' => 303,
                    'siteId' => self::SITE_ID,
                    'title' => 'Synonym result',
                    'url' => '/synonym-result',
                    'type' => 'entry',
                    'score' => 0.5,
                ]],
                'total' => 1,
            ];
        }

        return [
            'hits' => [
                [
                    'id' => 101,
                    'elementId' => 101,
                    'siteId' => self::SITE_ID,
                    'title' => 'Boost target',
                    'url' => '/boost-target',
                    'type' => 'entry',
                    'score' => 1.0,
                ],
                [
                    'id' => 202,
                    'elementId' => 202,
                    'siteId' => self::SITE_ID,
                    'title' => 'Organic leader',
                    'url' => '/organic-leader',
                    'type' => 'entry',
                    'score' => 2.0,
                ],
            ],
            'total' => 2,
        ];
    }

    /**
     * @param array<int, int> $elementIds
     * @return array<int, array<string, mixed>>
     */
    private function promotionDocuments(array $elementIds, ?int $siteId): array
    {
        if (!in_array(303, $elementIds, true)) {
            return [];
        }

        return [
            303 => [
                'id' => 303,
                'elementId' => 303,
                'siteId' => $siteId ?? self::SITE_ID,
                'title' => 'Promoted result',
                'url' => '/promoted-result',
                'type' => 'entry',
            ],
        ];
    }
}

/**
 * @since 5.54.0
 */
final class EditionPipelineBackendService extends BackendService
{
    public function __construct(private readonly BackendInterface $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->backend;
    }
}
