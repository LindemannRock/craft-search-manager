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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\controllers\PendingSyncsController;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\web\ForbiddenHttpException;

/**
 * Behavioral regression coverage for PR1.38, PR1.39, and PR1.40.
 *
 * @since 5.54.0
 */
#[CoversClass(SearchManager::class)]
#[CoversClass(ApiKeysController::class)]
#[CoversClass(IndicesController::class)]
#[CoversClass(PendingSyncsController::class)]
#[CoversClass(WidgetsController::class)]
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(AnalyticsController::class)]
final class ControlPanelPermissionProjectionTest extends TestCase
{
    private const PREFIX = 'sm-pr138-140';
    private const CONFIG_BACKEND = self::PREFIX . '-config-backend';
    private const HOSTED_BACKEND = self::PREFIX . '-hosted-backend';
    private const DATABASE_BACKEND = self::PREFIX . '-database-backend';
    private const DATABASE_INDEX = self::PREFIX . '-database-index';
    private const CONFIG_INDEX = self::PREFIX . '-config-index';
    private const HOSTED_INDEX = self::PREFIX . '-hosted-index';
    private const INDEX_COLLISION = self::PREFIX . '-index-collision';
    private const DATABASE_STYLE = self::PREFIX . '-database-style';
    private const CONFIG_STYLE = self::PREFIX . '-config-style';
    private const STYLE_COLLISION = self::PREFIX . '-style-collision';
    private const CONFIG_WIDGET = self::PREFIX . '-config-widget';
    private const DATABASE_WIDGET = self::PREFIX . '-database-widget';
    private const PROMOTION_TITLE = 'PR1.38 Promotion';
    private const RULE_NAME = 'PR1.38 Query Rule';

    private mixed $originalConfigCache;
    private object $originalRequest;
    private object $originalResponse;
    private object $originalUser;
    private mixed $originalTwigCurrentUser = null;
    private bool $twigCurrentUserCaptured = false;
    private string $originalRequestMethod;
    private int $databaseBackendId;
    private int $databaseIndexId;
    private int $databaseStyleId;
    private int $promotionId;
    private int $queryRuleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->installConfigFixtures();

        $this->databaseBackendId = $this->insertBackend();
        $this->databaseIndexId = $this->insertIndex(self::DATABASE_INDEX, 'Database Index');
        $this->insertIndex(self::INDEX_COLLISION, 'Shadowed Database Index');
        $this->databaseStyleId = $this->insertStyle(self::DATABASE_STYLE, 'Database Style');
        $this->insertStyle(self::STYLE_COLLISION, 'Shadowed Database Style');
        $this->insertWidget();
        $this->promotionId = $this->insertPromotion();
        $this->queryRuleId = $this->insertQueryRule();
        $this->resetResourceCaches();

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalUser = Craft::$app->getUser();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        Craft::$app->set('user', $this->originalUser);
        if ($this->twigCurrentUserCaptured) {
            Craft::$app->getView()->getTwig()->addGlobal('currentUser', $this->originalTwigCurrentUser);
        }
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;

        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        $this->resetResourceCaches();

