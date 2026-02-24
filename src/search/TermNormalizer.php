<?php

namespace lindemannrock\searchmanager\search;

use IntlChar;
use Normalizer;

/**
 * Shared Unicode-aware term normalization for indexing and querying.
 *
 * @since 5.0.0
 */
class TermNormalizer
{
    /**
     * Normalize text consistently across backends and collations.
     *
     * - Unicode normalization (NFKC/NFKD)
     * - Remove Arabic tatweel/kashida
     * - Fold Unicode decimal digits to ASCII where possible
     * - Lowercase and remove combining marks (accent folding)
     */
    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if ($normalized !== false && $normalized !== null) {
                $text = $normalized;
            }
        }

        // Tatweel/kashida is collation-ignorable in MySQL _ai collations.
        $text = preg_replace('/\x{0640}/u', '', $text);

        // Fold any Unicode decimal digit (Thai, Devanagari, Bengali, etc.) to ASCII.
        if (class_exists(IntlChar::class)) {
            $text = preg_replace_callback('/\p{Nd}/u', static function(array $m): string {
                $value = IntlChar::charDigitValue(IntlChar::ord($m[0]));
                return $value >= 0 ? (string)$value : $m[0];
            }, $text);
        } else {
            // Safe fallback when ext-intl is unavailable.
            $text = strtr($text, [
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            ]);
        }

        $text = mb_strtolower($text, 'UTF-8');

        // Accent/diacritic folding (e.g. jalapeño -> jalapeno, maámoul -> maamoul).
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
            if ($decomposed !== false && $decomposed !== null) {
                $text = $decomposed;
            }
        }

        return preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
    }
}
