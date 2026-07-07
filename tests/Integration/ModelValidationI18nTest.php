<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\models\WidgetStyle;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(QueryRule::class)]
#[CoversClass(Promotion::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(WidgetConfig::class)]
#[CoversClass(WidgetStyle::class)]
final class ModelValidationI18nTest extends TestCase
{
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
            QueryRule::ACTION_FILTER => 'Filter Results',
            QueryRule::ACTION_REDIRECT => 'Redirect',
        ], QueryRule::getActionTypes());
        self::assertSame([
            QueryRule::MATCH_EXACT => 'Exact Match',
            QueryRule::MATCH_CONTAINS => 'Contains',
            QueryRule::MATCH_PREFIX => 'Starts With',
            QueryRule::MATCH_REGEX => 'Regex',
        ], QueryRule::getMatchTypes());
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
        self::assertSame(['Element not found.'], $promotion->getErrors('elementId'));

        $index = new SearchIndex();
        $index->siteId = ['not-a-site'];
        $index->validateSiteId('siteId');
        self::assertSame(['siteId array must contain at least one valid site ID.'], $index->getErrors('siteId'));

        $index = new SearchIndex();
        $index->transformerClass = 'not a php class';
        $index->validateTransformerClass('transformerClass');
        self::assertSame([
            'Transformer class must be a valid PHP class name (e.g., modules\\transformers\\MyTransformer).',
        ], $index->getErrors('transformerClass'));

        $widget = $this->makeWidgetConfig();
        $widget->settings['behavior']['debounce'] = 'not-a-number';
        $widget->settings['behavior']['queryParamName'] = '1bad';
        $widget->settings['behavior']['destinationHighlightSelector'] = '<script';

        self::assertFalse($widget->validate(['settings']));
        self::assertSame(['Debounce must be a whole number.'], $widget->getErrors('settings.behavior.debounce'));
        self::assertSame([
            'Query Parameter Name must start with a letter and contain only letters, numbers, hyphens, and underscores.',
        ], $widget->getErrors('settings.behavior.queryParamName'));
        self::assertSame(['Content Selector contains unsafe characters.'], $widget->getErrors('settings.behavior.destinationHighlightSelector'));
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
