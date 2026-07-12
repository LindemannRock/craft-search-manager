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
                'entrySection' => $section->name ?? $section->handle,
                'entrySectionHandle' => $section->handle,
                'entrySectionType' => $section->type,
            ];
        }

        if ($element instanceof Category) {
            $group = $element->getGroup();

            return [
                'categoryGroup' => $group->name ?? $group->handle,
                'categoryGroupHandle' => $group->handle,
            ];
        }

        if ($element instanceof Asset) {
            $volume = $element->getVolume();
            $metadata = [
                'volume' => $volume->name ?? $volume->handle,
                'volumeHandle' => $volume->handle,
            ];

            $filename = self::assetFilename($element);
            if ($filename !== '') {
                $metadata['filename'] = $filename;
            }

            $assetKind = self::stringValue($element->kind ?? null);
            if ($assetKind !== '') {
                $metadata['assetKind'] = $assetKind;
            }

            $extension = self::assetExtension($element);
            if ($extension !== '') {
                $metadata['extension'] = strtolower($extension);
            }

            if (is_int($element->size)) {
                $metadata['size'] = $element->size;
            }

            $width = $element->getWidth();
            if (is_int($width)) {
                $metadata['width'] = $width;
            }

            $height = $element->getHeight();
            if (is_int($height)) {
                $metadata['height'] = $height;
            }

            return $metadata;
        }

        if (!$element instanceof User) {
            return [
                'source' => ucfirst($documentType),
            ];
        }

        return [];
    }

    private static function assetFilename(Asset $asset): string
    {
        try {
            return self::stringValue($asset->getFilename());
        } catch (\Throwable) {
            return '';
        }
    }

    private static function assetExtension(Asset $asset): string
    {
        try {
            return self::stringValue($asset->getExtension());
        } catch (\Throwable) {
            return '';
        }
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
