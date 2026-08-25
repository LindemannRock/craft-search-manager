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
 * Regression coverage for the display/UX audit batch (#414, #417, #418,
 * #420, #422, and #424).
 *
 * @since 5.54.0
 */
final class IndexedSnippetUnicodeTest extends TestCase
{
    public function testSnippetSelectsOneCharacterMatchUsingCharacterLength(): void
    {
        $text = str_repeat('before ', 40) . '東';
        $snippets = (new Highlighter([
            'snippetMaxLength' => 40,
            'maxSnippets' => 1,
        ]))->generateSnippets($text, ['東']);

        self::assertCount(1, $snippets);
        self::assertStringStartsWith('...', $snippets[0]);
        self::assertStringContainsString('<mark>東</mark>', $snippets[0]);
    }

    public function testSnippetSelectsOneCharacterDigitAndLatinMatches(): void
    {
        $highlighter = new Highlighter([
            'snippetMaxLength' => 20,
            'maxSnippets' => 1,
        ]);

        foreach (['5', 'x', '٠'] as $term) {
            $snippets = $highlighter->generateSnippets(
                str_repeat('before ', 10) . $term . ' target',
                [$term],
            );

            self::assertCount(1, $snippets);
            self::assertStringContainsString('<mark>' . $term . '</mark>', $snippets[0]);
        }
    }
}
