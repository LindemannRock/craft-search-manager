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
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks in Batch 1 Commerce index element-type UI integration.
 *
 * @since 5.53.0
 */
#[CoversClass(IndicesController::class)]
final class CommerceIndexElementTypeUiTest extends TestCase
{
    public function testIndexElementTypeOptionsUseCommerceHelperAvailability(): void
    {
        $options = $this->invokeControllerMethod('getElementTypeOptions');

        self::assertArrayHasKey(\craft\elements\Entry::class, $options);
        self::assertArrayHasKey(\craft\elements\Asset::class, $options);
        self::assertArrayHasKey(\craft\elements\Category::class, $options);
        self::assertArrayHasKey(\craft\elements\User::class, $options);

        foreach (CommerceElementTypeHelper::elementTypes() as $elementType) {
            if (in_array($elementType, CommerceElementTypeHelper::availableElementTypes(), true)) {
                self::assertArrayHasKey($elementType, $options);
                self::assertSame(
                    $this->expectedTranslatedCommerceLabel($elementType),
                    $options[$elementType],
                );
            } else {
                self::assertArrayNotHasKey($elementType, $options);
            }
        }
    }

    public function testIndexElementTypeDisplayLabelsUseCommerceHelperAvailability(): void
    {
        $labels = $this->invokeControllerMethod('getElementTypeLabels');

        foreach (CommerceElementTypeHelper::elementTypes() as $elementType) {
            if (in_array($elementType, CommerceElementTypeHelper::availableElementTypes(), true)) {
                self::assertArrayHasKey($elementType, $labels);
                self::assertSame(
                    $this->expectedTranslatedCommerceLabel($elementType),
                    $labels[$elementType],
                );
            } else {
                self::assertArrayNotHasKey($elementType, $labels);
            }
        }
    }

    public function testProductTypesAreNotIndexElementTypeOptions(): void
    {
        $options = $this->invokeControllerMethod('getElementTypeOptions');
        $labels = $this->invokeControllerMethod('getElementTypeLabels');

        $values = array_merge(array_keys($options), array_values($options), array_keys($labels), array_values($labels));

        foreach ($values as $value) {
            self::assertStringNotContainsString('ProductType', $value);
            self::assertStringNotContainsString('Product Type', $value);
            self::assertStringNotContainsString('Product Types', $value);
        }
    }

