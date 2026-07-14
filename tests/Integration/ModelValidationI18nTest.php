<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(QueryRule::class)]
#[CoversClass(SearchManager::class)]
#[CoversClass(ApiKey::class)]
#[CoversClass(ConfiguredBackend::class)]
#[CoversClass(Promotion::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(WidgetConfig::class)]
#[CoversClass(WidgetStyle::class)]
final class ModelValidationI18nTest extends TestCase
{
    public function testSearchIndexValidationUsesTranslatedAttributeLabels(): void
    {
        $previousLanguage = \Craft::$app->language;

        try {
            \Craft::$app->language = 'de';

            $index = new SearchIndex();

            self::assertFalse($index->validate(['elementType']));
            self::assertSame([
                \Craft::t('yii', '{attribute} cannot be blank.', [
                    'attribute' => \Craft::t('search-manager', 'Element Type'),
                ]),
            ], $index->getErrors('elementType'));
            self::assertStringNotContainsString('Element Type', implode(' ', $index->getErrors('elementType')));
        } finally {
            \Craft::$app->language = $previousLanguage;
        }
    }

    public function testBareHandleMatchValidatorsUseTranslatedMessages(): void
    {
        $previousLanguage = \Craft::$app->language;

        try {
            \Craft::$app->language = 'de';

            $index = new SearchIndex();
            $index->handle = '9foo';

            self::assertFalse($index->validate(['handle']));
            self::assertSame([
                \Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.'),
            ], $index->getErrors('handle'));

            $index = new SearchIndex();
            $index->handle = 'foo9_bar-baz';

            self::assertTrue($index->validate(['handle']), implode('; ', $index->getErrors('handle')));
            self::assertSame([], $index->getErrors('handle'));

            $backend = new ConfiguredBackend();
            $backend->handle = '1 invalid handle';

            self::assertFalse($backend->validate(['handle']));
            self::assertSame([
                \Craft::t('search-manager', 'Handle must start with a letter and contain only letters, numbers, underscores, and hyphens.'),
            ], $backend->getErrors('handle'));
        } finally {
            \Craft::$app->language = $previousLanguage;
        }
    }

    public function testQueryRuleValidationMessagesAndDescriptionsStayStable(): void
    {
        $rule = new QueryRule();
        $rule->name = 'Invalid boost section';
        $rule->matchValue = 'sale';
        $rule->actionType = QueryRule::ACTION_BOOST_SECTION;
        $rule->actionValue = [];

        $rule->validateActionValue('actionValue');
        self::assertContains('Boost section action requires a "sectionHandle".', $rule->getErrors('actionValue'));
        self::assertContains('Boost section action requires a numeric "multiplier".', $rule->getErrors('actionValue'));

        $rule->actionValue = [
            'sectionHandle' => 'news',
            'multiplier' => 2,
        ];

        self::assertSame('Boost section "news" ×2', $rule->getActionDescription());
        self::assertSame([
            QueryRule::ACTION_SYNONYM => 'Synonyms',
            QueryRule::ACTION_BOOST_SECTION => 'Boost Section',
            QueryRule::ACTION_BOOST_CATEGORY => 'Boost Category',
            QueryRule::ACTION_BOOST_ELEMENT => 'Boost Element',
            QueryRule::ACTION_REDIRECT => 'Redirect',
        ], QueryRule::getActionTypes());
        self::assertArrayNotHasKey('filter', QueryRule::getActionTypes());
        self::assertSame([
            QueryRule::MATCH_EXACT => 'Exact Match',
            QueryRule::MATCH_CONTAINS => 'Contains',
            QueryRule::MATCH_PREFIX => 'Starts With',
            QueryRule::MATCH_REGEX => 'Regex',
        ], QueryRule::getMatchTypes());
    }

