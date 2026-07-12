<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\transformers;

use craft\base\ElementInterface;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\searchmanager\helpers\DocsManagerDocumentHelper;
use lindemannrock\searchmanager\helpers\SearchContentBuilderHelper;
use lindemannrock\searchmanager\helpers\SearchHeadingHelper;

/**
 * Docs Manager Transformer
 *
 * Transforms SourceDoc elements into searchable documents,
 * including the full HTML content, headings, and keywords.
 *
 * @since 5.39.0
 */
class DocsManagerTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return SourceDoc::class;
    }

    /**
     * Transform a source doc page into a searchable document
     *
     * @param ElementInterface|SourceDoc $element
     * @return array
     */
    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        if (!($element instanceof SourceDoc)) {
            return $data;
        }

        $data['type'] = $this->resolveDocumentType($element);
        $data['elementType'] = $data['type'];
        $data['source'] = DocsManagerDocumentHelper::sourceName($element->sourceId);
        $data['slug'] = $element->slug;
        $data['docCategory'] = $element->category;
        $data['description'] = $element->description ?? '';
        $data['sourceId'] = $element->sourceId;

        $htmlContent = DocsManagerDocumentHelper::htmlContent($element);
        $searchableContent = DocsManagerDocumentHelper::contentParts($element, $this->contentCleaner());
        $cleanBody = DocsManagerDocumentHelper::cleanBody($element, $this->contentCleaner());
        if ($cleanBody !== '') {
            $data['_bodyClean'] = $cleanBody;
        }
        $bodyWithCode = DocsManagerDocumentHelper::cleanBodyWithCode($element, $this->contentCleaner());
        if ($bodyWithCode !== '') {
            $data['_bodyWithCode'] = $bodyWithCode;
        }

        // Extract headings for boosting and hierarchical display
        // Always use BaseTransformer::extractHeadings() which respects index headingLevels
        $headings = [];
        if ($htmlContent !== '') {
            $headings = $this->extractHeadings($htmlContent);
        }

        if (!empty($headings)) {
            $headingTexts = array_column($headings, 'text');
            $headingTexts = array_filter($headingTexts);
            if (!empty($headingTexts)) {
                $data['headings'] = SearchHeadingHelper::headingText($headings);
            }

            // Keep raw headings for hierarchical display in frontend
            $data['_headings'] = SearchHeadingHelper::normalizeHeadings($headings);
        }

        // Index extracted keywords
        if (!empty($element->keywords)) {
            $data['keywords'] = DocsManagerDocumentHelper::keywords($element);
            $searchableContent[] = $data['keywords'];
        }

        return SearchContentBuilderHelper::apply($data, $searchableContent);
    }
}
