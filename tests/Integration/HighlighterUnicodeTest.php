<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\Highlighter;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class HighlighterUnicodeTest extends TestCase
{
    public function testHighlightsNonAsciiTermsWithUnicodeBoundaries(): void
    {
        $highlighter = new Highlighter();

        self::assertSame(
            '<mark>Über</mark> cafe and <mark>東京</mark> search',
            $highlighter->highlight('Über cafe and 東京 search', ['über', '東京']),
        );
    }

    public function testZeroIsHighlightedAndRetainedInSnippets(): void
    {
        $highlighter = new Highlighter(['snippetMaxLength' => 50]);

        self::assertSame(
            'Version <mark>0</mark> remains searchable',
            $highlighter->highlight('Version 0 remains searchable', ['0']),
        );
        self::assertSame(
            ['Version <mark>0</mark> remains searchable'],
            $highlighter->generateSnippets('Version 0 remains searchable', ['0']),
        );
        self::assertSame('0', $highlighter->highlight('0', []));
        self::assertSame([], $highlighter->generateSnippets('', ['0']));
    }
}
