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
use craft\base\Plugin;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\ControlledSettings;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
#[CoversClass(BackendsController::class)]
#[CoversClass(WidgetsController::class)]
final class DefaultPersistenceControllerTest extends TestCase
{
    private const PREFIX = 'sm-pr1-27';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?object $originalConfigService = null;
    private mixed $originalSettings = null;
    private \ReflectionProperty $settingsProperty;
    private ControlledSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set(
            'config',
            new SearchManagerConfigServiceStub($this->originalConfigService),
        );

        $this->settingsProperty = new \ReflectionProperty(Plugin::class, '_settings');
        $this->settingsProperty->setAccessible(true);
        $this->originalSettings = $this->settingsProperty->getValue(SearchManager::$plugin);
        $this->settings = new ControlledSettings();
        $this->settings->setAttributes(Settings::loadFromDatabase()->getAttributes(), false);
        $this->settingsProperty->setValue(SearchManager::$plugin, $this->settings);
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeRows();
        $this->settingsProperty->setValue(SearchManager::$plugin, $this->originalSettings);
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function listDefaultStates(): iterable
    {
        foreach (['backend', 'widget'] as $family) {
            foreach (['empty', 'invalid', 'missing', 'disabled'] as $state) {
                yield $family . ' ' . $state => [$family, $state];
            }
        }
    }

