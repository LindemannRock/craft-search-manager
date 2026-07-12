<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Extracts and normalizes indexed heading/outline data.
 *
 * @since 5.53.0
 */
class SearchHeadingHelper
{
    /**
     * @var array<int>
     * @since 5.53.0
     */
    public const DEFAULT_LEVELS = [2, 3, 4];

    /**
     * @param array<int>|null $levels
     * @return array<int>
     */
    public static function normalizeLevels(?array $levels, array $default = self::DEFAULT_LEVELS): array
    {
        if ($levels === null) {
            return $default;
        }

        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $levels),
            static fn($level) => $level >= 1 && $level <= 6
        )));

        if ($normalized === []) {
            return $default;
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<int> $allowedLevels
     * @return array<int, array{text: string, id: string, level: int, description: string}>
     */
    public static function extract(string $content, array $allowedLevels): array
    {
        $headings = [];

        if (preg_match('/<[^>]+>/', $content)) {
            $pattern = '/<h([1-6])([^>]*)>(.*?)<\/h\1>/is';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $i => $match) {
                    $level = (int)$match[1][0];
                    if (!in_array($level, $allowedLevels, true)) {
                        continue;
                    }
                    $text = strip_tags($match[3][0]);
                    $text = ltrim($text, '#');
                    $id = '';
                    if (preg_match('/id="([^"]*)"/', $match[2][0], $idMatch)) {
                        $id = $idMatch[1];
                    }
                    if (empty($id)) {
                        $id = self::headingId($text);
                    }

                    if (!empty(trim($text))) {
                        $afterOffset = $match[0][1] + strlen($match[0][0]);
                        $nextOffset = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : null;

                        $headings[] = [
                            'text' => trim($text),
                            'id' => $id,
                            'level' => $level,
                            'description' => self::descriptionFromHtml($content, $afterOffset, $nextOffset),
                        ];
                    }
                }
            }
        } else {
            $pattern = '/^(#{1,6})\s+(.+)$/m';
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $i => $match) {
                    $level = strlen($match[1][0]);
                    if (!in_array($level, $allowedLevels, true)) {
                        continue;
                    }
                    $text = trim($match[2][0]);
                    $id = self::headingId($text);

                    if (!empty($text)) {
                        $afterOffset = $match[0][1] + strlen($match[0][0]);
                        $nextOffset = isset($matches[$i + 1]) ? $matches[$i + 1][0][1] : null;

                        $headings[] = [
                            'text' => $text,
                            'id' => $id,
                            'level' => $level,
                            'description' => self::descriptionFromMarkdown($content, $afterOffset, $nextOffset),
                        ];
                    }
                }
            }
        }

        return $headings;
    }

    public static function headingId(string $text): string
    {
        return \craft\helpers\StringHelper::toKebabCase($text);
    }

    /**
     * @param array<int, array<string, mixed>> $headings
     */
    public static function headingText(array $headings): string
    {
        $headingTexts = array_map(static fn($h) => $h['text'], $headings);

        return implode(' ', $headingTexts);
    }

    /**
     * @param array<int, array<string, mixed>> $headings
     * @return array<int, array{text: mixed, id: mixed, level: mixed, description: mixed}>
     */
    public static function normalizeHeadings(array $headings): array
    {
        return array_map(static function($h): array {
            $text = $h['text'] ?? '';
            $id = $h['id'] ?? ($h['anchor'] ?? '');
            if (empty($id) && !empty($text)) {
                $id = self::headingId((string)$text);
            }

            return [
                'text' => $text,
                'id' => $id,
                'level' => $h['level'] ?? 2,
                'description' => $h['description'] ?? '',
            ];
        }, $headings);
    }

    private static function descriptionFromHtml(string $content, int $afterOffset, ?int $nextOffset): string
    {
        $endOffset = $nextOffset ?? strlen($content);
        $between = substr($content, $afterOffset, $endOffset - $afterOffset);

        $description = '';
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $between, $pMatch)) {
            $description = trim(strip_tags($pMatch[1]));
        }

        if (empty($description)) {
            $description = trim(strip_tags($between));
        }

        return self::cleanDescription($description);
    }

    private static function descriptionFromMarkdown(string $content, int $afterOffset, ?int $nextOffset): string
    {
        $endOffset = $nextOffset ?? strlen($content);
        $between = substr($content, $afterOffset, $endOffset - $afterOffset);

        $between = SearchContentCleaner::cleanMarkdownPlainText($between, SearchContentCleaner::MARKDOWN_CODE_REMOVE);
        $between = (string)preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $between);

        $lines = array_filter(array_map('trim', explode("\n", $between)));
        $description = implode(' ', array_slice($lines, 0, 2));

        return self::cleanDescription($description);
    }

    private static function cleanDescription(string $description): string
    {
        return (string)preg_replace('/\s+/', ' ', trim($description));
    }
}
