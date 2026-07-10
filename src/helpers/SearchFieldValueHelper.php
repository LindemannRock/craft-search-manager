<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Normalizes indexed custom field values for API and GraphQL responses.
 *
 * @since 5.53.0
 */
class SearchFieldValueHelper
{
    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public static function fieldsFromHit(array $hit): array
    {
        $fields = is_array($hit['_fields'] ?? null) ? $hit['_fields'] : [];
        $normalized = [];

        foreach ($fields as $handle => $value) {
            if (!is_string($handle) || $handle === '' || $value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $values = array_values(array_filter(
                    array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value),
                    static fn(string $item): bool => $item !== '',
                ));
                if ($values === []) {
                    continue;
                }

                $normalized[$handle] = $values;
                continue;
            }

            if (is_scalar($value)) {
                $normalized[$handle] = (string)$value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    public static function exposeFields(array $hit): array
    {
        if (array_key_exists('_fields', $hit)) {
            $hit['fields'] = self::fieldsFromHit($hit);
        } elseif (!isset($hit['fields']) || !is_array($hit['fields'])) {
            $hit['fields'] = [];
        }
        unset($hit['_fields']);

        return $hit;
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<array{handle: string, value: string|null, values: list<string>}>
     */
    public static function toGraphqlList(array $fields): array
    {
        $values = [];

        foreach ($fields as $handle => $value) {
            if (!is_string($handle) || $handle === '') {
                continue;
            }

            if (is_array($value)) {
                $list = array_values(array_filter(
                    array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value),
                    static fn(string $item): bool => $item !== '',
                ));
                $values[] = [
                    'handle' => $handle,
                    'value' => $list !== [] ? implode(' ', $list) : null,
                    'values' => $list,
                ];
                continue;
            }

            $scalar = is_scalar($value) ? (string)$value : null;
            $values[] = [
                'handle' => $handle,
                'value' => $scalar,
                'values' => [],
            ];
        }

        return $values;
    }
}
