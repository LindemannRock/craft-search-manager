<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\helpers\SearchContentCleaner;
use lindemannrock\searchmanager\helpers\SearchHeadingHelper;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins indexed field text cleanup before content reaches backend tokenizers.
 *
 * @since 5.53.0
 */
#[CoversClass(SearchContentCleaner::class)]
final class SearchContentCleanerTest extends TestCase
{
    public function testStripHtmlKeepsBlockBoundariesBetweenHeadingsAndParagraphs(): void
    {
        $cleaner = new SearchContentCleaner();
        $text = $cleaner->stripHtml('<h2>Discovery</h2><p>Lorem ipsum daterangehelper.</p><h3>Cicero</h3><p>Hedonist Roots.</p>');

        self::assertSame('Discovery Lorem ipsum daterangehelper. Cicero Hedonist Roots.', $text);
        self::assertStringNotContainsString('DiscoveryLorem', $text);
        self::assertStringNotContainsString('CiceroHedonist', $text);
    }

    public function testStripHtmlSeparatesListItems(): void
    {
        $cleaner = new SearchContentCleaner();
        $text = $cleaner->stripHtml('<ul><li>First daterangehelper</li><li>Second item</li></ul>');

        self::assertSame('First daterangehelper Second item', $text);
        self::assertStringNotContainsString('daterangehelperSecond', $text);
    }

    public function testStripHtmlSeparatesAdjacentButtons(): void
    {
        $cleaner = new SearchContentCleaner();
        $text = $cleaner->stripHtml('<button class="code-tab-btn">Composer</button><button class="code-tab-btn">DDEV</button>');

        self::assertSame('Composer DDEV', $text);
        self::assertStringNotContainsString('ComposerDDEV', $text);
    }

    public function testStripHtmlWithoutCodeKeepsBlockBoundariesAfterRemovingCode(): void
    {
        $cleaner = new SearchContentCleaner();
        $text = $cleaner->stripHtmlWithoutCode('<h2>Discovery</h2><pre>ignored</pre><p>Lorem ipsum</p><h3>Cicero</h3><p>Hedonist Roots</p>');

        self::assertSame('Discovery Lorem ipsum Cicero Hedonist Roots', $text);
        self::assertStringNotContainsString('DiscoveryLorem', $text);
        self::assertStringNotContainsString('CiceroHedonist', $text);
    }

    public function testCleanBodyWithCodeKeepsPreContentWithoutHeadings(): void
    {
        $cleaner = new SearchContentCleaner();
        $text = $cleaner->cleanBodyWithCode('<h2>Install</h2><p>Before command.</p><pre><code>ddev composer require acme/package</code></pre><p>After command.</p>');

        self::assertSame('Before command. ddev composer require acme/package After command.', $text);
        self::assertStringNotContainsString('Install', $text);
    }

    public function testMarkdownHeadingDescriptionsDoNotStripSentenceEndingNumbers(): void
    {
        $headings = SearchHeadingHelper::extract("## History\n\n1) Timeline item.\nThey developed it in 1966. No matter what.\n", [2]);

        self::assertSame('Timeline item. They developed it in 1966. No matter what.', $headings[0]['description'] ?? null);
    }
}
