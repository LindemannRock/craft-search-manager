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
use craft\config\BaseConfig;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\services\Config;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\web\ForbiddenHttpException;

/**
 * @since 5.54.0
 */
#[CoversClass(BackendsController::class)]
#[CoversClass(WidgetsController::class)]
#[CoversClass(ConfiguredBackend::class)]
final class BackendWidgetReadOnlyPresentationTest extends TestCase
{
    private const PREFIX = 'sm-pr136-137';
    private const CONFIG_BACKEND = self::PREFIX . '-config-backend';
    private const DATABASE_BACKEND = self::PREFIX . '-database-backend';
    private const CHOICE_A = self::PREFIX . '-choice-a';
    private const CHOICE_B = self::PREFIX . '-choice-b';
    private const COLLISION = self::PREFIX . '-collision';
    private const DISABLED_BACKEND = self::PREFIX . '-disabled';
    private const CONFIG_WIDGET = self::PREFIX . '-config-widget';
    private const DATABASE_WIDGET = self::PREFIX . '-database-widget';

    private Config $originalConfig;
    private object $originalRequest;
    private object $originalResponse;
    private ?object $originalUser = null;
    private mixed $originalTwigCurrentUser = null;
    private bool $twigCurrentUserCaptured = false;
    private string $originalRequestMethod;
    private ?string $originalDefaultBackend;
    private ?string $originalDefaultWidget;
    private int $databaseBackendId;
    private int $databaseWidgetId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();

        $this->originalConfig = Craft::$app->getConfig();
        Craft::$app->set('config', new BackendWidgetConfigConfigService(
            $this->originalConfig,
            [
                self::CONFIG_BACKEND => [
                    'name' => 'Config Backend',
                    'backendType' => 'file',
                    'enabled' => true,
                    'settings' => [],
                ],
                self::COLLISION => [
                    'name' => 'Config Precedence',
                    'backendType' => 'file',
                    'enabled' => true,
                    'settings' => [],
                ],
                self::PREFIX . '-disabled-config' => [
                    'name' => 'Disabled Config Backend',
                    'backendType' => 'file',
                    'enabled' => false,
                    'settings' => [],
                ],
            ],
            [
                self::CONFIG_WIDGET => [
                    'name' => 'Config Widget',
                    'type' => 'modal',
                    'enabled' => true,
                    'settings' => [],
                ],
            ],
        ));
        BaseConfigFileHelper::clearCache('search-manager');
        $this->swapPluginComponent('search-manager', 'widgetConfigs', new WidgetConfigService());

        $this->databaseBackendId = $this->insertBackend(self::DATABASE_BACKEND, 'Database Backend', 'file');
        $this->insertBackend(self::CHOICE_A, 'MySQL', 'mysql');
        $this->insertBackend(self::CHOICE_B, 'MySQL', 'mysql');
        $this->insertBackend(self::COLLISION, 'Shadowed Database Backend', 'file');
        $this->insertBackend(self::DISABLED_BACKEND, 'Disabled Database Backend', 'file', false);
        $this->databaseWidgetId = $this->insertWidget(self::DATABASE_WIDGET, 'Database Widget');

        $settings = SearchManager::$plugin->getSettings();
        $this->originalDefaultBackend = $settings->defaultBackendHandle;
        $this->originalDefaultWidget = $settings->defaultWidgetHandle;
        $settings->defaultBackendHandle = self::CHOICE_A;
        $settings->defaultWidgetHandle = self::DATABASE_WIDGET;

        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
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
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $this->originalDefaultBackend;
        $settings->defaultWidgetHandle = $this->originalDefaultWidget;

        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('response', $this->originalResponse);
        if ($this->originalUser !== null) {
            Craft::$app->set('user', $this->originalUser);
        }
        if ($this->twigCurrentUserCaptured) {
            Craft::$app->getView()->getTwig()->addGlobal('currentUser', $this->originalTwigCurrentUser);
        }
        $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        Craft::$app->set('config', $this->originalConfig);
        BaseConfigFileHelper::clearCache('search-manager');
        $this->purgeRows();

