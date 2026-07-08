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
            self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Product', $source);
            self::assertStringNotContainsString('craft\\\\commerce\\\\elements\\\\Variant', $source);
            self::assertStringNotContainsString('Product Type', $source);
        }
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

    public function testPromotionsAndQueryRulesDoNotIncludeCommerceBatchOneChanges(): void
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
        }
    }

    /**
     * @return array<string, string>
     */
    private function invokeControllerMethod(string $method): array
    {
        $controller = new IndicesController('indices', SearchManager::$plugin);
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        /** @var array<string, string> $result */
        $result = $reflection->invoke($controller);

        return $result;
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
