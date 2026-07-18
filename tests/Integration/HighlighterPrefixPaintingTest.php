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
use lindemannrock\searchmanager\variables\SearchManagerVariable;

/**
 * Regression coverage for exact, prefix-extension, and typo painting.
 *
 * @since 5.54.0
 */
final class HighlighterPrefixPaintingTest extends TestCase
{
    public function testPrefixExtensionsPaintOnlyTheRawQueryPrefix(): void
    {
        $highlighter = new Highlighter();

        self::assertSame(
            '<mark>Test</mark>ing <mark>Tool</mark>s',
            $highlighter->highlight(
                'Testing Tools',
                ['testing', 'tools'],
                true,
                ['test', 'tool'],
            ),
        );
    }

    public function testExactAndTypoMatchesPaintTheWholeWord(): void
    {
        $highlighter = new Highlighter();

        self::assertSame(
            '<mark>Testing</mark> <mark>jacket</mark>',
            $highlighter->highlight(
                'Testing jacket',
                ['testing', 'jacket'],
                true,
                ['test', 'testing', 'jaket'],
            ),
        );
    }

    public function testMidWordOccurrencesNeverPaint(): void
    {
        self::assertSame(
            'stop',
            (new Highlighter())->highlight('stop', ['to']),
        );
    }

    public function testPrefixPaintingIsCaseAndAccentInsensitive(): void
    {
        self::assertSame(
            '<mark>Café</mark>teria',
            (new Highlighter())->highlight('Caféteria', ['cafe']),
        );
        self::assertSame(
            "<mark>Cafe\u{0301}</mark>teria",
            (new Highlighter())->highlight("Cafe\u{0301}teria", ['cafe']),
        );
    }

    public function testTwigHighlightAndSnippetHelpersPaintPrefixExtensions(): void
    {
        $variable = new SearchManagerVariable();

        self::assertSame(
            '<mark>Test</mark>ing <mark>Tool</mark>s',
            $variable->highlight('Testing Tools', 'test tool', ['field' => 'title', 'class' => '']),
        );
        self::assertSame(
            ['<mark>Test</mark>ing <mark>Tool</mark>s'],
            $variable->snippets('Testing Tools', 'test tool', ['class' => '']),
        );
    }
}
