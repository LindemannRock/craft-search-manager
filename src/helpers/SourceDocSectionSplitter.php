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
        $html = (string)($element->htmlContent ?? '');
        if (trim($html) === '') {
            return [];
        }

        $matches = self::headingMatches($html, $headingLevels);
        $sections = [];
        $cleaner = new SearchContentCleaner();
        $sectionIndex = 0;

        $introHtml = $matches === [] ? $html : substr($html, 0, (int)$matches[0][0][1]);
        $introBody = $cleaner->cleanBody($introHtml);
        if ($introBody !== '') {
            $sections[] = self::sectionDocument(
                pageData: $pageData,
                sectionType: 'intro',
                sectionId: 'intro',
                sectionTitle: (string)($pageData['title'] ?? ''),
                sectionLevel: null,
                sectionAnchor: null,
                sectionBody: $introBody,
                sectionIndex: $sectionIndex++,
            );
        }

        foreach ($matches as $i => $match) {
            $level = (int)$match[1][0];
            $headingHtml = $match[0][0];
            $headingOffset = (int)$match[0][1];
            $afterHeading = $headingOffset + strlen($headingHtml);
            $nextOffset = isset($matches[$i + 1]) ? (int)$matches[$i + 1][0][1] : strlen($html);
            $sectionHtml = $headingHtml . substr($html, $afterHeading, $nextOffset - $afterHeading);

            $title = trim(ltrim(html_entity_decode(strip_tags((string)$match[3][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), '#'));
            if ($title === '') {
                continue;
            }

            $anchor = self::headingAnchor((string)$match[2][0], $title);
            $body = $cleaner->cleanBody($sectionHtml);
            if ($body === '') {
                $body = $title;
            }

            $sections[] = self::sectionDocument(
                pageData: $pageData,
                sectionType: 'heading',
                sectionId: $anchor,
                sectionTitle: $title,
                sectionLevel: $level,
                sectionAnchor: $anchor,
                sectionBody: $body,
                sectionIndex: $sectionIndex++,
            );
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $pageData
     * @return array<string, mixed>
     */
    private static function sectionDocument(
        array $pageData,
        string $sectionType,
        string $sectionId,
        string $sectionTitle,
        ?int $sectionLevel,
        ?string $sectionAnchor,
        string $sectionBody,
        int $sectionIndex,
    ): array {
        $elementId = (int)($pageData['elementId'] ?? $pageData['id'] ?? 0);
        $siteId = $pageData['siteId'] ?? null;
        $pageUrl = is_string($pageData['url'] ?? null) ? (string)$pageData['url'] : null;
        $sectionUrl = $pageUrl;
        if ($pageUrl !== null && $sectionAnchor !== null && $sectionAnchor !== '') {
            $sectionUrl = rtrim($pageUrl, '#') . '#' . $sectionAnchor;
        }

        $document = $pageData;
        $document['id'] = $elementId;
        $document['elementId'] = $elementId;
        $document['backendId'] = SearchHitIdentityHelper::sectionDocumentId($elementId, $siteId, $sectionId);
        $document['sectionType'] = $sectionType;
        $document['sectionId'] = $sectionId;
        $document['sectionTitle'] = $sectionTitle;
        $document['sectionLevel'] = $sectionLevel;
        $document['sectionAnchor'] = $sectionAnchor;
        $document['sectionUrl'] = $sectionUrl;
        $document['sectionIndex'] = $sectionIndex;
        $document['sectionBody'] = $sectionBody;
        $document['_bodyClean'] = $sectionBody;
        $document['_headings'] = [];
        $document['headings'] = '';
        $document['content'] = trim(implode(' ', array_filter([
            $sectionTitle,
            $pageData['description'] ?? null,
            $sectionBody,
            $pageData['keywords'] ?? null,
        ], static fn(mixed $value): bool => is_scalar($value) && trim((string)$value) !== '')));

        return $document;
    }

    /**
     * @param array<int> $headingLevels
     * @return list<array<int, array{0: string, 1: int}>>
     */
    private static function headingMatches(string $html, array $headingLevels): array
    {
        if (!preg_match_all('/<h([1-6])([^>]*)>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_values(array_filter($matches, static fn(array $match): bool => in_array((int)$match[1][0], $headingLevels, true)));
    }

    private static function headingAnchor(string $attributes, string $title): string
    {
        if (preg_match('/\bid=(["\'])(.*?)\1/i', $attributes, $idMatch) && trim($idMatch[2]) !== '') {
            return trim($idMatch[2]);
        }

        return SearchHeadingHelper::headingId($title);
    }
}
