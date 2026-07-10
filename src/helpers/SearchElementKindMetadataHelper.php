<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Builds non-hierarchical element kind metadata for indexed documents.
 *
 * @since 5.53.0
 */
class SearchElementKindMetadataHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function metadata(ElementInterface $element, string $documentType): array
    {
        if ($element instanceof Entry && $element->getSection() !== null) {
            $section = $element->getSection();

            return [
                'section' => $section->name ?? $section->handle,
                'sectionHandle' => $section->handle,
                'sectionType' => $section->type,
            ];
        }

        if ($element instanceof Category) {
            $group = $element->getGroup();

            return [
                'group' => $group->name ?? $group->handle,
                'groupHandle' => $group->handle,
            ];
        }

        if ($element instanceof Asset) {
            $volume = $element->getVolume();

            return [
                'volume' => $volume->name ?? $volume->handle,
                'volumeHandle' => $volume->handle,
            ];
        }

        if (!$element instanceof User) {
            return [
                'section' => ucfirst($documentType),
            ];
        }

        return [];
    }
}
