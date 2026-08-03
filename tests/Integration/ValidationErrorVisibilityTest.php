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
use craft\elements\User;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;

/**
 * Regression coverage for audit findings #471 and #472.
 *
 * @since 5.54.0
 */
final class ValidationErrorVisibilityTest extends TestCase
{
    private User $renderIdentity;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->createTestUser('__sm_batch11f_user_');
        $this->grantPermissions($user, ['accessCp']);
        $this->actingAs($user);
        $this->renderIdentity = $user;
    }

    public function testDisabledWidgetSectionRendersValidatedChildError(): void
    {
        $settings = WidgetConfig::defaultSettings();
        $settings['trigger']['triggerEnabled'] = false;
        $settings['trigger']['triggerLabel'] = str_repeat('x', 256);

        $widget = $this->widgetConfig($settings);
        self::assertFalse($widget->validate());
        self::assertNotEmpty($widget->getErrors('settings.trigger.triggerLabel'));

        $html = $this->renderCpTemplate('search-manager/widgets/_partials/modal-trigger', [
            'widgetConfig' => $widget,
        ]);

        self::assertStringContainsString('<div id="trigger-label-field">', $html);
        self::assertStringContainsString('id="trigger-triggerLabel-field"', $html);
        self::assertStringContainsString('class="errors"', $html);
        self::assertStringContainsString(
            $widget->getFirstError('settings.trigger.triggerLabel'),
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
        );

        $settings['trigger']['triggerLabel'] = 'Search';
        $validWidget = $this->widgetConfig($settings);
        self::assertTrue($validWidget->validate());

        $validHtml = $this->renderCpTemplate('search-manager/widgets/_partials/modal-trigger', [
            'widgetConfig' => $validWidget,
        ]);
        self::assertStringContainsString('<div id="trigger-label-field" class="hidden">', $validHtml);
    }

    public function testDisabledSettingsSectionRendersValidatedChildError(): void
    {
        $settings = new Settings();
        $settings->enableAutocomplete = false;
        $settings->autocompleteMinLength = 0;

        self::assertFalse($settings->validate([
            'enableAutocomplete',
            'autocompleteMinLength',
            'autocompleteLimit',
        ]));
        self::assertNotEmpty($settings->getErrors('autocompleteMinLength'));

        $html = $this->renderCpTemplate('search-manager/settings/autocomplete', [
            'settings' => $settings,
        ]);

        self::assertMatchesRegularExpression(
            '/<div id="autocomplete-settings" class="(?!hidden)[^"]*">/',
            $html,
        );
        self::assertStringContainsString('id="autocompleteMinLength-field"', $html);
        self::assertStringContainsString('class="errors"', $html);
        self::assertStringContainsString(
            $settings->getFirstError('autocompleteMinLength'),
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
        );
    }

    public function testDisabledWidgetStyleSectionRendersValidatedChildError(): void
    {
        $styles = WidgetConfig::defaultStyleValues();
        $styles['highlightResultsEnabled'] = false;
        $styles['highlightTag'] = 'script';

        $widgetStyle = new WidgetStyle([
            'name' => 'Batch 11F Style',
            'handle' => 'sm-batch11f-style',
            'type' => WidgetStyle::TYPE_MODAL,
            'styles' => $styles,
        ]);

        self::assertFalse($widgetStyle->validate());
        self::assertNotEmpty($widgetStyle->getErrors('styles.highlightTag'));

        $html = $this->renderCpTemplate('search-manager/widgets/styles/_partials/results', [
            'styles' => $styles,
            'namePrefix' => 'styles',
            'widgetStyle' => $widgetStyle,
        ]);

        self::assertStringContainsString('<div id="styles-highlighting-options">', $html);
        self::assertStringContainsString('id="styles-highlightTag-field"', $html);
        self::assertStringContainsString('class="errors"', $html);
        self::assertStringContainsString(
            $widgetStyle->getFirstError('styles.highlightTag'),
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
        );
    }

    public function testDisabledAnalyticsSectionsRenderGeoErrors(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);

        $settings = new Settings();
        $settings->enableAnalytics = false;
        $settings->enableGeoDetection = false;
        $settings->geoProvider = 'invalid-provider';

        self::assertFalse($settings->validate([
            'enableAnalytics',
            'enableGeoDetection',
            'geoProvider',
            'geoApiKey',
            'anonymizeIpAddress',
            'analyticsRetention',
        ]));
        self::assertNotEmpty($settings->getErrors('geoProvider'));

        $html = $this->renderCpTemplate('search-manager/settings/analytics', [
            'settings' => $settings,
        ]);

        self::assertMatchesRegularExpression(
            '/<div id="analytics-settings" class="(?!hidden)[^"]*">/',
            $html,
        );
        self::assertMatchesRegularExpression(
            '/<div id="geo-provider-settings" class="(?!hidden)[^"]*" style="margin-top: 24px;">/',
            $html,
        );
        self::assertStringContainsString('id="geoProvider-field"', $html);
        self::assertStringContainsString('class="errors"', $html);
    }

    public function testSplitSectionsFieldRendersErrorsAndRemainsVisible(): void
    {
        $index = new SearchIndex([
            'name' => 'Batch 11F Index',
            'handle' => 'sm-batch11f-index',
            'elementType' => User::class,
            'transformerClass' => \stdClass::class,
            'splitSections' => true,
        ]);

        self::assertFalse($index->validate(['splitSections']));
        self::assertNotEmpty($index->getErrors('splitSections'));

        $html = $this->renderCpTemplate('search-manager/indices/edit', [
            'index' => $index,
            'isNew' => true,
            'defaultHeadingLevels' => [2, 3, 4],
            'elementTypeOptions' => [User::class => 'User'],
            'docsManagerTransformerAvailable' => false,
            'defaultTransformerPlaceholder' => AutoTransformer::class,
            'transformerPlaceholders' => [],
            'splitSectionsByElementType' => [User::class => false],
        ]);

        self::assertStringContainsString('id="splitSections-field"', $html);
        self::assertStringNotContainsString('id="splitSections-field" class="field hidden', $html);
        self::assertStringContainsString('name="splitSections"', $html);
        self::assertStringContainsString('class="errors"', $html);
        self::assertStringContainsString(
            $index->getFirstError('splitSections'),
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5),
        );
        self::assertStringContainsString('const splitSectionsHasErrors = true;', $html);
        self::assertStringContainsString(
            "splitSectionsField.classList.toggle('hidden', !isSplitSectionsEligible && !splitSectionsHasErrors);",
            $html,
        );
    }

    public function testEveryToggleValidationContainerNamesAllContainedErrorKeys(): void
    {
        $inventory = [
            ['src/templates/widgets/_partials/recently-viewed.twig', 'recently-viewed-limit-field', ['settings.behavior.recentlyViewedLimit']],
            ['src/templates/widgets/_partials/modal-trigger.twig', 'trigger-label-field', ['settings.trigger.triggerLabel']],
            ['src/templates/widgets/_partials/destination-highlighting.twig', 'destination-highlight-options', [
                'settings.behavior.highlightDestinationPersistQuery',
                'settings.behavior.highlightDestinationQueryParam',
                'settings.behavior.highlightDestinationContentSelector',
            ]],
            ['src/templates/widgets/_partials/results.twig', 'result-layout-default', ['settings.behavior.resultsGroupingEnabled']],
            ['src/templates/widgets/_partials/results.twig', 'result-layout-hierarchical', [
                'settings.behavior.hierarchyDisplay',
                'settings.behavior.hierarchyStyle',
                'settings.behavior.hierarchyMaxHeadings',
                'settings.behavior.hierarchyGroupBy',
            ]],
            ['src/templates/widgets/_partials/results.twig', 'promotion-display-badge', [
                'settings.behavior.promotionBadgeText',
                'settings.behavior.promotionBadgePosition',
            ]],
            ['src/templates/widgets/styles/_partials/results.twig', 'styles-highlighting-options', [
                'styles.highlightTag',
                'styles.highlightClass',
            ]],
            ['src/templates/settings/search.twig', 'fuzzy-settings', [
                'similarityThreshold',
                'maxFuzzyCandidates',
                'ngramSizes',
            ]],
            ['src/templates/settings/autocomplete.twig', 'autocomplete-settings', [
                'autocompleteMinLength',
                'autocompleteLimit',
            ]],
            ['src/templates/settings/highlighting.twig', 'highlighting-settings', [
                'highlightTag',
                'highlightClass',
            ]],
            ['src/templates/settings/cache.twig', 'autocomplete-cache-settings', ['autocompleteCacheDuration']],
            ['src/templates/settings/cache.twig', 'cache-warming-settings', ['cacheWarmingQueryCount']],
            ['src/templates/settings/cache.twig', 'device-cache-settings', ['deviceDetectionCacheDuration']],
            ['src/templates/settings/analytics.twig', 'analytics-settings', [
                'enableGeoDetection',
                'geoProvider',
                'geoApiKey',
                'anonymizeIpAddress',
            ]],
            ['src/templates/settings/analytics.twig', 'geo-provider-settings', [
                'geoProvider',
                'geoApiKey',
            ]],
        ];

        foreach ($inventory as [$path, $containerId, $errorKeys]) {
            $source = $this->readPluginFile($path);
            self::assertMatchesRegularExpression(
                '/<div id="' . preg_quote($containerId, '/') . '"[^>]*>/',
                $source,
            );
            preg_match('/<div id="' . preg_quote($containerId, '/') . '"[^>]*>/', $source, $matches);
            $openingTag = $matches[0];

            foreach ($errorKeys as $errorKey) {
                self::assertStringContainsString(
                    "getErrors('{$errorKey}')",
                    $openingTag,
                    "{$path} #{$containerId} must remain visible for {$errorKey}.",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function widgetConfig(array $settings): WidgetConfig
    {
        return new WidgetConfig([
            'name' => 'Batch 11F Widget',
            'handle' => 'sm-batch11f-widget',
            'type' => WidgetStyle::TYPE_MODAL,
            'settings' => $settings,
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderCpTemplate(string $template, array $variables): string
    {
        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalUser = Craft::$app->getUser();
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $renderUser = new class extends \craft\console\User {
            public function getRemainingSessionTime(): int
            {
                return -1;
            }

            public function getImpersonator(): ?User
            {
                return null;
            }
        };
        $renderUser->setIdentity($this->renderIdentity);
        Craft::$app->set('user', $renderUser);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $variables['currentUser'] = $this->renderIdentity;

            return Craft::$app->getView()->renderTemplate(
                $template,
                $variables,
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            Craft::$app->set('user', $originalUser);
            $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
