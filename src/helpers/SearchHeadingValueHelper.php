<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes indexed heading metadata for public API and GraphQL output.
 *
 * @since 5.53.0
 */
class SearchHeadingValueHelper
{
    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public static function exposeHeadings(array $hit): array
    {
        $source = [];
        if (is_array($hit['headings'] ?? null)) {
            $source = $hit['headings'];
        } elseif (is_array($hit['_matchedHeadings'] ?? null)) {
            $source = $hit['_matchedHeadings'];
        } elseif (is_array($hit['_headings'] ?? null)) {
            $source = $hit['_headings'];
        }

        $hit['headings'] = self::toPublicList($source, is_string($hit['url'] ?? null) ? $hit['url'] : null);
        unset($hit['_headings'], $hit['_matchedHeadings']);

        return $hit;
    }

    /**
     * @param array<int, mixed> $headings
     * @return list<array{title: string, id: string, level: int, url: string|null, snippet: string|null}>
     */
    public static function toPublicList(array $headings, ?string $baseUrl = null): array
    {
        $public = [];

        foreach ($headings as $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $title = self::stringValue($heading['title'] ?? ($heading['text'] ?? null));
            if ($title === '') {
                continue;
            }

            $id = self::stringValue($heading['id'] ?? null);
            $level = is_numeric($heading['level'] ?? null) ? (int)$heading['level'] : 2;
            $level = min(6, max(1, $level));
            $url = self::stringValue($heading['url'] ?? null);
            if ($url === '' && $baseUrl !== null && $id !== '') {
                $url = $baseUrl . '#' . $id;
            }

            $snippet = self::plainText($heading['snippet'] ?? null);

            $public[] = [
                'title' => $title,
                'id' => $id,
                'level' => $level,
                'url' => $url !== '' ? $url : null,
                'snippet' => $snippet !== '' ? $snippet : null,
            ];
        }

        return $public;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function plainText(mixed $value): string
    {
        $text = self::stringValue($value);
        if ($text === '') {
            return '';
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
