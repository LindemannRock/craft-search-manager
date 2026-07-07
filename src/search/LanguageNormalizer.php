<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

/**
 * Normalizes public and site-derived language handles before they reach search
 * storage or stop-word file resolution.
 *
 * @since 5.53.0
 */
class LanguageNormalizer
{
    private const DEFAULT_LANGUAGE = 'en';

    /**
     * Normalize a language handle to lowercase hyphen form.
     *
     * Accepts normal language handles such as `en`, `ar`, `fr`, `en-US`, and
     * `pt_BR`. Invalid values fall back to English so public API callers keep
     * the existing safe-empty/fallback behavior.
     */
    public static function normalize(?string $language, string $fallback = self::DEFAULT_LANGUAGE): string
    {
        $normalizedFallback = self::normalizeOrNull($fallback) ?? self::DEFAULT_LANGUAGE;

        return self::normalizeOrNull($language) ?? $normalizedFallback;
    }

    /**
     * Return a normalized language handle, or null when the value is unsafe.
     */
    public static function normalizeOrNull(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $language = trim($language);
        if ($language === '') {
            return null;
        }

        $language = str_replace('_', '-', mb_strtolower($language, 'UTF-8'));

        if (!preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}\z/', $language)) {
            return null;
        }

        return $language;
    }
}
