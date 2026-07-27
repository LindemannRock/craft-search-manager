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
use craft\base\ElementInterface;
use craft\db\Query;
use craft\events\ElementEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\services\Elements;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\jobs\CacheWarmJob;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;
use yii\base\Action;
use yii\base\Event;
use yii\web\HeaderCollection;

/**
 * @since 5.54.0
 */
final class EditionDowngradeLifecycleTest extends TestCase
{
    private const PREFIX = '__sm_edition_lifecycle_';
    private const SEARCH_QUERY = self::PREFIX . 'search';
    private const SYNONYM_QUERY = self::PREFIX . 'synonym';
    private const COMMERCE_QUERY = self::PREFIX . 'commerce';
    private const STYLE_HANDLE = self::PREFIX . 'style';
    private const WIDGET_HANDLE = self::PREFIX . 'widget';
    private const PRESET_COLOR = '#13579b';
    private const ANALYTICS_SITE_ID = 999995;

    private SearchIndex $index;
    private ElementInterface $element;
    private int $searchSiteId;
    private bool $originalIndexAnalytics;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalTemplateMode = null;
    private ?EditionDowngradeLifecycleBackendService $searchService = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = $this->findWorkingIndexAndElement();
        if ($pair === null) {
            self::markTestSkipped('No enabled Entry index with a matching element is available for the downgrade lifecycle.');
        }