    #[DataProvider('listDefaultStates')]
    public function testManageOnlyListGetLeavesSettingsRowByteIdentical(string $family, string $state): void
    {
        if ($family === 'backend') {
            $this->insertBackend('enabled-list', true);
            if ($state === 'disabled') {
                $this->insertBackend('disabled-list', false);
            }
        } else {
            $this->insertWidget('enabled-list', true);
            if ($state === 'disabled') {
                $this->insertWidget('disabled-list', false);
            }
        }

        $default = match ($state) {
            'empty' => null,
            'invalid' => '!!! invalid !!!',
            'missing' => self::PREFIX . '-missing',
            'disabled' => self::PREFIX . '-disabled-list',
        };
        $this->setPersistedDefault($family, $default);
        $before = $this->settingsRow();
        $this->actAsManageOnly($family);
        $this->withRequest('GET', 'text/html');

        $response = $family === 'backend'
            ? (new Pr127BackendsController('backends', SearchManager::$plugin))->actionIndex()
            : (new Pr127WidgetsController('widgets', SearchManager::$plugin))->actionIndex();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame($before, $this->settingsRow());
        self::assertSame([], $this->settings->saveCalls);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defaultWritePaths(): iterable
    {
        foreach (['backend', 'widget'] as $family) {
            foreach (['create-toggle', 'create-auto', 'explicit'] as $path) {
                yield $family . ' ' . $path => [$family, $path];
            }
        }
    }

    #[DataProvider('defaultWritePaths')]
    public function testDefaultPersistenceFailureIsSurfacedTruthfully(string $family, string $path): void
    {
        $this->setPersistedDefault($family, null);
        $this->settings->failWrites = true;
        $targetId = null;

        if ($path === 'explicit') {
            $targetId = $family === 'backend'
                ? $this->insertBackend('failure-explicit', true)
                : $this->insertWidget('failure-explicit', true);
            $this->actAsEditor($family);
            $this->withRequest('POST', 'application/json', [
                $family === 'backend' ? 'backendId' : 'configId' => $targetId,
            ]);
        } else {
            $this->actAsCreator($family);
            $this->withRequest('POST', 'text/html', $this->createPayload(
                $family,
                'failure-' . $path,
                $path === 'create-toggle',
            ));
        }

        $controller = $family === 'backend'
            ? new Pr127BackendsController('backends', SearchManager::$plugin)
            : new Pr127WidgetsController('widgets', SearchManager::$plugin);
        if ($path === 'explicit') {
            $result = $controller->actionSetDefault();
        } else {
            $result = $controller->actionSave();
        }

        $expectedError = $family === 'backend'
            ? 'Failed to update default backend'
            : 'Failed to update default widget';
        if ($path === 'explicit') {
            self::assertSame(['success' => false, 'error' => $expectedError], $result->data);
        } else {
            self::assertInstanceOf(Response::class, $result);
            self::assertSame($expectedError, $controller->saveError);
            self::assertFalse($controller->saveSucceeded);
        }

        self::assertSame(null, $this->persistedDefault($family));
        self::assertSame(null, $this->effectiveDefault($family));
        self::assertSame(
            [[$family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle']],
            $this->settings->saveCalls,
        );
        self::assertSame([], array_values(array_filter(
            $controller->infoLogs,
            static fn(array $log): bool => stripos($log['message'], 'default') !== false,
        )));
    }

    #[DataProvider('defaultWritePaths')]
    public function testSuccessfulDefaultWritePathsRemainAvailable(string $family, string $path): void
    {
        $this->setPersistedDefault($family, null);
        $targetHandle = self::PREFIX . '-success-' . $path;

        if ($path === 'explicit') {
            $targetId = $family === 'backend'
                ? $this->insertBackend('success-explicit', true)
                : $this->insertWidget('success-explicit', true);
            $this->actAsEditor($family);
            $this->withRequest('POST', 'application/json', [
                $family === 'backend' ? 'backendId' : 'configId' => $targetId,
            ]);
        } else {
            $this->actAsCreator($family);
            $this->withRequest('POST', 'text/html', $this->createPayload(
                $family,
                'success-' . $path,
                $path === 'create-toggle',
            ));
        }

        $controller = $family === 'backend'
            ? new Pr127BackendsController('backends', SearchManager::$plugin)
            : new Pr127WidgetsController('widgets', SearchManager::$plugin);
        if ($path === 'explicit') {
            $result = $controller->actionSetDefault();
        } else {
            $result = $controller->actionSave();
        }

        if ($path === 'explicit') {
            self::assertTrue($result->data['success'] ?? false);
            self::assertSame($targetHandle, $this->persistedDefault($family));
        } else {
            self::assertInstanceOf(Response::class, $result);
            self::assertTrue($controller->saveSucceeded);
            self::assertNull($controller->saveError);
            $expectedHandle = $path === 'create-toggle'
                ? $targetHandle
                : $this->firstEnabledHandle($family);
            self::assertSame($expectedHandle, $this->persistedDefault($family));
        }

        self::assertSame($this->persistedDefault($family), $this->effectiveDefault($family));
        self::assertSame(
            [[$family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle']],
            $this->settings->saveCalls,
        );
        self::assertCount(1, array_filter(
            $controller->infoLogs,
            static fn(array $log): bool => stripos($log['message'], 'default') !== false,
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function configOverridePaths(): iterable
    {
        foreach (['backend', 'widget'] as $family) {
            foreach (['save', 'explicit'] as $path) {
                yield $family . ' ' . $path => [$family, $path];
            }
        }
    }

    #[DataProvider('configOverridePaths')]
    public function testConfigOverrideRemainsImmutableAndAuthoritative(string $family, string $path): void
    {
        $configuredHandle = $family === 'backend' ? 'mysql' : 'default';
        Craft::$app->set(
            'config',
            new SearchManagerConfigServiceStub($this->originalConfigService, [
                $family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle' => $configuredHandle,
            ]),
        );
        $this->setPersistedDefault($family, self::PREFIX . '-database-value');
        $targetId = $family === 'backend'
            ? $this->insertBackend('config-target', true)
            : $this->insertWidget('config-target', true);

        if ($path === 'explicit') {
            $this->actAsEditor($family);
            $this->withRequest('POST', 'application/json', [
                $family === 'backend' ? 'backendId' : 'configId' => $targetId,
            ]);
        } else {
            $this->actAsCreator($family);
            $this->withRequest('POST', 'text/html', $this->createPayload(
                $family,
                'config-save',
                true,
            ));
        }

        $controller = $family === 'backend'
            ? new Pr127BackendsController('backends', SearchManager::$plugin)
            : new Pr127WidgetsController('widgets', SearchManager::$plugin);
        $result = $path === 'explicit'
            ? $controller->actionSetDefault()
            : $controller->actionSave();

        if ($path === 'explicit') {
            self::assertFalse($result->data['success'] ?? true);
            self::assertStringContainsString('set via config file', $result->data['error']);
        } else {
            self::assertInstanceOf(Response::class, $result);
            self::assertTrue($controller->saveSucceeded);
            self::assertNull($controller->saveError);
        }
        self::assertSame(self::PREFIX . '-database-value', $this->persistedDefault($family));
        self::assertSame($configuredHandle, $this->effectiveDefault($family));
        self::assertSame([], $this->settings->saveCalls);
    }

    private function actAsManageOnly(string $family): void
    {
        $permission = $family === 'backend'
            ? 'searchManager:manageBackends'
            : 'searchManager:manageWidgetConfigs';
        $user = $this->createTestUser(self::PREFIX . '-manage-' . $family);
        $this->grantPermissions($user, ['accessCp', $permission]);
        $this->actingAs($user);

        self::assertFalse(Craft::$app->getUser()->checkPermission(
            $family === 'backend'
                ? 'searchManager:editBackends'
                : 'searchManager:editWidgetConfigs',
        ));
    }

    private function actAsEditor(string $family): void
    {
        $permissions = $family === 'backend'
            ? ['searchManager:manageBackends', 'searchManager:editBackends']
            : ['searchManager:manageWidgetConfigs', 'searchManager:editWidgetConfigs'];
        $user = $this->createTestUser(self::PREFIX . '-edit-' . $family);
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);
    }

    private function actAsCreator(string $family): void
    {
        $permissions = $family === 'backend'
            ? ['searchManager:manageBackends', 'searchManager:createBackends']
            : ['searchManager:manageWidgetConfigs', 'searchManager:createWidgetConfigs'];
        $user = $this->createTestUser(self::PREFIX . '-create-' . $family);
        $this->grantPermissions($user, array_merge(['accessCp'], $permissions));
        $this->actingAs($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(string $family, string $suffix, bool $isDefault): array
    {
        if ($family === 'backend') {
            return [
                'name' => 'PR1.27 Backend ' . $suffix,
                'handle' => self::PREFIX . '-' . $suffix,
                'backendType' => 'file',
                'enabled' => '1',
                'settings' => [],
                'isDefault' => $isDefault ? '1' : '0',
                'redirect' => 'search-manager/backends',
            ];
        }

        return [
            'name' => 'PR1.27 Widget ' . $suffix,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => 'modal',
            'enabled' => '1',
            'settings' => [],
            'isDefault' => $isDefault ? '1' : '0',
            'redirect' => 'search-manager/widgets',
        ];
    }

    private function insertBackend(string $suffix, bool $enabled): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'PR1.27 Backend ' . $suffix,
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertWidget(string $suffix, bool $enabled): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'name' => 'PR1.27 Widget ' . $suffix,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => '{}',
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function setPersistedDefault(string $family, ?string $handle): void
    {
        $column = $family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle';
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_settings}}', [$column => $handle], ['id' => 1])
            ->execute();
        $this->settings->$column = $handle;
        $this->settings->saveCalls = [];
    }

    private function persistedDefault(string $family): ?string
    {
        $column = $family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle';
        $value = (new Query())
            ->select([$column])
            ->from('{{%searchmanager_settings}}')
            ->where(['id' => 1])
            ->scalar();

        return $value === false || $value === null ? null : (string)$value;
    }

    private function effectiveDefault(string $family): ?string
    {
        $settings = SearchManager::$plugin->getSettings();
        return $family === 'backend'
            ? $settings->defaultBackendHandle
            : $settings->defaultWidgetHandle;
    }

    private function firstEnabledHandle(string $family): string
    {
        $resources = $family === 'backend'
            ? ConfiguredBackend::findAll()
            : SearchManager::$plugin->widgetConfigs->getAll();

        foreach ($resources as $resource) {
            if ($resource->enabled) {
                return $resource->handle;
            }
        }

        self::fail('Expected an enabled resource.');
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsRow(): array
    {
        $row = (new Query())
            ->from('{{%searchmanager_settings}}')
            ->where(['id' => 1])
            ->one();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function withRequest(string $method, string $accept, array $body = []): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams($body);
        $request->getHeaders()->set('Accept', $accept);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = $method;
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
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

final class Pr127BackendsController extends BackendsController
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $infoLogs = [];
    public ?string $saveError = null;
    public bool $saveSucceeded = false;

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = $variables;
        return $response;
    }

    protected function logInfo(string $message, array $params = []): void
    {
        $this->infoLogs[] = compact('message', 'params');
    }

    protected function backendSaveResponse(ConfiguredBackend $backend, ?string $error = null): Response
    {
        $this->saveError = $error;
        $this->saveSucceeded = $error === null;
        return new Response();
    }
}

final class Pr127WidgetsController extends WidgetsController
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $infoLogs = [];
    public ?string $saveError = null;
    public bool $saveSucceeded = false;

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = $variables;
        return $response;
    }

    protected function logInfo(string $message, array $params = []): void
    {
        $this->infoLogs[] = compact('message', 'params');
    }

    protected function widgetSaveResponse(WidgetConfig $widget, ?string $error = null): Response
    {
        $this->saveError = $error;
        $this->saveSucceeded = $error === null;
        return new Response();
    }
}
