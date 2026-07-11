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
 * Builds section search documents from AutoTransformer-family rich-text fields.
 *
 * @since 5.53.0
 */
class AutoTransformerSectionSplitter
{
    /**
     * @param array<string, mixed> $pageData
     * @param array<int> $headingLevels
     * @return list<array<string, mixed>>
     */
    public static function split(ElementInterface $element, array $pageData, array $headingLevels): array
    {
        $contentBag = (new SearchAutoContentHelper(
            new NativeFieldKeywordHelper(),
            new SearchFieldTypeContentHelper(new SearchContentCleaner()),
        ))->collect($element);

        $sources = array_map(
            static fn(array $source): array => ['html' => (string)$source['html']],
            $contentBag['richTextSources'],
        );

        if ($sources === []) {
            return [];
        }

        $sections = HtmlSectionSplitter::split(
            pageData: $pageData,
            sources: $sources,
            headingLevels: $headingLevels,
            introContentParts: self::introContentParts($contentBag, $pageData),
            emitIntroWithoutBody: true,
            preferHeadingIdAttribute: false,
            dedupeAnchors: true,
        );

        if (!self::hasHeadingSection($sections)) {
            return [];
        }

        return array_map(self::prepareSectionDocument(...), $sections);
    }

    /**
     * @param array{parts: array<int, mixed>, fields: array<string, string>, richText: array<int, string>, richTextSources: list<array{handle: string, html: string}>, bodyClean: string} $contentBag
     * @param array<string, mixed> $pageData
     * @return array<int, mixed>
     */
    private static function introContentParts(array $contentBag, array $pageData): array
    {
        $parts = $contentBag['parts'];
        $extraContent = self::extraTransformerContent($contentBag, $pageData);
        if ($extraContent !== '') {
            $parts[] = $extraContent;
        }

        return $parts;
    }

    /**
     * Preserve project/Commerce transformer content appended after the base
     * AutoTransformer content, but keep it on the intro record only.
     *
     * @param array{parts: array<int, mixed>, fields: array<string, string>, richText: array<int, string>, richTextSources: list<array{handle: string, html: string}>, bodyClean: string} $contentBag
     * @param array<string, mixed> $pageData
     */
    private static function extraTransformerContent(array $contentBag, array $pageData): string
    {
        $content = trim((string)($pageData['content'] ?? ''));
        if ($content === '') {
            return '';
        }

        $baseParts = $contentBag['parts'];
        if (!empty($pageData['headings']) && is_scalar($pageData['headings'])) {
            $baseParts[] = (string)$pageData['headings'];
        }

        $baseContent = trim(SearchContentBuilderHelper::content($baseParts));
        if ($baseContent === '') {
            return $content;
        }

        if ($content === $baseContent) {
            return '';
        }

        if (str_starts_with($content, $baseContent)) {
            return trim(substr($content, strlen($baseContent)));
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $sections
     */
    private static function hasHeadingSection(array $sections): bool
    {
        foreach ($sections as $section) {
            if (($section['sectionType'] ?? null) === 'heading') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function prepareSectionDocument(array $document): array
    {
        if (($document['sectionType'] ?? null) === 'heading') {
            unset($document['_fields'], $document['_snippetFields'], $document['fields']);
        }

        return $document;
    }
}
