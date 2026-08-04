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
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks Commerce transformer selection and dependency-safe document shaping.
 *
 * @since 5.53.0
 */
#[CoversClass(CommerceTransformer::class)]
#[CoversClass(TransformerService::class)]
final class CommerceTransformerTest extends TestCase
{
    public function testTransformerServiceResolvesAvailableCommerceElementsToCommerceTransformer(): void
    {
        $productClass = CommerceElementTypeHelper::productElementType();
        $variantClass = CommerceElementTypeHelper::variantElementType();
        $service = new TransformerService();

        self::assertInstanceOf(CommerceTransformer::class, $service->getTransformer(new $productClass()));
        self::assertInstanceOf(CommerceTransformer::class, $service->getTransformer(new $variantClass()));
    }

    public function testTransformerServiceKeepsCoreElementDefaultsOnAutoTransformer(): void
    {
        $service = new TransformerService();

        self::assertInstanceOf(AutoTransformer::class, $service->getTransformer(new Entry()));
        self::assertInstanceOf(AutoTransformer::class, $service->getTransformer(new Asset()));
        self::assertInstanceOf(AutoTransformer::class, $service->getTransformer(new Category()));
        self::assertInstanceOf(AutoTransformer::class, $service->getTransformer(new User()));
    }

    public function testCommerceTransformerSourceHasNoHardCommerceImports(): void
    {
        $transformerSource = $this->readPluginFile('src/transformers/CommerceTransformer.php');
        $helperSource = $this->readPluginFile('src/helpers/SearchCommerceDocumentHelper.php');

        self::assertStringNotContainsString('use craft\\commerce', $transformerSource . $helperSource);
        self::assertStringNotContainsString('\\Product::class', $transformerSource . $helperSource);
        self::assertStringNotContainsString('\\Variant::class', $transformerSource . $helperSource);
        self::assertStringContainsString('CommerceElementTypeHelper::productElementType()', $helperSource);
        self::assertStringContainsString('CommerceElementTypeHelper::variantElementType()', $helperSource);
    }

    public function testProductTransformIncludesProductMetadataAndVariantSearchData(): void
    {
        $type = $this->realProductType();
        $product = $this->product($type);
        $product->id = 101;
        $product->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->uri = 'products/trail-sneaker';
        $redVariant = $this->variant('SKU-RED', 'Red Sneaker', 301, $product);
        $blueVariant = $this->variant('SKU-BLUE', 'Blue Sneaker', 302, $product);
        $product->setVariants([$redVariant, $blueVariant]);
        $product->defaultVariantId = $redVariant->id;

        $data = (new CommerceTransformer())->transform($product);

        self::assertSame(101, $data['elementId']);
        self::assertSame('product', $data['type']);
        self::assertArrayNotHasKey('elementType', $data);
        self::assertSame('Trail Sneaker', $data['title']);
        self::assertSame('trail-sneaker', $data['slug']);
        self::assertSame($product->getUrl() ?? '', $data['url']);
        self::assertSame($type->name, $data['productType']);
        self::assertSame($type->handle, $data['productTypeHandle']);
        self::assertArrayNotHasKey('productTypeName', $data);
        self::assertArrayNotHasKey('section', $data);
        self::assertArrayNotHasKey('ancestors', $data);
        self::assertArrayNotHasKey('level', $data);
        self::assertArrayNotHasKey('folderPath', $data);
        self::assertSame(['SKU-RED', 'SKU-BLUE'], $data['variantSkus']);
        self::assertSame(['Red Sneaker', 'Blue Sneaker'], $data['variantTitles']);
        self::assertArrayNotHasKey('variantOptions', $data);
        self::assertContainsOnlyInstancesOf(Variant::class, $product->getVariants(true));
        self::assertSame('SKU-RED', $data['defaultVariantSku']);
        self::assertSame('Red Sneaker', $data['defaultVariantTitle']);
        self::assertStringContainsString('SKU-BLUE', $data['content']);
        self::assertStringContainsString('Red Sneaker', $data['content']);
        self::assertArrayNotHasKey('_title', $data);
        self::assertArrayNotHasKey('_slug', $data);
        self::assertArrayNotHasKey('_defaultSku', $data);
    }

    public function testVariantTransformIncludesVariantDataAndParentProductMetadata(): void
    {
        $type = $this->realProductType();
        $product = $this->product($type);
        $product->id = 101;
        $product->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->uri = 'products/trail-sneaker';

        $variant = $this->variant('SKU-RED', 'Red Sneaker', 301, $product);
        $product->setVariants([$variant]);
        $product->defaultVariantId = $variant->id;

        $data = (new CommerceTransformer())->transform($variant);

        self::assertSame(301, $data['elementId']);
        self::assertSame($product->siteId, $data['siteId']);
        self::assertSame('variant', $data['type']);
        self::assertArrayNotHasKey('elementType', $data);
        self::assertSame('SKU-RED', $data['sku']);
        self::assertSame('Red Sneaker', $data['variantTitle']);
        self::assertSame('Trail Sneaker', $data['productTitle']);
        self::assertSame('trail-sneaker', $data['productSlug']);
        self::assertSame($variant->getUrl() ?? '', $data['url']);
        self::assertSame($type->name, $data['productType']);
        self::assertSame($type->handle, $data['productTypeHandle']);
        self::assertArrayNotHasKey('productTypeName', $data);
        self::assertArrayNotHasKey('section', $data);
        self::assertArrayNotHasKey('ancestors', $data);
        self::assertArrayNotHasKey('level', $data);
        self::assertArrayNotHasKey('folderPath', $data);
        self::assertSame([], $data['variantOptions']);
        self::assertStringContainsString('SKU-RED', $data['content']);
        self::assertStringContainsString('Trail Sneaker', $data['content']);
        self::assertArrayNotHasKey('_title', $data);
        self::assertArrayNotHasKey('_slug', $data);
        self::assertArrayNotHasKey('_sku', $data);
    }

    public function testProductTypeIsMetadataNotElementType(): void
    {
        $type = $this->realProductType();
        $product = $this->product($type);
        $product->id = 101;
        $product->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $data = (new CommerceTransformer())->transform($product);

        self::assertSame('product', $data['type']);
        self::assertArrayNotHasKey('elementType', $data);
        self::assertSame($type->name, $data['productType']);
        self::assertSame($type->handle, $data['productTypeHandle']);
        self::assertArrayNotHasKey('productTypeName', $data);
        self::assertArrayNotHasKey('section', $data);
        self::assertStringNotContainsString('ProductType', implode(' ', array_keys($data)));
    }

    private function realProductType(): ProductType
    {
        $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0] ?? null;
        self::assertInstanceOf(ProductType::class, $type, 'The supported test fixture must install a real Commerce product type.');

        return $type;
    }

    private function product(ProductType $type): Product
    {
        $product = new Product();
        $product->typeId = $type->id;

        return $product;
    }

    private function variant(string $sku, string $title, int $id, Product $product): Variant
    {
        $variant = new Variant();
        $variant->id = $id;
        $variant->title = $title;
        $variant->setSku($sku);
        $variant->setPrice(25.0);
        $variant->setOwner($product);

        return $variant;
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
