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
use craft\elements\User as UserIdentity;
use craft\web\Request;
use craft\web\Response;
use craft\web\User;
use craft\web\View;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\searchmanager\controllers\DashboardController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\ForbiddenHttpException;

/**
 * Behavioral regression coverage for dashboard permission.
 *
 * @since 5.54.0
 */
#[CoversClass(SearchManager::class)]
#[CoversClass(DashboardController::class)]
final class DashboardCardPermissionTest extends TestCase
{
    private const PREFIX = '__sm_pr179_dashboard_';

    private object $originalRequest;
    private object $originalResponse;
    private object $originalUser;
    private mixed $originalTwigCurrentUser = null;
    private bool $twigCurrentUserCaptured = false;
    private bool $originalEnableAnalytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalUser = Craft::$app->getUser();
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableAnalytics = $settings->enableAnalytics;
        $settings->enableAnalytics = true;
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->enableAnalytics = $this->originalEnableAnalytics;
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        Craft::$app->set('user', $this->originalUser);
        if ($this->twigCurrentUserCaptured) {
            Craft::$app->getView()->getTwig()->addGlobal('currentUser', $this->originalTwigCurrentUser);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{list<string>, array{indices: bool, promotions: bool, queryRules: bool, analytics: bool}}>
     */
    public static function singleAndCombinedCardProvider(): iterable
    {
        yield 'indices only' => [
            ['searchManager:manageIndices'],
            ['indices' => true, 'promotions' => false, 'queryRules' => false, 'analytics' => false],
        ];
        yield 'promotions only' => [
            ['searchManager:managePromotions'],
            ['indices' => false, 'promotions' => true, 'queryRules' => false, 'analytics' => false],
        ];
        yield 'query rules only' => [
            ['searchManager:manageQueryRules'],
            ['indices' => false, 'promotions' => false, 'queryRules' => true, 'analytics' => false],
        ];
        yield 'analytics only' => [
            ['searchManager:viewAnalytics'],
            ['indices' => false, 'promotions' => false, 'queryRules' => false, 'analytics' => true],
        ];
        yield 'combined cards' => [
            [
                'searchManager:manageIndices',
                'searchManager:managePromotions',
                'searchManager:manageQueryRules',
                'searchManager:viewAnalytics',
            ],
            ['indices' => true, 'promotions' => true, 'queryRules' => true, 'analytics' => true],
        ];
        yield 'backends only' => [
            ['searchManager:manageBackends'],
            ['indices' => false, 'promotions' => false, 'queryRules' => false, 'analytics' => false],
        ];
        yield 'child permissions only' => [
            [
                'searchManager:createIndices',
                'searchManager:createPromotions',
                'searchManager:createQueryRules',
            ],
            ['indices' => false, 'promotions' => false, 'queryRules' => false, 'analytics' => false],
        ];
    }

    /**
     * @param list<string> $permissions
     * @param array{indices: bool, promotions: bool, queryRules: bool, analytics: bool} $expected
     */
    #[DataProvider('singleAndCombinedCardProvider')]
    public function testApplicableProjectionCoversSingleCombinedAndNoCardIdentities(
        array $permissions,
        array $expected,
    ): void {
        $user = $this->createIdentity($permissions, false, implode('-', $permissions) ?: 'none');

        self::assertSame(
            $expected,
            SearchManager::$plugin->getDashboardCardAccess(
                SearchManager::$plugin->getSettings(),
                $this->webUser($user),
                true,
            ),
        );
    }

    public function testProjectionEnforcesBackendEditionAnalyticsAndAdministratorConditions(): void
    {
        $allPermissions = [
            'searchManager:manageIndices',
            'searchManager:managePromotions',
            'searchManager:manageQueryRules',
            'searchManager:viewAnalytics',
        ];
        $settings = SearchManager::$plugin->getSettings();
        $user = $this->createIdentity($allPermissions, false, 'conditions');
        $webUser = $this->webUser($user);

        self::assertSame(
            $this->noCards(),
            SearchManager::$plugin->getDashboardCardAccess($settings, $webUser, false),
            'No backend must make every Dashboard card inapplicable.',
        );

        $settings->enableAnalytics = false;
        self::assertSame(
            ['indices' => true, 'promotions' => true, 'queryRules' => true, 'analytics' => false],
            SearchManager::$plugin->getDashboardCardAccess($settings, $webUser, true),
        );

        $settings->enableAnalytics = true;
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        self::assertSame(
            ['indices' => true, 'promotions' => false, 'queryRules' => false, 'analytics' => false],
            SearchManager::$plugin->getDashboardCardAccess($settings, $webUser, true),
        );

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $administrator = $this->createIdentity([], true, 'administrator');
        self::assertSame(
            ['indices' => true, 'promotions' => true, 'queryRules' => true, 'analytics' => true],
            SearchManager::$plugin->getDashboardCardAccess($settings, $this->webUser($administrator), true),
        );
    }

    public function testCpNavigationAndDirectRouteAgreeForEveryCardIdentity(): void
    {
        self::assertNotSame([], ConfiguredBackend::findAllEnabled(), 'Runtime fixture requires one enabled Backend.');

        foreach ([
            'indices' => 'searchManager:manageIndices',
            'promotions' => 'searchManager:managePromotions',
            'query-rules' => 'searchManager:manageQueryRules',
            'analytics' => 'searchManager:viewAnalytics',
        ] as $label => $permission) {
            $this->actWithPermissions([$permission], false, $label);
            $subnav = $this->currentSubnav();
            self::assertArrayHasKey('dashboard', $subnav, $label);

            $controller = $this->recordingController();
            $controller->forcedCards = null;
            $response = $controller->actionIndex();
            self::assertSame('search-manager/dashboard/index', $response->data['template'] ?? null, $label);
        }

        $this->actWithPermissions([], true, 'administrator-route');
        self::assertArrayHasKey('dashboard', $this->currentSubnav());
        $controller = $this->recordingController();
        $controller->forcedCards = null;
        self::assertSame(
            'search-manager/dashboard/index',
            $controller->actionIndex()->data['template'] ?? null,
        );

        $this->actWithPermissions(['searchManager:manageBackends'], false, 'backends-only');
        self::assertArrayNotHasKey('dashboard', $this->currentSubnav());
        $controller = $this->recordingController();
        $controller->forcedCards = null;
        $backendsRedirect = $controller->actionIndex();
        self::assertStringContainsString(
            '/search-manager/backends',
            (string)$backendsRedirect->getHeaders()->get('Location'),
        );

        $this->actWithPermissions(['searchManager:manageSettings'], false, 'settings-only');
        $controller = $this->recordingController();
        $controller->forcedCards = null;
        $settingsRedirect = $controller->actionIndex();
        self::assertStringContainsString(
            '/search-manager/setup',
            (string)$settingsRedirect->getHeaders()->get('Location'),
        );

        $this->actWithPermissions([], false, 'no-access');
        $this->expectException(ForbiddenHttpException::class);
        $controller = $this->recordingController();
        $controller->forcedCards = null;
        $controller->actionIndex();
    }

    /**
     * @return iterable<string, array{array{indices: bool, promotions: bool, queryRules: bool, analytics: bool}, list<string>}>
     */
    public static function dataLoadingProvider(): iterable
    {
        yield 'indices' => [
            ['indices' => true, 'promotions' => false, 'queryRules' => false, 'analytics' => false],
            ['indices'],
        ];
        yield 'promotions' => [
            ['indices' => false, 'promotions' => true, 'queryRules' => false, 'analytics' => false],
            ['promotions'],
        ];
        yield 'query rules' => [
            ['indices' => false, 'promotions' => false, 'queryRules' => true, 'analytics' => false],
            ['queryRules'],
        ];
        yield 'analytics' => [
            ['indices' => false, 'promotions' => false, 'queryRules' => false, 'analytics' => true],
            ['analytics'],
        ];
        yield 'combined' => [
            ['indices' => true, 'promotions' => true, 'queryRules' => true, 'analytics' => true],
            ['indices', 'promotions', 'queryRules', 'analytics'],
        ];
    }

    /**
     * @param array{indices: bool, promotions: bool, queryRules: bool, analytics: bool} $cards
     * @param list<string> $expectedLoads
     */
    #[DataProvider('dataLoadingProvider')]
    public function testControllerLoadsExactlyTheApplicableCardFamilies(array $cards, array $expectedLoads): void
    {
        $this->actWithPermissions([], true, 'data-' . implode('-', $expectedLoads));
        $controller = $this->recordingController();
        $controller->forcedCards = $cards;

        $response = $controller->actionIndex();

        self::assertSame($expectedLoads, $controller->loads);
        self::assertSame($cards, $response->data['variables']['dashboardCards'] ?? null);
    }

    public function testTwigCardsQuickActionsAndDestinationsConsumeServerProjection(): void
    {
        $this->actWithPermissions([
            'searchManager:managePromotions',
            'searchManager:createPromotions',
            'searchManager:createIndices',
            'searchManager:createQueryRules',
        ], false, 'promotion-card');

        $controller = $this->recordingController();
        $controller->forcedCards = [
            'indices' => false,
            'promotions' => true,
            'queryRules' => false,
            'analytics' => false,
        ];
        $html = $this->renderCaptured($controller->actionIndex());

        self::assertStringContainsString('Promotions', $html);
        self::assertStringContainsString('search-manager/promotions/create', $html);
        self::assertStringNotContainsString('search-manager/indices/create', $html);
        self::assertStringNotContainsString('search-manager/query-rules/create', $html);
        self::assertStringNotContainsString('Search Indices', $html);
        self::assertStringNotContainsString('Searches Today', $html);
    }

    public function testDashboardSummaryAuthorityRemainsOnTheAnalyticsCardLoader(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/DashboardController.php');
        self::assertIsString($source);
        self::assertStringContainsString("getAnalyticsSummary(\$editableSiteIds, 'today')", $source);
        self::assertStringContainsString("getAnalyticsSummary(\$editableSiteIds, 'yesterday')", $source);
        self::assertStringNotContainsString('getAnalyticsCount(', $source);
    }

    /**
     * @param list<string> $permissions
     */
    private function createIdentity(array $permissions, bool $admin, string $suffix): UserIdentity
    {
        $handle = preg_replace('/[^a-z0-9]+/', '-', strtolower($suffix)) ?: 'identity';
        $identity = $this->createTestUser(self::PREFIX . $handle, ['admin' => $admin]);
        if ($admin) {
            $identity->admin = true;
            if (!Craft::$app->getElements()->saveElement($identity, false)) {
                throw new \RuntimeException('Administrator test user failed to save.');
            }
        }
        $this->grantPermissions($identity, array_merge(['accessCp'], $permissions));

        return $identity;
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(array $permissions, bool $admin, string $suffix): void
    {
        $identity = $this->createIdentity($permissions, $admin, $suffix);
        $this->actingAs($identity);

        $user = new class() extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?UserIdentity
            {
                return null;
            }
        };
        $user->setIdentity($identity);
        Craft::$app->set('user', $user);

        $twig = Craft::$app->getView()->getTwig();
        if (!$this->twigCurrentUserCaptured) {
            $this->originalTwigCurrentUser = $twig->getGlobals()['currentUser'] ?? null;
            $this->twigCurrentUserCaptured = true;
        }
        $twig->addGlobal('currentUser', $identity);
    }

    private function webUser(UserIdentity $identity): User
    {
        $user = new User([
            'identityClass' => UserIdentity::class,
            'enableSession' => false,
        ]);
        $user->setIdentity($identity);

        return $user;
    }

    /**
     * @return array<string, array{label: string, url: string}>
     */
    private function currentSubnav(): array
    {
        return CpNavHelper::buildSubnav(
            $this->webUserForCurrentIdentity(),
            SearchManager::$plugin->getSettings(),
            SearchManager::$plugin->getCpSections(SearchManager::$plugin->getSettings()),
        );
    }

    private function webUserForCurrentIdentity(): User
    {
        $identity = Craft::$app->getUser()->getIdentity();
        self::assertInstanceOf(UserIdentity::class, $identity);

        return $this->webUser($identity);
    }

    private function renderCaptured(\yii\web\Response $response): string
    {
        $template = $response->data['template'] ?? null;
        $variables = $response->data['variables'] ?? null;
        self::assertIsString($template);
        self::assertIsArray($variables);

        return Craft::$app->getView()->renderTemplate(
            $template,
            $variables,
            View::TEMPLATE_MODE_CP,
        );
    }

    private function recordingController(): RecordingDashboardController
    {
        $controller = new RecordingDashboardController('dashboard', SearchManager::$plugin);
        $controller->cpUser = $this->webUserForCurrentIdentity();

        return $controller;
    }

    /**
     * @return array{indices: false, promotions: false, queryRules: false, analytics: false}
     */
    private function noCards(): array
    {
        return [
            'indices' => false,
            'promotions' => false,
            'queryRules' => false,
            'analytics' => false,
        ];
    }
}

final class RecordingDashboardController extends DashboardController
{
    /**
     * @var array{indices: bool, promotions: bool, queryRules: bool, analytics: bool}
     */
    public ?array $forcedCards = [
        'indices' => false,
        'promotions' => false,
        'queryRules' => false,
        'analytics' => false,
    ];

