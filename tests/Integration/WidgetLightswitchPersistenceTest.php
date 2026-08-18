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
use craft\errors\MissingComponentException;
use craft\helpers\Json;
use craft\web\Request;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\services\WidgetStyleService;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\Response;

/**
 * @since 5.55.0
 */
#[CoversClass(WidgetsController::class)]
#[CoversClass(WidgetConfig::class)]
#[CoversClass(WidgetStyle::class)]
final class WidgetLightswitchPersistenceTest extends TestCase
{
    private const PREFIX = 'sm-widget-lightswitch-';
    private const WIDGET_HANDLE = self::PREFIX . 'config';
    private const STYLE_HANDLE = self::PREFIX . 'style';
    private const UNRELATED_WIDGET_HANDLE = self::PREFIX . 'unrelated-config';
    private const UNRELATED_STYLE_HANDLE = self::PREFIX . 'unrelated-style';

    /** @var array<string, string> */
    private const WIDGET_BOOLEAN_ACCESSORS = [
        'behavior.modalPreventBodyScroll' => 'isModalPreventBodyScrollEnabled',
        'behavior.loadingIndicatorEnabled' => 'isLoadingIndicatorEnabled',
        'trigger.triggerEnabled' => 'isTriggerEnabled',
        'behavior.recentlyViewedEnabled' => 'isRecentlyViewedEnabled',
        'behavior.resultsRequireUrl' => 'isResultsRequireUrlEnabled',
        'behavior.resultsGroupingEnabled' => 'isResultsGroupingEnabled',
        'behavior.snippetIncludeCodeBlocks' => 'isSnippetIncludeCodeBlocksEnabled',
        'behavior.snippetCleanMarkdown' => 'isSnippetCleanMarkdownEnabled',
        'behavior.highlightDestinationEnabled' => 'isHighlightDestinationEnabled',
        'behavior.highlightDestinationPersistQuery' => 'isHighlightDestinationPersistQueryEnabled',
    ];

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?object $originalConfigService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set('config', new SearchManagerConfigServiceStub($this->originalConfigService));

