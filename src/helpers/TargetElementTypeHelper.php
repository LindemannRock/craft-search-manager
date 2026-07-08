<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Shared CP target element type metadata for promotions and query rules.
 *
 * @since 5.53.0
 */
class TargetElementTypeHelper
{
    private const TYPE_KEYS = [
        'entry' => Entry::class,
        'asset' => Asset::class,
        'category' => Category::class,
        'user' => User::class,
    ];

    private const LABEL_KEYS = [
        Entry::class => 'Entry',
        Asset::class => 'Asset',
        Category::class => 'Category',
        User::class => 'User',
    ];

    private const COMMERCE_TYPE_KEYS = [
        CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE => 'product',
        CommerceElementTypeHelper::VARIANT_ELEMENT_TYPE => 'variant',
    ];

    private const COMMERCE_LABEL_KEYS = [
        'Product' => 'Commerce Product',
        'Variant' => 'Commerce Variant',
    ];

    /**
     * @return array<string, string>
     */
    public static function typeKeys(): array
    {
        $keys = self::TYPE_KEYS;

        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $key = self::COMMERCE_TYPE_KEYS[$elementType] ?? null;
            if ($key !== null) {
                $keys[$key] = $elementType;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return CommerceElementTypeHelper::mergeAvailableElementTypeLabels(self::LABEL_KEYS);
    }

    /**
     * @return array<int, array{label: string, value: string, elementType: string}>
     */
    public static function options(): array
    {
        $labels = self::translatedLabels();
        $options = [];

        foreach (self::typeKeys() as $key => $elementType) {
            $options[] = [
                'label' => $labels[$elementType] ?? self::fallbackLabel($elementType),
                'value' => $key,
                'elementType' => $elementType,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function translatedLabels(): array
    {
        $labels = [];

        foreach (self::labels() as $elementType => $label) {
            $labelKey = self::COMMERCE_LABEL_KEYS[$label] ?? $label;
            $labels[$elementType] = Craft::t('search-manager', $labelKey);
        }

        return $labels;
    }

    public static function elementTypeForKey(?string $key): ?string
    {
        $key = trim((string)$key);
        if ($key === '') {
            return null;
        }

        return self::typeKeys()[$key] ?? null;
    }

    public static function keyForElementType(?string $elementType): string
    {
        if ($elementType === null || $elementType === '') {
            return 'entry';
        }

        $key = array_search($elementType, self::typeKeys(), true);

        return is_string($key) ? $key : 'entry';
    }

    public static function isSupportedElementType(?string $elementType): bool
    {
        if ($elementType === null || $elementType === '') {
            return false;
        }

        return in_array($elementType, self::typeKeys(), true)
            && is_subclass_of($elementType, ElementInterface::class);
    }

    private static function fallbackLabel(string $elementType): string
    {
        $parts = explode('\\', $elementType);

        return end($parts) ?: $elementType;
    }
}
