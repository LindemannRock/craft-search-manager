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
    public function testSnippetMinimumUsesCharacterLength(): void
    {
        $text = str_repeat('a', 250) . '東';

        self::assertSame(
            [str_repeat('a', 40) . '...'],
            (new Highlighter(['snippetMaxLength' => 40]))->generateSnippets($text, ['東']),
        );
    }
}