        $user = $this->createTestUser(self::PREFIX . 'user', ['admin' => true]);
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:manageWidgetConfigs',
            'searchManager:createWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:createWidgetStyles',
            'searchManager:editWidgetStyles',
        ]);
        $this->actingAs($user);

        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_settings}}', ['defaultWidgetHandle' => 'default'], ['id' => 1])
            ->execute();
        SearchManager::$plugin->getSettings()->defaultWidgetHandle = 'default';
    }

    protected function tearDown(): void
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
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
        }

        parent::tearDown();
    }

    public function testWidgetAndStyleLightswitchesRoundTripWithCanonicalStorage(): void
    {
        $this->createUnrelatedRows();
        $unrelatedWidgetBefore = $this->row('{{%searchmanager_widget_configs}}', self::UNRELATED_WIDGET_HANDLE);
        $unrelatedStyleBefore = $this->row('{{%searchmanager_widget_styles}}', self::UNRELATED_STYLE_HANDLE);

        $this->post($this->stylePayload(''));
        $this->runCpSave(
            static fn(): ?Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionSaveStyle(),
        );

        $style = SearchManager::$plugin->widgetStyles->getByHandle(self::STYLE_HANDLE);
        self::assertInstanceOf(WidgetStyle::class, $style);
        $this->assertStyleState($style, false);

        $this->post($this->widgetPayload('', ''));
        $this->runCpSave(
            static fn(): ?Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionSave(),
        );

        $widget = SearchManager::$plugin->widgetConfigs->getByHandle(self::WIDGET_HANDLE);
        self::assertInstanceOf(WidgetConfig::class, $widget);
        $this->assertWidgetState($widget, false);
        self::assertSame('default', SearchManager::$plugin->getSettings()->defaultWidgetHandle);

        $this->post($this->stylePayload('1', (int)$style->id));
        $this->runCpSave(
            static fn(): ?Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionSaveStyle(),
        );

        $style = SearchManager::$plugin->widgetStyles->getById((int)$style->id);
        self::assertInstanceOf(WidgetStyle::class, $style);
        $this->assertStyleState($style, true);

        $this->post($this->widgetPayload('1', '1', (int)$widget->id));
        $this->runCpSave(
            static fn(): ?Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionSave(),
        );

        $widget = SearchManager::$plugin->widgetConfigs->getById((int)$widget->id);
        self::assertInstanceOf(WidgetConfig::class, $widget);
        $this->assertWidgetState($widget, true);
        self::assertSame(self::WIDGET_HANDLE, SearchManager::$plugin->getSettings()->defaultWidgetHandle);

        self::assertSame($unrelatedWidgetBefore, $this->row('{{%searchmanager_widget_configs}}', self::UNRELATED_WIDGET_HANDLE));
        self::assertSame($unrelatedStyleBefore, $this->row('{{%searchmanager_widget_styles}}', self::UNRELATED_STYLE_HANDLE));
    }

    public function testHistoricalEmptyStringsReadAsFalseWithoutRewritingTheRow(): void
    {
        $settings = WidgetConfig::defaultSettings();
        foreach (array_keys(self::WIDGET_BOOLEAN_ACCESSORS) as $path) {
            $this->setNestedValue($settings, $path, '');
        }
        $settings['behavior']['recentlyViewedLimit'] = 0;
        $id = $this->insertHistoricalWidget($settings);
        $before = $this->rowById('{{%searchmanager_widget_configs}}', $id);

        $widget = SearchManager::$plugin->widgetConfigs->getById($id);
        self::assertInstanceOf(WidgetConfig::class, $widget);
        foreach (self::WIDGET_BOOLEAN_ACCESSORS as $method) {
            self::assertFalse($widget->$method(), $method . ' should recover the historical off sentinel');
        }
        self::assertTrue($widget->validate(), print_r($widget->getErrors(), true));
        self::assertSame($before, $this->rowById('{{%searchmanager_widget_configs}}', $id));
    }

    public function testMissingBooleanSettingsContinueUsingDocumentedDefaults(): void
    {
        $widget = new WidgetConfig();
        $widget->settings = [
            'behavior' => ['searchDebounceMs' => 475],
            'trigger' => [],
        ];

        self::assertTrue($widget->isModalPreventBodyScrollEnabled());
        self::assertTrue($widget->isLoadingIndicatorEnabled());
        self::assertTrue($widget->isTriggerEnabled());
        self::assertTrue($widget->isRecentlyViewedEnabled());
        self::assertFalse($widget->isResultsRequireUrlEnabled());
        self::assertTrue($widget->isResultsGroupingEnabled());
        self::assertFalse($widget->isSnippetIncludeCodeBlocksEnabled());
        self::assertFalse($widget->isSnippetCleanMarkdownEnabled());
        self::assertTrue($widget->isHighlightDestinationEnabled());
        self::assertTrue($widget->isHighlightDestinationPersistQueryEnabled());
        self::assertSame(475, $widget->getSearchDebounceMs());
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function canonicalBooleanValues(): iterable
    {
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'string one' => ['1', true];
        yield 'string zero' => ['0', false];
    }

    #[DataProvider('canonicalBooleanValues')]
    public function testCanonicalBooleanCompatibilityIsPreserved(mixed $value, bool $expected): void
    {
        $payload = $this->widgetPayload($value, '');
        $payload['styleHandle'] = '';
        $this->post($payload);
        $this->runCpSave(
            static fn(): ?Response => (new WidgetsController('widgets', SearchManager::$plugin))->actionSave(),
        );

        $storedWidget = SearchManager::$plugin->widgetConfigs->getByHandle(self::WIDGET_HANDLE);
        self::assertInstanceOf(WidgetConfig::class, $storedWidget);
        self::assertSame($expected, $storedWidget->enabled);
        $storedRow = $this->row('{{%searchmanager_widget_configs}}', self::WIDGET_HANDLE);
        $storedSettings = Json::decode((string)$storedRow['settings']);
        foreach (array_keys(self::WIDGET_BOOLEAN_ACCESSORS) as $path) {
            self::assertSame($expected, $this->nestedValue($storedSettings, $path));
        }

        $settings = WidgetConfig::defaultSettings();
        foreach (array_keys(self::WIDGET_BOOLEAN_ACCESSORS) as $path) {
            $this->setNestedValue($settings, $path, $value);
        }

        $widget = new WidgetConfig();
        $widget->settings = $settings;

        foreach (self::WIDGET_BOOLEAN_ACCESSORS as $method) {
            self::assertSame($expected, $widget->$method(), $method . ' compatibility changed');
        }
    }

    public function testConfigDefinedWidgetsAndStylesKeepCanonicalBooleanSemantics(): void
    {
        $widgetMethod = new \ReflectionMethod(new WidgetConfigService(), 'createFromConfig');
        $widget = $widgetMethod->invoke(new WidgetConfigService(), 'config-lightswitch', [
            'name' => 'Config Lightswitch',
            'enabled' => 'false',
            'settings' => [
                'behavior' => [
                    'recentlyViewedEnabled' => false,
                    'resultsRequireUrl' => '1',
                    'highlightDestinationEnabled' => 0,
                    'highlightDestinationPersistQuery' => true,
                ],
            ],
        ]);

        self::assertInstanceOf(WidgetConfig::class, $widget);
        self::assertTrue($widget->isFromConfig());
        self::assertFalse($widget->enabled);
        self::assertFalse($widget->isRecentlyViewedEnabled());
        self::assertTrue($widget->isResultsRequireUrlEnabled());
        self::assertFalse($widget->isHighlightDestinationEnabled());
        self::assertTrue($widget->isHighlightDestinationPersistQueryEnabled());

        $styleMethod = new \ReflectionMethod(new WidgetStyleService(), 'createFromConfig');
        $style = $styleMethod->invoke(new WidgetStyleService(), 'config-style-lightswitch', [
            'name' => 'Config Style Lightswitch',
            'enabled' => 'false',
            'styles' => [
                'backdropBlur' => '0',
                'highlightResultsEnabled' => '1',
            ],
        ]);

        self::assertInstanceOf(WidgetStyle::class, $style);
        self::assertTrue($style->isFromConfig());
        self::assertFalse($style->enabled);
        self::assertSame('0', $style->getStyles()['backdropBlur'] ?? null);
        self::assertSame('1', $style->getStyles()['highlightResultsEnabled'] ?? null);
    }

    public function testValidationRedisplayRetainsSubmittedOffState(): void
    {
        $payload = $this->widgetPayload('', '');
        $payload['name'] = '';
        $payload['styleHandle'] = '';
        $payload['settings']['behavior']['recentlyViewedLimit'] = 0;
        $this->post($payload);
        Craft::$app->getUrlManager()->setRouteParams([], false);

        try {
            (new WidgetsController('widgets', SearchManager::$plugin))->actionSave();
            self::fail('The console integration harness should not provide a CP session.');
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        $routeParams = Craft::$app->getUrlManager()->getRouteParams();
        self::assertIsArray($routeParams);
        $widget = $routeParams['widgetConfig'] ?? null;
        self::assertInstanceOf(WidgetConfig::class, $widget);
        self::assertFalse($widget->isRecentlyViewedEnabled());
        self::assertFalse($widget->isHighlightDestinationEnabled());
        self::assertFalse($widget->isHighlightDestinationPersistQueryEnabled());
        self::assertSame(false, $widget->getSetting('behavior.recentlyViewedEnabled'));
        self::assertEmpty($widget->getErrors('settings.behavior.recentlyViewedLimit'));
        self::assertNotEmpty($widget->getErrors('name'));
        self::assertNull(SearchManager::$plugin->widgetConfigs->getByHandle(self::WIDGET_HANDLE));
    }

    private function assertWidgetState(WidgetConfig $widget, bool $expected): void
    {
        self::assertSame($expected, $widget->enabled);
        foreach (self::WIDGET_BOOLEAN_ACCESSORS as $method) {
            self::assertSame($expected, $widget->$method(), $method . ' did not reload canonically');
        }

        $row = $this->row('{{%searchmanager_widget_configs}}', self::WIDGET_HANDLE);
        self::assertSame($expected ? 1 : 0, (int)$row['enabled']);
        $stored = Json::decode((string)$row['settings']);
        foreach (array_keys(self::WIDGET_BOOLEAN_ACCESSORS) as $path) {
            self::assertSame($expected, $this->nestedValue($stored, $path), $path . ' was not stored as a JSON boolean');
        }
        self::assertSame('Lightswitch persistence', $stored['search']['placeholder'] ?? null);
        self::assertSame(475, $stored['behavior']['searchDebounceMs'] ?? null);
        self::assertSame($expected ? 5 : 0, $stored['behavior']['recentlyViewedLimit'] ?? null);
    }

    private function assertStyleState(WidgetStyle $style, bool $expected): void
    {
        self::assertSame($expected, $style->enabled);
        $row = $this->row('{{%searchmanager_widget_styles}}', self::STYLE_HANDLE);
        self::assertSame($expected ? 1 : 0, (int)$row['enabled']);
        $stored = Json::decode((string)$row['styles']);
        self::assertSame($expected ? '1' : '0', $stored['backdropBlur'] ?? null);
        self::assertSame($expected ? '1' : '0', $stored['highlightResultsEnabled'] ?? null);
        self::assertSame('2', $stored['modalBorderWidth'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetPayload(mixed $switchValue, string $isDefault, ?int $id = null): array
    {
        $settings = WidgetConfig::defaultSettings();
        foreach (array_keys(self::WIDGET_BOOLEAN_ACCESSORS) as $path) {
            $this->setNestedValue($settings, $path, $switchValue);
        }
        $settings['search']['placeholder'] = 'Lightswitch persistence';
        $settings['behavior']['searchDebounceMs'] = 475;
        $settings['behavior']['recentlyViewedLimit'] = $switchValue === '' ? 0 : 5;

        return [
            'configId' => $id,
            'name' => 'Widget Lightswitch Persistence',
            'handle' => self::WIDGET_HANDLE,
            'type' => WidgetStyle::TYPE_MODAL,
            'enabled' => $switchValue,
            'settings' => $settings,
            'styleHandle' => self::STYLE_HANDLE,
            'isDefault' => $isDefault,
            'redirect' => 'search-manager/widgets',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stylePayload(string $switchValue, ?int $id = null): array
    {
        return [
            'styleId' => $id,
            'name' => 'Widget Style Lightswitch Persistence',
            'handle' => self::STYLE_HANDLE,
            'type' => WidgetStyle::TYPE_MODAL,
            'enabled' => $switchValue,
            'styles' => [
                'backdropBlur' => $switchValue,
                'highlightResultsEnabled' => $switchValue,
                'modalBorderWidth' => '2',
            ],
            'redirect' => 'search-manager/widgets/styles',
        ];
    }

    private function createUnrelatedRows(): void
    {
        $widget = new WidgetConfig();
        $widget->name = 'Unrelated Widget';
        $widget->handle = self::UNRELATED_WIDGET_HANDLE;
        $widget->type = WidgetStyle::TYPE_MODAL;
        $widget->settings = WidgetConfig::defaultSettings();
        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($widget), print_r($widget->getErrors(), true));

        $style = new WidgetStyle();
        $style->name = 'Unrelated Style';
        $style->handle = self::UNRELATED_STYLE_HANDLE;
        $style->type = WidgetStyle::TYPE_MODAL;
        $style->styles = ['modalBorderWidth' => '3'];
        self::assertTrue(SearchManager::$plugin->widgetStyles->save($style), print_r($style->getErrors(), true));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function insertHistoricalWidget(array $settings): int
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'name' => 'Historical Lightswitch Widget',
            'handle' => self::PREFIX . 'historical',
            'type' => WidgetStyle::TYPE_MODAL,
            'styleHandle' => null,
            'settings' => Json::encode($settings),
            'enabled' => 1,
            'dateCreated' => '2026-08-18 00:00:00',
            'dateUpdated' => '2026-08-18 00:00:00',
            'uid' => '7bc12250-785a-4b39-9ebf-0904b120912a',
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, string $handle): array
    {
        $row = (new Query())->from($table)->where(['handle' => $handle])->one();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowById(string $table, int $id): array
    {
        $row = (new Query())->from($table)->where(['id' => $id])->one();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function setNestedValue(array &$values, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $current = &$values;
        foreach ($segments as $segment) {
            $current = &$current[$segment];
        }
        $current[$last] = $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function nestedValue(array $values, string $path): mixed
    {
        $current = $values;
        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($current);
            self::assertArrayHasKey($segment, $current);
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function post(array $params): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
        }

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams($params);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /**
     * @param callable(): (?Response) $callback
     */
    private function runCpSave(callable $callback): void
    {
        try {
            $callback();
            self::fail('The console integration harness should not provide a CP session.');
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }
    }
}
