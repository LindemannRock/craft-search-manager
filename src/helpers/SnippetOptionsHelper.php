<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Central snippet option defaults and bounds for REST, GraphQL, CP test tools, and widgets.
 *
 * @since 5.53.0
 */
class SnippetOptionsHelper
{
    public const DEFAULT_MODE = 'balanced';
    public const MODES = ['early', 'balanced', 'deep'];
    public const DEFAULT_LENGTH = 150;
    public const MIN_LENGTH = 50;
    public const MAX_LENGTH = 1000;
    public const DEFAULT_SHOW_CODE = false;
    public const DEFAULT_PARSE_MARKDOWN = false;

    public static function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string)$mode));

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    public static function normalizeLength(mixed $length): int
    {
        $length = (int)$length;

        return min(self::MAX_LENGTH, max(self::MIN_LENGTH, $length > 0 ? $length : self::DEFAULT_LENGTH));
    }

    /**
     * @return array{showCodeSnippets: bool, snippetMode: string, snippetLength: int, parseMarkdownSnippets: bool, minSnippetLength: int, maxSnippetLength: int, snippetModes: list<string>}
     */
    public static function widgetDefaults(): array
    {
        return [
            'showCodeSnippets' => self::DEFAULT_SHOW_CODE,
            'snippetMode' => self::DEFAULT_MODE,
            'snippetLength' => self::DEFAULT_LENGTH,
            'parseMarkdownSnippets' => self::DEFAULT_PARSE_MARKDOWN,
            'minSnippetLength' => self::MIN_LENGTH,
            'maxSnippetLength' => self::MAX_LENGTH,
            'snippetModes' => self::MODES,
        ];
    }
}
