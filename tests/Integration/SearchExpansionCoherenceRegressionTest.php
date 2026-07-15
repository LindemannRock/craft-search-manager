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
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\TermResolver;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Phase B regressions for the search↔autocomplete coherence refactor
 * (#383/#384): search rides the shared TermResolver, so a token is satisfied
 * by any of its resolved terms, fuzzy expansion fires even when exact matches
 * exist (scored below exact), and strict AND relaxes to OR on zero results.
 *
 * Corpus shape mirrors the #383 report: "tools" common, "tool" rare — the
 * old exact-first fuzzy gate made "testing tool" return 0 despite a document
 * literally titled "Testing tools".
 *
 * @since 5.53.0
 */
final class SearchExpansionCoherenceRegressionTest extends TestCase
{
    private const INDEX_HANDLE = 'test_search_expansion_coherence';
    private const BACKEND_INDEX_HANDLE = 'test_search_expansion_backend';
    private const SITE_ID = 1;

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$seeded) {
            self::deleteRowsForIndexes();

            $engine = new SearchEngine(new MySqlStorage(self::INDEX_HANDLE), self::INDEX_HANDLE);
            $this->seedCorpus($engine);

            // Same corpus under the PREFIXED name the real backend resolves to,
            // for the backend-level searchDebug forwarding test.
            $fullBackendIndex = SearchManager::$plugin->getSettings()->getFullIndexName(self::BACKEND_INDEX_HANDLE);
            $backendEngine = new SearchEngine(new MySqlStorage($fullBackendIndex), $fullBackendIndex);
            $this->seedCorpus($backendEngine);

            self::$seeded = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteRowsForIndexes();
        self::$seeded = false;

        parent::tearDownAfterClass();
    }

    public function testTestingToolFindsTestingToolsRankedFirstWithoutRelaxing(): void
    {
        $engine = $this->makeEngine();
        $results = $engine->search('testing tool', self::SITE_ID);

        // The #383 case: token "tool" resolves to {tool, tools}, so AND is
        // satisfied by the "tools" documents instead of returning 0.
        self::assertNotEmpty($results);
        self::assertSame(100001, array_key_first($results), '"Testing tools" ranks #1 via the exact title boost on "testing"');
        self::assertArrayHasKey(100002, $results);

        // AND still filters: the tool-only document lacks every resolution of
        // "testing", so it stays excluded.
        self::assertArrayNotHasKey(100003, $results);

        self::assertFalse($engine->getLastSearchDebug()['relaxedMatching'], 'expansion satisfied AND — the backstop must not fire');
    }

    public function testExactMatchOutranksFuzzyOnlyMatch(): void
    {
        $engine = $this->makeEngine();
        $results = $engine->search('tools', self::SITE_ID);

        // Expansion pulls in the fuzzy-only "tool" document…
        self::assertCount(3, $results);
        self::assertArrayHasKey(100003, $results);

        // …but at similarity × fuzzyWeight, so both exact-match docs outrank it.
        self::assertGreaterThan($results[100003], $results[100001]);
        self::assertGreaterThan($results[100003], $results[100002]);
        self::assertSame(100003, array_key_last($results), 'fuzzy-only match ranks last');
    }

    public function testRelaxOnZeroBroadensDisjointAndQuery(): void
    {
        $engine = $this->makeEngine();
        $results = $engine->search('redis excel', self::SITE_ID);

        self::assertCount(2, $results, 'zero-intersection AND broadens to OR over the same resolved sets');
        self::assertArrayHasKey(100004, $results);
        self::assertArrayHasKey(100005, $results);
        self::assertTrue($engine->getLastSearchDebug()['relaxedMatching']);
    }

    public function testRelaxDoesNotFireForSingleTokenMiss(): void
    {
        $engine = $this->makeEngine();
        $results = $engine->search('zzzqqq', self::SITE_ID);

        self::assertSame([], $results);
        self::assertFalse($engine->getLastSearchDebug()['relaxedMatching'], 'backstop only applies to multi-token AND queries');
    }

    public function testDebugExposesPerTokenResolvedTerms(): void
    {
        $engine = $this->makeEngine();
        $engine->search('testing tool', self::SITE_ID);

        $resolvedTerms = $engine->getLastSearchDebug()['resolvedTerms'];

        self::assertSame(['testing', 'tool'], array_keys($resolvedTerms));

        $toolByTerm = [];
        foreach ($resolvedTerms['tool'] as $entry) {
            $toolByTerm[$entry['term']] = $entry;
        }

        self::assertSame(TermResolver::MATCH_EXACT, $toolByTerm['tool']['matchType']);
        self::assertSame(TermResolver::MATCH_FUZZY, $toolByTerm['tools']['matchType']);
        self::assertGreaterThan(0.0, $toolByTerm['tools']['similarity']);
        self::assertLessThan(1.0, $toolByTerm['tools']['similarity']);
    }

    public function testBackendForwardsSearchDebugAndResolverDrivenMatchedIn(): void
    {
        $backend = new MySqlBackend();
        $result = $backend->search(self::BACKEND_INDEX_HANDLE, 'redis excel', ['siteId' => self::SITE_ID]);

        self::assertCount(2, $result['hits']);
        self::assertArrayHasKey('searchDebug', $result);
        self::assertTrue($result['searchDebug']['relaxedMatching']);
        self::assertArrayHasKey('redis', $result['searchDebug']['resolvedTerms']);
        self::assertArrayHasKey('excel', $result['searchDebug']['resolvedTerms']);

        // matchedIn now consumes the SAME resolution that drove ranking.
        foreach ($result['hits'] as $hit) {
            self::assertNotEmpty($hit['matchedIn'] ?? null);
        }
    }

    private function makeEngine(): SearchEngine
    {
        return new SearchEngine(new MySqlStorage(self::INDEX_HANDLE), self::INDEX_HANDLE);
    }

    private function seedCorpus(SearchEngine $engine): void
    {
        $engine->indexDocument(self::SITE_ID, 100001, 'Testing tools', 'testing tools guide overview');
        $engine->indexDocument(self::SITE_ID, 100002, 'Developers Overview', 'testing tools reference material');
        $engine->indexDocument(self::SITE_ID, 100003, 'Template Variables', 'tool reference material');
        $engine->indexDocument(self::SITE_ID, 100004, 'Redis Backend', 'redis connection storage');
        $engine->indexDocument(self::SITE_ID, 100005, 'Excel Export', 'excel export spreadsheet');
    }

    private static function deleteRowsForIndexes(): void
    {
        $handles = [
            self::INDEX_HANDLE,
            SearchManager::$plugin->getSettings()->getFullIndexName(self::BACKEND_INDEX_HANDLE),
        ];

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
                ->delete($table, ['indexHandle' => $handles])
                ->execute();
        }
    }
}