    /** @var list<string> */
    public array $loads = [];

    public ?User $cpUser = null;

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): \yii\web\Response
    {
        $response = new Response();
        $response->data = [
            'template' => $template,
            'variables' => $variables,
        ];

        return $response;
    }

    /**
     * @return array{indices: bool, promotions: bool, queryRules: bool, analytics: bool}
     */
    protected function getDashboardCardAccess(): array
    {
        return $this->forcedCards ?? parent::getDashboardCardAccess();
    }

    protected function getCpUser(): User
    {
        if ($this->cpUser === null) {
            throw new \LogicException('The test CP user was not configured.');
        }

        return $this->cpUser;
    }

    /** @return array<string, mixed> */
    protected function loadIndexCardData(): array
    {
        $this->loads[] = 'indices';

        return [
            'indices' => [],
            'totalDocuments' => 0,
            'enabledIndices' => 0,
        ];
    }

    /** @return array<string, int> */
    protected function loadPromotionCardData(): array
    {
        $this->loads[] = 'promotions';

        return ['promotionsCount' => 2, 'enabledPromotions' => 1];
    }

    /** @return array<string, int> */
    protected function loadQueryRuleCardData(): array
    {
        $this->loads[] = 'queryRules';

        return ['queryRulesCount' => 2, 'enabledQueryRules' => 1];
    }

    /** @return array<string, mixed> */
    protected function loadAnalyticsCardData(): array
    {
        $this->loads[] = 'analytics';

        return [
            'searchesToday' => 2,
            'searchesYesterday' => 1,
            'topSearches' => [],
            'recentZeroResults' => [],
        ];
    }
}
