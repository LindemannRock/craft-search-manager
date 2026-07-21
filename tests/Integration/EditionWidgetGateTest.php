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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.54.0
 */
final class EditionWidgetGateTest extends TestCase
{
    private const PREFIX = 'sm-edition-widget-';
    private const STYLE_HANDLE = self::PREFIX . 'style';
    private const WIDGET_HANDLE = self::PREFIX . 'config';
    private const PRESET_COLOR = '#123456';
    private const INLINE_COLOR = '#abcdef';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?string $originalTemplateMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();
        $this->purgeFixtures();
        $this->insertStyle();
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeFixtures();

        if ($this->originalTemplateMode !== null) {
            Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
        }

        parent::tearDown();
    }

    public function testProConfiguredWidgetRendersInertDefaultsInStandardAndRestoresInPro(): void
    {
        $widget = $this->proConfiguredWidget();

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardHtml = $this->renderWidget($widget, [
            'styles' => ['modalBg' => self::INLINE_COLOR],
        ]);
        $decodedStandardHtml = html_entity_decode($standardHtml, ENT_QUOTES | ENT_HTML5);

        self::assertStringNotContainsString('promotion-display=', $standardHtml);
        self::assertStringNotContainsString('promotion-badge-text=', $standardHtml);
        self::assertStringNotContainsString('promotion-badge-position=', $standardHtml);
        self::assertStringNotContainsString('analytics-source=', $standardHtml);
        self::assertStringNotContainsString('analytics-idle-timeout-ms=', $standardHtml);
        self::assertStringNotContainsString(self::PRESET_COLOR, $decodedStandardHtml);
        self::assertStringContainsString(self::INLINE_COLOR, $decodedStandardHtml);
        self::assertStringContainsString('recently-viewed-enabled="true"', $standardHtml);
        self::assertStringContainsString('highlight-destination-enabled="true"', $standardHtml);
        self::assertStringContainsString('snippet-mode="balanced"', $standardHtml);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proHtml = $this->renderWidget($widget);
        $decodedProHtml = html_entity_decode($proHtml, ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('promotion-display="badge"', $proHtml);
        self::assertStringContainsString('promotion-badge-text="Sponsored"', $proHtml);
        self::assertStringContainsString('promotion-badge-position="above"', $proHtml);
        self::assertStringContainsString('analytics-source="header-search"', $proHtml);
        self::assertStringContainsString('analytics-idle-timeout-ms="2750"', $proHtml);
        self::assertStringContainsString(self::PRESET_COLOR, $decodedProHtml);
    }

    public function testWidgetStyleReadsRemainAvailableWhileWritesAreRejectedInStandard(): void
    {
        $style = SearchManager::$plugin->widgetStyles->getByHandle(self::STYLE_HANDLE);
        self::assertInstanceOf(WidgetStyle::class, $style);

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);

        self::assertInstanceOf(WidgetStyle::class, SearchManager::$plugin->widgetStyles->getById((int)$style->id));
        $this->assertForbidden(static fn() => SearchManager::$plugin->widgetStyles->save(new WidgetStyle()));
        $this->assertForbidden(static fn() => SearchManager::$plugin->widgetStyles->delete((int)$style->id));
        self::assertSame(1, $this->fixtureCount('{{%searchmanager_widget_styles}}', ['handle' => self::STYLE_HANDLE]));
    }

    public function testStandardEditorHidesProSurfacesAndKeepsSevenFunctionalTabs(): void
    {
        $widget = $this->proConfiguredWidget();

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $standardHtml = $this->renderEditorPartials($widget, false);
        $editSource = file_get_contents(dirname(__DIR__, 2) . '/src/templates/widgets/edit.twig');
        $previewSource = file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/widgetpreview/src/widget-preview.js');
        self::assertIsString($editSource);
        self::assertIsString($previewSource);

        foreach (['general', 'searchInput', 'modalTrigger', 'recentlyViewed', 'results', 'snippets', 'destinationHighlighting'] as $tab) {
            self::assertStringContainsString("url: '#{$tab}'", $editSource);
        }

        $tabsStart = strpos($editSource, '{% set tabs = {');
        $proTabsStart = strpos($editSource, '{% if isPro %}', $tabsStart);
        self::assertIsInt($tabsStart);
        self::assertIsInt($proTabsStart);
        $standardTabsSource = substr($editSource, $tabsStart, $proTabsStart - $tabsStart);
        self::assertSame(7, substr_count($standardTabsSource, "url: '#"));
        self::assertStringNotContainsString("url: '#analytics'", $standardTabsSource);
        self::assertStringContainsString("url: '#analytics'", $editSource);
        self::assertStringNotContainsString('edition-upgrade-prompt', $editSource);
        self::assertStringNotContainsString('featureName:', $editSource);
        self::assertStringNotContainsString('requires Search Manager Pro', $standardHtml);
        self::assertStringNotContainsString('plugins.craftcms.com', $standardHtml);
        self::assertStringNotContainsString('name="settings[behavior][promotionDisplay]"', $standardHtml);
        self::assertStringNotContainsString('name="settings[analytics][analyticsSource]"', $standardHtml);
        self::assertStringContainsString('name="settings[behavior][resultsLimit]"', $standardHtml);
        self::assertStringContainsString('name="settings[behavior][resultsLayout]"', $standardHtml);
        self::assertStringContainsString('<div id="widget-preview-wrapper">', $editSource);
        self::assertStringContainsString('SearchManagerPreview.initPromotionPreview();', $editSource);
        self::assertStringContainsString("var display = document.getElementById('behavior-promotionDisplay');\n\t\t\tif (!display) return;", $previewSource);

        $styleBlockStart = strpos($editSource, '{# Style Preset #}');
        $previewBlockStart = strpos($editSource, '{# Widget Preview #}');
        self::assertIsInt($styleBlockStart);
        self::assertIsInt($previewBlockStart);
        $styleBlock = substr($editSource, $styleBlockStart, $previewBlockStart - $styleBlockStart);
        self::assertStringContainsString('{% if isPro %}', $styleBlock);
        self::assertStringContainsString("name: 'styleHandle'", $styleBlock);
        self::assertStringNotContainsString('{% else %}', $styleBlock);
        self::assertStringNotContainsString('edition-upgrade-prompt', $styleBlock);

        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $proHtml = $this->renderEditorPartials($widget, true);

        self::assertStringContainsString('name="settings[behavior][promotionDisplay]"', $proHtml);
        self::assertStringContainsString('name="settings[analytics][analyticsSource]"', $proHtml);
    }

    public function testInlineUpgradePromptsRemainOnlyInDashboardWidgetDowngradeNotices(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pluginRoot . '/src'));
        $promptConsumers = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (is_string($source) && str_contains($source, 'lindemannrock-base/_partials/edition-upgrade-prompt')) {
                $promptConsumers[] = str_replace($pluginRoot . '/', '', $file->getPathname());
            }
        }

        sort($promptConsumers);
        self::assertSame([
            'src/widgets/AnalyticsSummaryWidget.php',
            'src/widgets/ContentGapsWidget.php',
            'src/widgets/TopSearchesWidget.php',
            'src/widgets/TrendingSearchesWidget.php',
        ], $promptConsumers);
    }

    public function testWidgetStylesSidebarItemIsAddedOnlyForPro(): void
    {
        $pluginRoot = dirname(__DIR__, 2);

        foreach (['src/templates/widgets/index.twig', 'src/templates/widgets/styles/index.twig'] as $path) {
            $source = file_get_contents($pluginRoot . '/' . $path);
            self::assertIsString($source);

            $itemsStart = strpos($source, '{% set sidebarItems = {');
            $proStart = strpos($source, '{% if isPro %}', $itemsStart);
            self::assertIsInt($itemsStart);
            self::assertIsInt($proStart);

            $standardItems = substr($source, $itemsStart, $proStart - $itemsStart);
            self::assertStringContainsString('configurations:', $standardItems);
            self::assertStringNotContainsString('styles:', $standardItems);
            self::assertStringContainsString("styles: { label: 'Styles'", substr($source, $proStart));
        }
    }

    public function testStandardEditorSavePreservesHiddenProConfiguration(): void
    {
        $widgetId = $this->insertWidget();
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $this->actWithWidgetEditPermission();

        $submitted = WidgetConfig::defaultSettings();
        $submitted['search']['placeholder'] = 'Updated in Standard';
        unset(
            $submitted['behavior']['promotionDisplay'],
            $submitted['behavior']['promotionBadgeText'],
            $submitted['behavior']['promotionBadgePosition'],
            $submitted['analytics'],
        );

        $this->withPost([
            'configId' => $widgetId,
            'name' => 'Edition Widget',
            'handle' => self::WIDGET_HANDLE,
            'type' => 'modal',
            'enabled' => '1',
            'settings' => $submitted,
            'styleHandle' => 'hand-added-standard-override',
            'redirect' => Craft::$app->getSecurity()->hashData('search-manager/widgets'),
        ]);
        self::assertSame('Updated in Standard', Craft::$app->getRequest()->getBodyParam('settings')['search']['placeholder']);

        try {
            (new WidgetsController('widgets', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        $saved = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertInstanceOf(WidgetConfig::class, $saved);
        self::assertSame('Updated in Standard', $saved->getPlaceholder());
        self::assertSame('badge', $saved->getPromotionDisplay());
        self::assertSame('Sponsored', $saved->getPromotionBadgeText());
        self::assertSame('above', $saved->getPromotionBadgePosition());
        self::assertSame('header-search', $saved->getAnalyticsSource());
        self::assertSame(2750, $saved->getAnalyticsIdleTimeoutMs());
        self::assertSame(self::STYLE_HANDLE, $saved->styleHandle);
    }

    public function testInvalidHiddenProConfigurationDoesNotBlockStandardEditorSave(): void
    {
        $widgetId = $this->insertWidget();
        $stored = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertInstanceOf(WidgetConfig::class, $stored);

        $settings = $stored->getSettingsArray();
        $settings['behavior']['promotionDisplay'] = 'legacy-mode';
        $settings['behavior']['promotionBadgePosition'] = 'bottom';
        $settings['analytics']['analyticsSource'] = 'invalid source!';
        $settings['analytics']['analyticsIdleTimeoutMs'] = 20000;
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_widget_configs}}', [
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            ], ['id' => $widgetId])
            ->execute();

        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $this->actWithWidgetEditPermission();

        $submitted = WidgetConfig::defaultSettings();
        $submitted['search']['placeholder'] = 'Saved despite hidden legacy values';
        unset(
            $submitted['behavior']['promotionDisplay'],
            $submitted['behavior']['promotionBadgeText'],
            $submitted['behavior']['promotionBadgePosition'],
            $submitted['analytics'],
        );

        $this->withPost([
            'configId' => $widgetId,
            'name' => 'Edition Widget',
            'handle' => self::WIDGET_HANDLE,
            'type' => 'modal',
            'enabled' => '1',
            'settings' => $submitted,
            'redirect' => Craft::$app->getSecurity()->hashData('search-manager/widgets'),
        ]);

        try {
            (new WidgetsController('widgets', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        $saved = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertInstanceOf(WidgetConfig::class, $saved);
        self::assertSame('Saved despite hidden legacy values', $saved->getPlaceholder());
        self::assertSame('legacy-mode', $saved->getSetting('behavior.promotionDisplay'));
        self::assertSame('bottom', $saved->getSetting('behavior.promotionBadgePosition'));
        self::assertSame('invalid source!', $saved->getSetting('analytics.analyticsSource'));
        self::assertSame(20000, $saved->getSetting('analytics.analyticsIdleTimeoutMs'));
    }

    private function proConfiguredWidget(): WidgetConfig
    {
        $settings = WidgetConfig::defaultSettings();
        $settings['behavior']['promotionDisplay'] = 'badge';
        $settings['behavior']['promotionBadgeText'] = 'Sponsored';
        $settings['behavior']['promotionBadgePosition'] = 'above';
        $settings['analytics']['analyticsSource'] = 'header-search';
        $settings['analytics']['analyticsIdleTimeoutMs'] = 2750;

        $widget = new WidgetConfig();
        $widget->handle = self::WIDGET_HANDLE;
        $widget->name = 'Edition Widget';
        $widget->type = 'modal';
        $widget->enabled = true;
        $widget->styleHandle = self::STYLE_HANDLE;
        $widget->settings = $settings;

        return $widget;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderWidget(WidgetConfig $widget, array $params = []): string
    {
        $service = new class($widget) extends WidgetConfigService {
            public function __construct(private readonly WidgetConfig $widget)
            {
                parent::__construct();
            }

            public function getConfigForWidget(?string $handle = null): WidgetConfig
            {
                return $this->widget;
            }
        };

        $this->swapPluginComponent('search-manager', 'widgetConfigs', $service);

        return Craft::$app->getView()->renderTemplate('search-manager/_widget/search-modal', $params);
    }

    private function renderEditorPartials(WidgetConfig $widget, bool $isPro): string
    {
        $params = ['widgetConfig' => $widget, 'isPro' => $isPro];

        $html = Craft::$app->getView()->renderTemplate(
            'search-manager/widgets/_partials/results',
            $params,
            View::TEMPLATE_MODE_CP,
        );

        if ($isPro) {
            $html .= Craft::$app->getView()->renderTemplate(
                'search-manager/widgets/_partials/analytics',
                $params,
                View::TEMPLATE_MODE_CP,
            );
        }

        return $html;
    }

    private function insertStyle(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_styles}}', [
            'handle' => self::STYLE_HANDLE,
            'name' => 'Edition Style',
            'type' => 'modal',
            'styles' => json_encode(['modalBg' => self::PRESET_COLOR], JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function insertWidget(): int
    {
        $widget = $this->proConfiguredWidget();
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $widget->handle,
            'name' => $widget->name,
            'type' => $widget->type,
            'styleHandle' => $widget->styleHandle,
            'settings' => json_encode($widget->getSettingsArray(), JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function actWithWidgetEditPermission(): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageWidgetConfigs', 'searchManager:editWidgetConfigs']);
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPost(array $params): void
    {
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
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

    /**
     * @param array<string, mixed> $condition
     */
    private function fixtureCount(string $table, array $condition): int
    {
        return (int)(new Query())->from($table)->where($condition)->count();
    }

    private function purgeFixtures(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_styles}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