        parent::tearDown();
    }

    public function testManageOnlyRowsUseWorkingReadOnlyRoutesAndViews(): void
    {
        $this->actWithPermissions([
            'searchManager:manageBackends',
            'searchManager:manageWidgetConfigs',
        ]);

        $backendController = new BackendPresentationBackendsController('backends', SearchManager::$plugin);
        $backendListHtml = $this->renderCaptured($backendController->actionIndex());
        $this->assertNameLink(
            $backendListHtml,
            'Database Backend',
            'search-manager/backends/view/' . self::DATABASE_BACKEND,
        );
        $this->assertNameLink(
            $backendListHtml,
            'Config Backend',
            'search-manager/backends/view/' . self::CONFIG_BACKEND,
        );
        self::assertStringNotContainsString(
            'search-manager/backends/' . $this->databaseBackendId . '"',
            $backendListHtml,
        );

        $databaseBackendView = $backendController->actionView(self::DATABASE_BACKEND);
        self::assertTrue($databaseBackendView->data['variables']['readOnly'] ?? false);
        $this->assertBackendReadOnlyHtml($this->renderCaptured($databaseBackendView));
        $this->assertBackendReadOnlyHtml(
            $this->renderCaptured($backendController->actionView(self::CONFIG_BACKEND)),
        );

        $widgetController = new BackendPresentationWidgetsController('widgets', SearchManager::$plugin);
        $widgetListHtml = $this->renderCaptured($widgetController->actionIndex());
        $this->assertNameLink(
            $widgetListHtml,
            'Database Widget',
            'search-manager/widgets/view/' . self::DATABASE_WIDGET,
        );
        $this->assertNameLink(
            $widgetListHtml,
            'Config Widget',
            'search-manager/widgets/view/' . self::CONFIG_WIDGET,
        );
        self::assertStringNotContainsString(
            'search-manager/widgets/edit/' . $this->databaseWidgetId,
            $widgetListHtml,
        );

        $databaseWidgetHtml = $this->renderCaptured($widgetController->actionView(self::DATABASE_WIDGET));
        self::assertStringNotContainsString('id="main-form"', $databaseWidgetHtml);
        self::assertStringNotContainsString(
            'This widget is defined in your config file and cannot be edited here.',
            $databaseWidgetHtml,
        );
        $configWidgetHtml = $this->renderCaptured($widgetController->actionView(self::CONFIG_WIDGET));
        self::assertStringContainsString(
            'This widget is defined in your config file and cannot be edited here.',
            $configWidgetHtml,
        );
    }

    public function testManageOnlyDirectEditAndSaveActionsRemainForbidden(): void
    {
        $this->actWithPermissions([
            'searchManager:manageBackends',
            'searchManager:manageWidgetConfigs',
        ]);

        $this->assertActionForbidden(
            fn(): Response => (new BackendPresentationBackendsController('backends', SearchManager::$plugin))
                ->actionEdit($this->databaseBackendId),
        );
        $this->assertActionForbidden(
            fn(): Response => (new BackendPresentationWidgetsController('widgets', SearchManager::$plugin))
                ->actionEdit($this->databaseWidgetId),
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams(['backendId' => $this->databaseBackendId]);
        $this->assertActionForbidden(
            fn(): Response => (new BackendPresentationBackendsController('backends', SearchManager::$plugin))
                ->actionSave(),
        );

        Craft::$app->getRequest()->setBodyParams(['configId' => $this->databaseWidgetId]);
        $this->assertActionForbidden(
            fn(): ?Response => (new BackendPresentationWidgetsController('widgets', SearchManager::$plugin))
                ->actionSave(),
        );
    }

    public function testEditCapableRowsKeepDatabaseEditAndConfigViewRoutes(): void
    {
        $this->actWithPermissions([
            'searchManager:manageBackends',
            'searchManager:editBackends',
            'searchManager:manageWidgetConfigs',
            'searchManager:editWidgetConfigs',
        ]);

        $backendController = new BackendPresentationBackendsController('backends', SearchManager::$plugin);
        $backendListHtml = $this->renderCaptured($backendController->actionIndex());
        $this->assertNameLink(
            $backendListHtml,
            'Database Backend',
            'search-manager/backends/' . $this->databaseBackendId,
        );
        $this->assertNameLink(
            $backendListHtml,
            'Config Backend',
            'search-manager/backends/view/' . self::CONFIG_BACKEND,
        );

        $backendEditHtml = $this->renderCaptured($backendController->actionEdit($this->databaseBackendId));
        self::assertStringContainsString('id="main-form"', $backendEditHtml);
        self::assertStringContainsString('href="#settings"', $backendEditHtml);
        self::assertStringContainsString('id="save-button-container"', $backendEditHtml);

        $widgetController = new BackendPresentationWidgetsController('widgets', SearchManager::$plugin);
        $widgetListHtml = $this->renderCaptured($widgetController->actionIndex());
        $this->assertNameLink(
            $widgetListHtml,
            'Database Widget',
            'search-manager/widgets/edit/' . $this->databaseWidgetId,
        );
        $this->assertNameLink(
            $widgetListHtml,
            'Config Widget',
            'search-manager/widgets/view/' . self::CONFIG_WIDGET,
        );

        $widgetEditResponse = $widgetController->actionEdit($this->databaseWidgetId);
        self::assertSame('search-manager/widgets/edit', $widgetEditResponse->data['template'] ?? null);
        self::assertSame(
            $this->databaseWidgetId,
            $widgetEditResponse->data['variables']['widgetConfig']->id ?? null,
        );
    }

    public function testCanonicalChoiceIdentityFeedsAllThreeSelectorSurfaces(): void
    {
        $this->actWithPermissions(['searchManager:manageSettings']);
        $settings = SearchManager::$plugin->getSettings();

        $choiceA = ConfiguredBackend::findByHandle(self::CHOICE_A);
        $choiceB = ConfiguredBackend::findByHandle(self::CHOICE_B);
        self::assertNotNull($choiceA);
        self::assertNotNull($choiceB);
        self::assertSame('MySQL (' . self::CHOICE_A . ')', $choiceA->getChoiceLabel());
        self::assertSame('MySQL (' . self::CHOICE_B . ')', $choiceB->getChoiceLabel());

        $settingsController = new SettingsPresentationSettingsController('settings', SearchManager::$plugin);
        $testVariables = $settingsController->actionTest()->data['variables'] ?? [];
        $diagnosticsHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/settings/test/_partials/backend',
            [
                'settings' => $testVariables['settings'],
                'backends' => $testVariables['backends'],
            ],
            View::TEMPLATE_MODE_CP,
        );
        self::assertStringContainsString(
            'MySQL (' . self::CHOICE_A . ') — Default',
            $diagnosticsHtml,
        );
        self::assertStringContainsString('MySQL (' . self::CHOICE_B . ')', $diagnosticsHtml);
        self::assertStringNotContainsString(
            'MySQL (' . self::CHOICE_B . ') — Default',
            $diagnosticsHtml,
        );
        self::assertStringNotContainsString(self::DISABLED_BACKEND, $diagnosticsHtml);

        $generalHtml = $this->renderCaptured($settingsController->actionGeneral());
        self::assertStringContainsString('MySQL (' . self::CHOICE_A . ')', $generalHtml);
        self::assertStringContainsString('MySQL (' . self::CHOICE_B . ')', $generalHtml);
        self::assertStringContainsString(
            'Config Precedence (' . self::COLLISION . ')',
            $generalHtml,
        );
        self::assertStringNotContainsString('Shadowed Database Backend', $generalHtml);
        self::assertStringNotContainsString(self::DISABLED_BACKEND, $generalHtml);

        $indexOptions = SearchManager::$plugin->backend->getBackendOptions();
        self::assertSame(
            'Default (MySQL (' . self::CHOICE_A . '))',
            $indexOptions[''] ?? null,
        );
        self::assertSame('MySQL (' . self::CHOICE_A . ')', $indexOptions[self::CHOICE_A] ?? null);
        self::assertSame('MySQL (' . self::CHOICE_B . ')', $indexOptions[self::CHOICE_B] ?? null);
        self::assertSame(
            'Config Precedence (' . self::COLLISION . ')',
            $indexOptions[self::COLLISION] ?? null,
        );
        self::assertArrayNotHasKey(self::DISABLED_BACKEND, $indexOptions);
        self::assertArrayNotHasKey(self::PREFIX . '-disabled-config', $indexOptions);
        self::assertSame(
            array_merge(
                [''],
                array_values(array_map(
                    static fn(ConfiguredBackend $backend): string => $backend->handle,
                    ConfiguredBackend::findAllEnabled(),
                )),
            ),
            array_keys($indexOptions),
        );

        $settings->defaultBackendHandle = self::PREFIX . '-missing-default';
        $missingDefaultOptions = SearchManager::$plugin->backend->getBackendOptions();
        self::assertSame(
            'Default (' . self::PREFIX . '-missing-default)',
            $missingDefaultOptions[''] ?? null,
        );
        $settings->defaultBackendHandle = self::CHOICE_A;
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(array $permissions): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);

        if ($this->originalUser === null) {
            $this->originalUser = Craft::$app->getUser();
        }

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

    private function renderCaptured(Response $response): string
    {
        $template = $response->data['template'] ?? null;
        $variables = $response->data['variables'] ?? null;
        self::assertIsString($template);
        self::assertIsArray($variables);

        $currentUser = Craft::$app->getUser()->getIdentity();
        self::assertNotNull($currentUser);

        return Craft::$app->getView()->renderTemplate(
            $template,
            array_merge($variables, ['currentUser' => $currentUser]),
            View::TEMPLATE_MODE_CP,
        );
    }

    private function assertNameLink(string $html, string $name, string $path): void
    {
        self::assertMatchesRegularExpression(
            '/href="[^"]*' . preg_quote($path, '/') . '"[^>]*>\\s*<span>'
            . preg_quote($name, '/') . '<\\/span>/',
            $html,
        );
    }

    private function assertBackendReadOnlyHtml(string $html): void
    {
        self::assertStringNotContainsString('id="main-form"', $html);
        self::assertStringNotContainsString('href="#settings"', $html);
        self::assertStringNotContainsString('<div id="settings"', $html);
        self::assertStringNotContainsString('id="save-button-container"', $html);
        self::assertStringNotContainsString('name="action" value="search-manager/backends/save"', $html);
        self::assertStringNotContainsString('name="backendType"', $html);
        self::assertStringNotContainsString('name="settings[', $html);
        self::assertStringNotContainsString('data-action="search-manager/backends/delete"', $html);
        self::assertStringContainsString('<div id="diagnostics"', $html);
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertActionForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('Expected manage-only access to remain forbidden.');
        } catch (ForbiddenHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    private function insertBackend(
        string $handle,
        string $name,
        string $backendType,
        bool $enabled = true,
    ): int {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => $name,
            'handle' => $handle,
            'backendType' => $backendType,
            'settings' => '{}',
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertWidget(string $handle, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $handle,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}

/**
 * @since 5.54.0
 */
final class BackendPresentationBackendsController extends BackendsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = ['template' => $template, 'variables' => $variables];

        return $response;
    }
}

/**
 * @since 5.54.0
 */
final class BackendPresentationWidgetsController extends WidgetsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = ['template' => $template, 'variables' => $variables];

        return $response;
    }
}

/**
 * @since 5.54.0
 */
final class SettingsPresentationSettingsController extends SettingsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = ['template' => $template, 'variables' => $variables];

        return $response;
    }
}

/**
 * @since 5.54.0
 */
final class BackendWidgetConfigConfigService extends Config
{
    /**
     * @param array<string, array<string, mixed>> $backends
     * @param array<string, array<string, mixed>> $widgets
     */
    public function __construct(
        private readonly Config $original,
        private readonly array $backends,
        private readonly array $widgets,
    ) {
        parent::__construct();
    }

    public function getConfigSettings(string $category): object
    {
        return $this->original->getConfigSettings($category);
    }

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        $config = $this->original->getConfigFromFile($filename);
        if ($filename !== 'search-manager') {
            return $config;
        }

        $config = is_array($config) ? $config : [];
        unset($config['defaultBackendHandle'], $config['defaultWidgetHandle']);
        $config['backends'] = $this->backends;
        $config['widgets'] = $this->widgets;

        return $config;
    }
}
