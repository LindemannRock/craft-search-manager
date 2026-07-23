<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\docsmanager\records\SourceRecord;

/**
 * Builds Docs Manager SourceDoc metadata and content for transformer documents.
 *
 * @since 5.53.0
 */
class DocsManagerDocumentHelper
{
    private const SOURCE_NAMES_CACHE_TTL_SECONDS = 5;

    /**
     * @var array<int, string>
     */
    private static array $sourceNames = [];

    private static ?float $sourceNamesExpiresAt = null;

    public static function sourceName(?int $sourceId): string
    {
        if (!$sourceId) {
            return 'Docs';
        }

        if (
            self::$sourceNamesExpiresAt === null
            || microtime(true) >= self::$sourceNamesExpiresAt
        ) {
            self::$sourceNames = [];
            self::$sourceNamesExpiresAt = microtime(true) + self::SOURCE_NAMES_CACHE_TTL_SECONDS;
        }

        if (!isset(self::$sourceNames[$sourceId])) {
            self::$sourceNames[$sourceId] = SourceRecord::findOne($sourceId)?->name ?? 'Docs';
        }

        return self::$sourceNames[$sourceId];
    }

    /**
     * @return array<int, string>
     */
    public static function contentParts(SourceDoc $element): array
    {
        $searchableContent = [];

        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        if ($element->description) {
            $searchableContent[] = $element->description;
        }

        return $searchableContent;
    }

    public static function cleanBody(SourceDoc $element, SearchContentCleaner $contentCleaner): string
    {
        return $contentCleaner->cleanBody(self::htmlContent($element));
    }

    /**
     * Return SourceDoc body text while preserving code blocks for snippet display.
     *
     */
    public static function cleanBodyWithCode(SourceDoc $element, SearchContentCleaner $contentCleaner): string
    {
        return $contentCleaner->cleanBodyWithCode(self::htmlContent($element));
    }

    /**
     * Return SourceDoc HTML with docs-manager UI chrome removed before indexing.
     *
     */
    public static function htmlContent(SourceDoc $element): string
    {
        return self::stripTabChrome((string)($element->htmlContent ?? ''));
    }

    private static function stripTabChrome(?string $html): string
    {
        if (!$html) {
            return '';
        }

        return (string)preg_replace(
            '/<div\b(?=[^>]*\bclass=(["\'])(?=[^"\']*\bcode-tab-buttons\b)[^"\']*\1)[^>]*>.*?<\/div>/is',
            ' ',
            $html,
        );
    }

    public static function keywords(SourceDoc $element): string
    {
        return implode(' ', $element->keywords);
    }
}
