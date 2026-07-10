<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Centralizes element availability rules for Search Manager indexing,
 * enrichment, and promotions.
 *
 * @since 5.53.0
 */
class SearchElementAvailabilityHelper
{
    /**
     * @param class-string $elementClass
     */
    public static function isSiteIndependent(string $elementClass): bool
    {
        return $elementClass === User::class || is_subclass_of($elementClass, User::class);
    }

    /**
     * Apply Search Manager's searchable-element availability contract to an
     * element query.
     *
     * @param class-string $elementClass
     */
    public static function applyToQuery(ElementQueryInterface $query, string $elementClass): ElementQueryInterface
    {
        if ($elementClass === CommerceElementTypeHelper::variantElementType()) {
            $query->status(Element::STATUS_ENABLED);
            if (method_exists($query, 'productStatus')) {
                $query->productStatus(self::liveStatusFor($elementClass));
            }

            return $query;
        }

        if ($elementClass === User::class || is_subclass_of($elementClass, User::class)) {
            return $query->status(User::STATUS_ACTIVE);
        }

        if ($elementClass === Entry::class || is_subclass_of($elementClass, Entry::class)) {
            return $query->status(Entry::STATUS_LIVE);
        }

        if ($elementClass === CommerceElementTypeHelper::productElementType()) {
            return $query->status(self::liveStatusFor($elementClass));
        }

        if (
            $elementClass === Asset::class
            || is_subclass_of($elementClass, Asset::class)
            || $elementClass === Category::class
            || is_subclass_of($elementClass, Category::class)
        ) {
            return $query->status(Element::STATUS_ENABLED);
        }

        return $query->status(Element::STATUS_ENABLED);
    }

    public static function isSearchable(ElementInterface $element): bool
    {
        if ($element instanceof Element) {
            if ($element->getIsDraft() || $element->getIsRevision()) {
                return false;
            }

            if (!$element->enabled || !$element->getEnabledForSite()) {
                return false;
            }
        }

        $elementClass = get_class($element);
        $status = $element->getStatus();

        if ($element instanceof Entry) {
            return $status === Entry::STATUS_LIVE;
        }

        if ($element instanceof User) {
            return $status === User::STATUS_ACTIVE;
        }

        if ($elementClass === CommerceElementTypeHelper::variantElementType()) {
            return $status === Element::STATUS_ENABLED && self::variantProductIsLive($element);
        }

        if ($elementClass === CommerceElementTypeHelper::productElementType()) {
            return $status === self::liveStatusFor($elementClass);
        }

        return $status === Element::STATUS_ENABLED;
    }

    /**
     * @param class-string $elementClass
     */
    private static function liveStatusFor(string $elementClass): string
    {
        $constant = $elementClass . '::STATUS_LIVE';

        return defined($constant) ? (string) constant($constant) : 'live';
    }

    private static function variantProductIsLive(ElementInterface $variant): bool
    {
        try {
            $product = method_exists($variant, 'getProduct')
                ? $variant->getProduct()
                : ($variant->product ?? null);
        } catch (\Throwable) {
            return false;
        }

        if (!$product instanceof ElementInterface) {
            return false;
        }

        return $product->getStatus() === self::liveStatusFor(get_class($product));
    }
}
