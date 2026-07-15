<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Shared helpers for backend-native filter expressions.
 *
 * @since 5.53.0
 */
class SearchFilterExpressionHelper
{
    public static function escapeDelimitedValue(mixed $value, string $delimiter): string
    {
        return str_replace(['\\', $delimiter], ['\\\\', '\\' . $delimiter], (string)$value);
    }

    public static function normalizeExpression(?string $expression): ?string
    {
        if ($expression === null) {
            return null;
        }

        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        return self::hasBalancedParentheses($expression) ? $expression : null;
    }

    public static function mergeWithRequiredFilter(?string $existing, string $required, string $operator): string
    {
        $existing = self::normalizeExpression($existing);

        return $existing === null ? $required : '(' . $existing . ') ' . $operator . ' ' . $required;
    }

    private static function hasBalancedParentheses(string $expression): bool
    {
        $depth = 0;
        $quote = null;
        $escaped = false;

        foreach (str_split($expression) as $char) {
            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($quote !== null) {
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'" || $char === '`') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0 && $quote === null && !$escaped;
    }
}
