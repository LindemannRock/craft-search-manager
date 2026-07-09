<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;
use craft\base\Field;

/**
 * Extracts keywords from Craft-native fields using Craft's own searchable-field contract.
 *
 * @since 5.53.0
 */
final class NativeFieldKeywordHelper
{
    public function supports(object $field): bool
    {
        if (!$field instanceof Field) {
            return false;
        }

        foreach ([$field::class, ...class_parents($field)] as $class) {
            if (str_starts_with($class, 'craft\\fields\\')) {
                return true;
            }
        }

        return false;
    }

    public function getSearchKeywords(Field $field, ElementInterface $element): ?string
    {
        if (!$field->searchable || $field->handle === null || $field->handle === '') {
            return null;
        }

        $value = $element->getFieldValue($field->handle);
        $keywords = $field->getSearchKeywords($value, $element);
        $keywords = trim((string)preg_replace('/\s+/', ' ', $keywords));

        return $keywords !== '' ? $keywords : null;
    }
}
