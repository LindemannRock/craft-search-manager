<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;

/**
 * Commerce Transformer
 *
 * Transforms Craft Commerce Product and Variant elements into storefront-friendly
 * search documents without requiring Commerce classes at load time.
 *
 * @since 5.53.0
 */
class CommerceTransformer extends AutoTransformer
{
    protected function getElementType(): string
    {
        return ElementInterface::class;
    }

    public function supports(ElementInterface $element): bool
    {
        return $this->isProduct($element) || $this->isVariant($element);
    }

    public function transform(ElementInterface $element): array
    {
        $data = parent::transform($element);

        if ($this->isProduct($element)) {
            return $this->transformProduct($element, $data);
        }

        if ($this->isVariant($element)) {
            return $this->transformVariant($element, $data);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function transformProduct(ElementInterface $product, array $data): array
    {
        $productType = $this->productType($product);
        $variants = $this->variants($product);
        $defaultVariant = $this->defaultVariant($product);

        $variantSkus = [];
        $variantTitles = [];
        $variantOptions = [];

        foreach ($variants as $variant) {
            $sku = $this->stringValue($variant, ['sku', 'getSku']);
            if ($sku !== '') {
                $variantSkus[] = $sku;
            }

            $title = $this->stringValue($variant, ['title', 'getTitle']);
            if ($title !== '') {
                $variantTitles[] = $title;
            }

            $variantOptions = array_merge($variantOptions, $this->variantOptionStrings($variant));
        }

        $data['type'] = 'product';
        $data['elementType'] = 'product';
        $data['slug'] = $this->stringValue($product, ['slug']);
        $data['productType'] = $this->stringValue($productType, ['name', 'getName']);
        $data['productTypeName'] = $data['productType'];
        $data['productTypeHandle'] = $this->stringValue($productType, ['handle', 'getHandle']);
        $data['section'] = $data['productTypeName'] !== '' ? $data['productTypeName'] : 'Products';

        if (!empty($variantSkus)) {
            $data['variantSkus'] = array_values(array_unique($variantSkus));
        }

        if (!empty($variantTitles)) {
            $data['variantTitles'] = array_values(array_unique($variantTitles));
        }

        if (!empty($variantOptions)) {
            $data['variantOptions'] = array_values(array_unique($variantOptions));
        }

        if ($defaultVariant !== null) {
            $data['defaultVariantSku'] = $this->stringValue($defaultVariant, ['sku', 'getSku']);
            $data['defaultVariantTitle'] = $this->stringValue($defaultVariant, ['title', 'getTitle']);
        }

        return $this->appendSearchableContent($data, [
            $data['title'] ?? '',
            $data['slug'],
            $data['productTypeName'],
            $data['productTypeHandle'],
            $data['variantSkus'] ?? [],
            $data['variantTitles'] ?? [],
            $data['variantOptions'] ?? [],
            $data['defaultVariantSku'] ?? '',
            $data['defaultVariantTitle'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function transformVariant(ElementInterface $variant, array $data): array
    {
        $product = $this->parentProduct($variant);
        $productType = $product instanceof ElementInterface ? $this->productType($product) : null;
        $variantOptions = $this->variantOptionStrings($variant);

        $data['type'] = 'variant';
        $data['elementType'] = 'variant';
        $data['sku'] = $this->stringValue($variant, ['sku', 'getSku']);
        $data['variantTitle'] = $this->stringValue($variant, ['title', 'getTitle']);
        $data['variantOptions'] = $variantOptions;
        $data['section'] = $this->stringValue($productType, ['name', 'getName']);
        $data['productType'] = $data['section'];
        $data['productTypeName'] = $data['section'];
        $data['productTypeHandle'] = $this->stringValue($productType, ['handle', 'getHandle']);

        if ($product instanceof ElementInterface) {
            $data['productId'] = $product->id;
            $data['productTitle'] = $product->title ?? '';
            $data['productSlug'] = $this->stringValue($product, ['slug']);
            $data['productUrl'] = $product->url ?? '';

            if (($data['url'] ?? '') === '' && $data['productUrl'] !== '') {
                $data['url'] = $data['productUrl'];
            }
        }

        if ($data['section'] === '') {
            $data['section'] = 'Variants';
        }

        return $this->appendSearchableContent($data, [
            $data['title'] ?? '',
            $data['sku'],
            $data['variantTitle'],
            $data['variantOptions'],
            $data['productTitle'] ?? '',
            $data['productSlug'] ?? '',
            $data['productTypeName'],
            $data['productTypeHandle'],
        ]);
    }

    private function isProduct(ElementInterface $element): bool
    {
        return is_a($element, CommerceElementTypeHelper::productElementType());
    }

    private function isVariant(ElementInterface $element): bool
    {
        return is_a($element, CommerceElementTypeHelper::variantElementType());
    }

    private function productType(ElementInterface $product): ?object
    {
        $type = $this->objectValue($product, ['getType', 'getProductType', 'type', 'productType']);

        return $type !== null && !is_array($type) ? $type : null;
    }

    /**
     * @return object[]
     */
    private function variants(ElementInterface $product): array
    {
        $variants = $this->objectValue($product, ['getVariants', 'variants']);

        return $this->normalizeObjectList($variants);
    }

    private function defaultVariant(ElementInterface $product): ?object
    {
        $variant = $this->objectValue($product, ['getDefaultVariant', 'defaultVariant']);

        return is_object($variant) ? $variant : null;
    }

    private function parentProduct(ElementInterface $variant): ?ElementInterface
    {
        $product = $this->objectValue($variant, ['getProduct', 'product']);

        return $product instanceof ElementInterface ? $product : null;
    }

    /**
     * @return string[]
     */
    private function variantOptionStrings(object $variant): array
    {
        $options = $this->objectValue($variant, ['getOptions', 'options', 'getOptionValues', 'optionValues']);
        if (!is_iterable($options)) {
            return [];
        }

        $strings = [];
        foreach ($options as $label => $value) {
            $labelText = is_string($label) ? $label : '';
            $valueText = is_object($value)
                ? $this->stringValue($value, ['label', 'name', 'title', 'value', 'getLabel', 'getName', 'getTitle'])
                : trim((string)$value);

            $combined = trim($labelText . ' ' . $valueText);
            if ($combined !== '') {
                $strings[] = $combined;
            }
        }

        return $strings;
    }

    /**
     * @param string[] $names
     */
    private function stringValue(?object $object, array $names): string
    {
        if ($object === null) {
            return '';
        }

        $value = $this->objectValue($object, $names);

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }

    /**
     * @param string[] $names
     */
    private function objectValue(object $object, array $names): mixed
    {
        foreach ($names as $name) {
            try {
                if (method_exists($object, $name)) {
                    return $object->{$name}();
                }

                if (isset($object->{$name})) {
                    return $object->{$name};
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return object[]
     */
    private function normalizeObjectList(mixed $value): array
    {
        try {
            if (is_object($value) && method_exists($value, 'all')) {
                $value = $value->all();
            }
        } catch (\Throwable) {
            return [];
        }

        if (!is_iterable($value)) {
            return [];
        }

        $objects = [];
        foreach ($value as $item) {
            if (is_object($item)) {
                $objects[] = $item;
            }
        }

        return $objects;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, mixed> $parts
     * @return array<string, mixed>
     */
    private function appendSearchableContent(array $data, array $parts): array
    {
        $contentParts = [$data['content'] ?? ''];

        foreach ($parts as $part) {
            if (is_array($part)) {
                $contentParts = array_merge($contentParts, $part);
            } elseif (is_scalar($part)) {
                $contentParts[] = (string)$part;
            }
        }

        $data['content'] = implode(' ', array_filter(array_map(
            static fn($part) => trim((string)$part),
            $contentParts,
        )));
        $data['excerpt'] = $this->getExcerpt($data['content'], 200);

        return $data;
    }
}
