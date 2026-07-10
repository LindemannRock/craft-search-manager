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
 * Builds stable base document data for indexed Craft elements.
 *
 * @since 5.53.0
 */
class SearchDocumentDataHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function commonData(ElementInterface $element): array
    {
        $backendId = SearchHitIdentityHelper::backendId($element->id, $element->siteId);
        $documentType = self::documentType($element);

        return [
            'objectID' => $element->id,
            'id' => $element->id,
            'elementId' => $element->id,
            'backendId' => $backendId,
            'type' => $documentType,
            'elementType' => $documentType,
            'title' => self::title($element),
            'slug' => self::stringValue($element, 'slug'),
            'url' => $element->url ?? '',
            'siteId' => $element->siteId,
            'dateCreated' => $element->dateCreated?->getTimestamp(),
            'dateUpdated' => $element->dateUpdated?->getTimestamp(),
        ];
    }

    public static function stringValue(ElementInterface $element, string $property): string
    {
        $value = $element->{$property} ?? null;

        return is_scalar($value) ? trim((string)$value) : '';
    }

    public static function title(ElementInterface $element): string
    {
        if ($element instanceof User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = self::stringValue($element, $property);
                if ($value !== '') {
                    return $value;
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        return self::stringValue($element, 'title');
    }

    public static function documentType(ElementInterface $element): string
    {
        if ($element instanceof Entry) {
            return 'entry';
        }

        if (is_a($element, CommerceElementTypeHelper::productElementType())) {
            return 'product';
        }

        if (is_a($element, CommerceElementTypeHelper::variantElementType())) {
            return 'variant';
        }

        if ($element instanceof Category) {
            return 'category';
        }

        if ($element instanceof Asset) {
            return 'asset';
        }

        if ($element instanceof User) {
            return 'user';
        }

        if (method_exists($element, 'refHandle')) {
            $refHandle = $element::refHandle();
            if (is_string($refHandle) && $refHandle !== '') {
                return self::normalizeDocumentType($refHandle);
            }
        }

        $className = get_class($element);
        $shortName = basename(str_replace('\\', '/', $className));
        if ($shortName !== '') {
            return self::normalizeDocumentType($shortName);
        }

        return self::normalizeDocumentType($element::displayName());
    }

    public static function normalizeDocumentType(string $value): string
    {
        $normalized = preg_replace('/(?<!^)[A-Z]/', '-$0', trim($value));
        $normalized = strtolower((string)$normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);

        return trim((string)$normalized, '-') ?: 'element';
    }
}
