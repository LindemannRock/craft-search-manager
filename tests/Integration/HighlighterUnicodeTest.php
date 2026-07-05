<?php
/**
 * LindemannRock Search Manager
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
}
