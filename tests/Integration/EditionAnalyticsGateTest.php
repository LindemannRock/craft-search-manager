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
use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\services\analytics\AnalyticsRulesService;
use lindemannrock\searchmanager\services\analytics\AnalyticsTrackingService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\searchmanager\widgets\ContentGapsWidget;
use lindemannrock\searchmanager\widgets\TopSearchesWidget;
use lindemannrock\searchmanager\widgets\TrendingSearchesWidget;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HeaderCollection;

/**
 * @since 5.54.0
 */
final class EditionAnalyticsGateTest extends TestCase
{
    private const QUERY_PREFIX = 'sm-edition-analytics-';
    private const TEST_SITE_ID = 999996;
    private const CANONICAL_DATA_TYPES = [
        'summary',
        'chart',
        'query-analysis',
        'content-gaps',
        'device-stats',
        'countries',
        'cities',
        'hourly',
        'trending',
        'intent',
        'source',
        'performance',
        'cache-stats',
        'top-queries',
        'worst-queries',
        'query-rules-top',
        'query-rules-by-type',
        'query-rules-queries',
        'promotions-top',
        'promotions-by-position',
        'promotions-queries',
        'recent-searches',
        'recent-unhandled',
        'bot-stats',
    ];
    private const AUTHORED_ASSET_DATA_TYPES = [
        'chart',
        'query-analysis',
        'content-gaps',
        'device-stats',
        'countries',
        'cities',
        'hourly',
        'trending',
        'intent',
        'source',
        'performance',
        'cache-stats',
        'top-queries',
        'worst-queries',
        'query-rules-top',
        'query-rules-by-type',
        'query-rules-queries',
        'promotions-top',
        'promotions-by-position',
        'promotions-queries',
        'recent-searches',
        'recent-unhandled',
        'bot-stats',
    ];

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalTemplateMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();
        $this->purgeAnalyticsRows();
    }

    protected function tearDown(): void
    {
        $this->purgeAnalyticsRows();

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalTemplateMode !== null) {
            Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
        }

        parent::tearDown();
    }

    public function testCollectionChokePointsWriteNothingInStandard(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        SearchManager::$plugin->analytics->trackSearch(
            'all',
            self::QUERY_PREFIX . 'facade',
            1,
            1.0,
            'test',
            self::TEST_SITE_ID,
            ['source' => 'test'],
        );

        (new AnalyticsTrackingService())->trackSearch(
            'all',
            self::QUERY_PREFIX . 'tracking-service',
            1,
            1.0,
            'test',
            self::TEST_SITE_ID,
            ['source' => 'test'],
        );

        self::assertSame(0, $this->analyticsRowCount());
    }

    public function testCollectionStillWritesInPro(): void
    {
        $indexHandle = $this->analyticsIndexHandle();
        if ($indexHandle === null) {
            self::markTestSkipped('No enabled analytics index is available for the Pro collection check.');
        }

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        Craft::$app->set('request', new \craft\web\Request());
        SearchManager::$plugin->getSettings()->enableAnalytics = true;
        SearchManager::$plugin->getSettings()->enableGeoDetection = false;

        SearchManager::$plugin->analytics->trackSearch(
            $indexHandle,
            self::QUERY_PREFIX . 'pro',
            1,
            1.0,
            'test',
            self::TEST_SITE_ID,
            ['source' => 'test'],
        );

        self::assertSame(1, $this->analyticsRowCount());
    }

    public function testPublicTrackingEndpointsReturnNoContentInStandard(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        SearchManager::$plugin->getSettings()->requireApiKey = true;
        $this->installRequest([
            'q' => self::QUERY_PREFIX . 'endpoint',
            'elementId' => '123',
        ]);

        $controller = new SearchController('search', SearchManager::$plugin);

        self::assertTrue($controller->beforeAction(new Action('track-search', $controller)));
        $searchResponse = $controller->actionTrackSearch();
        self::assertSame(204, $searchResponse->getStatusCode());
        self::assertNull($searchResponse->data);

        Craft::$app->set('response', new Response());
        self::assertTrue($controller->beforeAction(new Action('track-click', $controller)));
        $clickResponse = $controller->actionTrackClick();
        self::assertSame(204, $clickResponse->getStatusCode());
        self::assertNull($clickResponse->data);
        self::assertSame(0, $this->analyticsRowCount());
    }

    public function testStandardCanExportAndClearRetainedAnalytics(): void
    {
        $this->seedAnalyticsRow(self::QUERY_PREFIX . 'retained');
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        $export = SearchManager::$plugin->analytics->exportAnalytics(self::TEST_SITE_ID, 'all');
        $queries = array_column($export['jsonData']['data'], 'query');
        self::assertContains(self::QUERY_PREFIX . 'retained', $queries);

        self::assertSame(1, SearchManager::$plugin->analytics->clearAnalytics(self::TEST_SITE_ID));
        self::assertSame(0, $this->analyticsRowCount());

        self::assertStringNotContainsString('requireProAnalytics', $this->methodSource(AnalyticsController::class, 'actionExport'));
        self::assertStringNotContainsString('requireProAnalytics', $this->methodSource(AnalyticsController::class, 'actionClearAll'));
        self::assertStringNotContainsString('requireProAnalytics', $this->methodSource(AnalyticsController::class, 'actionDelete'));
    }

    public function testDetailAnalyticsFacadeReturnsExactStandardShapesWithoutReadingDetails(): void
    {
        $rules = new EditionAnalyticsRecordingRulesService();
        $analytics = new AnalyticsService();
        $rulesProperty = new \ReflectionProperty(AnalyticsService::class, '_rules');
        $rulesProperty->setValue($analytics, $rules);

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        self::assertSame([
            'totalTriggers' => 0,
            'uniqueQueries' => 0,
            'avgResultsAfter' => 0.0,
            'topQueries' => [],
            'dailyTriggers' => [],
            'recentTriggers' => [],
        ], $analytics->getRuleAnalytics(42, 'today', [1]));
        self::assertSame([
            'totalImpressions' => 0,
            'uniqueQueries' => 0,
            'avgPosition' => 0.0,
            'topQueries' => [],
            'dailyImpressions' => [],
            'recentImpressions' => [],
        ], $analytics->getPromotionAnalytics(84, 'last7days', []));
        self::assertSame([], $rules->calls);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);

        self::assertSame($rules->ruleResponse, $analytics->getRuleAnalytics(42, 'today'));
        self::assertSame($rules->promotionResponse, $analytics->getPromotionAnalytics(84, 'last7days', [1, 2]));
        self::assertSame([
            ['method' => 'rule', 'id' => 42, 'dateRange' => 'today', 'siteId' => null],
            ['method' => 'promotion', 'id' => 84, 'dateRange' => 'last7days', 'siteId' => [1, 2]],
        ], $rules->calls);
    }

    public function testStandardRejectsEveryReportingAction(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $this->installRequest(['type' => 'summary']);
        $controller = $this->analyticsControllerWithoutRequestOrPermissionGates();

        foreach ([
            'actionIndex',
            'actionGetData',
            'actionExportRuleAnalytics',
            'actionExportPromotionAnalytics',
            'actionExportTab',
            'actionExportContentGaps',
        ] as $method) {
            try {
                $controller->{$method}();
                self::fail("{$method} should require Search Manager Pro.");
            } catch (ForbiddenHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testReportingActionPassesInPro(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->installRequest([
            'type' => 'summary',
            'dateRange' => 'today',
        ]);

        $response = $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData();

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->data['success']);
        self::assertArrayHasKey('summary', $response->data['data']);
    }

    public function testAnalyticsAssetRequestTypesMatchCanonicalControllerAllowlist(): void
    {
        $analyticsSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/analytics/src/analytics.js');
        preg_match_all(
            "/data:\\s*\\{[^\\n]*\\btype:\\s*'([^']+)'/",
            $analyticsSource,
            $literalMatches,
        );
        preg_match('/const mapping = \\{(?<mapping>.*?)\\};/s', $analyticsSource, $mappingMatch);
        preg_match_all("/:\\s*'([^']+)'/", $mappingMatch['mapping'] ?? '', $mappingValues);

        $authoredTypes = array_values(array_unique(array_merge(
            $literalMatches[1] ?? [],
            $mappingValues[1] ?? [],
        )));
        $expectedAuthoredTypes = self::AUTHORED_ASSET_DATA_TYPES;
        sort($authoredTypes);
        sort($expectedAuthoredTypes);
        self::assertSame($expectedAuthoredTypes, $authoredTypes);

        $controllerSource = $this->methodSource(AnalyticsController::class, 'actionGetData');
        preg_match('/\\$validTypes = \\[(?<types>.*?)\\];/s', $controllerSource, $allowlistMatch);
        preg_match_all("/'([^']+)'/", $allowlistMatch['types'] ?? '', $allowlistValues);
        self::assertSame(self::CANONICAL_DATA_TYPES, $allowlistValues[1] ?? []);
        self::assertSame(['summary'], array_values(array_diff(self::CANONICAL_DATA_TYPES, self::AUTHORED_ASSET_DATA_TYPES)));

        foreach (['all', 'devices', 'browsers', 'os', 'bots'] as $removedType) {
            self::assertNotContains($removedType, $authoredTypes);
            self::assertNotContains($removedType, $allowlistValues[1] ?? []);
        }
    }

    public function testOmittedAndRemovedAnalyticsDataTypesAreRejected(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);

        foreach ([null, 'all', 'devices', 'browsers', 'os', 'bots'] as $invalidType) {
            $this->installRequest($invalidType === null ? [] : ['type' => $invalidType]);

            try {
                $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData();
                self::fail(($invalidType ?? 'omitted type') . ' should be rejected.');
            } catch (BadRequestHttpException $exception) {
                self::assertSame('Invalid data type', $exception->getMessage());
            }
        }
    }

    public function testEveryCanonicalAnalyticsDataTypeKeepsItsResponseShape(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $expectedDataShapes = [
            'summary' => ['summary'],
            'chart' => ['chartData'],
            'query-analysis' => ['queryAnalysis'],
            'content-gaps' => ['contentGaps'],
            'device-stats' => ['deviceStats'],
            'countries' => null,
            'cities' => null,
            'hourly' => ['data', 'labels', 'peakHour', 'peakHourFormatted'],
            'trending' => null,
            'intent' => ['data', 'labels', 'values', 'percentages'],
            'source' => ['data', 'labels', 'values', 'percentages'],
            'performance' => ['labels', 'avgTime', 'minTime', 'maxTime', 'indexSearches'],
            'cache-stats' => ['total', 'cacheHits', 'cacheMisses', 'hitRate', 'missRate'],
            'top-queries' => null,
            'worst-queries' => null,
            'query-rules-top' => null,
            'query-rules-by-type' => ['labels', 'values'],
            'query-rules-queries' => null,
            'promotions-top' => null,
            'promotions-by-position' => ['labels', 'values'],
            'promotions-queries' => null,
            'recent-searches' => null,
            'recent-unhandled' => null,
            'bot-stats' => [
                'total',
                'bots',
                'systems',
                'humans',
                'botPercentage',
                'nonHumanPercentage',
                'topBots',
                'topAgents',
                'chart',
            ],
        ];

        self::assertSame(self::CANONICAL_DATA_TYPES, array_keys($expectedDataShapes));

        foreach ($expectedDataShapes as $type => $expectedKeys) {
            $this->installRequest([
                'type' => $type,
                'dateRange' => 'today',
            ]);
            $response = $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData();

            self::assertSame(200, $response->getStatusCode(), $type);
            self::assertTrue($response->data['success'] ?? false, $type);
            $data = $response->data['data'] ?? null;
            self::assertIsArray($data, $type);
            if ($expectedKeys === null) {
                self::assertTrue(array_is_list($data), $type);
            } else {
                self::assertSame($expectedKeys, array_keys($data), $type);
            }
        }

        $this->installRequest(['type' => 'summary', 'dateRange' => 'today']);
        $summary = $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData()->data['data']['summary'] ?? [];
        self::assertSame(
            ['totalCount', 'handledCount', 'unhandledCount', 'mostCommon', 'recentUnhandled'],
            array_keys($summary),
        );

        $this->installRequest(['type' => 'device-stats', 'dateRange' => 'today']);
        $deviceStats = $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData()->data['data']['deviceStats'] ?? [];
        self::assertSame(
            ['deviceBreakdown', 'browserBreakdown', 'osBreakdown', 'botStats'],
            array_keys($deviceStats),
        );
        foreach (['deviceBreakdown', 'browserBreakdown', 'osBreakdown'] as $breakdown) {
            self::assertSame(['labels', 'values'], array_keys($deviceStats[$breakdown] ?? []), $breakdown);
        }

        $this->installRequest(['type' => 'bot-stats', 'dateRange' => 'today']);
        $botStats = $this->analyticsControllerWithoutRequestOrPermissionGates()->actionGetData()->data['data'] ?? [];
        self::assertSame(['labels', 'types', 'values'], array_keys($botStats['chart'] ?? []));
    }

    public function testDashboardWidgetsRenderUpgradeNoticeInStandard(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        foreach ([
            AnalyticsSummaryWidget::class,
            TopSearchesWidget::class,
            TrendingSearchesWidget::class,
            ContentGapsWidget::class,
        ] as $widgetClass) {
            $widget = new $widgetClass();
            $html = $widget->getBodyHtml();

            self::assertNotNull($html);
            self::assertStringContainsString('Analytics requires Search Manager Pro', $html);
            self::assertStringContainsString('plugin-store/search-manager', $html);
            self::assertFalse($widgetClass::isSelectable());
        }
    }

    public function testAnalyticsNavAndSettingsRespectEditionSplit(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableAnalytics = true;

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardAnalyticsSection = $this->cpSection('analytics');
        self::assertFalse($standardAnalyticsSection['when']);

        $settingsMethod = new \ReflectionMethod(\lindemannrock\searchmanager\controllers\SettingsController::class, '_validationAttributesForSection');
        $standardAttributes = $settingsMethod->invoke(
            new \lindemannrock\searchmanager\controllers\SettingsController('settings', SearchManager::$plugin),
            'analytics',
        );
        self::assertSame(['analyticsRetention'], $standardAttributes);

        $indicesControllerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/IndicesController.php');
        self::assertStringContainsString(
            "if (SearchManager::\$plugin->isPro()) {\n            \$index->enableAnalytics = (bool)\$request->getBodyParam('enableAnalytics', true);",
            $indicesControllerSource,
        );
        $indexEditSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/indices/edit.twig');
        self::assertStringContainsString('{% elseif isPro %}', $indexEditSource);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proAnalyticsSection = $this->cpSection('analytics');
        self::assertSame(!empty(ConfiguredBackend::findAllEnabled()), $proAnalyticsSection['when']);

        $proAttributes = $settingsMethod->invoke(
            new \lindemannrock\searchmanager\controllers\SettingsController('settings', SearchManager::$plugin),
            'analytics',
        );
        self::assertContains('enableAnalytics', $proAttributes);
        self::assertContains('analyticsRetention', $proAttributes);
    }

    private function analyticsControllerWithoutRequestOrPermissionGates(): AnalyticsController
    {
        return new class('analytics', SearchManager::$plugin) extends AnalyticsController {
            public function requirePermission(string $permissionName): void
            {
            }

            public function requirePostRequest(): void
            {
            }

            public function requireAcceptsJson(): void
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function installRequest(array $params): void
    {
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

    private function seedAnalyticsRow(string $query): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
            'indexHandle' => 'edition-test',
            'query' => $query,
            'normalizedQuery' => QueryNormalizer::forCacheIdentity($query),
            'resultsCount' => 1,
            'executionTime' => 1.0,
            'backend' => 'test',
            'siteId' => self::TEST_SITE_ID,
            'isHit' => 1,
            'wasRedirected' => 0,
            'promotionsShown' => 0,
            'synonymsExpanded' => 0,
            'rulesMatched' => 0,
            'isRobot' => 0,
            'isMobileApp' => 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function analyticsRowCount(): int
    {
        return (int) (new Query())
            ->from('{{%searchmanager_analytics}}')
            ->where(['like', 'query', self::QUERY_PREFIX . '%', false])
            ->count();
    }

    private function purgeAnalyticsRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_analytics}}', ['like', 'query', self::QUERY_PREFIX . '%', false])
            ->execute();
    }

    private function analyticsIndexHandle(): ?string
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled && $index->enableAnalytics) {
                return $index->handle;
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     */
    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}

