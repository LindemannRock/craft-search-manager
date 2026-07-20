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
use lindemannrock\searchmanager\services\analytics\AnalyticsTrackingService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\widgets\AnalyticsSummaryWidget;
use lindemannrock\searchmanager\widgets\ContentGapsWidget;
use lindemannrock\searchmanager\widgets\TopSearchesWidget;
use lindemannrock\searchmanager\widgets\TrendingSearchesWidget;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\HeaderCollection;

/**
 * @since 5.54.0
 */
final class EditionAnalyticsGateTest extends TestCase
{
    private const QUERY_PREFIX = 'sm-edition-analytics-';
    private const TEST_SITE_ID = 999996;

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
