<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

use craft\elements\db\ElementQueryInterface;

/**
 * Applies promotion live-status constraints to element queries.
 *
 * @since 5.53.0
 */
class PromotionLiveElementQueryHelper
{
    /**
     * Commerce variants are live for search when the variant is enabled and
     * the parent product is live. Other element types keep Craft's live status.
     *
     * @param class-string $elementClass
     */
    public static function apply(ElementQueryInterface $query, string $elementClass): ElementQueryInterface
    {
        if ($elementClass === CommerceElementTypeHelper::variantElementType() && method_exists($query, 'productStatus')) {
            $query->status('enabled');
            $query->productStatus('live');

            return $query;
        }

        return $query->status('live');
    }
}
