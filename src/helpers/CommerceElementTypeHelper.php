<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\base\helpers\PluginHelper;

/**
 * Dependency-safe metadata for Craft Commerce element types.
 *
 * Product types are Commerce configuration/models, not standalone Craft
 * elements, so they intentionally do not appear here.
 *
 * @since 5.53.0
 */
class CommerceElementTypeHelper
{
    public const PRODUCT_ELEMENT_TYPE = 'craft\\commerce\\elements\\Product';
    public const VARIANT_ELEMENT_TYPE = 'craft\\commerce\\elements\\Variant';

    private const COMMERCE_PLUGIN_HANDLE = 'commerce';

    public static function productElementType(): string
    {
        return self::PRODUCT_ELEMENT_TYPE;
    }

    public static function variantElementType(): string
    {
        return self::VARIANT_ELEMENT_TYPE;
    }

    public static function commercePluginEnabled(): bool
    {
        return PluginHelper::isPluginEnabled(self::COMMERCE_PLUGIN_HANDLE);
    }

    public static function productElementTypeAvailable(): bool
    {
        return self::commercePluginEnabled() && class_exists(self::PRODUCT_ELEMENT_TYPE);
    }

    public static function variantElementTypeAvailable(): bool
    {
        return self::commercePluginEnabled() && class_exists(self::VARIANT_ELEMENT_TYPE);
    }

    public static function commerceElementTypesAvailable(): bool
    {
        return self::productElementTypeAvailable() && self::variantElementTypeAvailable();
    }

    /**
     * @return string[]
     */
    public static function elementTypes(): array
    {
        return [
            self::PRODUCT_ELEMENT_TYPE,
            self::VARIANT_ELEMENT_TYPE,
        ];
    }

    /**
     * @return string[]
     */
    public static function availableElementTypes(): array
    {
        $elementTypes = [];

        if (self::productElementTypeAvailable()) {
            $elementTypes[] = self::PRODUCT_ELEMENT_TYPE;
        }

        if (self::variantElementTypeAvailable()) {
            $elementTypes[] = self::VARIANT_ELEMENT_TYPE;
        }

        return $elementTypes;
    }

    /**
     * @return array<string, string>
     */
    public static function elementTypeLabels(): array
    {
        return [
            self::PRODUCT_ELEMENT_TYPE => 'Product',
            self::VARIANT_ELEMENT_TYPE => 'Variant',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function availableElementTypeLabels(): array
    {
        return array_intersect_key(self::elementTypeLabels(), array_flip(self::availableElementTypes()));
    }

    /**
     * @param array<string, string> $baseLabels
     * @return array<string, string>
     */
    public static function mergeAvailableElementTypeLabels(array $baseLabels): array
    {
        return array_merge($baseLabels, self::availableElementTypeLabels());
    }
}
