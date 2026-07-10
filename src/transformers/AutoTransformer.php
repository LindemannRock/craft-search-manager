<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\searchmanager\helpers\NativeFieldKeywordHelper;
use lindemannrock\searchmanager\helpers\SearchAutoContentHelper;
use lindemannrock\searchmanager\helpers\SearchContentBuilderHelper;
use lindemannrock\searchmanager\helpers\SearchElementKindMetadataHelper;
use lindemannrock\searchmanager\helpers\SearchFieldTypeContentHelper;
use lindemannrock\searchmanager\helpers\SearchHeadingHelper;

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
    private SearchFieldTypeContentHelper $fieldTypeContentHelper;

    private SearchAutoContentHelper $autoContentHelper;

    public function init(): void
    {
        parent::init();
        $this->fieldTypeContentHelper = new SearchFieldTypeContentHelper($this->contentCleaner());
        $this->autoContentHelper = new SearchAutoContentHelper(
            new NativeFieldKeywordHelper(),
            $this->fieldTypeContentHelper,
        );
    }

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
     * Transform an element by automatically detecting and processing all field types
     *
     * @param ElementInterface $element
     * @return array
     */
    public function transform(ElementInterface $element): array
    {
        // Start with common data
        $data = $this->getCommonData($element);

        // Set element-kind metadata for grouping (hierarchical layout, groupResults).
        $data = array_merge($data, SearchElementKindMetadataHelper::metadata($element, $data['elementType']));

        $data = array_merge($data, $this->getHierarchyMetadata($element));

        $contentBag = $this->autoContentHelper->collect($element);
        $searchableContent = $contentBag['parts'];
        $richTextContent = $contentBag['richText'];
        if ($contentBag['fields'] !== []) {
            $data['_fields'] = $contentBag['fields'];
        }

        // Extract headings from rich text fields
        $allRichText = implode("\n", $richTextContent);
        if (!empty($allRichText)) {
            $headings = $this->extractHeadings($allRichText);
            if (!empty($headings)) {
                $data['_headings'] = $headings;
                $data['headings'] = SearchHeadingHelper::headingText($headings);
                $searchableContent[] = $data['headings'];
            }
        }

        // Fall back to markdown heading detection from plain text content
        if (empty($data['_headings'])) {
            $contentForMarkdown = implode("\n", array_filter($searchableContent));
            $headings = $this->extractHeadings($contentForMarkdown);
            if (!empty($headings)) {
                $data['_headings'] = $headings;
                $data['headings'] = SearchHeadingHelper::headingText($headings);
            }
        }

        // Combine all searchable content
        return SearchContentBuilderHelper::apply($data, $searchableContent);
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
        return $this->fieldTypeContentHelper->process($field, $fieldValue, $element);
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
        return $this->fieldTypeContentHelper->processRelational($fieldValue);
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
        return $this->fieldTypeContentHelper->processMatrix($fieldValue);
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
        return $this->fieldTypeContentHelper->processTable($fieldValue);
    }
}
