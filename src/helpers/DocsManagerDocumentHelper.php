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
    /**
     * @var array<int, string>
     */
    private static array $sourceNames = [];

    public static function sourceName(?int $sourceId): string
    {
        if (!$sourceId) {
            return 'Docs';
        }

        if (!isset(self::$sourceNames[$sourceId])) {
            self::$sourceNames[$sourceId] = SourceRecord::findOne($sourceId)?->name ?? 'Docs';
        }

        return self::$sourceNames[$sourceId];
    }

    /**
     * @return array<int, string>
     */
    public static function contentParts(SourceDoc $element, SearchContentCleaner $contentCleaner): array
    {
        $searchableContent = [];

        if ($element->title) {
            $searchableContent[] = $element->title;
        }

        if ($element->description) {
            $searchableContent[] = $element->description;
        }

        if ($element->htmlContent) {
            $searchableContent[] = $contentCleaner->stripHtml($element->htmlContent);
        }

        return $searchableContent;
    }

    public static function cleanBody(SourceDoc $element, SearchContentCleaner $contentCleaner): string
    {
        return $contentCleaner->cleanBody($element->htmlContent);
    }

    public static function keywords(SourceDoc $element): string
    {
        return implode(' ', $element->keywords);
    }
}