final class EditionAnalyticsRecordingRulesService extends AnalyticsRulesService
{
    /** @var list<array{method: string, id: int, dateRange: string, siteId: int|list<int>|null}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $ruleResponse = [
        'totalTriggers' => 2,
        'uniqueQueries' => 1,
        'avgResultsAfter' => 4.5,
        'topQueries' => [['query' => 'seeded rule', 'count' => 2]],
        'dailyTriggers' => [['date' => '2026-07-27', 'count' => 2]],
        'recentTriggers' => [['query' => 'seeded rule']],
    ];

    /** @var array<string, mixed> */
    public array $promotionResponse = [
        'totalImpressions' => 3,
        'uniqueQueries' => 2,
        'avgPosition' => 1.5,
        'topQueries' => [['query' => 'seeded promotion', 'count' => 3]],
        'dailyImpressions' => [['date' => '2026-07-27', 'count' => 3]],
        'recentImpressions' => [['query' => 'seeded promotion']],
    ];

    public function getRuleAnalytics(int $ruleId, string $dateRange = 'last7days', int|array|null $siteId = null): array
    {
        $this->calls[] = ['method' => 'rule', 'id' => $ruleId, 'dateRange' => $dateRange, 'siteId' => $siteId];

        return $this->ruleResponse;
    }

    public function getPromotionAnalytics(int $promotionId, string $dateRange = 'last7days', int|array|null $siteId = null): array
    {
        $this->calls[] = ['method' => 'promotion', 'id' => $promotionId, 'dateRange' => $dateRange, 'siteId' => $siteId];

        return $this->promotionResponse;
    }
}
