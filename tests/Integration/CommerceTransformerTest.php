<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

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
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::defineCommerceTestClasses();
    }

    public function testTransformerServiceResolvesAvailableCommerceElementsToCommerceTransformer(): void
    {
        if (!CommerceElementTypeHelper::commerceElementTypesAvailable()) {
            self::markTestSkipped('Craft Commerce is not installed and enabled in this test environment.');
        }

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
        $source = $this->readPluginFile('src/transformers/CommerceTransformer.php');

        self::assertStringNotContainsString('use craft\\commerce', $source);
        self::assertStringNotContainsString('\\Product::class', $source);
        self::assertStringNotContainsString('\\Variant::class', $source);
        self::assertStringContainsString('CommerceElementTypeHelper::productElementType()', $source);
        self::assertStringContainsString('CommerceElementTypeHelper::variantElementType()', $source);
    }

    public function testProductTransformIncludesProductMetadataAndVariantSearchData(): void
    {
        $type = $this->productType('Shoes', 'shoes');
        $redVariant = $this->variant('SKU-RED', 'Red Sneaker', ['Color' => 'Red', 'Size' => 'Large']);
        $blueVariant = $this->variant('SKU-BLUE', 'Blue Sneaker', ['Color' => 'Blue']);
        $product = $this->product($type, [$redVariant, $blueVariant], $redVariant);
        $product->id = 101;
        $product->siteId = 1;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->fakeUrl = 'https://example.test/products/trail-sneaker';

        $data = (new CommerceTransformer())->transform($product);

        self::assertSame(101, $data['elementId']);
        self::assertSame('product', $data['type']);
        self::assertSame('Trail Sneaker', $data['title']);
        self::assertSame('trail-sneaker', $data['slug']);
        self::assertSame('https://example.test/products/trail-sneaker', $data['url']);
        self::assertSame('Shoes', $data['productTypeName']);
        self::assertSame('shoes', $data['productTypeHandle']);
        self::assertSame(['SKU-RED', 'SKU-BLUE'], $data['variantSkus']);
        self::assertSame(['Red Sneaker', 'Blue Sneaker'], $data['variantTitles']);
        self::assertContains('Color Red', $data['variantOptions']);
        self::assertContains('Size Large', $data['variantOptions']);
        self::assertSame('SKU-RED', $data['defaultVariantSku']);
        self::assertSame('Red Sneaker', $data['defaultVariantTitle']);
        self::assertStringContainsString('SKU-BLUE', $data['content']);
        self::assertStringContainsString('Color Red', $data['content']);
    }

    public function testVariantTransformIncludesVariantDataAndParentProductMetadata(): void
    {
        $type = $this->productType('Shoes', 'shoes');
        $product = $this->product($type);
        $product->id = 101;
        $product->siteId = 1;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->fakeUrl = 'https://example.test/products/trail-sneaker';

        $variant = $this->variant('SKU-RED', 'Red Sneaker', ['Color' => 'Red', 'Size' => 'Large'], $product);
        $variant->id = 301;
        $variant->siteId = 1;

        $data = (new CommerceTransformer())->transform($variant);

        self::assertSame(301, $data['elementId']);
        self::assertSame(1, $data['siteId']);
        self::assertSame('variant', $data['type']);
        self::assertSame('SKU-RED', $data['sku']);
        self::assertSame('Red Sneaker', $data['variantTitle']);
        self::assertSame('Trail Sneaker', $data['productTitle']);
        self::assertSame('trail-sneaker', $data['productSlug']);
        self::assertSame('https://example.test/products/trail-sneaker', $data['url']);
        self::assertSame('Shoes', $data['productTypeName']);
        self::assertSame('shoes', $data['productTypeHandle']);
        self::assertContains('Color Red', $data['variantOptions']);
        self::assertStringContainsString('SKU-RED', $data['content']);
        self::assertStringContainsString('Trail Sneaker', $data['content']);
    }

    public function testProductTypeIsMetadataNotElementType(): void
    {
        $product = $this->product($this->productType('Shoes', 'shoes'));
        $product->id = 101;
        $product->siteId = 1;

        $data = (new CommerceTransformer())->transform($product);

        self::assertSame('product', $data['elementType']);
        self::assertSame('Shoes', $data['productTypeName']);
        self::assertSame('shoes', $data['productTypeHandle']);
        self::assertStringNotContainsString('ProductType', implode(' ', array_keys($data)));
    }

    private static function defineCommerceTestClasses(): void
    {
        if (!class_exists(CommerceElementTypeHelper::productElementType())) {
            eval(<<<'PHP'
namespace craft\commerce\elements;

class Product extends \craft\base\Element
{
    public ?string $fakeUrl = null;
    public ?object $fakeType = null;
    public ?object $fakeDefaultVariant = null;
    public array $fakeVariants = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getType(): ?object
    {
        return $this->fakeType;
    }

    public function getVariants(): array
    {
        return $this->fakeVariants;
    }

    public function getDefaultVariant(): ?object
    {
        return $this->fakeDefaultVariant;
    }
}

class Variant extends \craft\base\Element
{
    public string $sku = '';
    public ?string $fakeUrl = null;
    public ?Product $fakeProduct = null;
    public array $fakeOptions = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): void
    {
        $this->sku = $sku;
    }

    public function getProduct(): ?Product
    {
        return $this->fakeProduct;
    }

    public function getOptions(): array
    {
        return $this->fakeOptions;
    }
}
PHP);
            return;
        }

        if (!class_exists(__NAMESPACE__ . '\\CommerceTransformerTestProduct')) {
            eval(<<<'PHP'
namespace lindemannrock\searchmanager\tests\Integration;

class CommerceTransformerTestProduct extends \craft\commerce\elements\Product
{
    public ?string $fakeUrl = null;
    public ?\craft\commerce\models\ProductType $fakeType = null;
    public ?\craft\commerce\elements\Variant $fakeDefaultVariant = null;
    public array $fakeVariants = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getType(): \craft\commerce\models\ProductType
    {
        return $this->fakeType ?? new \craft\commerce\models\ProductType(['name' => '', 'handle' => '']);
    }

    public function getVariants(?bool $includeDisabled = null): \craft\commerce\elements\VariantCollection
    {
        return \craft\commerce\elements\VariantCollection::make($this->fakeVariants);
    }

    public function getDefaultVariant(bool $includeDisabled = false): ?\craft\commerce\elements\Variant
    {
        return $this->fakeDefaultVariant;
    }
}

class CommerceTransformerTestVariant extends \craft\commerce\elements\Variant
{
    public ?string $fakeUrl = null;
    public ?\craft\commerce\elements\Product $fakeProduct = null;
    public array $fakeOptions = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getProduct(): ?\craft\commerce\elements\Product
    {
        return $this->fakeProduct;
    }

    public function getOptions(): array
    {
        return $this->fakeOptions;
    }
}
PHP);
        }
    }

    private function productType(string $name, string $handle): object
    {
        if (class_exists('craft\\commerce\\models\\ProductType')) {
            return new \craft\commerce\models\ProductType([
                'name' => $name,
                'handle' => $handle,
            ]);
        }

        return (object)[
            'name' => $name,
            'handle' => $handle,
        ];
    }

    /**
     * @param object[] $variants
     */
    private function product(object $type, array $variants = [], ?object $defaultVariant = null): object
    {
        $class = class_exists(__NAMESPACE__ . '\\CommerceTransformerTestProduct')
            ? __NAMESPACE__ . '\\CommerceTransformerTestProduct'
            : CommerceElementTypeHelper::productElementType();

        $product = new $class();
        $product->fakeType = $type;
        $product->fakeVariants = $variants;
        $product->fakeDefaultVariant = $defaultVariant;

        return $product;
    }

    /**
     * @param array<string, string> $options
     */
    private function variant(string $sku, string $title, array $options, ?object $product = null): object
    {
        $class = class_exists(__NAMESPACE__ . '\\CommerceTransformerTestVariant')
            ? __NAMESPACE__ . '\\CommerceTransformerTestVariant'
            : CommerceElementTypeHelper::variantElementType();

        $variant = new $class();
        $variant->title = $title;
        $variant->setSku($sku);
        $variant->fakeOptions = $options;
        $variant->fakeProduct = $product;

        return $variant;
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
