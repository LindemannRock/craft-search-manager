<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\search\ResultHighlightTermSelector;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins exact-first result highlighting independently of retrieval and scoring.
 */
final class ResultHighlightTermSelectorTest extends TestCase
{
    #[DataProvider('builtInBackendProvider')]
    public function testBuiltInBackendsUseTheSameExactFirstSelection(string $backend): void
    {
        self::assertContains($backend, ['mysql', 'pgsql', 'redis', 'file']);
        self::assertSame(['test'], ResultHighlightTermSelector::forText(
            $this->collisionTerms('test', 'text'),
            'TEST TEXT',
        ));
        self::assertSame(['text'], ResultHighlightTermSelector::forText(
            $this->collisionTerms('text', 'test'),
            'TEST TEXT',
        ));
    }

    public function testGenuineTypoAndMixedTokensRemainEligible(): void
    {
        self::assertSame(['jacket'], ResultHighlightTermSelector::forText([
            'jaket' => [$this->entry('jacket', 'fuzzy')],
        ], 'JACKET'));

        self::assertSame(['test', 'jacket'], ResultHighlightTermSelector::forText([
            ...$this->collisionTerms('test', 'text'),
            'jaket' => [$this->entry('jacket', 'fuzzy')],
        ], 'TEST TEXT JACKET'));
    }

    public function testFuzzyTermRemainsEligibleWhenLiteralIsAbsentFromArea(): void
    {
        self::assertSame(['text'], ResultHighlightTermSelector::forText(
            $this->collisionTerms('test', 'text'),
            'Only TEXT occurs in this displayed area.',
        ));
    }

    public function testTitleAndContentAreasResolveIndependently(): void
    {
        $terms = $this->collisionTerms('test', 'text');

        self::assertSame(['test'], ResultHighlightTermSelector::forIndexedTerms($terms, ['test', 'text']));
        self::assertSame(['text'], ResultHighlightTermSelector::forIndexedTerms($terms, ['text']));
        self::assertSame([], ResultHighlightTermSelector::forIndexedTerms($terms, ['other']));
    }

    public function testPrefixWildcardCaseAccentAndSynonymTermsKeepTheirBackendProvenance(): void
    {
        $terms = [
            'test*' => [
                $this->entry('test', 'prefix'),
                $this->entry('testing', 'prefix'),
            ],
            'cafe' => [
                $this->entry('cafe', 'exact'),
                $this->entry('cafes', 'fuzzy'),
            ],
            'coat' => [$this->entry('jacket', 'exact')],
        ];

        self::assertSame(
            ['test', 'testing', 'cafe', 'jacket'],
            ResultHighlightTermSelector::forText($terms, 'TEST testing Café cafes JACKET'),
        );
    }

    public function testPrivateResolverProvenanceNeverChangesThePublicHitShape(): void
    {
        $presented = SearchHitPresenter::present([
            'elementId' => 42,
            'title' => 'TEST TEXT',
            'matchedTerms' => ['title' => ['test'], 'content' => []],
            '_resultHighlightTerms' => $this->collisionTerms('test', 'text'),
        ]);

        self::assertArrayNotHasKey('_resultHighlightTerms', $presented);
        self::assertSame(['title' => ['test'], 'content' => []], $presented['matchedTerms']);
    }

    /** @return iterable<string, array{string}> */
    public static function builtInBackendProvider(): iterable
    {
        yield 'MySQL' => ['mysql'];
        yield 'PostgreSQL' => ['pgsql'];
        yield 'Redis' => ['redis'];
        yield 'File' => ['file'];
    }

    /**
     * @return array<string, list<array{term: string, matchType: string, similarity: float}>>
     */
    private function collisionTerms(string $literal, string $fuzzy): array
    {
        return [
            $literal => [
                $this->entry($literal, 'exact'),
                $this->entry($fuzzy, 'fuzzy'),
            ],
        ];
    }

    /** @return array{term: string, matchType: string, similarity: float} */
    private function entry(string $term, string $matchType): array
    {
        return [
            'term' => $term,
            'matchType' => $matchType,
            'similarity' => $matchType === 'fuzzy' ? 0.2 : 1.0,
        ];
    }
}
