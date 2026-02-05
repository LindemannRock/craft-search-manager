<?php

namespace lindemannrock\searchmanager\transformers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\ElementHelper;

/**
 * Auto Transformer
 *
 * Automatically indexes all fields from any element type
 * - Traverses relational fields (Entries, Categories, Tags, Assets)
 * - Handles complex fields (Matrix, Table, etc.)
 * - Falls back to searchable keywords for unknown types
 * - Works with any element type automatically
 *
 * This is the default transformer when no custom transformer is specified
 *
 * @since 5.0.0
 */
class AutoTransformer extends BaseTransformer
{
    // =========================================================================
    // ELEMENT TYPE
    // =========================================================================

    protected function getElementType(): string
    {
        // Auto transformer supports all element types
        return ElementInterface::class;
    }

    /**
     * @since 5.0.0
     */
    public function supports(ElementInterface $element): bool
    {
        // Auto transformer supports everything
        return true;
    }

    // =========================================================================
    // TRANSFORMATION
    // =========================================================================

    /**
     * Transform an element by automatically detecting and processing all field types
     *
     * @param ElementInterface $element
     * @return array
     * @since 5.0.0
     */
    public function transform(ElementInterface $element): array
    {
        // Start with common data
        $data = $this->getCommonData($element);

        // Derive element type from section handle (for Entries) or element class
        $data['elementType'] = $this->deriveElementType($element);

        // Collect searchable content
        $searchableContent = [];

        // Add title
        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        // Process all searchable attributes (Craft's built-in searchable fields)
        foreach (ElementHelper::searchableAttributes($element) as $attribute) {
            try {
                $value = $element->getSearchKeywords($attribute);
                if (!empty($value)) {
                    $searchableContent[] = $value;
                    $data['_' . $attribute] = $value;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Process custom fields with automatic type detection
        if ($element->getFieldLayout()) {
            foreach ($element->getFieldLayout()->getCustomFields() as $field) {
                try {
                    $fieldValue = $element->getFieldValue($field->handle);

                    // Skip empty values
                    if ($fieldValue === null || $fieldValue === '' || $fieldValue === []) {
                        continue;
                    }

                    // Process based on field type
                    $content = $this->processFieldByType($field, $fieldValue, $element);

                    if (!empty($content)) {
                        if (is_array($content)) {
                            $searchableContent = array_merge($searchableContent, $content);
                        } else {
                            $searchableContent[] = $content;
                        }
                        $data[$field->handle] = is_array($content) ? implode(' ', $content) : $content;
                    }
                } catch (\Throwable $e) {
                    // Skip fields that error
                    continue;
                }
            }
        }

        // Combine all searchable content
        $data['content'] = implode(' ', array_filter($searchableContent));
        $data['excerpt'] = $this->getExcerpt($data['content'], 200);

        return $data;
    }

    // =========================================================================
    // ELEMENT TYPE DERIVATION
    // =========================================================================

    /**
     * Derive element type from section handle (for Entries) or element class
     *
     * For Entries: uses section handle, singularized (products → product)
     * For Categories: returns 'category'
     * For Assets: returns 'asset'
     * For other elements: derives from class name
     *
     * @param ElementInterface $element
     * @return string Element type for search results
     */
    protected function deriveElementType(ElementInterface $element): string
    {
        // For Entries: use section handle (singularized)
        if ($element instanceof \craft\elements\Entry && $element->section) {
            return $this->singularize($element->section->handle);
        }

        // For Categories: return 'category'
        if ($element instanceof \craft\elements\Category) {
            return 'category';
        }

        // For Assets: return 'asset'
        if ($element instanceof \craft\elements\Asset) {
            return 'asset';
        }

        // For Users: return 'user'
        if ($element instanceof \craft\elements\User) {
            return 'user';
        }

        // For Tags: return 'tag'
        if ($element instanceof \craft\elements\Tag) {
            return 'tag';
        }

        // Fallback: derive from class name
        $className = get_class($element);
        $shortName = basename(str_replace('\\', '/', $className));
        return strtolower($shortName);
    }

    /**
     * Singularize a word (simple English rules)
     *
     * products → product
     * categories → category
     * entries → entry
     * stores → store
     *
     * @param string $word
     * @return string
     */
    protected function singularize(string $word): string
    {
        $word = strtolower($word);

        // Common irregular plurals
        $irregulars = [
            'categories' => 'category',
            'entries' => 'entry',
            'stories' => 'story',
            'series' => 'series',
            'news' => 'news',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        // Words ending in 'ies' → 'y' (but not 'ies' at start)
        if (strlen($word) > 3 && str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }

        // Words ending in 'es' after s, x, z, ch, sh → remove 'es'
        if (str_ends_with($word, 'ses') || str_ends_with($word, 'xes') ||
            str_ends_with($word, 'zes') || str_ends_with($word, 'ches') ||
            str_ends_with($word, 'shes')) {
            return substr($word, 0, -2);
        }

        // Words ending in 's' → remove 's'
        if (strlen($word) > 1 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }

        return $word;
    }

    // =========================================================================
    // FIELD TYPE PROCESSING
    // =========================================================================

    /**
     * Process field value based on its type
     * Automatically handles relational and complex field types
     *
     * @param mixed $field The field instance
     * @param mixed $fieldValue The field value
     * @param ElementInterface $element The parent element
     * @return string|array|null Searchable content
     */
    protected function processFieldByType($field, $fieldValue, ElementInterface $element)
    {
        $fieldClass = get_class($field);

        switch ($fieldClass) {
            // Plain text fields
            case 'craft\fields\PlainText':
            case 'craft\fields\Dropdown':
            case 'craft\fields\Url':
            case 'craft\fields\Email':
            case 'craft\fields\Number':
            case 'craft\fields\Color':
                return (string)$fieldValue;

            // Rich text
            case 'craft\ckeditor\Field':
            case 'craft\redactor\Field':
                return $this->stripHtml((string)$fieldValue);

            // Relational: Entries
            case 'craft\fields\Entries':
                return $this->processRelationalField($fieldValue);

            // Relational: Categories
            case 'craft\fields\Categories':
                return $this->processRelationalField($fieldValue);

            // Relational: Tags
            case 'craft\fields\Tags':
                return $this->processRelationalField($fieldValue);

            // Relational: Assets
            case 'craft\fields\Assets':
                return $this->processRelationalField($fieldValue);

            // Relational: Users
            case 'craft\fields\Users':
                return $this->processRelationalField($fieldValue);

            // Matrix blocks
            case 'craft\fields\Matrix':
                return $this->processMatrixField($fieldValue);

            // Table field
            case 'craft\fields\Table':
                return $this->processTableField($fieldValue);

            // Icon Manager (custom field)
            case 'lindemannrock\iconmanager\fields\IconManagerField':
                return $this->processIconManagerField($fieldValue);

            // Default: Fall back to searchable keywords
            default:
                if ($field->searchable) {
                    $keywords = $field->getSearchKeywords($fieldValue, $element);
                    return !empty($keywords) ? $keywords : null;
                }
                return null;
        }
    }

    /**
     * Process relational fields (Entries, Categories, Tags, Assets, Users)
     * Extracts titles from all related elements
     *
     * @param mixed $fieldValue Query or array of elements
     * @return array Array of titles
     */
    protected function processRelationalField($fieldValue): array
    {
        $titles = [];

        if (!is_object($fieldValue) && !is_array($fieldValue)) {
            return $titles;
        }

        // Handle ElementQuery objects (have ->all() method)
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
     * Process Matrix field
     * Extracts content from all text fields within matrix blocks
     *
     * @param mixed $fieldValue Matrix blocks query
     * @return array Array of content strings
     */
    protected function processMatrixField($fieldValue): array
    {
        $content = [];

        if (!is_object($fieldValue) && !is_array($fieldValue)) {
            return $content;
        }

        // Handle MatrixBlockQuery objects (have ->all() method)
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
                        $content[] = $this->stripHtml($blockValue);
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return $content;
    }

    /**
     * Process Table field
     * Extracts all cell values
     *
     * @param array $fieldValue Table data
     * @return array Array of cell values
     */
    protected function processTableField($fieldValue): array
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

    /**
     * Process Icon Manager field
     * Extracts display labels from icons
     *
     * @param mixed $fieldValue Icon or array of icons
     * @return array Array of icon labels
     */
    protected function processIconManagerField($fieldValue): array
    {
        $labels = [];

        try {
            // Handle both array (multi) and object (single)
            $icons = [];
            if (is_array($fieldValue)) {
                $icons = $fieldValue;
            } elseif (is_object($fieldValue)) {
                if (method_exists($fieldValue, 'all')) {
                    $icons = $fieldValue->all();
                } else {
                    $icons = [$fieldValue];
                }
            }

            foreach ($icons as $icon) {
                if ($icon && is_object($icon) && method_exists($icon, 'getDisplayLabel')) {
                    $label = $icon->getDisplayLabel();
                    if ($label) {
                        $labels[] = $label;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Return empty array on error
        }

        return $labels;
    }
}