    public function testTemplatesDoNotDuplicateCommerceAvailabilityChecks(): void
    {
        foreach ([
            'src/templates/indices/edit.twig',
            'src/templates/indices/index.twig',
            'src/templates/indices/view.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);

            self::assertStringNotContainsString("lrPluginEnabled('commerce')", $source);
            self::assertStringNotContainsString('CommerceElementTypeHelper', $source);
            self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Product', $source);
            self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Variant', $source);
            self::assertStringNotContainsString('Product Type', $source);
        }
    }

    public function testIndexEditTemplateGatesBuiltInTransformersThroughControllerData(): void
    {
        $source = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringContainsString('{% if docsManagerTransformerAvailable %}', $source);
        self::assertStringContainsString('docpage-criteria', $source);
        self::assertStringNotContainsString('{% if commerceTransformerAvailable %}', $source);
        self::assertStringNotContainsString('CommerceTransformer', $source);
        self::assertStringNotContainsString("lrPluginEnabled('docs-manager')", $source);
        self::assertStringNotContainsString("lrPluginEnabled('commerce')", $source);
    }

    public function testIndexEditTemplateUsesBaseInfoBoxForTransformerHelp(): void
    {
        $source = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringContainsString("{% include 'lindemannrock-base/_components/info-box' with {", $source);
        self::assertStringContainsString('transformerHelpMessage', $source);
        self::assertStringContainsString("<p>{{ 'Leave blank for the recommended setup. Search Manager will choose the built-in transformer for the selected element type.'|t('search-manager') }} {{ 'Only enter a class if this index needs project-specific indexing logic.'|t('search-manager') }}</p>", $source);
        self::assertStringContainsString("'Example custom class:'|t('search-manager')", $source);
        self::assertStringContainsString('modules\\transformers\\ProductTransformer', $source);
        self::assertStringNotContainsString('transformer-placeholder-preview', $source);
        self::assertStringNotContainsString("'Current auto-detected transformer:'|t('search-manager')", $source);
        self::assertStringNotContainsString("url('plugins/search-manager/docs/developers/custom-transformers')", $source);
        self::assertStringNotContainsString('AutoTransformer handles Entries, Assets, Categories, and Users.', $source);
        self::assertStringNotContainsString('Optional/manual transformers', $source);
        self::assertStringNotContainsString('Basic entry fields only', $source);
        self::assertStringNotContainsString('Custom transformer examples', $source);
    }

    public function testIndexEditTemplateRemovesLegacyTransformerHelpBlock(): void
    {
        $source = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringNotContainsString('{# Helpful transformer examples #}', $source);
        self::assertStringNotContainsString('<details', $source);
        self::assertStringNotContainsString('</details>', $source);
        self::assertStringNotContainsString('style="font-size: 0.9em; font-weight: normal; color: #596673;"', $source);
    }

    public function testIndexEditTemplateKeepsHeadingLevelsAfterTransformerInfoBox(): void
    {
        $source = $this->readPluginFile('src/templates/indices/edit.twig');

        $transformerFieldPosition = strpos($source, "id: 'transformerClass'");
        $infoBoxPosition = strpos($source, "{% include 'lindemannrock-base/_components/info-box' with {", $transformerFieldPosition);
        $headingLevelsPosition = strpos($source, 'Heading Levels');
        $advancedSettingsPosition = strpos($source, 'Advanced Settings', $headingLevelsPosition);

        self::assertIsInt($transformerFieldPosition);
        self::assertIsInt($infoBoxPosition);
        self::assertIsInt($headingLevelsPosition);
        self::assertIsInt($advancedSettingsPosition);
        self::assertGreaterThan($headingLevelsPosition, $advancedSettingsPosition);
        self::assertGreaterThan($advancedSettingsPosition, $transformerFieldPosition);
        self::assertGreaterThan($transformerFieldPosition, $infoBoxPosition);
    }

    public function testIndexEditTemplateUsesControllerPlaceholderMap(): void
    {
        $source = $this->readPluginFile('src/templates/indices/edit.twig');

        self::assertStringContainsString('const defaultTransformerPlaceholder = {{ defaultTransformerPlaceholder|json_encode|raw }};', $source);
        self::assertStringContainsString('const transformerPlaceholders = {{ transformerPlaceholders|json_encode|raw }};', $source);
        self::assertStringContainsString('transformerPlaceholders[elementTypeSelect.value] || defaultTransformerPlaceholder', $source);
        self::assertStringNotContainsString('transformerPlaceholderPreview', $source);
        self::assertStringNotContainsString("transformerInput.placeholder = 'lindemannrock\\\\searchmanager\\\\transformers\\\\DocsManagerTransformer'", $source);
        self::assertStringNotContainsString("transformerInput.placeholder = 'lindemannrock\\\\searchmanager\\\\transformers\\\\AutoTransformer'", $source);
    }

    public function testTransformerDocsDoNotMentionRemovedEntrySpecificClass(): void
    {
        $customTransformers = $this->readPluginFile('docs/developers/custom-transformers.md');
        $indices = $this->readPluginFile('docs/feature-tour/indices.md');
        $docs = $customTransformers . "\n" . $indices;

        self::assertStringContainsString('Default fallback', $customTransformers);
        self::assertStringContainsString('It handles entries generically itself.', $customTransformers);
        self::assertStringContainsString('project-specific transformer class', $customTransformers);
        self::assertStringContainsString('modules\\transformers\\ProductTransformer', $docs);
        self::assertStringContainsString('DocsManagerTransformer` for `SourceDoc`', $customTransformers);
        self::assertStringContainsString('`CommerceTransformer` for Commerce Product/Variant elements', $customTransformers);
        self::assertStringNotContainsString('Entry' . 'Transformer', $docs);
        self::assertStringNotContainsString('Everything from `AutoTransformer` plus entry-specific metadata', $docs);
        self::assertStringNotContainsString('AutoTransformer` | Entries, Assets, Categories, Users', $docs);
    }

    public function testTransformerPlaceholderDataMapsAvailableCommerceElements(): void
    {
        $placeholders = $this->invokeControllerMethod('getTransformerPlaceholders');

        self::assertIsArray($placeholders);

        foreach (CommerceElementTypeHelper::elementTypes() as $elementType) {
            if (in_array($elementType, CommerceElementTypeHelper::availableElementTypes(), true)) {
                self::assertSame(CommerceTransformer::class, $placeholders[$elementType] ?? null);
            } else {
                self::assertArrayNotHasKey($elementType, $placeholders);
            }
        }
    }

    public function testTransformerPlaceholderDataKeepsDocsManagerGated(): void
    {
        $placeholders = $this->invokeControllerMethod('getTransformerPlaceholders');
        $isAvailable = $this->invokeControllerMethod('isDocsManagerTransformerAvailable');
        $sourceDocElementType = 'lindemannrock\\docsmanager\\elements\\SourceDoc';

        self::assertIsArray($placeholders);

        if ($isAvailable) {
            self::assertSame(DocsManagerTransformer::class, $placeholders[$sourceDocElementType] ?? null);
        } else {
            self::assertArrayNotHasKey($sourceDocElementType, $placeholders);
        }
    }

    public function testDefaultTransformerPlaceholderRemainsAutoTransformer(): void
    {
        $placeholder = $this->invokeControllerMethod('getDefaultTransformerPlaceholder');

        self::assertSame(AutoTransformer::class, $placeholder);
    }

    public function testControllerUsesCommerceElementTypeHelperInsteadOfDuplicatedCommerceChecks(): void
    {
        $source = $this->readPluginFile('src/controllers/IndicesController.php');

        self::assertStringContainsString('use lindemannrock\\searchmanager\\helpers\\CommerceElementTypeHelper;', $source);
        self::assertStringContainsString('CommerceElementTypeHelper::availableElementTypeLabels', $source);
        self::assertStringNotContainsString("isPluginEnabled('commerce')", $source);
        self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Product', $source);
        self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Variant', $source);
    }

    public function testPromotionsAndQueryRulesDoNotDuplicateCommerceAvailabilityChecks(): void
    {
        foreach ([
            'src/controllers/PromotionsController.php',
            'src/controllers/QueryRulesController.php',
            'src/templates/promotions/edit.twig',
            'src/templates/query-rules/edit.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);

            self::assertStringNotContainsString('CommerceElementTypeHelper', $source);
            self::assertStringNotContainsString('craft\\\\commerce', $source);
            self::assertStringNotContainsString("isPluginEnabled('commerce')", $source);
            self::assertStringNotContainsString('class_exists(', $source);
        }
    }

    /**
     * @return mixed
     */
    private function invokeControllerMethod(string $method): mixed
    {
        $controller = new IndicesController('indices', SearchManager::$plugin);
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($controller);
    }

    private function readPluginFile(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    private function expectedTranslatedCommerceLabel(string $elementType): string
    {
        $rawLabel = CommerceElementTypeHelper::elementTypeLabels()[$elementType];
        $labelKeys = [
            'Product' => 'Commerce Product',
            'Variant' => 'Commerce Variant',
        ];

        return Craft::t('search-manager', $labelKeys[$rawLabel] ?? $rawLabel);
    }
}