    public function testQueryRuleControllerReadsActionSpecificBoostMultiplierParams(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/controllers/QueryRulesController.php');

        self::assertStringContainsString("'multiplier' => (float)\$request->getBodyParam('boostSectionMultiplier', 2.0)", $source);
        self::assertStringContainsString("'multiplier' => (float)\$request->getBodyParam('boostCategoryMultiplier', 2.0)", $source);
        self::assertStringContainsString("'multiplier' => (float)\$request->getBodyParam('boostElementMultiplier', 2.0)", $source);
        self::assertStringNotContainsString("getBodyParam('boostMultiplier'", $source);
    }

    public function testQueryRuleBoostMultiplierActionValueShapeStaysStable(): void
    {
        $rule = new QueryRule();
        $rule->name = 'Custom boost section';
        $rule->matchValue = 'sale';
        $rule->actionType = QueryRule::ACTION_BOOST_SECTION;
        $rule->actionValue = [
            'sectionHandle' => 'news',
            'multiplier' => 10.0,
        ];

        self::assertTrue($rule->validate(['actionValue']), implode('; ', $rule->getErrors('actionValue')));
        self::assertSame(10.0, $rule->actionValue['multiplier']);
        self::assertArrayNotHasKey('boostSectionMultiplier', $rule->actionValue);
        self::assertSame(10.0, $rule->getBoostMultiplier());
    }

    public function testFilterIsNotAValidQueryRuleActionType(): void
    {
        $rule = new QueryRule();
        $rule->name = 'Legacy filter';
        $rule->matchValue = 'sale';
        $rule->actionType = 'filter';
        $rule->actionValue = [
            'field' => 'status',
            'value' => 'featured',
        ];

        self::assertFalse($rule->validate(['actionType', 'actionValue']));
        self::assertNotSame([], $rule->getErrors('actionType'));
        self::assertSame([], $rule->getErrors('actionValue'));
    }

    public function testQueryRuleActionTypeColorSetDoesNotIncludeFilter(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/SearchManager.php');
        $start = strpos($source, "'actionType' => [");
        self::assertIsInt($start);
        $end = strpos($source, '],', $start);
        self::assertIsInt($end);
        $actionTypeColorSet = substr($source, $start, $end - $start);

        self::assertStringContainsString("'synonym' => ColorHelper::getPaletteColor('blue')", $actionTypeColorSet);
        self::assertStringContainsString("'boost_section' => ColorHelper::getPaletteColor('green')", $actionTypeColorSet);
        self::assertStringContainsString("'boost_category' => ColorHelper::getPaletteColor('teal')", $actionTypeColorSet);
        self::assertStringContainsString("'boost_element' => ColorHelper::getPaletteColor('lime')", $actionTypeColorSet);
        self::assertStringContainsString("'redirect' => ColorHelper::getPaletteColor('red')", $actionTypeColorSet);
        self::assertStringNotContainsString("'filter' =>", $actionTypeColorSet);
    }

    /**
     * @param array<string, string> $actionValue
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('queryRuleRedirectUrlValidationProvider')]
    public function testQueryRuleRedirectUrlValidationUsesSafeRedirectTargets(array $actionValue, bool $valid): void
    {
        $rule = new QueryRule();
        $rule->name = 'Redirect rule';
        $rule->matchValue = 'sale';
        $rule->actionType = QueryRule::ACTION_REDIRECT;
        $rule->actionValue = $actionValue;

        self::assertSame($valid, $rule->validate(['actionValue']));

        if ($valid) {
            self::assertSame([], $rule->getErrors('actionValue'));
            return;
        }

        self::assertContains(
            'Redirect URL must start with https://, http://, or / (relative path).',
            $rule->getErrors('actionValue')
        );
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: bool}>
     */
    public static function queryRuleRedirectUrlValidationProvider(): iterable
    {
        yield 'relative path' => [['url' => '/valid-path'], true];
        yield 'same-origin path that resembles host' => [['url' => '/evil.com'], true];
        yield 'https absolute URL' => [['url' => 'https://example.com/page'], true];
        yield 'http absolute URL' => [['url' => 'http://example.com/page'], true];
        yield 'element redirect' => [['elementId' => '123', 'elementType' => \craft\elements\Entry::class], true];
        yield 'protocol-relative URL' => [['url' => '//evil.com'], false];
        yield 'javascript URL' => [['url' => 'javascript:alert(1)'], false];
        yield 'data URL' => [['url' => 'data:text/html,<h1>x</h1>'], false];
        yield 'bare host' => [['url' => 'example.com'], false];
    }

