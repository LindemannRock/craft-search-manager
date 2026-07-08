<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the dependency-safe Commerce element-type metadata contract.
 *
 * @since 5.53.0
 */
#[CoversClass(CommerceElementTypeHelper::class)]
final class CommerceElementTypeHelperTest extends TestCase
{
    public function testReturnsCommerceElementTypeClassStrings(): void
    {
        self::assertSame('craft\\commerce\\elements\\Product', CommerceElementTypeHelper::productElementType());
        self::assertSame('craft\\commerce\\elements\\Variant', CommerceElementTypeHelper::variantElementType());
        self::assertSame([
            'craft\\commerce\\elements\\Product',
            'craft\\commerce\\elements\\Variant',
        ], CommerceElementTypeHelper::elementTypes());
    }

    public function testDoesNotHardRequireCommerceClassesAtLoadTime(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/helpers/CommerceElementTypeHelper.php');

        self::assertStringNotContainsString('use craft\\commerce', $source);
        self::assertStringNotContainsString('\\Product::class', $source);
        self::assertStringNotContainsString('\\Variant::class', $source);
        self::assertSame('craft\\commerce\\elements\\Product', CommerceElementTypeHelper::productElementType());
        self::assertSame('craft\\commerce\\elements\\Variant', CommerceElementTypeHelper::variantElementType());
    }

    public function testAvailabilityMatchesEnabledCommerceAndClassChecks(): void
    {
        $productAvailable = PluginHelper::isPluginEnabled('commerce')
            && class_exists(CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE);
        $variantAvailable = PluginHelper::isPluginEnabled('commerce')
            && class_exists(CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE);

        self::assertSame($productAvailable, CommerceElementTypeHelper::productElementTypeAvailable());
        self::assertSame($variantAvailable, CommerceElementTypeHelper::variantElementTypeAvailable());
        self::assertSame($productAvailable && $variantAvailable, CommerceElementTypeHelper::commerceElementTypesAvailable());
    }

    public function testAvailableOptionsIncludeOnlyAvailableCommerceElementTypes(): void
    {
        $labels = CommerceElementTypeHelper::availableElementTypeLabels();
        $types = CommerceElementTypeHelper::availableElementTypes();

        self::assertSame(array_keys($labels), $types);

        if (CommerceElementTypeHelper::productElementTypeAvailable()) {
            self::assertSame('Product', $labels[CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE] ?? null);
            self::assertContains(CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE, $types);
        } else {
            self::assertArrayNotHasKey(CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE, $labels);
            self::assertNotContains(CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE, $types);
        }

        if (CommerceElementTypeHelper::variantElementTypeAvailable()) {
            self::assertSame('Variant', $labels[CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE] ?? null);
            self::assertContains(CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE, $types);
        } else {
            self::assertArrayNotHasKey(CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE, $labels);
            self::assertNotContains(CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE, $types);
        }
    }

    public function testMergeableLabelsPreserveBaseLabels(): void
    {
        $baseLabels = [
            'craft\\elements\\Entry' => 'Entry',
            'craft\\elements\\Category' => 'Category',
        ];

        self::assertSame(
            array_merge($baseLabels, CommerceElementTypeHelper::availableElementTypeLabels()),
            CommerceElementTypeHelper::mergeAvailableElementTypeLabels($baseLabels),
        );
    }

    public function testProductTypesAreNotCommerceElementTypes(): void
    {
        $allValues = array_merge(
            CommerceElementTypeHelper::elementTypes(),
            array_keys(CommerceElementTypeHelper::elementTypeLabels()),
            array_values(CommerceElementTypeHelper::elementTypeLabels()),
        );

        foreach ($allValues as $value) {
            self::assertStringNotContainsString('ProductType', $value);
            self::assertStringNotContainsString('Product Type', $value);
            self::assertStringNotContainsString('Product Types', $value);
        }
    }
}
