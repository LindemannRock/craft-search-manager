<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\docsmanager\elements\SourceDoc;

/**
 * Builds one searchable document per SourceDoc intro/heading section.
 *
 * @since 5.55.0
 */
class SourceDocSectionSplitter
{
    /**
     * @param array<string, mixed> $pageData
     * @param array<int> $headingLevels
     * @return list<array<string, mixed>>
     */
    public static function split(SourceDoc $element, array $pageData, array $headingLevels): array
    {
        $html = DocsManagerDocumentHelper::htmlContent($element);
        if (trim($html) === '') {
            return [];
        }

        return HtmlSectionSplitter::split(
            pageData: $pageData,
            sources: [['html' => $html]],
            headingLevels: $headingLevels,
            introContentParts: [
                $pageData['title'] ?? null,
                $pageData['description'] ?? null,
                $pageData['keywords'] ?? null,
            ],
            emitIntroWithoutBody: false,
            preferHeadingIdAttribute: true,
            dedupeAnchors: true,
        );
    }
}