    public function testPromotionSearchIndexAndWidgetValidationBehaviorStaysStable(): void
    {
        $promotion = new Promotion();
        $promotion->elementId = 999999999;
        $promotion->validateElement('elementId');
        self::assertSame(['Element not found'], $promotion->getErrors('elementId'));

        $index = new SearchIndex();
        $index->siteId = ['not-a-site'];
        $index->validateSiteId('siteId');
        self::assertSame(['siteId array must contain at least one valid site ID.'], $index->getErrors('siteId'));

        $index = new SearchIndex();
        $index->transformerClass = 'not a php class';
        $index->validateTransformerClass('transformerClass');
        self::assertSame([
            'Transformer class must be a valid PHP class name (e.g., modules\\search\\transformers\\MyTransformer).',
        ], $index->getErrors('transformerClass'));

        $widget = $this->makeWidgetConfig();
        $widget->settings['behavior']['searchDebounceMs'] = 'not-a-number';
        $widget->settings['behavior']['resultsRequireUrl'] = 'not-a-boolean';
        $widget->settings['behavior']['hierarchyGroupBy'] = str_repeat('x', 65);
        $widget->settings['behavior']['highlightDestinationQueryParam'] = '1bad';
        $widget->settings['behavior']['highlightDestinationContentSelector'] = '<script';

        self::assertFalse($widget->validate(['settings']));
        self::assertSame(['Search Debounce must be a whole number.'], $widget->getErrors('settings.behavior.searchDebounceMs'));
        self::assertSame(['Require URL for Results must be true or false.'], $widget->getErrors('settings.behavior.resultsRequireUrl'));
        self::assertSame(['Hierarchy Group By Field must be 64 characters or fewer.'], $widget->getErrors('settings.behavior.hierarchyGroupBy'));
        self::assertSame([
            'Destination Highlighting Query Parameter must start with a letter and contain only letters, numbers, hyphens, and underscores.',
        ], $widget->getErrors('settings.behavior.highlightDestinationQueryParam'));
        self::assertSame(['Content Selector contains unsafe characters.'], $widget->getErrors('settings.behavior.highlightDestinationContentSelector'));
    }

    public function testWidgetStyleRangeValidationUsesTranslatedLabelAndMessage(): void
    {
        $previousLanguage = \Craft::$app->language;

        try {
            \Craft::$app->language = 'de';

            $style = new WidgetStyle();
            $style->name = 'Invalid style';
            $style->handle = 'invalid-style';
            $style->type = WidgetStyle::TYPE_MODAL;
            $style->styles = [
                'modalMaxWidth' => 1299,
            ];

            self::assertFalse($style->validate(['styles']));
            self::assertSame([
                'Maximale Modal-Breite muss zwischen 300 und 1200 liegen.',
            ], $style->getErrors('styles.modalMaxWidth'));
            self::assertSame([], $style->getErrors('styles'));
        } finally {
            \Craft::$app->language = $previousLanguage;
        }
    }

    public function testWidgetStyleHighlightMarkupValidationRejectsUnsafeValues(): void
    {
        $style = new WidgetStyle();
        $style->name = 'Unsafe highlight style';
        $style->handle = 'unsafe-highlight-style';
        $style->type = WidgetStyle::TYPE_MODAL;
        $style->styles = [
            'highlightTag' => 'img src=x onerror=alert(1)',
            'highlightClass' => 'x" onmouseover="alert(1)',
        ];

        self::assertFalse($style->validate(['styles']));
        self::assertNotSame([], $style->getErrors('styles.highlightTag'));
        self::assertNotSame([], $style->getErrors('styles.highlightClass'));
    }

