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
use lindemannrock\searchmanager\search\QueryUnderstanding;
use lindemannrock\searchmanager\search\ResultHighlightTermSelector;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\TermResolver;
use lindemannrock\searchmanager\search\Tokenizer;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Keeps meaningful Unicode marks attached to searchable word tokens.
 *
 * @since 5.55.1
 */
final class UnicodeMarkTokenizationTest extends TestCase
{
    private const INDEX_HANDLE = 'test_unicode_mark_tokens';
    private const SITE_ID = 1;

    #[DataProvider('tokenProvider')]
    public function testMarksAndExistingNormalizationControlsHaveStableTokenShapes(string $text, array $expected): void
    {
        self::assertSame($expected, (new Tokenizer())->tokenize($text));
    }

    public function testIndexQueryVocabularyPrefixAndRankingStayCoherent(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);
        $engine = new SearchEngine($storage, self::INDEX_HANDLE, [
            'enableStopWords' => false,
            'enableFuzzy' => false,
            'exactMatchBoost' => 1.0,
        ]);

        self::assertTrue($engine->indexDocument(self::SITE_ID, 710001, 'काम guide', 'काम neighbour', 'hi'));
        self::assertTrue($engine->indexDocument(self::SITE_ID, 710002, 'काम detailed guide', 'काम काम neighbour', 'hi'));
        self::assertTrue($engine->indexDocument(self::SITE_ID, 710003, 'Neighbour guide', 'neighbour only', 'en'));

        $documentTerms = $storage->getDocumentTerms(self::SITE_ID, 710001);
        self::assertCount(3, $documentTerms);
        self::assertSame(2, $documentTerms['काम'] ?? null);
        self::assertSame(1, $documentTerms['guide'] ?? null);
        self::assertSame(1, $documentTerms['neighbour'] ?? null);
        $titleTerms = $storage->getTitleTerms(self::SITE_ID, 710001);
        self::assertCount(2, $titleTerms);
        self::assertContains('काम', $titleTerms);
        self::assertContains('guide', $titleTerms);

        $vocabulary = $storage->getTermsForAutocomplete(self::SITE_ID, null, 100);
        self::assertArrayHasKey('काम', $vocabulary);
        self::assertArrayNotHasKey('क', $vocabulary);
        self::assertArrayNotHasKey('म', $vocabulary);

        $exact = $engine->search('काम', self::SITE_ID);
        self::assertSame([710002, 710001], array_keys($exact));
        self::assertGreaterThan($exact[710001], $exact[710002]);
        self::assertArrayNotHasKey(710003, $exact);
        self::assertSame(['काम'], QueryUnderstanding::parse('काम')->tokens);

        $debug = $engine->getLastSearchDebug();
        $resolvedEntry = $debug['resolvedTerms']['काम'][0] ?? null;
        self::assertIsArray($resolvedEntry);
        self::assertSame('काम', $resolvedEntry['term']);
        self::assertSame(TermResolver::MATCH_EXACT, $resolvedEntry['matchType']);

        $prefix = $engine->search('का*', self::SITE_ID);
        self::assertSame([710002, 710001], array_keys($prefix));
        self::assertSame(
            ['काम'],
            array_column((new TermResolver($storage, ['enableFuzzy' => false]))->resolve('का', self::SITE_ID, ['includePrefix' => true]), 'term'),
        );

        self::assertSame([710002, 710001], array_keys($engine->search('काम neighbour', self::SITE_ID)));
    }

    public function testMatchedMetadataAndPhpRenderingUseOriginalSlices(): void
    {
        $resolved = [
            'काम' => [[
                'term' => 'काम',
                'matchType' => TermResolver::MATCH_EXACT,
                'similarity' => 1.0,
            ]],
        ];

        self::assertSame(['काम'], ResultHighlightTermSelector::forText($resolved, 'A काम result'));
        self::assertSame(['काम'], ResultHighlightTermSelector::forIndexedTerms($resolved, ['काम', 'result']));

        $highlighter = new Highlighter([
            'tag' => 'em',
            'class' => 'safe-mark',
            'snippetMaxLength' => 40,
            'maxSnippets' => 1,
        ]);

        self::assertSame(
            '5 &amp; <em class="safe-mark">काम</em> &quot;result&quot;',
            $highlighter->highlight('5 & काम "result"', ['काम']),
        );
        self::assertSame(
            ['Before <em class="safe-mark">काम</em> after'],
            $highlighter->generateSnippets('Before काम after', ['काम']),
        );
        self::assertSame(
            '<mark>का</mark>मना',
            (new Highlighter())->highlight('कामना', ['कामना'], true, ['का']),
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function tokenProvider(): iterable
    {
        yield 'Devanagari spacing mark' => ['काम', ['काम']];
        yield 'multiple spacing marks' => ['कामना', ['कामना']];
        yield 'attached trailing spacing mark' => ['का', ['का']];
        yield 'enclosing mark' => ["AB\u{20DD}", ["ab\u{20DD}"]];
        yield 'leading spacing mark' => ["\u{093E}काम", ['काम']];
        yield 'separated trailing spacing mark' => ["काम \u{093E}", ['काम']];
        yield 'orphan and mark-only input' => ["\u{093E}\u{20DD}", []];
        yield 'punctuation-separated marks' => ["काम-\u{093E}-म", ['काम', 'म']];
        yield 'Latin accent folding' => ["Caf\u{00E9} Cafe\u{0301}", ['cafe', 'cafe']];
        yield 'Japanese dakuten and handakuten' => ["\u{3068}\u{306F}\u{3099} \u{3068}\u{306F}\u{309A}", ['とば', 'とぱ']];
        yield 'Unicode decimal folding' => ["item\u{0665} item\u{06F5} item\u{FF15} item\u{104A0}", ['item5', 'item5', 'item5', 'item0']];
        yield 'one-character controls' => ["x 5 \u{6771} \u{10400}", ['x', '5', '東', "\u{10428}"]];
        yield 'underscore and hyphen controls' => ['foo_bar foo-bar', ['foo', 'bar', 'foo', 'bar']];
    }
}
