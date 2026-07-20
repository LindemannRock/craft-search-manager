<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

/**
 * Applies Search Manager index criteria to Craft element queries.
 *
 * @since 5.53.0
 */
final class SearchIndexCriteriaHelper
{
    private const SOURCE_DOC_ELEMENT_TYPE = 'lindemannrock\\docsmanager\\elements\\SourceDoc';

    /**
     * Return the supported config selector for an element type.
     *
     * @since 5.54.0
     */
    public static function selectorForElementType(string $elementType): ?string
    {
        return match ($elementType) {
            Entry::class => 'sections',
            Asset::class => 'volumes',
            Category::class => 'groups',
            self::SOURCE_DOC_ELEMENT_TYPE => 'sourceHandles',
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|\Closure $criteria
     */
    public static function apply(ElementQuery $query, string $elementType, array|\Closure $criteria): ElementQuery
    {
        if ($criteria instanceof \Closure) {
            return $criteria($query);
        }

        if ($elementType === Entry::class && !empty($criteria['sections']) && $query instanceof EntryQuery) {
            $query->section($criteria['sections']);
        }

        if ($elementType === Asset::class && !empty($criteria['volumes']) && $query instanceof AssetQuery) {
            $query->volume($criteria['volumes']);
        }

        if ($elementType === Category::class && !empty($criteria['groups']) && $query instanceof CategoryQuery) {
            $query->group($criteria['groups']);
        }

        if ($elementType === self::SOURCE_DOC_ELEMENT_TYPE && !empty($criteria['sourceHandles']) && is_callable([$query, 'sourceHandle'])) {
            $query->sourceHandle($criteria['sourceHandles']);
        }

        return $query;
    }
}
