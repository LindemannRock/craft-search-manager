<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\base\ElementInterface;

/**
 * Processes custom field values into searchable transformer content.
 *
 * @since 5.53.0
 */
class SearchFieldTypeContentHelper
{
    public function __construct(private readonly SearchContentCleaner $contentCleaner)
    {
    }

    public function isRichTextField(object $field): bool
    {
        return is_a($field, 'craft\ckeditor\Field') || is_a($field, 'craft\redactor\Field');
    }

    public function process(object $field, mixed $fieldValue, ElementInterface $element): string|array|null
    {
        if (is_a($field, 'craft\fields\PlainText')
            || is_a($field, 'craft\fields\Dropdown')
            || is_a($field, 'craft\fields\Url')
            || is_a($field, 'craft\fields\Email')
            || is_a($field, 'craft\fields\Number')
            || is_a($field, 'craft\fields\Color')) {
            return (string)$fieldValue;
        }

        if ($this->isRichTextField($field)) {
            return $this->contentCleaner->stripHtml((string)$fieldValue);
        }

        if (is_a($field, 'craft\fields\Entries')
            || is_a($field, 'craft\fields\Categories')
            || is_a($field, 'craft\fields\Tags')
            || is_a($field, 'craft\fields\Assets')
            || is_a($field, 'craft\fields\Users')) {
            return $this->processRelational($fieldValue);
        }

        if (is_a($field, 'craft\fields\Matrix')) {
            return $this->processMatrix($fieldValue);
        }

        if (is_a($field, 'craft\fields\Table')) {
            return $this->processTable($fieldValue);
        }

        if ($field->searchable) {
            $keywords = $field->getSearchKeywords($fieldValue, $element);

            return !empty($keywords) ? $keywords : null;
        }

        return null;
    }

    public function cleanBody(string $html): string
    {
        return $this->contentCleaner->cleanBody($html);
    }

    /**
     * @return string[]
     */
    public function processRelational(mixed $fieldValue): array
    {
        $titles = [];

        if (!is_object($fieldValue) && !is_array($fieldValue)) {
            return $titles;
        }

        if (is_object($fieldValue) && method_exists($fieldValue, 'all')) {
            $elements = $fieldValue->all();
        } elseif (is_array($fieldValue)) {
            $elements = $fieldValue;
        } else {
            return $titles;
        }

        foreach ($elements as $relatedElement) {
            if ($relatedElement && isset($relatedElement->title) && $relatedElement->title) {
                $titles[] = $relatedElement->title;
            }
        }

        return $titles;
    }

    /**
     * @return string[]
     */
    public function processMatrix(mixed $fieldValue): array
    {
        $content = [];

        if (!is_object($fieldValue) && !is_array($fieldValue)) {
            return $content;
        }

        if (is_object($fieldValue) && method_exists($fieldValue, 'all')) {
            $blocks = $fieldValue->all();
        } elseif (is_array($fieldValue)) {
            $blocks = $fieldValue;
        } else {
            return $content;
        }

        foreach ($blocks as $block) {
            if (!is_object($block) || !method_exists($block, 'getFieldLayout') || !method_exists($block, 'getFieldValue')) {
                continue;
            }

            $fieldLayout = $block->getFieldLayout();
            if (!$fieldLayout) {
                continue;
            }

            foreach ($fieldLayout->getCustomFields() as $blockField) {
                try {
                    $blockValue = $block->getFieldValue($blockField->handle);
                    if ($blockValue && is_string($blockValue)) {
                        $content[] = $this->contentCleaner->stripHtml($blockValue);
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $content;
    }

    /**
     * @return string[]
     */
    public function processTable(mixed $fieldValue): array
    {
        $content = [];

        if (!is_array($fieldValue)) {
            return $content;
        }

        foreach ($fieldValue as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $content[] = (string)$cell;
                }
            }
        }

        return $content;
    }
}
