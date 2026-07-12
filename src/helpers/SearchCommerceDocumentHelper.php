<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;

/**
 * Adds dependency-safe Craft Commerce metadata to transformer documents.
 *
 * @since 5.53.0
 */
class SearchCommerceDocumentHelper
{
    public static function supports(ElementInterface $element): bool
    {
        return self::isProduct($element) || self::isVariant($element);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function augment(ElementInterface $element, array $data): array
    {
        if (self::isProduct($element)) {
            return self::product($element, $data);
        }

        if (self::isVariant($element)) {
            return self::variant($element, $data);
        }

        return $data;
    }

    public static function isProduct(ElementInterface $element): bool
    {
        return is_a($element, CommerceElementTypeHelper::productElementType());
    }

    public static function isVariant(ElementInterface $element): bool
    {
        return is_a($element, CommerceElementTypeHelper::variantElementType());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function product(ElementInterface $product, array $data): array
    {
        $productType = self::productType($product);
        $variants = self::variants($product);
        $defaultVariant = self::defaultVariant($product);

        $variantSkus = [];
        $variantTitles = [];
        $variantOptions = [];

        foreach ($variants as $variant) {
            $sku = self::stringValue($variant, ['sku', 'getSku']);
            if ($sku !== '') {
                $variantSkus[] = $sku;
            }

            $title = self::stringValue($variant, ['title', 'getTitle']);
            if ($title !== '') {
                $variantTitles[] = $title;
            }

            $variantOptions = array_merge($variantOptions, self::variantOptionStrings($variant));
        }

        $data['type'] = 'product';
        $data['slug'] = self::stringValue($product, ['slug']);
        $data['productType'] = self::stringValue($productType, ['name', 'getName']);
        $data['productTypeHandle'] = self::stringValue($productType, ['handle', 'getHandle']);
        unset($data['source']);

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
            $data['defaultVariantSku'] = self::stringValue($defaultVariant, ['sku', 'getSku']);
            $data['defaultVariantTitle'] = self::stringValue($defaultVariant, ['title', 'getTitle']);
            $price = self::numericValue($defaultVariant, ['price', 'getPrice']);
            if ($price !== null) {
                $data['price'] = $price;
            }
        }

        return SearchContentBuilderHelper::append($data, [
            $data['title'] ?? '',
            $data['slug'],
            $data['productType'],
            $data['productTypeHandle'],
            $data['variantSkus'] ?? [],
            $data['variantTitles'] ?? [],
            $data['variantOptions'] ?? [],
            $data['defaultVariantSku'] ?? '',
            $data['defaultVariantTitle'] ?? '',
            $data['price'] ?? '',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function variant(ElementInterface $variant, array $data): array
    {
        $product = self::parentProduct($variant);
        $productType = $product instanceof ElementInterface ? self::productType($product) : null;
        $variantOptions = self::variantOptionStrings($variant);

        $data['type'] = 'variant';
        $data['sku'] = self::stringValue($variant, ['sku', 'getSku']);
        $data['variantTitle'] = self::stringValue($variant, ['title', 'getTitle']);
        $data['variantOptions'] = $variantOptions;
        $price = self::numericValue($variant, ['price', 'getPrice']);
        if ($price !== null) {
            $data['price'] = $price;
        }
        $data['productType'] = self::stringValue($productType, ['name', 'getName']);
        $data['productTypeHandle'] = self::stringValue($productType, ['handle', 'getHandle']);
        unset($data['source']);

        if ($product instanceof ElementInterface) {
            $data['productId'] = $product->id;
            $data['productTitle'] = $product->title ?? '';
            $data['productSlug'] = self::stringValue($product, ['slug']);
            $data['productUrl'] = $product->url ?? '';

            if (($data['url'] ?? '') === '' && $data['productUrl'] !== '') {
                $data['url'] = $data['productUrl'];
            }
        }

        return SearchContentBuilderHelper::append($data, [
            $data['title'] ?? '',
            $data['sku'],
            $data['variantTitle'],
            $data['variantOptions'],
            $data['productTitle'] ?? '',
            $data['productSlug'] ?? '',
            $data['productType'],
            $data['productTypeHandle'],
            $data['price'] ?? '',
        ]);
    }

    private static function productType(ElementInterface $product): ?object
    {
        $type = self::objectValue($product, ['getType', 'getProductType', 'type', 'productType']);

        return $type !== null && !is_array($type) ? $type : null;
    }

    /**
     * @return object[]
     */
    private static function variants(ElementInterface $product): array
    {
        $variants = self::objectValue($product, ['getVariants', 'variants']);

        return self::normalizeObjectList($variants);
    }

    private static function defaultVariant(ElementInterface $product): ?object
    {
        $variant = self::objectValue($product, ['getDefaultVariant', 'defaultVariant']);

        return is_object($variant) ? $variant : null;
    }

    private static function parentProduct(ElementInterface $variant): ?ElementInterface
    {
        $product = self::objectValue($variant, ['getProduct', 'product']);

        return $product instanceof ElementInterface ? $product : null;
    }

    /**
     * @return string[]
     */
    private static function variantOptionStrings(object $variant): array
    {
        $options = self::objectValue($variant, ['getOptions', 'options', 'getOptionValues', 'optionValues']);
        if (!is_iterable($options)) {
            return [];
        }

        $strings = [];
        foreach ($options as $label => $value) {
            $labelText = is_string($label) ? $label : '';
            $valueText = is_object($value)
                ? self::stringValue($value, ['label', 'name', 'title', 'value', 'getLabel', 'getName', 'getTitle'])
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
    private static function stringValue(?object $object, array $names): string
    {
        if ($object === null) {
            return '';
        }

        $value = self::objectValue($object, $names);

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }

    /**
     * @param string[] $names
     */
    private static function numericValue(?object $object, array $names): int|float|null
    {
        if ($object === null) {
            return null;
        }

        $value = self::objectValue($object, $names);
        if (!is_numeric($value)) {
            return null;
        }

        $float = (float)$value;

        return floor($float) === $float ? (int)$float : $float;
    }

    /**
     * @param string[] $names
     */
    private static function objectValue(object $object, array $names): mixed
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
    private static function normalizeObjectList(mixed $value): array
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
}