        parent::tearDown();
    }

    public function testResourceTargetsFollowParentEditFullAndAdminIdentities(): void
    {
        $identities = [
            'parent only' => [
                [
                    'searchManager:manageIndices',
                    'searchManager:manageWidgetStyles',
                    'searchManager:managePromotions',
                    'searchManager:manageQueryRules',
                ],
                false,
                false,
            ],
            'parent plus edit' => [
                [
                    'searchManager:manageIndices',
                    'searchManager:editIndices',
                    'searchManager:manageWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:managePromotions',
                    'searchManager:editPromotions',
                    'searchManager:manageQueryRules',
                    'searchManager:editQueryRules',
                ],
                true,
                false,
            ],
            'full families' => [
                [
                    'searchManager:manageIndices',
                    'searchManager:createIndices',
                    'searchManager:editIndices',
                    'searchManager:deleteIndices',
                    'searchManager:rebuildIndices',
                    'searchManager:clearIndices',
                    'searchManager:manageWidgetStyles',
                    'searchManager:createWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:deleteWidgetStyles',
                    'searchManager:managePromotions',
                    'searchManager:createPromotions',
                    'searchManager:editPromotions',
                    'searchManager:deletePromotions',
                    'searchManager:manageQueryRules',
                    'searchManager:createQueryRules',
                    'searchManager:editQueryRules',
                    'searchManager:deleteQueryRules',
                ],
                true,
                false,
            ],
            'administrator' => [[], true, true],
        ];

        foreach ($identities as $label => [$permissions, $canEdit, $admin]) {
            $this->actWithPermissions($permissions, $admin, $label);

            $indexHtml = $this->renderCaptured($this->indexListResponse());
            $this->assertResourceTarget(
                $indexHtml,
                'Database Index',
                $canEdit
                    ? 'search-manager/indices/edit/' . $this->databaseIndexId
                    : 'search-manager/indices/view/' . self::DATABASE_INDEX,
            );
            $this->assertResourceTarget(
                $indexHtml,
                'Config Index',
                'search-manager/indices/view/' . self::CONFIG_INDEX,
            );
            $this->assertResourceTarget(
                $indexHtml,
                'Config Collision Index',
                'search-manager/indices/view/' . self::INDEX_COLLISION,
            );
            self::assertStringNotContainsString(
                'search-manager/indices/edit/' . $this->indexId(self::INDEX_COLLISION),
                $indexHtml,
            );

            $styleHtml = $this->renderCaptured(
                (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionStylesIndex(),
            );
            $this->assertResourceTarget(
                $styleHtml,
                'Database Style',
                $canEdit
                    ? 'search-manager/widgets/styles/edit/' . $this->databaseStyleId
                    : 'search-manager/widgets/styles/view/' . self::DATABASE_STYLE,
            );
            $this->assertResourceTarget(
                $styleHtml,
                'Config Style',
                'search-manager/widgets/styles/view/' . self::CONFIG_STYLE,
            );
            $this->assertResourceTarget(
                $styleHtml,
                'Config Collision Style',
                'search-manager/widgets/styles/view/' . self::STYLE_COLLISION,
            );
            self::assertStringNotContainsString(
                'search-manager/widgets/styles/edit/' . $this->styleId(self::STYLE_COLLISION),
                $styleHtml,
            );

            $promotionHtml = $this->renderCaptured(
                (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))->actionIndex(),
            );
            $queryRuleHtml = $this->renderCaptured(
                (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex(),
            );
            if ($canEdit) {
                $this->assertResourceTarget(
                    $promotionHtml,
                    self::PROMOTION_TITLE,
                    'search-manager/promotions/edit/' . $this->promotionId,
                    false,
                );
                $this->assertResourceTarget(
                    $queryRuleHtml,
                    self::RULE_NAME,
                    'search-manager/query-rules/edit/' . $this->queryRuleId,
                );
            } else {
                self::assertStringContainsString(self::PROMOTION_TITLE, $promotionHtml);
                self::assertStringNotContainsString(
                    'search-manager/promotions/edit/' . $this->promotionId,
                    $promotionHtml,
                );
                self::assertStringContainsString(self::RULE_NAME, $queryRuleHtml);
                self::assertStringNotContainsString(
                    'search-manager/query-rules/edit/' . $this->queryRuleId,
                    $queryRuleHtml,
                );
            }
        }
    }

    public function testReadOnlyDatabaseAndConfigDetailsAreSourceAccurate(): void
    {
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:manageWidgetStyles',
        ]);

        $indices = new PermissionProjectionIndicesController('indices', SearchManager::$plugin);
        $databaseIndexResponse = $indices->actionView(self::DATABASE_INDEX);
        self::assertSame('search-manager/indices/view', $databaseIndexResponse->data['template'] ?? null);
        $databaseIndexHtml = $this->renderCaptured($databaseIndexResponse);
        self::assertStringContainsString('Database Index', $databaseIndexHtml);
        self::assertStringContainsString('Database', $databaseIndexHtml);
        self::assertStringNotContainsString(
            'This index is defined in your config file and cannot be edited here.',
            $databaseIndexHtml,
        );
        $this->assertReadOnlyDetail($databaseIndexHtml, 'search-manager/indices/save');

        $configIndexHtml = $this->renderCaptured($indices->actionView(self::CONFIG_INDEX));
        self::assertStringContainsString(
            'This index is defined in your config file and cannot be edited here.',
            $configIndexHtml,
        );
        $this->assertReadOnlyDetail($configIndexHtml, 'search-manager/indices/save');

        $widgets = new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin);
        $databaseStyleHtml = $this->renderCaptured($widgets->actionViewStyle(self::DATABASE_STYLE));
        self::assertStringContainsString('Database Style', $databaseStyleHtml);
        self::assertStringContainsString('Database', $databaseStyleHtml);
        self::assertStringNotContainsString(
            'This style is defined in your config file and cannot be edited here.',
            $databaseStyleHtml,
        );
        $this->assertReadOnlyDetail($databaseStyleHtml, 'search-manager/widgets/save-style');

        $configStyleHtml = $this->renderCaptured($widgets->actionViewStyle(self::CONFIG_STYLE));
        self::assertStringContainsString(
            'This style is defined in your config file and cannot be edited here.',
            $configStyleHtml,
        );
        $this->assertReadOnlyDetail($configStyleHtml, 'search-manager/widgets/save-style');
    }

    public function testManageOnlyDirectEditAndSaveStayForbiddenAndEditOpensEditors(): void
    {
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:manageWidgetStyles',
        ]);

        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))
                ->actionEdit($this->databaseIndexId),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                ->actionEditStyle($this->databaseStyleId),
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams(['indexId' => $this->databaseIndexId]);
        $this->assertForbiddenAction(
            fn(): ?Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))
                ->actionSave(),
        );
        Craft::$app->getRequest()->setBodyParams(['styleId' => $this->databaseStyleId]);
        $this->assertForbiddenAction(
            fn(): ?Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                ->actionSaveStyle(),
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        Craft::$app->getRequest()->setBodyParams([]);
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:editIndices',
            'searchManager:manageWidgetStyles',
            'searchManager:editWidgetStyles',
        ], false, 'edit-positive');

        $indexEdit = (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))
            ->actionEdit($this->databaseIndexId);
        self::assertSame('search-manager/indices/edit', $indexEdit->data['template'] ?? null);
        self::assertSame(
            $this->databaseIndexId,
            $indexEdit->data['variables']['index']->id ?? null,
        );

        $styleEdit = (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
            ->actionEditStyle($this->databaseStyleId);
        self::assertSame('search-manager/widgets/styles/edit', $styleEdit->data['template'] ?? null);
        self::assertSame(
            $this->databaseStyleId,
            $styleEdit->data['variables']['widgetStyle']->id ?? null,
        );
    }

    public function testDestinationAwareIndexBackendAndEditorLinksMatchDirectGates(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableStopWords = false;

        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:editIndices',
        ]);
        $indices = new PermissionProjectionIndicesController('indices', SearchManager::$plugin);
        $indexHtml = $this->renderCaptured($this->indexListResponse());
        $indexViewHtml = $this->renderCaptured($indices->actionView(self::DATABASE_INDEX));
        foreach ([$indexHtml, $indexViewHtml] as $html) {
            self::assertStringContainsString('Database Backend', $html);
            self::assertStringNotContainsString(
                'search-manager/backends/view/' . self::DATABASE_BACKEND,
                $html,
            );
            self::assertStringNotContainsString(
                'search-manager/backends/' . $this->databaseBackendId,
                $html,
            );
        }

        $originalBackend = SearchManager::$plugin->backend;
        $backend = $this->createMock(BackendService::class);
        $backend->method('getBackendOptions')->willReturn(['' => 'Default']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $indexEditHtml = $this->renderCaptured($indices->actionEdit($this->databaseIndexId));
        self::assertStringContainsString('No configured backends yet.', $indexEditHtml);
        self::assertStringContainsString('Create a backend', $indexEditHtml);
        self::assertStringNotContainsString('search-manager/backends/new', $indexEditHtml);
        self::assertStringContainsString('Enable stop words', $indexEditHtml);
        self::assertStringNotContainsString('search-manager/settings/language', $indexEditHtml);
        SearchManager::$plugin->set('backend', $originalBackend);

        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionView(self::DATABASE_BACKEND),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionEdit(),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionSettingsController('settings', SearchManager::$plugin))
                ->actionLanguage(),
        );

        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:editIndices',
            'searchManager:manageBackends',
            'searchManager:createBackends',
            'searchManager:manageSettings',
        ], false, 'index-destinations');
        $indices = new PermissionProjectionIndicesController('indices', SearchManager::$plugin);
        foreach ([
            $this->renderCaptured($this->indexListResponse()),
            $this->renderCaptured($indices->actionView(self::DATABASE_INDEX)),
        ] as $html) {
            self::assertStringContainsString(
                'search-manager/backends/view/' . self::DATABASE_BACKEND,
                $html,
            );
            self::assertStringNotContainsString(
                'search-manager/backends/' . $this->databaseBackendId,
                $html,
            );
        }

        $backend = $this->createMock(BackendService::class);
        $backend->method('getBackendOptions')->willReturn(['' => 'Default']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $indexEditHtml = $this->renderCaptured($indices->actionEdit($this->databaseIndexId));
        SearchManager::$plugin->set('backend', $originalBackend);
        self::assertStringContainsString('search-manager/backends/new', $indexEditHtml);
        self::assertStringContainsString('search-manager/settings/language', $indexEditHtml);
        self::assertSame(
            'search-manager/backends/edit',
            (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionView(self::DATABASE_BACKEND)->data['template'] ?? null,
        );
        self::assertSame(
            'search-manager/backends/edit',
            (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionEdit()->data['template'] ?? null,
        );
        self::assertSame(
            'search-manager/settings/language',
            (new PermissionProjectionSettingsController('settings', SearchManager::$plugin))
                ->actionLanguage()->data['template'] ?? null,
        );
    }

    public function testSettingsAndAnalyticsCtasMatchDestinationPermissions(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableGeoDetection = false;

        $this->actWithPermissions([
            'searchManager:manageSettings',
            'searchManager:viewAnalytics',
        ]);
        $settingsController = new PermissionProjectionSettingsController('settings', SearchManager::$plugin);
        $settingsResponse = $settingsController->actionGeneral();
        $settingsResponse->data['variables']['enabledBackends'] = [];
        $settingsResponse->data['variables']['enabledWidgets'] = [];
        $settingsHtml = $this->renderCaptured($settingsResponse);
        self::assertStringContainsString('No enabled backends.', $settingsHtml);
        self::assertStringContainsString('No enabled widgets.', $settingsHtml);
        self::assertStringNotContainsString('search-manager/backends/new', $settingsHtml);
        self::assertStringNotContainsString('search-manager/widgets/new', $settingsHtml);

        $analyticsVariables = (new PermissionProjectionAnalyticsController('analytics', SearchManager::$plugin))
            ->actionIndex()->data['variables'] ?? [];
        $this->assertAnalyticsCtas($analyticsVariables, false, false, true);

        $this->actWithPermissions([
            'searchManager:viewAnalytics',
        ], false, 'analytics-no-destinations');
        $analyticsVariables = (new PermissionProjectionAnalyticsController('analytics', SearchManager::$plugin))
            ->actionIndex()->data['variables'] ?? [];
        $this->assertAnalyticsCtas($analyticsVariables, false, false, false);
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionSettingsController('settings', SearchManager::$plugin))
                ->actionGeneral(),
        );

        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionEdit(),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                ->actionEdit(),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))
                ->actionEdit(),
        );
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))
                ->actionEdit(),
        );

        $this->actWithPermissions([
            'searchManager:manageSettings',
            'searchManager:viewAnalytics',
            'searchManager:manageBackends',
            'searchManager:createBackends',
            'searchManager:manageWidgetConfigs',
            'searchManager:createWidgetConfigs',
            'searchManager:managePromotions',
            'searchManager:createPromotions',
            'searchManager:manageQueryRules',
            'searchManager:createQueryRules',
        ], false, 'all-destinations');
        $settingsResponse = (new PermissionProjectionSettingsController('settings', SearchManager::$plugin))
            ->actionGeneral();
        $settingsResponse->data['variables']['enabledBackends'] = [];
        $settingsResponse->data['variables']['enabledWidgets'] = [];
        $settingsHtml = $this->renderCaptured($settingsResponse);
        self::assertStringContainsString('search-manager/backends/new', $settingsHtml);
        self::assertStringContainsString('search-manager/widgets/new', $settingsHtml);

        $analyticsVariables = (new PermissionProjectionAnalyticsController('analytics', SearchManager::$plugin))
            ->actionIndex()->data['variables'] ?? [];
        $this->assertAnalyticsCtas($analyticsVariables, true, true, true);

        self::assertSame(
            'search-manager/backends/edit',
            (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))
                ->actionEdit()->data['template'] ?? null,
        );
        self::assertSame(
            'search-manager/widgets/edit',
            (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                ->actionEdit()->data['template'] ?? null,
        );
        self::assertSame(
            'search-manager/promotions/edit',
            (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))
                ->actionEdit()->data['template'] ?? null,
        );
        self::assertSame(
            'search-manager/query-rules/edit',
            (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))
                ->actionEdit()->data['template'] ?? null,
        );
    }

    public function testCanonicalWidgetWorkspaceCoversAllPermissionIdentities(): void
    {
        $cases = [
            'access CP only' => [SearchManager::EDITION_PRO, [], null, []],
            'config parent' => [
                SearchManager::EDITION_PRO,
                ['searchManager:manageWidgetConfigs'],
                'search-manager/widgets',
                ['configurations'],
            ],
            'style parent' => [
                SearchManager::EDITION_PRO,
                ['searchManager:manageWidgetStyles'],
                'search-manager/widgets/styles',
                ['styles'],
            ],
            'full style family without config parent' => [
                SearchManager::EDITION_PRO,
                [
                    'searchManager:manageWidgetStyles',
                    'searchManager:createWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:deleteWidgetStyles',
                ],
                'search-manager/widgets/styles',
                ['styles'],
            ],
            'both parents' => [
                SearchManager::EDITION_PRO,
                [
                    'searchManager:manageWidgetConfigs',
                    'searchManager:manageWidgetStyles',
                ],
                'search-manager/widgets',
                ['configurations', 'styles'],
            ],
            'config child only' => [
                SearchManager::EDITION_PRO,
                ['searchManager:editWidgetConfigs'],
                null,
                [],
            ],
            'style child only' => [
                SearchManager::EDITION_PRO,
                ['searchManager:editWidgetStyles'],
                null,
                [],
            ],
            'full families' => [
                SearchManager::EDITION_PRO,
                [
                    'searchManager:manageWidgetConfigs',
                    'searchManager:createWidgetConfigs',
                    'searchManager:editWidgetConfigs',
                    'searchManager:deleteWidgetConfigs',
                    'searchManager:manageWidgetStyles',
                    'searchManager:createWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:deleteWidgetStyles',
                ],
                'search-manager/widgets',
                ['configurations', 'styles'],
            ],
            'Standard config parent' => [
                SearchManager::EDITION_STANDARD,
                [
                    'searchManager:manageWidgetConfigs',
                    'searchManager:manageWidgetStyles',
                ],
                'search-manager/widgets',
                ['configurations'],
            ],
            'Standard style parent' => [
                SearchManager::EDITION_STANDARD,
                ['searchManager:manageWidgetStyles'],
                null,
                [],
            ],
        ];

        foreach ($cases as $label => [$edition, $permissions, $landing, $tabs]) {
            $this->forcePluginEdition($edition);
            $this->actWithPermissions($permissions, false, 'nav-' . $label);
            $workspace = SearchManager::$plugin->getWidgetWorkspaceNavigation();

            if ($landing === null) {
                self::assertNull($workspace, $label);
                self::assertNull($this->widgetSection(), $label);
                continue;
            }

            self::assertNotNull($workspace, $label);
            self::assertSame($landing, $workspace['landingRoute'], $label);
            self::assertSame($tabs, array_keys($workspace['tabs']), $label);
            self::assertSame($landing, $this->widgetSection()['url'] ?? null, $label);

            $subnav = CpNavHelper::buildSubnav(
                $this->webUserForCurrentIdentity(),
                SearchManager::$plugin->getSettings(),
                SearchManager::$plugin->getCpSections(SearchManager::$plugin->getSettings()),
            );
            self::assertSame($landing, $subnav['widgets']['url'] ?? null, $label);
        }

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->actWithPermissions([], true, 'nav-admin');
        $adminWorkspace = SearchManager::$plugin->getWidgetWorkspaceNavigation();
        self::assertSame('search-manager/widgets', $adminWorkspace['landingRoute'] ?? null);
        self::assertSame(
            ['configurations', 'styles'],
            array_keys($adminWorkspace['tabs'] ?? []),
        );
    }

    public function testWidgetDefaultRedirectTabsCrumbsAndDirectListsStayAligned(): void
    {
        $this->actWithPermissions(['searchManager:manageWidgetStyles'], false, 'style-only');
        $defaultRoute = CpNavHelper::firstAccessibleRoute(
            $this->webUserForCurrentIdentity(),
            SearchManager::$plugin->getSettings(),
            SearchManager::$plugin->getCpSections(
                SearchManager::$plugin->getSettings(),
                false,
                true,
            ),
        );
        self::assertSame('search-manager/widgets/styles', $defaultRoute);

        $widgets = new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin);
        $styleIndexHtml = $this->renderCaptured($widgets->actionStylesIndex());
        self::assertStringContainsString('search-manager/widgets/styles', $styleIndexHtml);
        self::assertStringNotContainsString('href="/admin/search-manager/widgets"', $styleIndexHtml);
        self::assertStringNotContainsString('Configurations</a>', $styleIndexHtml);
        $this->assertWidgetsCrumbLanding(
            $this->renderCaptured($widgets->actionViewStyle(self::DATABASE_STYLE)),
            'search-manager/widgets/styles',
        );
        $this->assertForbiddenAction(fn(): Response => $widgets->actionIndex());

        $this->actWithPermissions([
            'searchManager:manageWidgetStyles',
            'searchManager:editWidgetStyles',
        ], false, 'style-edit');
        $this->assertWidgetsCrumbLanding(
            $this->renderCaptured(
                (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                    ->actionEditStyle($this->databaseStyleId),
            ),
            'search-manager/widgets/styles',
        );

        $this->actWithPermissions([
            'searchManager:manageWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:editWidgetStyles',
        ], false, 'both-widget-parents');
        $widgets = new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin);
        $configIndexHtml = $this->renderCaptured($widgets->actionIndex());
        self::assertStringContainsString('Configurations', $configIndexHtml);
        self::assertStringContainsString('Styles', $configIndexHtml);

        $configView = $widgets->actionView(self::DATABASE_WIDGET);
        $configEdit = $widgets->actionEdit($this->widgetId());
        $styleView = $widgets->actionViewStyle(self::DATABASE_STYLE);
        $styleEdit = $widgets->actionEditStyle($this->databaseStyleId);
        foreach ([$configView, $configEdit, $styleView, $styleEdit] as $response) {
            $this->assertWidgetsCrumbLanding(
                $this->renderCaptured($response),
                'search-manager/widgets',
            );
        }

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $this->actWithPermissions(['searchManager:manageWidgetStyles'], false, 'standard-style');
        self::assertNull(SearchManager::$plugin->getWidgetWorkspaceNavigation());
        $this->assertForbiddenAction(
            fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))
                ->actionStylesIndex(),
        );
    }

    public function testActionsColumnTracksEmptyAndParentOnlyPageCapabilities(): void
    {
        $this->seedActionProjectionRows();
        $parents = [
            'searchManager:manageApiKeys',
            'searchManager:manageBackends',
            'searchManager:manageIndices',
            'searchManager:managePendingSyncs',
            'searchManager:managePromotions',
            'searchManager:manageQueryRules',
            'searchManager:manageWidgetConfigs',
            'searchManager:manageWidgetStyles',
        ];

        $this->actWithPermissions($parents, false, 'pr141-parent-only');
        $tables = $this->renderActionTables();

        $this->assertTableActionProjection($tables['apiKeys'], false, 'API Keys parent-only');
        $this->assertTableActionProjection($tables['backends'], true, 'Backends parent-only');
        $this->assertTableActionProjection($tables['indices'], true, 'Indices parent-only');
        $this->assertTableActionProjection($tables['pendingSyncs'], false, 'Pending Syncs unresolved parent-only');
        $this->assertTableActionProjection($tables['promotions'], false, 'Promotions parent-only');
        $this->assertTableActionProjection($tables['queryRules'], false, 'Query Rules parent-only');
        $this->assertTableActionProjection($tables['widgetConfigs'], true, 'Widget Configs parent-only');
        $this->assertTableActionProjection($tables['widgetStyles'], true, 'Widget Styles parent-only');

        self::assertStringContainsString('Test Connection', $tables['backends']);
        self::assertStringContainsString('View', $tables['backends']);
        self::assertStringContainsString('View', $tables['indices']);
        self::assertStringContainsString('View', $tables['widgetConfigs']);
        self::assertStringContainsString('View', $tables['widgetStyles']);
        self::assertStringContainsString('Config Backend', $tables['backends']);
        self::assertStringContainsString('Config Widget', $tables['widgetConfigs']);
        self::assertStringContainsString('Config Index', $tables['indices']);
        self::assertStringContainsString('Config Style', $tables['widgetStyles']);

        $resolvedPending = $this->renderFiltered(
            ['search' => self::PREFIX, 'status' => PendingSyncRepository::STATUS_ABANDONED],
            fn(): Response => (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                ->actionIndex(),
        );
        $this->assertTableActionProjection($resolvedPending, true, 'Pending Syncs resolved View Element');
        self::assertStringContainsString('View element', $resolvedPending);
        $mixedPending = $this->renderFiltered(
            ['search' => self::PREFIX],
            fn(): Response => (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                ->actionIndex(),
        );
        $this->assertTableActionProjection($mixedPending, true, 'Pending Syncs mixed resolvability');

        $this->actWithPermissions($parents, true, 'pr141-empty-admin');
        foreach ($this->renderActionTables(self::PREFIX . '-no-matches') as $resource => $html) {
            $this->assertTableActionProjection($html, false, $resource . ' empty administrator page');
        }
    }

    public function testPendingSyncAjaxKeepsProjectionAndRowsAligned(): void
    {
        $this->seedActionProjectionRows();
        $this->actWithPermissions(
            ['searchManager:managePendingSyncs'],
            false,
            'pr141-pending-ajax-parent',
        );

        $unresolved = $this->pendingSyncAjaxData([
            'search' => self::PREFIX,
            'status' => PendingSyncRepository::STATUS_FAILED,
        ]);
        self::assertFalse($unresolved['hasAvailableRowActions']);
        $this->assertFragmentRowCellCounts($unresolved['rowsHtml'], 9);

        $resolved = $this->pendingSyncAjaxData([
            'search' => self::PREFIX,
            'status' => PendingSyncRepository::STATUS_ABANDONED,
        ]);
        self::assertTrue($resolved['hasAvailableRowActions']);
        self::assertStringContainsString('View element', $resolved['rowsHtml']);
        $this->assertFragmentRowCellCounts($resolved['rowsHtml'], 10);

        $mixed = $this->pendingSyncAjaxData(['search' => self::PREFIX]);
        self::assertTrue($mixed['hasAvailableRowActions']);
        $this->assertFragmentRowCellCounts($mixed['rowsHtml'], 10);

        $this->actWithPermissions(
            ['searchManager:managePendingSyncs', 'searchManager:retryPendingSyncs'],
            false,
            'pr141-pending-ajax-retry',
        );
        $retry = $this->pendingSyncAjaxData([
            'search' => self::PREFIX,
            'status' => PendingSyncRepository::STATUS_FAILED,
        ]);
        self::assertTrue($retry['hasAvailableRowActions']);
        self::assertStringContainsString('Retry now', $retry['rowsHtml']);
        $this->assertFragmentRowCellCounts($retry['rowsHtml'], 11);
    }

    public function testActionsColumnTracksChildFullFamilyAndAdministratorCapabilities(): void
    {
        $this->seedActionProjectionRows();
        SearchManager::$plugin->getSettings()->enableCache = true;

        $individualCases = [
            'API Keys edit' => [
                ['searchManager:manageApiKeys', 'searchManager:editApiKeys'],
                fn(): Response => (new PermissionActionProjectionApiKeysController('api-keys', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'API Keys revoke' => [
                ['searchManager:manageApiKeys', 'searchManager:revokeApiKeys'],
                fn(): Response => (new PermissionActionProjectionApiKeysController('api-keys', SearchManager::$plugin))->actionIndex(),
                'Revoke',
                [],
            ],
            'Backends edit' => [
                ['searchManager:manageBackends', 'searchManager:editBackends'],
                fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'Backends delete' => [
                ['searchManager:manageBackends', 'searchManager:deleteBackends'],
                fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))->actionIndex(),
                'Delete',
                [],
            ],
            'Indices edit' => [
                ['searchManager:manageIndices', 'searchManager:editIndices'],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'Indices rebuild' => [
                ['searchManager:manageIndices', 'searchManager:rebuildIndices'],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
                'Sync Count from Backend',
                [],
            ],
            'Indices clear' => [
                ['searchManager:manageIndices', 'searchManager:clearIndices'],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
                'Clear Index Data',
                [],
            ],
            'Indices delete' => [
                ['searchManager:manageIndices', 'searchManager:deleteIndices'],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
                'Delete Index',
                [],
            ],
            'Indices cache clear' => [
                ['searchManager:manageIndices', 'searchManager:clearCache'],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
                'Clear Index Cache',
                [],
            ],
            'Pending Syncs retry' => [
                ['searchManager:managePendingSyncs', 'searchManager:retryPendingSyncs'],
                fn(): Response => (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                    ->actionIndex(),
                'Retry now',
                ['status' => PendingSyncRepository::STATUS_FAILED],
            ],
            'Pending Syncs purge' => [
                ['searchManager:managePendingSyncs', 'searchManager:purgePendingSyncs'],
                fn(): Response => (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                    ->actionIndex(),
                'Delete from buffer',
                ['status' => PendingSyncRepository::STATUS_FAILED],
            ],
            'Promotions duplicate' => [
                ['searchManager:managePromotions', 'searchManager:createPromotions'],
                fn(): Response => (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))->actionIndex(),
                'Duplicate',
                [],
            ],
            'Promotions edit' => [
                ['searchManager:managePromotions', 'searchManager:editPromotions'],
                fn(): Response => (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'Promotions delete' => [
                ['searchManager:managePromotions', 'searchManager:deletePromotions'],
                fn(): Response => (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))->actionIndex(),
                'Delete',
                [],
            ],
            'Query Rules duplicate' => [
                ['searchManager:manageQueryRules', 'searchManager:createQueryRules'],
                fn(): Response => (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex(),
                'Duplicate',
                [],
            ],
            'Query Rules edit' => [
                ['searchManager:manageQueryRules', 'searchManager:editQueryRules'],
                fn(): Response => (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'Query Rules delete' => [
                ['searchManager:manageQueryRules', 'searchManager:deleteQueryRules'],
                fn(): Response => (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex(),
                'Delete',
                [],
            ],
            'Widget Configs edit' => [
                ['searchManager:manageWidgetConfigs', 'searchManager:editWidgetConfigs'],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionIndex(),
                'Edit',
                [],
            ],
            'Widget Configs duplicate' => [
                [
                    'searchManager:manageWidgetConfigs',
                    'searchManager:createWidgetConfigs',
                    'searchManager:editWidgetConfigs',
                ],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionIndex(),
                'Duplicate',
                [],
            ],
            'Widget Configs delete' => [
                [
                    'searchManager:manageWidgetConfigs',
                    'searchManager:editWidgetConfigs',
                    'searchManager:deleteWidgetConfigs',
                ],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionIndex(),
                'Delete',
                [],
            ],
            'Widget Styles edit' => [
                ['searchManager:manageWidgetStyles', 'searchManager:editWidgetStyles'],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionStylesIndex(),
                'Edit',
                [],
            ],
            'Widget Styles duplicate' => [
                [
                    'searchManager:manageWidgetStyles',
                    'searchManager:createWidgetStyles',
                    'searchManager:editWidgetStyles',
                ],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionStylesIndex(),
                'Duplicate',
                [],
            ],
            'Widget Styles delete' => [
                [
                    'searchManager:manageWidgetStyles',
                    'searchManager:editWidgetStyles',
                    'searchManager:deleteWidgetStyles',
                ],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionStylesIndex(),
                'Delete',
                [],
            ],
        ];

        foreach ($individualCases as $label => [$permissions, $action, $menuLabel, $params]) {
            $this->actWithPermissions($permissions, false, 'pr141-' . $label);
            $html = $this->renderFiltered(['search' => self::PREFIX, ...$params], $action);
            $this->assertTableActionProjection($html, true, $label);
            self::assertStringContainsString($menuLabel, $html, $label);
        }

        $fullPermissions = [
            'searchManager:manageApiKeys',
            'searchManager:createApiKeys',
            'searchManager:editApiKeys',
            'searchManager:revokeApiKeys',
            'searchManager:manageBackends',
            'searchManager:createBackends',
            'searchManager:editBackends',
            'searchManager:deleteBackends',
            'searchManager:manageIndices',
            'searchManager:createIndices',
            'searchManager:editIndices',
            'searchManager:deleteIndices',
            'searchManager:rebuildIndices',
            'searchManager:clearIndices',
            'searchManager:clearCache',
            'searchManager:managePendingSyncs',
            'searchManager:retryPendingSyncs',
            'searchManager:purgePendingSyncs',
            'searchManager:managePromotions',
            'searchManager:createPromotions',
            'searchManager:editPromotions',
            'searchManager:deletePromotions',
            'searchManager:manageQueryRules',
            'searchManager:createQueryRules',
            'searchManager:editQueryRules',
            'searchManager:deleteQueryRules',
            'searchManager:manageWidgetConfigs',
            'searchManager:createWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:deleteWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:createWidgetStyles',
            'searchManager:editWidgetStyles',
            'searchManager:deleteWidgetStyles',
        ];

        foreach ([
            'full families' => [$fullPermissions, false],
            'administrator' => [$fullPermissions, true],
        ] as $label => [$permissions, $admin]) {
            $this->actWithPermissions($permissions, $admin, 'pr141-' . $label);
            $tables = $this->renderActionTables();
            foreach ($tables as $resource => $html) {
                $this->assertTableActionProjection($html, true, $resource . ' ' . $label);
            }
            foreach ([
                'apiKeys' => ['Edit', 'Revoke'],
                'backends' => ['View', 'Edit', 'Test Connection', 'Delete'],
                'indices' => [
                    'View',
                    'Edit',
                    'Rebuild Index',
                    'Sync Count from Backend',
                    'Clear Index Data',
                    'Clear Index Cache',
                    'Delete Index',
                ],
                'pendingSyncs' => ['Retry now', 'Delete from buffer'],
                'promotions' => ['Edit', 'Duplicate', 'Delete'],
                'queryRules' => ['Edit', 'Duplicate', 'Delete'],
                'widgetConfigs' => ['View', 'Edit', 'Duplicate', 'Delete'],
                'widgetStyles' => ['View', 'Edit', 'Duplicate', 'Delete'],
            ] as $resource => $actionLabels) {
                foreach ($actionLabels as $actionLabel) {
                    self::assertStringContainsString($actionLabel, $tables[$resource], $resource . ' ' . $label);
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function renderActionTables(string $search = self::PREFIX): array
    {
        return [
            'apiKeys' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionActionProjectionApiKeysController('api-keys', SearchManager::$plugin))->actionIndex(),
            ),
            'backends' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionBackendsController('backends', SearchManager::$plugin))->actionIndex(),
            ),
            'indices' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex(),
            ),
            'pendingSyncs' => $this->renderFiltered(
                ['search' => $search, 'status' => PendingSyncRepository::STATUS_FAILED],
                fn(): Response => (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                    ->actionIndex(),
            ),
            'promotions' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionPromotionsController('promotions', SearchManager::$plugin))->actionIndex(),
            ),
            'queryRules' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionQueryRulesController('query-rules', SearchManager::$plugin))->actionIndex(),
            ),
            'widgetConfigs' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionIndex(),
            ),
            'widgetStyles' => $this->renderFiltered(
                ['search' => $search],
                fn(): Response => (new PermissionProjectionWidgetsController('widgets', SearchManager::$plugin))->actionStylesIndex(),
            ),
        ];
    }

    /**
     * @param array<string, scalar> $queryParams
     * @param callable(): Response $action
     */
    private function renderFiltered(array $queryParams, callable $action): string
    {
        $request = Craft::$app->getRequest();
        $originalQueryParams = $request->getQueryParams();
        $request->setQueryParams($queryParams);

        try {
            return $this->renderCaptured($action());
        } finally {
            $request->setQueryParams($originalQueryParams);
        }
    }

    private function assertTableActionProjection(string $html, bool $expected, string $message): void
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded, $message);

        $xpath = new \DOMXPath($dom);
        $table = $xpath->query('//*[@id="lr-data-table"]')->item(0);
        self::assertNotNull($table, $message);

        $headers = $xpath->query('.//thead/tr[1]/th', $table);
        self::assertNotFalse($headers, $message);
        $actionHeaders = 0;
        foreach ($headers as $header) {
            if (trim($header->textContent) === 'Actions') {
                $actionHeaders++;
            }
        }
        self::assertSame($expected ? 1 : 0, $actionHeaders, $message);

        $rows = $xpath->query(
            './/tbody/tr[contains(concat(" ", normalize-space(@class), " "), " lr-data-row ")]',
            $table,
        );
        self::assertNotFalse($rows, $message);
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            self::assertNotFalse($cells, $message);
            self::assertSame($headers->length, $cells->length, $message);
        }
    }

    /**
     * @param array<string, scalar> $queryParams
     * @return array<string, mixed>
     */
    private function pendingSyncAjaxData(array $queryParams): array
    {
        $request = Craft::$app->getRequest();
        $originalQueryParams = $request->getQueryParams();
        $headers = $request->getHeaders();
        $originalAccept = $headers->get('Accept');
        $request->setQueryParams($queryParams);
        $headers->set('Accept', 'application/json');

        try {
            $response = (new PermissionActionProjectionPendingSyncsController('pending-syncs', SearchManager::$plugin))
                ->actionGetData();
            self::assertIsArray($response->data);

            return $response->data;
        } finally {
            $request->setQueryParams($originalQueryParams);
            if ($originalAccept === null) {
                $headers->remove('Accept');
            } else {
                $headers->set('Accept', $originalAccept);
            }
        }
    }

    private function assertFragmentRowCellCounts(string $rowsHtml, int $expected): void
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<table><tbody>' . $rowsHtml . '</tbody></table>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($loaded);

        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query(
            '//tbody/tr[contains(concat(" ", normalize-space(@class), " "), " lr-data-row ")]',
        );
        self::assertNotFalse($rows);
        self::assertGreaterThan(0, $rows->length);
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            self::assertNotFalse($cells);
            self::assertSame($expected, $cells->length);
        }
    }

    private function seedActionProjectionRows(): void
    {
        $generated = SearchManager::$plugin->apiKeys->generateKey(ApiKey::TYPE_PUBLIC);
        $apiKey = new ApiKey();
        $apiKey->name = self::PREFIX . ' API Key';
        $apiKey->handle = self::PREFIX . '-api-key';
        $apiKey->type = ApiKey::TYPE_PUBLIC;
        $apiKey->keyHash = $generated['hash'];
        $apiKey->keyPrefix = $generated['prefix'];
        $apiKey->allowedIndices = [ApiKey::ALL_INDICES];
        self::assertTrue($apiKey->save());

        $entry = Entry::find()
            ->siteId('*')
            ->status(null)
            ->drafts(null)
            ->revisions(false)
            ->one();
        self::assertNotNull($entry, 'The projection fixture requires one resolvable entry.');
        self::assertNotNull($entry->siteId);
        self::assertNotNull($entry->getCpEditUrl());

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        foreach ([
            [
                'elementId' => 2_000_000_000,
                'siteId' => (int)$entry->siteId,
                'status' => PendingSyncRepository::STATUS_FAILED,
                'lastError' => self::PREFIX . ' unresolved',
            ],
            [
                'elementId' => (int)$entry->id,
                'siteId' => (int)$entry->siteId,
                'status' => PendingSyncRepository::STATUS_ABANDONED,
                'lastError' => self::PREFIX . ' resolved',
            ],
        ] as $row) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_pending_syncs}}', [
                'indexHandle' => self::DATABASE_INDEX,
                'elementType' => Entry::class,
                'elementId' => $row['elementId'],
                'siteId' => $row['siteId'],
                'op' => PendingSyncRepository::OP_UPSERT,
                'status' => $row['status'],
                'attemptCount' => 1,
                'queuedAt' => $now,
                'nextAttemptAt' => $now,
                'claimedAt' => null,
                'claimToken' => null,
                'dirtyAt' => null,
                'lastError' => $row['lastError'],
                'lastProcessedAt' => null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(
        array $permissions,
        bool $admin = false,
        string $suffix = 'identity',
    ): void {
        $handle = preg_replace('/[^a-z0-9]+/', '-', strtolower($suffix)) ?: 'identity';
        $user = $this->createTestUser(self::PREFIX . '-' . $handle, ['admin' => $admin]);
        if ($admin) {
            $permissions = [
                'searchManager:manageApiKeys',
                'searchManager:createApiKeys',
                'searchManager:editApiKeys',
                'searchManager:revokeApiKeys',
                'searchManager:manageBackends',
                'searchManager:createBackends',
                'searchManager:editBackends',
                'searchManager:deleteBackends',
                'searchManager:manageIndices',
                'searchManager:createIndices',
                'searchManager:editIndices',
                'searchManager:deleteIndices',
                'searchManager:rebuildIndices',
                'searchManager:clearIndices',
                'searchManager:clearCache',
                'searchManager:managePendingSyncs',
                'searchManager:retryPendingSyncs',
                'searchManager:purgePendingSyncs',
                'searchManager:manageWidgetConfigs',
                'searchManager:createWidgetConfigs',
                'searchManager:editWidgetConfigs',
                'searchManager:deleteWidgetConfigs',
                'searchManager:manageWidgetStyles',
                'searchManager:createWidgetStyles',
                'searchManager:editWidgetStyles',
                'searchManager:deleteWidgetStyles',
                'searchManager:managePromotions',
                'searchManager:createPromotions',
                'searchManager:editPromotions',
                'searchManager:deletePromotions',
                'searchManager:manageQueryRules',
                'searchManager:createQueryRules',
                'searchManager:editQueryRules',
                'searchManager:deleteQueryRules',
            ];
        }
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);

        $renderUser = new class extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?\craft\elements\User
            {
                return null;
            }
        };
        $renderUser->setIdentity($user);
        Craft::$app->set('user', $renderUser);

        $twig = Craft::$app->getView()->getTwig();
        if (!$this->twigCurrentUserCaptured) {
            $this->originalTwigCurrentUser = $twig->getGlobals()['currentUser'] ?? null;
            $this->twigCurrentUserCaptured = true;
        }
        $twig->addGlobal('currentUser', $user);
    }

    private function webUserForCurrentIdentity(): \craft\web\User
    {
        $identity = Craft::$app->getUser()->getIdentity();
        self::assertNotNull($identity);

        $user = new \craft\web\User([
            'identityClass' => \craft\elements\User::class,
            'enableSession' => false,
        ]);
        $user->setIdentity($identity);

        return $user;
    }

    private function renderCaptured(Response $response): string
    {
        $template = $response->data['template'] ?? null;
        $variables = $response->data['variables'] ?? null;
        self::assertIsString($template);
        self::assertIsArray($variables);

        $currentUser = Craft::$app->getUser()->getIdentity();
        self::assertNotNull($currentUser);
        $renderVariables = array_merge($variables, ['currentUser' => $currentUser]);
        if ($template === 'search-manager/indices/edit') {
            // Keep this permission-projection suite independent of the optional
            // Docs Manager fixture state exercised elsewhere in the full suite.
            $renderVariables['docsManagerTransformerAvailable'] = false;
        }

        return Craft::$app->getView()->renderTemplate(
            $template,
            $renderVariables,
            View::TEMPLATE_MODE_CP,
        );
    }

    private function indexListResponse(): Response
    {
        $request = Craft::$app->getRequest();
        $queryParams = $request->getQueryParams();
        $request->setQueryParams(['search' => self::PREFIX]);

        try {
            return (new PermissionProjectionIndicesController('indices', SearchManager::$plugin))->actionIndex();
        } finally {
            $request->setQueryParams($queryParams);
        }
    }

    private function renderPartial(string $template, array $variables): string
    {
        return Craft::$app->getView()->renderTemplate(
            $template,
            $variables,
            View::TEMPLATE_MODE_CP,
        );
    }

    private function assertAnalyticsCtas(
        array $variables,
        bool $promotionExpected,
        bool $queryRuleExpected,
        bool $settingsExpected,
    ): void
    {
        $promotionHtml = $this->renderPartial(
            'search-manager/analytics/_partials/promotions',
            array_merge($variables, ['promotionsExist' => false]),
        );
        $queryRuleHtml = $this->renderPartial(
            'search-manager/analytics/_partials/query-rules',
            array_merge($variables, ['queryRulesExist' => false]),
        );
        $geographicHtml = $this->renderPartial(
            'search-manager/analytics/_partials/geographic',
            $variables,
        );

        self::assertStringContainsString('No Promotions Created', $promotionHtml);
        self::assertStringContainsString('No Query Rules Created', $queryRuleHtml);
        self::assertStringContainsString('Geographic detection is disabled.', $geographicHtml);
        if ($promotionExpected) {
            self::assertStringContainsString('search-manager/promotions/create', $promotionHtml);
        } else {
            self::assertStringNotContainsString('search-manager/promotions/create', $promotionHtml);
        }
        if ($queryRuleExpected) {
            self::assertStringContainsString('search-manager/query-rules/create', $queryRuleHtml);
        } else {
            self::assertStringNotContainsString('search-manager/query-rules/create', $queryRuleHtml);
        }
        if ($settingsExpected) {
            self::assertStringContainsString('search-manager/settings', $geographicHtml);
        } else {
            self::assertStringNotContainsString('search-manager/settings', $geographicHtml);
        }
    }

    private function assertResourceTarget(
        string $html,
        string $label,
        string $path,
        bool $nestedSpan = true,
    ): void {
        $content = $nestedSpan
            ? '\\s*<span>' . preg_quote($label, '/') . '<\\/span>'
            : '[^<]*' . preg_quote($label, '/');
        self::assertMatchesRegularExpression(
            '/href="[^"]*' . preg_quote($path, '/') . '"[^>]*>' . $content . '/s',
            $html,
        );
    }

    private function assertReadOnlyDetail(string $html, string $saveAction): void
    {
        self::assertStringNotContainsString('id="main-form"', $html);
        self::assertStringNotContainsString('id="save-button-container"', $html);
        self::assertStringNotContainsString($saveAction, $html);
        self::assertStringNotContainsString('data-destructive', $html);
    }

    private function assertWidgetsCrumbLanding(string $html, string $path): void
    {
        self::assertMatchesRegularExpression(
            '/href="[^"]*' . preg_quote($path, '/') . '"[^>]*>\\s*<span>Widgets<\\/span>\\s*<\\/a>/',
            $html,
        );
    }

    private function assertForbiddenAction(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected the direct controller action to reject this identity.');
        } catch (ForbiddenHttpException) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function widgetSection(): ?array
    {
        foreach (SearchManager::$plugin->getCpSections(SearchManager::$plugin->getSettings()) as $section) {
            if (($section['key'] ?? null) === 'widgets') {
                return $section;
            }
        }

        return null;
    }

    private function installConfigFixtures(): void
    {
        $cache = is_array($this->originalConfigCache) ? $this->originalConfigCache : [];
        $cache['search-manager'] = [
            'backends' => [
                self::CONFIG_BACKEND => [
                    'name' => 'Config Backend',
                    'backendType' => 'file',
                    'enabled' => true,
                    'settings' => [],
                ],
                self::HOSTED_BACKEND => [
                    'name' => 'Hosted Backend',
                    'backendType' => 'algolia',
                    'enabled' => true,
                    'settings' => [],
                ],
            ],
            'indices' => [
                self::CONFIG_INDEX => $this->configIndexDefinition('Config Index'),
                self::HOSTED_INDEX => [
                    ...$this->configIndexDefinition('Hosted Index'),
                    'backend' => self::HOSTED_BACKEND,
                ],
                self::INDEX_COLLISION => $this->configIndexDefinition('Config Collision Index'),
            ],
            'widgets' => [
                self::CONFIG_WIDGET => [
                    'name' => 'Config Widget',
                    'type' => 'modal',
                    'enabled' => true,
                    'settings' => WidgetConfig::defaultSettings(),
                ],
            ],
            'widgetStyles' => [
                self::CONFIG_STYLE => $this->configStyleDefinition('Config Style'),
                self::STYLE_COLLISION => $this->configStyleDefinition('Config Collision Style'),
            ],
        ];
        $this->setConfigCache($cache);
    }

    /**
     * @return array<string, mixed>
     */
    private function configIndexDefinition(string $name): array
    {
        return [
            'name' => $name,
            'elementType' => Entry::class,
            'criteria' => [],
            'enabled' => true,
            'backend' => self::DATABASE_BACKEND,
            'splitSections' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configStyleDefinition(string $name): array
    {
        return [
            'name' => $name,
            'type' => 'modal',
            'enabled' => true,
            'styles' => [],
        ];
    }

    private function resetResourceCaches(): void
    {
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        foreach ([
            [SearchManager::$plugin->widgetConfigs, '_configFileConfigs'],
            [SearchManager::$plugin->widgetStyles, '_configFileStyles'],
        ] as [$service, $propertyName]) {
            $property = new \ReflectionProperty($service, $propertyName);
            $property->setAccessible(true);
            $property->setValue($service, null);
        }
    }

    private function insertBackend(): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Database Backend',
            'handle' => self::DATABASE_BACKEND,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertIndex(string $handle, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'backend' => self::DATABASE_BACKEND,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertStyle(string $handle, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => $handle,
            'name' => $name,
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertWidget(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::DATABASE_WIDGET,
            'name' => 'Database Widget',
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode(WidgetConfig::defaultSettings(), JSON_THROW_ON_ERROR),
            'enabled' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function insertPromotion(): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => self::DATABASE_INDEX,
            'title' => self::PROMOTION_TITLE,
            'query' => self::PREFIX,
            'matchType' => 'exact',
            'elementId' => 1,
            'elementType' => Entry::class,
            'position' => 1,
            'siteId' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertQueryRule(): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
            'name' => self::RULE_NAME,
            'indexHandle' => self::DATABASE_INDEX,
            'matchType' => QueryRule::MATCH_EXACT,
            'matchValue' => self::PREFIX,
            'actionType' => QueryRule::ACTION_SYNONYM,
            'actionValue' => '{"terms":["permission"]}',
            'priority' => 0,
            'siteId' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function indexId(string $handle): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT [[id]] FROM {{%searchmanager_indices}} WHERE [[handle]] = :handle',
            [':handle' => $handle],
        )->queryScalar();
    }

    private function styleId(string $handle): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT [[id]] FROM {{%searchmanager_widget_styles}} WHERE [[handle]] = :handle',
            [':handle' => $handle],
        )->queryScalar();
    }

    private function widgetId(): int
    {
        return (int)Craft::$app->getDb()->createCommand(
            'SELECT [[id]] FROM {{%searchmanager_widget_configs}} WHERE [[handle]] = :handle',
            [':handle' => self::DATABASE_WIDGET],
        )->queryScalar();
    }

    private function purgeRows(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', ['like', 'lastError', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'name', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'query', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'matchValue', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        $db->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}

final class PermissionProjectionIndicesController extends IndicesController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionWidgetsController extends WidgetsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionPromotionsController extends PromotionsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionQueryRulesController extends QueryRulesController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionBackendsController extends BackendsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionSettingsController extends SettingsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionAnalyticsController extends AnalyticsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionActionProjectionApiKeysController extends ApiKeysController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionActionProjectionPendingSyncsController extends PendingSyncsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        return PermissionProjectionResponseFactory::captured($template, $variables);
    }
}

final class PermissionProjectionResponseFactory
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function captured(string $template, array $variables): Response
    {
        $response = new Response();
        $response->data = [
            'template' => $template,
            'variables' => $variables,
        ];

        return $response;
    }
}
