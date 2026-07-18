<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\TermResolver;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Layer-2 contract of the shared search/autocomplete core (#383/#384 Phase A):
 * the single term-resolution policy — exact + two-tier fuzzy expansion +
 * opt-in prefix completion — over a real seeded MySQL index.
 *
 * @since 5.53.0
 */
final class TermResolverPolicyTest extends TestCase
{
    private const INDEX_HANDLE = 'test_term_resolver_policy';
    private const SITE_ID = 1;

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$seeded) {
            $this->deleteRowsForIndex();
            $engine = new SearchEngine(new MySqlStorage(self::INDEX_HANDLE), self::INDEX_HANDLE, [
                'enableStopWords' => false,
            ]);

            $engine->indexDocument(self::SITE_ID, 100001, 'Testing tools', 'testing tools guide');
            $engine->indexDocument(self::SITE_ID, 100002, 'Template variables', 'tool reference template');
            $engine->indexDocument(self::SITE_ID, 100003, 'Toolbar', 'toolbar customization options');
            $engine->indexDocument(self::SITE_ID, 100004, 'Tooling', 'tooling setup notes');
            $engine->indexDocument(self::SITE_ID, 100005, 'Toolkit', 'toolkit overview details');
            $engine->indexDocument(self::SITE_ID, 100006, 'البحث', 'بحث متقدم', 'ar');
            $engine->indexDocument(self::SITE_ID, 100007, 'Guide to Search', 'how to configure search');

            self::$seeded = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteRowsForIndexStatic();
        self::$seeded = false;

        parent::tearDownAfterClass();
    }

    public function testExactMatchTokenStillGetsTopKFuzzyExpansion(): void
    {
        $resolved = $this->makeResolver()->resolve('tool', self::SITE_ID);
        $byType = $this->groupByMatchType($resolved);

        // Exact entry present, listed first, at full weight.
        self::assertSame('tool', $resolved[0]['term']);
        self::assertSame(TermResolver::MATCH_EXACT, $resolved[0]['matchType']);
        self::assertSame(1.0, $resolved[0]['weight']);
        self::assertCount(1, $byType[TermResolver::MATCH_EXACT]);

        // Two-tier expander: fuzzy fires DESPITE the exact match (#383's gate removed),
        // capped at top-K=3 even though 4 candidates (tools/toolbar/tooling/toolkit) qualify.
        self::assertCount(3, $byType[TermResolver::MATCH_FUZZY], 'expansion is capped at top-K');
        $fuzzyTerms = array_column($byType[TermResolver::MATCH_FUZZY], 'term');
        self::assertContains('tools', $fuzzyTerms, 'the closest variant must survive the top-K cut');
    }

    public function testFuzzyEntriesCarrySimilarityAndDiscountedWeight(): void
    {
        $resolved = $this->makeResolver()->resolve('tool', self::SITE_ID);
        $fuzzy = $this->groupByMatchType($resolved)[TermResolver::MATCH_FUZZY];

        $tools = null;
        foreach ($fuzzy as $entry) {
            self::assertGreaterThan(0.0, $entry['similarity']);
            self::assertLessThan(1.0, $entry['similarity']);
            self::assertEqualsWithDelta($entry['similarity'] * 0.4, $entry['weight'], 0.000001, 'fuzzy weight = similarity × fuzzyWeight(0.4)');

            if ($entry['term'] === 'tools') {
                $tools = $entry;
            }
        }

        // Jaccard(tool, tools) with n-gram sizes [2,3]: 7 shared / 13 union.
        self::assertNotNull($tools);
        self::assertEqualsWithDelta(7 / 13, $tools['similarity'], 0.0001);
    }

    public function testFuzzyExpansionRejectsShortCandidateAndPreservesLegitimateVariants(): void
    {
        $toolTerms = array_column($this->makeResolver()->resolve('tool', self::SITE_ID), 'term');

        self::assertNotContains('to', $toolTerms);
        self::assertContains('tools', $toolTerms);

        $testTerms = array_column($this->makeResolver()->resolve('test', self::SITE_ID), 'term');
        self::assertContains('testing', $testTerms);
    }

    public function testZeroExactTokenAppliesPrecisionFilterToTheFullFallbackPool(): void
    {
        $resolved = $this->makeResolver()->resolve('toolz', self::SITE_ID);
        $byType = $this->groupByMatchType($resolved);

        self::assertSame([], $byType[TermResolver::MATCH_EXACT]);
        self::assertCount(2, $byType[TermResolver::MATCH_FUZZY]);

        $fuzzyTerms = array_column($byType[TermResolver::MATCH_FUZZY], 'term');
        foreach (['tool', 'tools'] as $expected) {
            self::assertContains($expected, $fuzzyTerms);
        }
        foreach (['toolbar', 'tooling', 'toolkit'] as $rejected) {
            self::assertNotContains($rejected, $fuzzyTerms);
        }
    }

    public function testSimilarityThresholdIsRespected(): void
    {
        $strict = new TermResolver(new MySqlStorage(self::INDEX_HANDLE), ['similarityThreshold' => 0.9]);

        self::assertSame([], $strict->resolve('toolz', self::SITE_ID), 'no candidate clears a 0.9 threshold');
    }

    public function testEnableFuzzyOffLeavesOnlyExactMatches(): void
    {
        $resolver = new TermResolver(new MySqlStorage(self::INDEX_HANDLE), ['enableFuzzy' => false]);

        self::assertSame([], $resolver->resolve('toolz', self::SITE_ID));

        $resolved = $resolver->resolve('tool', self::SITE_ID);
        self::assertCount(1, $resolved);
        self::assertSame(TermResolver::MATCH_EXACT, $resolved[0]['matchType']);
    }

    public function testPrefixTierCompletesTokenBeingTypedWithoutDuplicatingExact(): void
    {
        $resolved = $this->makeResolver()->resolve('tool', self::SITE_ID, ['includePrefix' => true]);
        $byType = $this->groupByMatchType($resolved);

        $prefixTerms = array_column($byType[TermResolver::MATCH_PREFIX], 'term');
        foreach (['tools', 'toolbar', 'tooling', 'toolkit'] as $expected) {
            self::assertContains($expected, $prefixTerms);
        }

        // A term qualifies once, strongest type wins: 'tool' stays exact-only,
        // and prefix completions must not be re-listed by the fuzzy tier.
        $allTerms = array_column($resolved, 'term');
        self::assertSame($allTerms, array_unique($allTerms), 'resolved terms are unique across tiers');
        self::assertNotContains('tool', $prefixTerms);
    }

    public function testInputIsNormalizedDefensively(): void
    {
        $upper = $this->makeResolver()->resolve('TOOL', self::SITE_ID);

        self::assertSame('tool', $upper[0]['term']);
        self::assertSame(TermResolver::MATCH_EXACT, $upper[0]['matchType']);
    }

    public function testArabicTokenWithTatweelResolvesToSeededTerm(): void
    {
        // U+0640 tatweel inside the token must normalize away before lookup.
        $resolved = $this->makeResolver()->resolve("بحـث", self::SITE_ID);

        self::assertNotSame([], $resolved);
        self::assertSame('بحث', $resolved[0]['term']);
        self::assertSame(TermResolver::MATCH_EXACT, $resolved[0]['matchType']);
    }

    public function testEmptyAndWhitespaceTokensResolveToNothing(): void
    {
        $resolver = $this->makeResolver();

        self::assertSame([], $resolver->resolve('', self::SITE_ID));
        self::assertSame([], $resolver->resolve('   ', self::SITE_ID));
    }

    private function makeResolver(): TermResolver
    {
        return new TermResolver(new MySqlStorage(self::INDEX_HANDLE));
    }

    /**
     * @param array<int, array{term: string, matchType: string, similarity: float, weight: float}> $resolved
     * @return array<string, array<int, array{term: string, matchType: string, similarity: float, weight: float}>>
     */
    private function groupByMatchType(array $resolved): array
    {
        $grouped = [
            TermResolver::MATCH_EXACT => [],
            TermResolver::MATCH_PREFIX => [],
            TermResolver::MATCH_FUZZY => [],
        ];

        foreach ($resolved as $entry) {
            $grouped[$entry['matchType']][] = $entry;
        }

        return $grouped;
    }

    private function deleteRowsForIndex(): void
    {
        self::deleteRowsForIndexStatic();
    }

    private static function deleteRowsForIndexStatic(): void
    {
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
        ];

        foreach ($tables as $table) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete($table, ['indexHandle' => self::INDEX_HANDLE])
                ->execute();
        }
    }
}
