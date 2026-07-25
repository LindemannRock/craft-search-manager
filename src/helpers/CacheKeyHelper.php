<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\helpers;

/**
 * Generates deterministic cache keys from supported JSON data.
 *
 * @since 5.54.0
 */
final class CacheKeyHelper
{
    /**
     * @param array<string|int, mixed> $keyData
     */
    public static function generate(array $keyData): ?string
    {
        try {
            // Validate before recursing so cyclic arrays and unsupported
            // values fail safely instead of reaching the normalizer.
            json_encode($keyData, JSON_THROW_ON_ERROR);
            $normalized = self::normalize($keyData);
            $encoded = json_encode($normalized, JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException | \JsonException) {
            return null;
        }

        return md5($encoded);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }

            if (!array_is_list($normalized)) {
                ksort($normalized, SORT_STRING);
            }

            return $normalized;
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Cache key data contains an unsupported value.');
    }
}
