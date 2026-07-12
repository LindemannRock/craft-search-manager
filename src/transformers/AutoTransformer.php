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
use lindemannrock\searchmanager\helpers\SearchCategoryRelationMetadataHelper;
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
        $kindMetadata = SearchElementKindMetadataHelper::metadata($element, $data['type']);
        $data = array_merge($data, $kindMetadata);

        $data = array_merge($data, $this->getHierarchyMetadata($element));

        $contentBag = $this->autoContentHelper->collect($element);
        $searchableContent = $contentBag['parts'];
        if ($data['type'] === 'asset') {
            self::appendUniqueSearchableTerm($searchableContent, $kindMetadata['assetKind'] ?? null);
            self::appendUniqueSearchableTerm($searchableContent, $kindMetadata['extension'] ?? null);
        }
        $richTextContent = $contentBag['richText'];
        if ($contentBag['fields'] !== []) {
            $data['_fields'] = $contentBag['fields'];
        }
        if ($contentBag['bodyClean'] !== '') {
            $data['_bodyClean'] = $contentBag['bodyClean'];
        }
        $categoryIds = SearchCategoryRelationMetadataHelper::categoryIds($element);
        if ($categoryIds !== []) {
            $data['_categoryIds'] = $categoryIds;
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

    /**
     * @param array<int, mixed> $searchableContent
     */
    private static function appendUniqueSearchableTerm(array &$searchableContent, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }

        $term = trim((string)$value);
        if ($term === '') {
            return;
        }

        foreach ($searchableContent as $part) {
            if (is_scalar($part) && strcasecmp(trim((string)$part), $term) === 0) {
                return;
            }
        }

        $searchableContent[] = $term;
    }
}
