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
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\services\WidgetStyleService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Regression coverage for effective Widget Style usage inventory.
 *
 * @since 5.54.0
 */
#[CoversClass(DependencyService::class)]
#[CoversClass(WidgetStyleService::class)]
#[CoversClass(WidgetsController::class)]
final class WidgetStyleEffectiveUsageTest extends TestCase
{
    private const PREFIX = 'sm-effective-style-usage';

    private mixed $originalConfigCache = null;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?object $originalUser = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->purgeMarkedRows();
        $this->setSearchManagerConfig();
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        $this->setConfigCache($this->originalConfigCache);
        $this->resetConfigServiceCaches();
        parent::tearDown();
    }

    public function testEffectiveInventoryOwnsDetailedUsagesAndCounts(): void
    {
        $this->insertWidget('database-only', 'Database Only', $this->handle('database-only-style'));
        $this->insertWidget('collision', 'Shadowed Database Widget', $this->handle('shadowed-style'));
        $this->insertWidget('shared-database', 'Shared Database Widget', $this->handle('shared-style'));
        $this->insertWidget('empty-database', 'Empty Database Widget', '');
        $this->insertWidget('null-database', 'Null Database Widget', null);

        $counts = $this->markedCounts(SearchManager::$plugin->widgetStyles->getUsageCountsByHandle());
        $dependencyCounts = $this->markedCounts(
            SearchManager::$plugin->dependencies->getStyleUsageCountsByHandle(),
        );

        self::assertSame([
            $this->handle('bulk-used-style') => 1,
            $this->handle('config-authoritative-style') => 1,
            $this->handle('config-only-style') => 1,
            $this->handle('database-only-style') => 1,
            $this->handle('disabled-style') => 1,
            $this->handle('shared-style') => 2,
            $this->handle('surface-config-style') => 2,
            $this->handle('surface-database-style') => 1,
        ], $counts);
        self::assertSame($counts, $dependencyCounts);

        self::assertSame([
            [
                'type' => 'Widget',
                'label' => 'Config Authoritative Widget',
                'kind' => 'widget',
            ],
        ], SearchManager::$plugin->dependencies->getStyleUsages($this->handle('config-authoritative-style')));
        self::assertSame([], SearchManager::$plugin->dependencies->getStyleUsages($this->handle('shadowed-style')));
        self::assertSame([], SearchManager::$plugin->dependencies->getStyleUsages(''));
        self::assertSame([], SearchManager::$plugin->dependencies->getStyleUsages($this->handle('unused-style')));

        $sharedUsages = SearchManager::$plugin->dependencies->getStyleUsages($this->handle('shared-style'));
        self::assertSame(
            ['Shared Config Widget', 'Shared Database Widget'],
            array_column($sharedUsages, 'label'),
        );
        self::assertCount($counts[$this->handle('shared-style')], $sharedUsages);
    }

    public function testSingleAndBulkDeletionUseTheEffectiveInventory(): void
    {
        $configOnlyStyleId = $this->insertStyle('config-only-style', 'Config-only Used Style');
        $shadowedStyleId = $this->insertStyle('shadowed-style', 'Shadowed Database Style');
        $disabledStyleId = $this->insertStyle('disabled-style', 'Disabled Widget Style');
        $unusedStyleId = $this->insertStyle('unused-style', 'Unused Style');
        $bulkUsedStyleId = $this->insertStyle('bulk-used-style', 'Bulk Used Style');
        $bulkUnusedStyleId = $this->insertStyle('bulk-unused-style', 'Bulk Unused Style');
        $this->insertWidget('collision', 'Shadowed Database Widget', $this->handle('shadowed-style'));

        $this->actWithPermissions();

        $configOnlyResponse = $this->postJson(
            ['styleId' => $configOnlyStyleId],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionDeleteStyle(),
        );
        self::assertSame(false, $configOnlyResponse->data['success'] ?? true);
        self::assertStringContainsString('Config Only Widget', (string)($configOnlyResponse->data['error'] ?? ''));
        self::assertSame(1, $this->countStyle($configOnlyStyleId));

        $disabledResponse = $this->postJson(
            ['styleId' => $disabledStyleId],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionDeleteStyle(),
        );
        self::assertSame(false, $disabledResponse->data['success'] ?? true);
        self::assertStringContainsString('Disabled Config Widget', (string)($disabledResponse->data['error'] ?? ''));
        self::assertSame(1, $this->countStyle($disabledStyleId));

        $shadowedResponse = $this->postJson(
            ['styleId' => $shadowedStyleId],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionDeleteStyle(),
        );
        self::assertSame(true, $shadowedResponse->data['success'] ?? false);
        self::assertSame(0, $this->countStyle($shadowedStyleId));

        $unusedResponse = $this->postJson(
            ['styleId' => $unusedStyleId],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionDeleteStyle(),
        );
        self::assertSame(true, $unusedResponse->data['success'] ?? false);
        self::assertSame(0, $this->countStyle($unusedStyleId));

        $bulkResponse = $this->postJson(
            ['styleIds' => [$bulkUsedStyleId, $bulkUnusedStyleId]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionBulkDeleteStyle(),
        );
        self::assertSame(false, $bulkResponse->data['success'] ?? true);
        self::assertStringContainsString('Bulk Config Widget', (string)($bulkResponse->data['error'] ?? ''));
        self::assertSame(1, $this->countStyle($bulkUsedStyleId));
        self::assertSame(1, $this->countStyle($bulkUnusedStyleId));
    }

    public function testListDatabaseEditAndConfigViewRenderEffectiveCounts(): void
    {
        $databaseStyleId = $this->insertStyle('surface-database-style', 'Surface Database Style');
        $this->insertWidget('surface-database', 'Surface Database Widget', $this->handle('surface-database-style'));
        $this->actWithPermissions();
        $this->withGetRequest();

        $controller = new CapturingWidgetStylesController('widgets', SearchManager::$plugin);

        $listResponse = $controller->actionStylesIndex();
        self::assertSame('search-manager/widgets/styles/index', $listResponse->data['template'] ?? null);
        $listVariables = $listResponse->data['variables'] ?? [];
        self::assertSame(2, $listVariables['styleUsageCounts'][$this->handle('surface-config-style')] ?? 0);
        self::assertSame(2, $listVariables['styleUsageCounts'][$this->handle('surface-database-style')] ?? 0);

        $editResponse = $controller->actionEditStyle($databaseStyleId);
        self::assertSame('search-manager/widgets/styles/edit', $editResponse->data['template'] ?? null);
        $editVariables = $editResponse->data['variables'] ?? [];
        self::assertSame(2, $editVariables['usageCount'] ?? 0);

        $viewResponse = $controller->actionViewStyle($this->handle('surface-config-style'));
        self::assertSame('search-manager/widgets/styles/view', $viewResponse->data['template'] ?? null);
        $viewVariables = $viewResponse->data['variables'] ?? [];
        self::assertSame(2, $viewVariables['usageCount'] ?? 0);

        $currentUser = Craft::$app->getUser()->getIdentity();
        self::assertNotNull($currentUser);

        $listHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/styles/index',
            array_merge($listVariables, ['currentUser' => $currentUser]),
            View::TEMPLATE_MODE_CP,
        );
        self::assertMatchesRegularExpression(
            '/' . preg_quote($this->handle('surface-config-style'), '/') . '.*?'
            . '<td data-column="widgets">\\s*<span>2<\\/span>/s',
            $listHtml,
        );

        $editHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/styles/edit',
            array_merge($editVariables, ['currentUser' => $currentUser]),
            View::TEMPLATE_MODE_CP,
        );
        self::assertMatchesRegularExpression('/Widgets Using.*?<dd class="value">2<\\/dd>/s', $editHtml);

        $viewHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/styles/view',
            array_merge($viewVariables, ['currentUser' => $currentUser]),
            View::TEMPLATE_MODE_CP,
        );
        self::assertMatchesRegularExpression('/Widgets Using.*?<dd class="value">2<\\/dd>/s', $viewHtml);
    }

    private function setSearchManagerConfig(): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }

        $cache['search-manager'] = [
            'widgets' => [
                $this->handle('config-only') => $this->widgetDefinition('Config Only Widget', 'config-only-style'),
                $this->handle('collision') => $this->widgetDefinition('Config Authoritative Widget', 'config-authoritative-style'),
                $this->handle('disabled') => $this->widgetDefinition('Disabled Config Widget', 'disabled-style', false),
                $this->handle('shared-config') => $this->widgetDefinition('Shared Config Widget', 'shared-style'),
                $this->handle('empty-config') => $this->widgetDefinition('Empty Config Widget', ''),
                $this->handle('null-config') => $this->widgetDefinition('Null Config Widget', null),
                $this->handle('bulk-config') => $this->widgetDefinition('Bulk Config Widget', 'bulk-used-style'),
                $this->handle('surface-config-one') => $this->widgetDefinition('Surface Config One', 'surface-config-style'),
                $this->handle('surface-config-two') => $this->widgetDefinition('Surface Config Two', 'surface-config-style'),
                $this->handle('surface-database-config') => $this->widgetDefinition('Surface Database Config', 'surface-database-style'),
            ],
            'widgetStyles' => [
                $this->handle('surface-config-style') => [
                    'name' => 'Surface Config Style',
                    'type' => 'modal',
                    'enabled' => true,
                    'styles' => [],
                ],
            ],
        ];

        $this->setConfigCache($cache);
        $this->resetConfigServiceCaches();
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetDefinition(string $name, ?string $styleHandle, bool $enabled = true): array
    {
        return [
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => $styleHandle === null || $styleHandle === '' ? $styleHandle : $this->handle($styleHandle),
            'enabled' => $enabled,
            'settings' => [],
        ];
    }

    private function resetConfigServiceCaches(): void
    {
        $widgetConfigsProperty = new \ReflectionProperty(SearchManager::$plugin->widgetConfigs, '_configFileConfigs');
        $widgetConfigsProperty->setAccessible(true);
        $widgetConfigsProperty->setValue(SearchManager::$plugin->widgetConfigs, null);

        $widgetStylesProperty = new \ReflectionProperty(SearchManager::$plugin->widgetStyles, '_configFileStyles');
        $widgetStylesProperty->setAccessible(true);
        $widgetStylesProperty->setValue(SearchManager::$plugin->widgetStyles, null);
    }

    private function actWithPermissions(): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:manageWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:createWidgetStyles',
            'searchManager:editWidgetStyles',
            'searchManager:deleteWidgetStyles',
        ]);
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
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): Response $callback
     */
    private function postJson(array $params, callable $callback): Response
    {
        $this->withWebRequest('POST');
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');

        return $callback();
    }

    private function withGetRequest(): void
    {
        $this->withWebRequest('GET');
        Craft::$app->getRequest()->setBodyParams([]);
        Craft::$app->getRequest()->getHeaders()->remove('Accept');
    }

    private function withWebRequest(string $method): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = $method;
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalUser !== null) {
            Craft::$app->set('user', $this->originalUser);
            $this->originalUser = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }
    }

    private function insertStyle(string $suffix, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => $this->handle($suffix),
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

    private function insertWidget(string $suffix, string $name, ?string $styleHandle, bool $enabled = true): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $this->handle($suffix),
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => $styleHandle,
            'settings' => json_encode(WidgetConfig::defaultSettings(), JSON_THROW_ON_ERROR),
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param array<string, int|string> $counts
     * @return array<string, int>
     */
    private function markedCounts(array $counts): array
    {
        $marked = [];
        foreach ($counts as $handle => $count) {
            if (str_starts_with($handle, self::PREFIX . '-')) {
                $marked[$handle] = (int)$count;
            }
        }
        ksort($marked);

        return $marked;
    }

    private function countStyle(int $id): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_widget_styles}}')
            ->where(['id' => $id])
            ->count();
    }

    private function handle(string $suffix): string
    {
        return self::PREFIX . '-' . $suffix;
    }

    private function purgeMarkedRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}

final class CapturingWidgetStylesController extends WidgetsController
{
    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $response = new Response();
        $response->data = [
            'template' => $template,
            'variables' => $variables,
        ];

        return $response;
    }
}
