<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Cleans indexed HTML and Markdown content before it reaches search records.
 *
 * @since 5.53.0
 */
class SearchContentCleaner
{
    public const MARKDOWN_CODE_REMOVE = 'remove-code';
    public const MARKDOWN_CODE_UNWRAP = 'unwrap-code';

    /**
     * Add plain-text spacing around block boundaries before tag stripping.
     *
     * @since 5.53.0
     */
    public static function addBlockBoundaries(string $html): string
    {
        $html = (string)preg_replace('/<br\s*\/?>/i', ' ', $html);

        return (string)preg_replace('/<\/(?:h[1-6]|p|div|li|td|th|blockquote|section|article|button)>/i', ' ', $html);
    }

    public static function removeMarkdownFencedCode(string $text): string
    {
        $text = (string)preg_replace('/```[A-Za-z0-9_-]*\s*.*?```/s', ' ', $text);

        return (string)preg_replace('/~~~[A-Za-z0-9_-]*\s*.*?~~~/s', ' ', $text);
    }

    public static function unwrapMarkdownFencedCode(string $text): string
    {
        $text = (string)preg_replace('/```[A-Za-z0-9_-]*\s*(.*?)```/s', ' $1 ', $text);

        return (string)preg_replace('/~~~[A-Za-z0-9_-]*\s*(.*?)~~~/s', ' $1 ', $text);
    }

    public static function cleanMarkdownPlainText(string $text, string $codeMode = self::MARKDOWN_CODE_REMOVE): string
    {
        $text = $codeMode === self::MARKDOWN_CODE_UNWRAP
            ? self::unwrapMarkdownFencedCode($text)
            : self::removeMarkdownFencedCode($text);
        $text = self::unwrapInlineMarkdownCode($text);
        $text = self::removeMarkdownHorizontalRules($text);
        $text = (string)preg_replace('/(^|\s)#{1,6}\s+/', '$1', $text);
        $text = self::stripPairedMarkdownMarkers($text, '\*\*');
        $text = self::stripPairedMarkdownMarkers($text, '__');
        $text = self::stripPairedMarkdownMarkers($text, '\*');
        $text = self::stripPairedMarkdownMarkers($text, '_');
        $text = (string)preg_replace('/(^|\s)(?:-|\d+\))\s+(?=\S)/', '$1', $text);

        return (string)preg_replace('/\s+/', ' ', $text);
    }

    public function stripHtml(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $text = strip_tags(self::addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string)$text);
    }

    public function stripHtmlWithoutCode(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $text = (string)preg_replace('/<pre[^>]*>.*?<\/pre>/is', ' ', $html);
        $text = strip_tags(self::addBlockBoundaries($text));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    public function cleanBody(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $html = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string)preg_replace('/<pre\b[^>]*>.*?<\/pre>/is', ' ', $html);
        $html = (string)preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html);
        $text = strip_tags(self::addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Clean body text while preserving block-level code content.
     *
     * @since 5.53.0
     */
    public function cleanBodyWithCode(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $html = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string)preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html);
        $html = (string)preg_replace('/<\/pre>/i', '</pre> ', $html);
        $text = strip_tags(self::addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private static function unwrapInlineMarkdownCode(string $text): string
    {
        return (string)preg_replace('/`{1,2}([^`\s][^`]*?[^`\s])`{1,2}/', '$1', $text);
    }

    private static function removeMarkdownHorizontalRules(string $text): string
    {
        return (string)preg_replace('/(^|\s)(?:-{3,}|\*{3,}|_{3,})(?=\s|$)/', '$1', $text);
    }

    private static function stripPairedMarkdownMarkers(string $text, string $markerPattern): string
    {
        $pattern = '/(?<![\pL\pN])' . $markerPattern . '(\S(?:.*?\S)?)' . $markerPattern . '(?![\pL\pN])/u';

        do {
            $previous = $text;
            $text = (string)preg_replace($pattern, '$1', $text);
        } while ($text !== $previous);

        return $text;
    }
}
