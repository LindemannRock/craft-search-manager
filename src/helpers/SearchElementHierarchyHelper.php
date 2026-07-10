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
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\VolumeFolder;

/**
 * Builds source-backed hierarchy/path metadata for element kinds with tree context.
 *
 * @since 5.53.0
 */
class SearchElementHierarchyHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function metadata(ElementInterface $element): array
    {
        if ($element instanceof Entry) {
            $section = $element->getSection();
            if ($section?->type !== Section::TYPE_STRUCTURE) {
                return [];
            }

            return self::structuredElementMetadata($element);
        }

        if ($element instanceof Category) {
            return self::structuredElementMetadata($element);
        }

        if ($element instanceof Asset) {
            return self::assetMetadata($element);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function structuredElementMetadata(ElementInterface $element): array
    {
        $level = $element->level ?? null;

        return self::filter([
            'ancestors' => self::ancestorItemsFromElementSource($element->getAncestors()),
            'level' => is_numeric($level) ? (int)$level : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function assetMetadata(Asset $element): array
    {
        if (SearchDocumentDataHelper::stringValue($element, 'url') === '') {
            return [];
        }

        try {
            $rootUrl = $element->getVolume()->getRootUrl();
        } catch (\Throwable) {
            return [];
        }

        if (!is_string($rootUrl) || trim($rootUrl) === '') {
            return [];
        }

        try {
            $folder = $element->getFolder();
        } catch (\Throwable) {
            return [];
        }

        return self::filter([
            'ancestors' => self::ancestorItemsFromFolder($folder),
            'folderPath' => trim((string)$folder->path),
        ]);
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private static function ancestorItemsFromElementSource(ElementQueryInterface|ElementCollection $source): array
    {
        return self::ancestorItemsFromElements($source->all());
    }

    /**
     * @param iterable<ElementInterface> $ancestors
     * @return array<int, array{id: int, title: string}>
     */
    private static function ancestorItemsFromElements(iterable $ancestors): array
    {
        $items = [];

        foreach ($ancestors as $ancestor) {
            $id = $ancestor->id ?? null;
            $title = SearchDocumentDataHelper::title($ancestor);
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $items[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private static function ancestorItemsFromFolder(VolumeFolder $folder): array
    {
        $folders = [];
        for ($current = $folder; $current !== null; $current = $current->getParent()) {
            array_unshift($folders, $current);
        }

        $items = [];
        foreach ($folders as $ancestor) {
            $id = $ancestor->id ?? null;
            $title = trim((string)($ancestor->name ?? ''));
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $items[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private static function filter(array $metadata): array
    {
        return array_filter($metadata, static function(mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return !is_array($value) || $value !== [];
        });
    }
}
