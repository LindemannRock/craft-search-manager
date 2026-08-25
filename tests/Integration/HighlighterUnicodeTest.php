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
    public function testMatchesSharedUnicodeAndPunctuationFixtures(): void
    {
        $highlighter = new Highlighter();

        foreach ($this->resultHighlightingFixtures() as $fixture) {
            $rendered = $highlighter->highlight(
                $fixture['text'],
                $fixture['terms'],
                true,
                $fixture['queryTerms'],
            );
            preg_match_all('/<mark>(.*?)<\/mark>/u', $rendered, $matches);
            $slices = array_map(
                static fn(string $slice): string => html_entity_decode($slice, ENT_QUOTES, 'UTF-8'),
                $matches[1],
            );

            self::assertSame($fixture['expectedSlices'], $slices, $fixture['name']);
        }
    }

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

    public function testEscapesDisplayedTextAndKeepsConfiguredMarkupSafe(): void
    {
        $highlighter = new Highlighter([
            'tag' => 'em',
            'class' => 'safe-class',
        ]);

        self::assertSame(
            '<em class="safe-class">5</em> &amp; <em class="safe-class">x</em> <em class="safe-class">東</em>',
            $highlighter->highlight('5 & x <b>東</b>', ['5', 'x', '東']),
        );
    }

    /**
     * @return list<array{
     *     name: string,
     *     text: string,
     *     terms: list<string>,
     *     queryTerms: list<string>,
     *     expectedSlices: list<string>
     * }>
     */
    private function resultHighlightingFixtures(): array
    {
        $path = dirname(__DIR__, 2) . '/tests/Fixtures/Highlighting/result-highlighting-parity.json';
        $fixtures = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $fixtures;
    }
}
