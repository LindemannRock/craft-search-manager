<?php

namespace lindemannrock\searchmanager\transformers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\ElementHelper;

/**
 * Auto Transformer
 *
 * Automatically indexes Craft's searchable fields (like Bramble Search)
 * Uses fields marked as "searchable" in the CP
 * This is the default transformer when no custom transformer is specified
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

    public function supports(ElementInterface $element): bool
    {
        // Auto transformer supports everything
        return true;
    }

    // =========================================================================
    // TRANSFORMATION
    // =========================================================================

    /**
     * Transform an element using Craft's searchable attributes
     * Like Bramble Search - automatically indexes fields marked as searchable
     *
     * @param ElementInterface $element
     * @return array
     */
    public function transform(ElementInterface $element): array
    {
        // Start with common data
        $data = $this->getCommonData($element);

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

                    // Store individual field values too
                    $data['_' . $attribute] = $value;
                }
            } catch (\Throwable $e) {
                // Skip attributes that can't be accessed
                continue;
            }
        }

        // Process custom fields marked as searchable
        if ($element->getFieldLayout()) {
            foreach ($element->getFieldLayout()->getCustomFields() as $field) {
                if ($field->searchable) {
                    try {
                        $fieldValue = $element->getFieldValue($field->handle);
                        if ($fieldValue) {
                            $keywords = $field->getSearchKeywords($fieldValue, $element);
                            if (!empty($keywords)) {
                                $searchableContent[] = $keywords;
                                $data[$field->handle] = $keywords;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Skip fields that error
                        continue;
                    }
                }
            }
        }

        // Combine all searchable content
        $data['content'] = implode(' ', $searchableContent);
        $data['excerpt'] = $this->getExcerpt($data['content'], 200);

        return $data;
    }
}
