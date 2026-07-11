<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;
use craft\fields\Categories;

/**
 * Extracts compact category-relation metadata for indexed search documents.
 *
 * @since 5.56.0
 */
class SearchCategoryRelationMetadataHelper
{
    /**
     * @return array<int, int>
     */
    public static function categoryIds(ElementInterface $element): array
    {
        $fieldLayout = $element->getFieldLayout();
        if ($fieldLayout === null) {
            return [];
        }

        $categoryIds = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            if (!$field instanceof Categories || !is_string($field->handle) || $field->handle === '') {
                continue;
            }

            try {
                $value = $element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            foreach (self::relatedElements($value) as $relatedElement) {
                if (isset($relatedElement->id) && is_numeric($relatedElement->id)) {
                    $categoryIds[] = (int)$relatedElement->id;
                }
            }
        }

        $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn(int $id): bool => $id > 0)));
        sort($categoryIds);

        return $categoryIds;
    }

    /**
     * @return iterable<object>
     */
    private static function relatedElements(mixed $value): iterable
    {
        if (is_object($value) && method_exists($value, 'all')) {
            return $value->all();
        }

        return is_iterable($value) ? $value : [];
    }
}
