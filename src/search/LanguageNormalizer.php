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
     * Detect a language from the query's script, or null when script alone
     * isn't conclusive.
     *
     * Shared by search and autocomplete so both surfaces treat, e.g., an
     * Arabic-script query on an English site identically — asymmetric
     * detection let autocomplete suggest terms search then language-filtered
     * away (#383/#384 coherence).
     */
    public static function detectScriptLanguage(string $text): ?string
    {
        // Arabic ranges: Arabic, Arabic Supplement, Arabic Extended-A.
        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text)) {
            return 'ar';
        }

        return null;
    }

    /**
     * Check whether a document language satisfies a filter language, exact or
     * generic either way ('ar' matches 'ar-sa' and vice versa).
     *
     * Shared by search's result filter and autocomplete's candidate filter so
     * both surfaces apply identical language semantics.
     */
    public static function matches(string $docLanguage, string $filterLanguage): bool
    {
        if ($docLanguage === $filterLanguage) {
            return true;
        }

        if (str_contains($docLanguage, '-') && substr($docLanguage, 0, 2) === $filterLanguage) {
            return true;
        }

        if (str_contains($filterLanguage, '-') && $docLanguage === substr($filterLanguage, 0, 2)) {
            return true;
        }

        return false;
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