        [$this->index, $this->element] = $pair;
        $this->searchSiteId = (int) $this->element->siteId;
        $this->originalIndexAnalytics = $this->index->enableAnalytics;
        $this->index->enableAnalytics = true;
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();
        $this->purgeFixtures();
        Craft::$app->set('request', new \craft\web\Request());
    }

    protected function tearDown(): void
    {
        try {
            $this->searchService?->clearAllSearchCache();
            $this->purgeFixtures();
            if (isset($this->index)) {
                $this->index->enableAnalytics = $this->originalIndexAnalytics;
            }

            if ($this->originalRequest !== null) {
                Craft::$app->set('request', $this->originalRequest);
            }
            if ($this->originalResponse !== null) {
                Craft::$app->set('response', $this->originalResponse);
            }
            if ($this->originalTemplateMode !== null) {
                Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testProStandardProLifecyclePreservesDataAndNeutralizesEveryProSurface(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->configureProState();
        $this->seedFixtures();
        $commerceTarget = $this->seedCommercePromotionIfAvailable();
        $this->searchService = $this->createSearchService();
        $this->swapPluginComponent('search-manager', 'backend', $this->searchService);
        $this->searchService->clearAllSearchCache();

        $settings = SearchManager::$plugin->getSettings();
        $settings->autoIndex = true;
        Event::trigger(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            new ElementEvent(['element' => $this->element]),
        );
        self::assertNotNull($this->fetchPendingRow(
            $this->index->handle,
            (int) $this->element->id,
            (int) $this->element->siteId,
        ));

        $options = [
            'limit' => 10,
            'siteId' => $this->searchSiteId,
            'skipAnalytics' => true,
            'editionLifecycleNonce' => StringHelper::UUID(),
        ];
        $proFresh = $this->searchService->search($this->index->handle, self::SEARCH_QUERY, $options);
        self::assertFalse($proFresh['meta']['cached']);
        self::assertTrue($proFresh['meta']['synonymsExpanded']);
        self::assertSame(303, $proFresh['hits'][0]['elementId']);
        self::assertTrue($proFresh['hits'][0]['promoted']);
        self::assertSame(101, $proFresh['hits'][1]['elementId']);
        self::assertTrue($proFresh['hits'][1]['boosted']);
        $this->trackLifecycleSearch();
        self::assertGreaterThan(0, $this->analyticsCount('{{%searchmanager_analytics}}'));
        self::assertGreaterThan(0, $this->analyticsCount('{{%searchmanager_rule_analytics}}'));
        self::assertGreaterThan(0, $this->analyticsCount('{{%searchmanager_promotion_analytics}}'));
        $ruleId = (int)(new Query())
            ->select('queryRuleId')
            ->from('{{%searchmanager_rule_analytics}}')
            ->where(['query' => self::SEARCH_QUERY])
            ->scalar();
        $promotionId = (int)(new Query())
            ->select('promotionId')
            ->from('{{%searchmanager_promotion_analytics}}')
            ->where(['query' => self::SEARCH_QUERY])
            ->scalar();
        $twigVariable = new SearchManagerVariable();
        $proRuleAnalytics = $twigVariable->getRuleAnalytics($ruleId, 'today');
        $proPromotionAnalytics = $twigVariable->getPromotionAnalytics($promotionId, 'today');
        self::assertGreaterThan(0, $proRuleAnalytics['totalTriggers']);
        self::assertGreaterThan(0, $proPromotionAnalytics['totalImpressions']);
        self::assertNotSame([], $proRuleAnalytics['dailyTriggers']);
        self::assertNotSame([], $proPromotionAnalytics['dailyImpressions']);

        $proWidget = $this->renderWidget();
        self::assertStringContainsString('promotion-display="badge"', $proWidget);
        self::assertStringContainsString('analytics-source="lifecycle-widget"', $proWidget);
        self::assertStringContainsString(self::PRESET_COLOR, html_entity_decode($proWidget, ENT_QUOTES | ENT_HTML5));
        $this->assertCommercePromotionState($commerceTarget, true);

        $storedCounts = $this->configurationCounts();
        $analyticsBeforeDowngrade = $this->analyticsCounts();

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardFresh = $this->searchService->search($this->index->handle, self::SEARCH_QUERY, $options);
        $standardCached = $this->searchService->search($this->index->handle, self::SEARCH_QUERY, $options);

        self::assertFalse($standardFresh['meta']['cached']);
        self::assertTrue($standardCached['meta']['cached']);
        self::assertSame([101, 202], array_column($standardFresh['hits'], 'elementId'));
        self::assertSame([101, 202], array_column($standardCached['hits'], 'elementId'));
        self::assertFalse($standardCached['meta']['synonymsExpanded']);
        self::assertSame([], $standardCached['meta']['rulesMatched']);
        self::assertSame([], $standardCached['meta']['promotionsMatched']);
        $this->trackLifecycleSearch();
        self::assertSame($analyticsBeforeDowngrade, $this->analyticsCounts());
        self::assertSame([
            'totalTriggers' => 0,
            'uniqueQueries' => 0,
            'avgResultsAfter' => 0.0,
            'topQueries' => [],
            'dailyTriggers' => [],
            'recentTriggers' => [],
        ], $twigVariable->getRuleAnalytics($ruleId, 'today'));
        self::assertSame([
            'totalImpressions' => 0,
            'uniqueQueries' => 0,
            'avgPosition' => 0.0,
            'topQueries' => [],
            'dailyImpressions' => [],
            'recentImpressions' => [],
        ], $twigVariable->getPromotionAnalytics($promotionId, 'today'));
        self::assertSame($analyticsBeforeDowngrade, $this->analyticsCounts());

        $standardWidget = $this->renderWidget();
        self::assertStringNotContainsString('promotion-display=', $standardWidget);
        self::assertStringNotContainsString('promotion-badge-text=', $standardWidget);
        self::assertStringNotContainsString('promotion-badge-position=', $standardWidget);
        self::assertStringNotContainsString('analytics-source=', $standardWidget);
        self::assertStringNotContainsString('analytics-idle-timeout-ms=', $standardWidget);
        self::assertStringNotContainsString(self::PRESET_COLOR, html_entity_decode($standardWidget, ENT_QUOTES | ENT_HTML5));
        $this->assertCommercePromotionState($commerceTarget, false);

        $this->installTrackingRequest();
        $trackingController = new SearchController('search', SearchManager::$plugin);
        self::assertTrue($trackingController->beforeAction(new Action('track-search', $trackingController)));
        $trackSearchResponse = $trackingController->actionTrackSearch();
        self::assertSame(204, $trackSearchResponse->getStatusCode());
        self::assertNull($trackSearchResponse->data);

        Craft::$app->set('response', new Response());
        self::assertTrue($trackingController->beforeAction(new Action('track-click', $trackingController)));
        $trackClickResponse = $trackingController->actionTrackClick();
        self::assertSame(204, $trackClickResponse->getStatusCode());
        self::assertNull($trackClickResponse->data);
        self::assertSame($analyticsBeforeDowngrade, $this->analyticsCounts());

        (new BatchSyncJob())->execute(Craft::$app->queue);
        self::assertNotSame([], $this->searchService->callsFor('batchIndex'));
        self::assertNull($this->fetchPendingRow(
            $this->index->handle,
            (int) $this->element->id,
            (int) $this->element->siteId,
        ));

        $searchCallsBeforeStandardWarm = count($this->searchService->callsFor('search'));
        (new CacheWarmJob(['indexHandle' => $this->index->handle]))->execute(Craft::$app->queue);
        self::assertCount($searchCallsBeforeStandardWarm, $this->searchService->callsFor('search'));

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        foreach (['Analytics', 'Query Rules', 'Promotions', 'Pending Syncs', 'Widget Styles'] as $feature) {
            $prompt = Craft::$app->getView()->renderTemplate(
                'lindemannrock-base/_partials/edition-upgrade-prompt',
                [
                    'plugin' => SearchManager::$plugin,
                    'edition' => SearchManager::EDITION_PRO,
                    'featureName' => $feature,
                    'pitch' => Craft::t('search-manager', 'Search Manager Pro adds analytics, query rules, promotions, pending-sync operations, and reusable widget style presets.'),
                ],
                View::TEMPLATE_MODE_CP,
            );
            self::assertStringContainsString("{$feature} requires Search Manager Pro", $prompt);
            self::assertStringContainsString('plugin-store/search-manager', $prompt);
        }
        foreach (['analytics', 'query-rules', 'promotions', 'pending-syncs'] as $section) {
            self::assertFalse($this->cpSection($section)['when']);
        }

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::ANALYTICS_SITE_ID, 'all');
        self::assertContains(self::SEARCH_QUERY, array_column($export['jsonData']['data'], 'query'));
        self::assertGreaterThan(0, SearchManager::$plugin->analytics->clearAnalytics(self::ANALYTICS_SITE_ID));
        self::assertSame([0, 0, 0], $this->analyticsCounts());
        self::assertSame($storedCounts, $this->configurationCounts());

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proRestored = $this->searchService->search($this->index->handle, self::SEARCH_QUERY, $options);
        self::assertTrue($proRestored['meta']['cached']);
        self::assertTrue($proRestored['meta']['synonymsExpanded']);
        self::assertSame(303, $proRestored['hits'][0]['elementId']);
        self::assertTrue($proRestored['hits'][0]['promoted']);
        self::assertSame(101, $proRestored['hits'][1]['elementId']);
        self::assertTrue($proRestored['hits'][1]['boosted']);
        $this->trackLifecycleSearch();
        self::assertGreaterThan(0, $this->analyticsCount('{{%searchmanager_analytics}}'));

        $restoredWidget = $this->renderWidget();
        self::assertStringContainsString('promotion-display="badge"', $restoredWidget);
        self::assertStringContainsString('analytics-source="lifecycle-widget"', $restoredWidget);
        self::assertStringContainsString(self::PRESET_COLOR, html_entity_decode($restoredWidget, ENT_QUOTES | ENT_HTML5));
        $this->assertCommercePromotionState($commerceTarget, true);
        self::assertSame($storedCounts, $this->configurationCounts());

        foreach (['Analytics', 'Query Rules', 'Promotions', 'Pending Syncs', 'Widget Styles'] as $feature) {
            self::assertNull(SearchManager::$plugin->requireEditionOrPrompt(SearchManager::EDITION_PRO, $feature));
        }

        $searchCallsBeforeProWarm = count($this->searchService->callsFor('search'));
        (new CacheWarmJob(['indexHandle' => $this->index->handle]))->execute(Craft::$app->queue);
        self::assertGreaterThan($searchCallsBeforeProWarm, count($this->searchService->callsFor('search')));
    }

    private function configureProState(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = true;
        $settings->enableAnalytics = true;
        $settings->enableGeoDetection = false;
        $settings->enableCacheWarming = true;
        $settings->enableAutocompleteCache = false;
        $settings->cacheWarmingQueryCount = 50;
    }

    private function seedFixtures(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        foreach ([
            [
                'name' => self::PREFIX . 'synonym-rule',
                'actionType' => QueryRule::ACTION_SYNONYM,
                'actionValue' => ['terms' => [self::SYNONYM_QUERY]],
                'priority' => 10,
            ],
            [
                'name' => self::PREFIX . 'boost-rule',
                'actionType' => QueryRule::ACTION_BOOST_ELEMENT,
                'actionValue' => ['elementId' => 101, 'multiplier' => 10],
                'priority' => 9,
            ],
        ] as $rule) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
                'name' => $rule['name'],
                'indexHandle' => $this->index->handle,
                'matchType' => QueryRule::MATCH_EXACT,
                'matchValue' => self::SEARCH_QUERY,
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
            'indexHandle' => $this->index->handle,
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

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => self::STYLE_HANDLE,
            'name' => 'Edition Lifecycle Style',
            'type' => 'modal',
            'styles' => json_encode(['modalBg' => self::PRESET_COLOR], JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $widgetSettings = WidgetConfig::defaultSettings();
        $widgetSettings['behavior']['promotionDisplay'] = 'badge';
        $widgetSettings['behavior']['promotionBadgeText'] = 'Sponsored';
        $widgetSettings['behavior']['promotionBadgePosition'] = 'above';
        $widgetSettings['analytics']['analyticsSource'] = 'lifecycle-widget';
        $widgetSettings['analytics']['analyticsIdleTimeoutMs'] = 2750;

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::WIDGET_HANDLE,
            'name' => 'Edition Lifecycle Widget',
            'type' => 'modal',
            'styleHandle' => self::STYLE_HANDLE,
            'settings' => json_encode($widgetSettings, JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    /**
     * @return array{elementType: string, elementId: int, siteId: int}|null
     */
    private function seedCommercePromotionIfAvailable(): ?array
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $element = $elementType::find()->status(null)->one();
            if (!$element instanceof ElementInterface || $element->siteId === null) {
                continue;
            }

            $target = [
                'elementType' => $elementType,
                'elementId' => (int) $element->id,
                'siteId' => (int) $element->siteId,
            ];
            $now = Db::prepareDateForDb(new \DateTimeImmutable());
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
                'indexHandle' => $this->index->handle,
                'title' => self::PREFIX . 'commerce-promotion',
                'query' => self::COMMERCE_QUERY,
                'matchType' => 'exact',
                'elementId' => $target['elementId'],
                'elementType' => $target['elementType'],
                'position' => 1,
                'siteId' => $target['siteId'],
                'enabled' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return $target;
        }

        self::addToAssertionCount(1);

        return null;
    }

    /**
     * @param array{elementType: string, elementId: int, siteId: int}|null $target
     */
    private function assertCommercePromotionState(?array $target, bool $active): void
    {
        if ($target === null) {
            self::addToAssertionCount(1);
            return;
        }

        $matches = Promotion::findMatching(self::COMMERCE_QUERY, $this->index->handle, $target['siteId']);
        if (!$active) {
            self::assertSame([], $matches);
            return;
        }

        self::assertCount(1, $matches);
        self::assertSame($target['elementId'], $matches[0]->elementId);
        self::assertSame($target['elementType'], $matches[0]->elementType);
    }

    private function createSearchService(): EditionDowngradeLifecycleBackendService
    {
        $backend = $this->createMock(BackendInterface::class);
        $backend->method('getName')->willReturn('edition-lifecycle');
        $backend->method('search')->willReturnCallback(
            fn(string $indexName, string $query, array $options = []): array => $this->backendSearchResponse($query),
        );
        $backend->method('getDocumentsByElementIds')->willReturnCallback(
            fn(string $indexName, array $elementIds, ?int $siteId = null): array => $this->promotionDocuments($elementIds, $siteId),
        );

        return new EditionDowngradeLifecycleBackendService($backend);
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
                    'siteId' => $this->searchSiteId,
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
                    'siteId' => $this->searchSiteId,
                    'title' => 'Boost target',
                    'url' => '/boost-target',
                    'type' => 'entry',
                    'score' => 1.0,
                ],
                [
                    'id' => 202,
                    'elementId' => 202,
                    'siteId' => $this->searchSiteId,
                    'title' => 'Organic result',
                    'url' => '/organic-result',
                    'type' => 'entry',
                    'score' => 2.0,
                ],
            ],
            'total' => 2,
        ];
    }

    /**
     * @param list<int> $elementIds
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
                'siteId' => $siteId ?? $this->searchSiteId,
                'title' => 'Promoted result',
                'url' => '/promoted-result',
                'type' => 'entry',
            ],
        ];
    }

    private function renderWidget(): string
    {
        return Craft::$app->getView()->renderTemplate('search-manager/_widget/search-modal', [
            'configHandle' => self::WIDGET_HANDLE,
        ]);
    }

    private function trackLifecycleSearch(): void
    {
        $matchedRules = QueryRule::findMatching(self::SEARCH_QUERY, $this->index->handle, self::ANALYTICS_SITE_ID);
        $matchedPromotions = Promotion::findMatching(self::SEARCH_QUERY, $this->index->handle, self::ANALYTICS_SITE_ID);

        SearchManager::$plugin->analytics->trackSearch(
            $this->index->handle,
            self::SEARCH_QUERY,
            3,
            1.0,
            'edition-lifecycle',
            self::ANALYTICS_SITE_ID,
            [
                'source' => 'edition-lifecycle',
                'synonymsExpanded' => $matchedRules !== [],
                'rulesMatched' => count($matchedRules),
                'promotionsShown' => count($matchedPromotions),
                'matchedRules' => $matchedRules,
                'matchedPromotions' => $matchedPromotions,
            ],
        );
    }

    private function installTrackingRequest(): void
    {
        $params = [
            'q' => self::SEARCH_QUERY,
            'query' => self::SEARCH_QUERY,
            'indexHandles' => $this->index->handle,
            'index' => $this->index->handle,
            'resultsCount' => 2,
            'elementId' => 303,
            'siteId' => self::ANALYTICS_SITE_ID,
        ];

        Craft::$app->set('response', new Response());
        Craft::$app->set('request', new class($params) extends \craft\console\Request {
            private HeaderCollection $headers;

            /** @param array<string, mixed> $params */
            public function __construct(private array $params)
            {
                parent::__construct();
                $this->headers = new HeaderCollection();
            }

            public function getHeaders(): HeaderCollection
            {
                return $this->headers;
            }

            public function getParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getBodyParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getQueryParam($name, $defaultValue = null)
            {
                return $this->params[$name] ?? $defaultValue;
            }

            public function getIsPost(): bool
            {
                return true;
            }

            public function getAcceptsJson(): bool
            {
                return true;
            }

            public function getReferrer(): ?string
            {
                return null;
            }

            public function getUserAgent(): ?string
            {
                return 'Edition lifecycle test';
            }

            public function getUserIP(): ?string
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
     * @return array{rules: int, promotions: int, styles: int, widgets: int}
     */
    private function configurationCounts(): array
    {
        return [
            'rules' => $this->fixtureCount('{{%searchmanager_query_rules}}', 'name'),
            'promotions' => $this->fixtureCount('{{%searchmanager_promotions}}', 'title'),
            'styles' => $this->fixtureCount('{{%searchmanager_widget_styles}}', 'handle'),
            'widgets' => $this->fixtureCount('{{%searchmanager_widget_configs}}', 'handle'),
        ];
    }

    private function fixtureCount(string $table, string $column): int
    {
        return (int) (new Query())
            ->from($table)
            ->where(['like', $column, self::PREFIX . '%', false])
            ->count();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function analyticsCounts(): array
    {
        return [
            $this->analyticsCount('{{%searchmanager_analytics}}'),
            $this->analyticsCount('{{%searchmanager_rule_analytics}}'),
            $this->analyticsCount('{{%searchmanager_promotion_analytics}}'),
        ];
    }

    private function analyticsCount(string $table): int
    {
        return (int) (new Query())
            ->from($table)
            ->where(['like', 'query', self::PREFIX . '%', false])
            ->count();
    }

    private function purgeFixtures(): void
    {
        foreach (['{{%searchmanager_rule_analytics}}', '{{%searchmanager_promotion_analytics}}', '{{%searchmanager_analytics}}'] as $table) {
            Craft::$app->getDb()->createCommand()
                ->delete($table, ['like', 'query', self::PREFIX . '%', false])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'name', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'title', self::PREFIX . '%', false])
            ->execute();
    }
}

/**
 * @since 5.54.0
 */
final class EditionDowngradeLifecycleBackendService extends BackendService
{
    /** @var list<array{method: string, indexName: string}> */
    private array $calls = [];

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

    /**
     * @param list<array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->calls[] = ['method' => 'batchIndex', 'indexName' => $indexName];

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->calls[] = ['method' => 'search', 'indexName' => $indexName];

        return parent::search($indexName, $query, $options);
    }

    /**
     * @return list<array{method: string, indexName: string}>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn(array $call): bool => $call['method'] === $method,
        ));
    }
}
