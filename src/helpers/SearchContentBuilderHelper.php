<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Builds flattened searchable content and excerpts for transformer documents.
 *
 * @since 5.53.0
 */
class SearchContentBuilderHelper
{
    /**
     * @param array<int, mixed> $parts
     */
    public static function content(array $parts): string
    {
        return implode(' ', array_filter($parts));
    }

    public static function excerpt(string $content, int $length = 200): string
    {
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = trim((string)preg_replace('/\s+/', ' ', $content));

        if (mb_strlen($content) <= $length) {
            return $content;
        }

        return mb_substr($content, 0, $length) . '...';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, mixed> $parts
     * @return array<string, mixed>
     */
    public static function apply(array $data, array $parts, int $excerptLength = 200): array
    {
        $data['content'] = self::content($parts);
        $data['excerpt'] = self::excerpt($data['content'], $excerptLength);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, mixed> $parts
     * @return array<string, mixed>
     */
    public static function append(array $data, array $parts, int $excerptLength = 200): array
    {
        $contentParts = [$data['content'] ?? ''];

        foreach ($parts as $part) {
            if (is_array($part)) {
                $contentParts = array_merge($contentParts, $part);
            } elseif (is_scalar($part)) {
                $contentParts[] = (string)$part;
            }
        }

        $data['content'] = implode(' ', array_filter(array_map(
            static fn($part) => trim((string)$part),
            $contentParts,
        )));
        $data['excerpt'] = self::excerpt($data['content'], $excerptLength);

        return $data;
    }
}
