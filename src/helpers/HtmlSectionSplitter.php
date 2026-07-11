<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Builds one searchable document per intro/heading section from ordered HTML sources.
 *
 * @since 5.53.0
 */
class HtmlSectionSplitter
{
    /**
     * @param array<string, mixed> $pageData
     * @param list<array{html: string}> $sources
     * @param array<int> $headingLevels
     * @param array<int, mixed> $introContentParts
     * @return list<array<string, mixed>>
     */
    public static function split(
        array $pageData,
        array $sources,
        array $headingLevels,
        array $introContentParts = [],
        bool $emitIntroWithoutBody = false,
        bool $preferHeadingIdAttribute = true,
        bool $dedupeAnchors = false,
    ): array {
        $cleaner = new SearchContentCleaner();
        $introHtmlParts = [];
        $headingSections = [];
        $usedAnchors = [];

        foreach ($sources as $source) {
            $html = trim($source['html']);
            if ($html === '') {
                continue;
            }

            $matches = self::headingMatches($html, $headingLevels);
            if ($matches === []) {
                $introHtmlParts[] = $html;
                continue;
            }

            $introHtml = substr($html, 0, (int)$matches[0][0][1]);
            if (trim($introHtml) !== '') {
                $introHtmlParts[] = $introHtml;
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

                $anchor = self::headingAnchor((string)$match[2][0], $title, $preferHeadingIdAttribute);
                if ($dedupeAnchors) {
                    $anchor = self::dedupeAnchor($anchor, $usedAnchors);
                } else {
                    $usedAnchors[$anchor] = true;
                }

                $body = $cleaner->cleanBody($sectionHtml);
                $bodyWithCode = $cleaner->cleanBodyWithCode($sectionHtml);
                if ($body === '') {
                    $body = $title;
                }
                if ($bodyWithCode === '') {
                    $bodyWithCode = $body;
                }

                $headingSections[] = [
                    'anchor' => $anchor,
                    'body' => $body,
                    'bodyWithCode' => $bodyWithCode,
                    'level' => $level,
                    'title' => $title,
                ];
            }
        }

        if ($headingSections === [] && $introHtmlParts === [] && !$emitIntroWithoutBody) {
            return [];
        }

        $sections = [];
        $sectionIndex = 0;
        $introHtml = implode(' ', $introHtmlParts);
        $introBody = $cleaner->cleanBody($introHtml);
        $introBodyWithCode = $cleaner->cleanBodyWithCode($introHtml);
        if ($introBody !== '' || ($emitIntroWithoutBody && self::hasContentParts($introContentParts))) {
            $sections[] = self::sectionDocument(
                pageData: $pageData,
                sectionType: 'intro',
                sectionId: 'intro',
                sectionTitle: (string)($pageData['title'] ?? ''),
                sectionLevel: null,
                sectionAnchor: null,
                sectionBody: $introBody,
                sectionBodyWithCode: $introBodyWithCode !== '' ? $introBodyWithCode : $introBody,
                sectionIndex: $sectionIndex++,
                contentParts: $introContentParts,
            );
        }

        foreach ($headingSections as $section) {
            $sections[] = self::sectionDocument(
                pageData: $pageData,
                sectionType: 'heading',
                sectionId: (string)$section['anchor'],
                sectionTitle: (string)$section['title'],
                sectionLevel: (int)$section['level'],
                sectionAnchor: (string)$section['anchor'],
                sectionBody: (string)$section['body'],
                sectionBodyWithCode: (string)$section['bodyWithCode'],
                sectionIndex: $sectionIndex++,
                contentParts: [(string)$section['title']],
            );
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $pageData
     * @param array<int, mixed> $contentParts
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
        string $sectionBodyWithCode,
        int $sectionIndex,
        array $contentParts,
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
        $document['_sectionBodyWithCode'] = $sectionBodyWithCode;
        $document['_headings'] = [];
        $document['headings'] = '';
        unset($document['_bodyWithCode'], $document['_contentClean']);

        $document['content'] = self::joinContentParts($contentParts);

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

    private static function headingAnchor(string $attributes, string $title, bool $preferHeadingIdAttribute): string
    {
        if ($preferHeadingIdAttribute && preg_match('/\bid=(["\'])(.*?)\1/i', $attributes, $idMatch) && trim($idMatch[2]) !== '') {
            return trim($idMatch[2]);
        }

        return SearchHeadingHelper::headingId($title);
    }

    /**
     * @param array<string, bool> $usedAnchors
     */
    private static function dedupeAnchor(string $anchor, array &$usedAnchors): string
    {
        $base = $anchor !== '' ? $anchor : 'section';
        $candidate = $base;
        $i = 2;

        while (isset($usedAnchors[$candidate])) {
            $candidate = $base . '-' . $i++;
        }

        $usedAnchors[$candidate] = true;

        return $candidate;
    }

    /**
     * @param array<int, mixed> $parts
     */
    private static function hasContentParts(array $parts): bool
    {
        foreach ($parts as $part) {
            if (is_scalar($part) && trim((string)$part) !== '') {
                return true;
            }
            if (is_array($part) && self::hasContentParts(array_values($part))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $parts
     */
    private static function joinContentParts(array $parts): string
    {
        $content = [];
        foreach ($parts as $part) {
            if (is_array($part)) {
                $content[] = self::joinContentParts(array_values($part));
                continue;
            }
            if (is_scalar($part) && trim((string)$part) !== '') {
                $content[] = trim((string)$part);
            }
        }

        return trim(implode(' ', array_filter($content, static fn(string $part): bool => $part !== '')));
    }
}