    public function testWidgetStyleHighlightMarkupValidationAllowsNormalValues(): void
    {
        $style = new WidgetStyle();
        $style->name = 'Safe highlight style';
        $style->handle = 'safe-highlight-style';
        $style->type = WidgetStyle::TYPE_MODAL;
        $style->styles = [
            'highlightTag' => 'span',
            'highlightClass' => 'search-highlight utility_2',
        ];

        self::assertTrue($style->validate(['styles']));
        self::assertSame([], $style->getErrors('styles.highlightTag'));
        self::assertSame([], $style->getErrors('styles.highlightClass'));
    }

    public function testWidgetConfigNormalizesUnsafeStoredHighlightMarkupBeforeRendering(): void
    {
        $widget = $this->makeWidgetConfig();
        $widget->settings['styles'] = [
            'highlightTag' => 'img src=x onerror=alert(1)',
            'highlightClass' => 'safe x" onmouseover="alert(1)',
        ];

        $styles = $widget->getStylesForRender();

        self::assertSame('mark', $styles['highlightTag']);
        self::assertSame('safe', $styles['highlightClass']);
    }

    public function testWidgetConfigNormalizesUnsafeRenderStyleOverridesAfterFinalMerge(): void
    {
        $widget = $this->makeWidgetConfig();
        $widget->settings['styles'] = [
            'highlightTag' => 'span',
            'highlightClass' => 'saved-highlight',
        ];

        $styles = $widget->getStylesForRender([
            'highlightTag' => 'img src=x onerror=alert(1)',
            'highlightClass' => 'override x" onmouseover="alert(1)',
        ]);

        self::assertSame('mark', $styles['highlightTag']);
        self::assertSame('override', $styles['highlightClass']);
    }

    public function testWidgetConfigPreviewStylesUseSameHighlightNormalization(): void
    {
        $widget = $this->makeWidgetConfig();
        $widget->settings['styles'] = [
            'highlightTag' => 'script',
            'highlightClass' => 'preview onmouseover=alert(1)',
        ];

        $styles = $widget->getStylesForPreview();

        self::assertSame('mark', $styles['highlightTag']);
        self::assertSame('preview', $styles['highlightClass']);
    }

    public function testTargetedModelValidationStringsAreTranslatedAtSource(): void
    {
        $root = dirname(__DIR__, 2);
        $targets = [
            'src/models/QueryRule.php',
            'src/controllers/QueryRulesController.php',
            'src/models/Promotion.php',
            'src/models/SearchIndex.php',
            'src/models/WidgetConfig.php',
        ];

        foreach ($targets as $target) {
            $source = (string)file_get_contents($root . '/' . $target);

            self::assertStringNotContainsString("Craft::t('search-manager', \$label)", $source, $target);
            self::assertDoesNotMatchRegularExpression('/addError\\([^,\\n]+,\\s*[\'"]/', $source, $target);
            self::assertDoesNotMatchRegularExpression('/[\'"]message[\'"]\\s*=>\\s*[\'"]/', $source, $target);
        }

        $source = (string)file_get_contents($root . '/src/models/WidgetStyle.php');
        self::assertStringNotContainsString('{$label} must be between {$min} and {$max}.', $source);
        self::assertStringNotContainsString("Craft::t('search-manager', \$label)", $source);
    }

    private function makeWidgetConfig(): WidgetConfig
    {
        $widget = new WidgetConfig();
        $widget->handle = 'model-validation-i18n';
        $widget->name = 'Model Validation i18n';
        $widget->settings = WidgetConfig::defaultSettings();

        return $widget;
    }
}
