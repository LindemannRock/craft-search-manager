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
use lindemannrock\searchmanager\tests\TestCase;

/**
 * THE COHERENCE KEYSTONE (#383/#384 Phase C): autocomplete must never suggest
 * a query that search returns 0 results for. Both surfaces now ride the shared
 * QueryUnderstanding + TermResolver core, and multi-word completions are
 * filtered by the preceding tokens' resolved doc-sets — this suite is the
 * invariant as executable spec: every suggest() output is fed to the real
 * MySQL backend's search() on the same index and must yield ≥1 hit.
 *
 * @since 5.53.0
 */
final class AutocompleteSearchCoherenceKeystoneTest extends TestCase
{
    private const INDEX_HANDLE = 'test_ac_search_keystone';
    private const SITE_ID = 1;

    private static bool $seeded = false;

    private bool $originalEnableAutocompleteCache;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableAutocompleteCache = (bool)$settings->enableAutocompleteCache;
        $settings->enableAutocompleteCache = false;

        if (!self::$seeded) {
            self::deleteRowsForIndex();

            // Seed with the SAME algorithm settings the live service/backend
            // resolve with, so stored n-grams match query-side n-grams.
            $engine = new SearchEngine(new MySqlStorage(self::fullIndexName()), self::fullIndexName(), [
                'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
                'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
                'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
            ]);

            $engine->indexDocument(self::SITE_ID, 200001, 'Testing tools', 'testing tools guide overview');
            $engine->indexDocument(self::SITE_ID, 200002, 'Developers Overview', 'testing tools reference material');
            $engine->indexDocument(self::SITE_ID, 200003, 'Template Variables', 'tool reference material');
            $engine->indexDocument(self::SITE_ID, 200004, 'Test Samples', 'test tools sample data');
            $engine->indexDocument(self::SITE_ID, 200005, 'Redis Backend', 'redis connection storage');
            $engine->indexDocument(self::SITE_ID, 200006, 'Excel Export', 'excel export spreadsheet');
            $engine->indexDocument(self::SITE_ID, 200007, 'البحث', 'بحث متقدم شامل', 'ar');

            self::$seeded = true;
        }
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->enableAutocompleteCache = $this->originalEnableAutocompleteCache;

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteRowsForIndex();
        self::$seeded = false;

        parent::tearDownAfterClass();
    }

    public function testTestingToolSuggestsTestingToolsAndNeverTheUnanswerableInput(): void
    {
        $suggestions = $this->suggest('testing tool');

        self::assertContains('testing tools', $suggestions);
        self::assertNotContains('testing tool', $suggestions, 'no document supports "testing tool" — the keystone filter must drop it');

        $this->assertEverySuggestionHasHits($suggestions);
    }

    public function testTestToolSuggestionsAreNonEmptyAndAnswerable(): void
    {
        $suggestions = $this->suggest('test tool');

        self::assertNotEmpty($suggestions, 'the #384 empty case: multi-word input must complete the last token');
        self::assertContains('test tools', $suggestions);

        $this->assertEverySuggestionHasHits($suggestions);
    }

    public function testToolzFuzzySuggestsTools(): void
    {
        $suggestions = $this->suggest('toolz');

        self::assertContains('tools', $suggestions);

        $this->assertEverySuggestionHasHits($suggestions);
    }

    public function testArabicPrefixSuggestsSeededTerm(): void
    {
        $suggestions = $this->suggest('بح');

        self::assertContains('بحث', $suggestions);

        $this->assertEverySuggestionHasHits($suggestions);
    }

    public function testEveryGeneratedSuggestionYieldsSearchHits(): void
    {
        $terms = array_map(
            'strval',
            array_keys((new MySqlStorage(self::fullIndexName()))->getTermsForAutocomplete(self::SITE_ID, null, 1000)),
        );
        sort($terms, SORT_STRING);
        self::assertNotEmpty($terms);

        // Every indexed term's prefixes…
        $queries = [];
        foreach ($terms as $term) {
            for ($length = 2; $length <= mb_strlen($term); $length++) {
                $queries[] = mb_substr($term, 0, $length);
            }
        }

        // …plus two-word combos (full first word + short second-word prefix),
        // over a deterministic subset to keep runtime bounded.
        $pairTerms = array_slice($terms, 0, 12);
        foreach ($pairTerms as $first) {
            foreach ($pairTerms as $second) {
                $queries[] = $first . ' ' . mb_substr($second, 0, min(3, mb_strlen($second)));
            }
        }

        $uniqueSuggestions = [];
        foreach (array_unique($queries) as $query) {
            foreach ($this->suggest($query) as $suggestion) {
                $uniqueSuggestions[$suggestion] = true;
            }
        }

        self::assertGreaterThanOrEqual(20, count($uniqueSuggestions), 'generator must exercise a real suggestion surface, not pass vacuously');

        $backend = new MySqlBackend();
        foreach (array_keys($uniqueSuggestions) as $suggestion) {
            $result = $backend->search(self::INDEX_HANDLE, (string)$suggestion, ['siteId' => self::SITE_ID]);

            self::assertGreaterThan(
                0,
                (int)($result['total'] ?? 0),
                sprintf('coherence invariant violated: suggestion "%s" returns 0 search results', $suggestion),
            );
        }
    }

    /**
     * @return string[]
     */
    private function suggest(string $query): array
    {
        return SearchManager::$plugin->autocomplete->suggest($query, self::INDEX_HANDLE, [
            'siteId' => self::SITE_ID,
            'minLength' => 2,
            'limit' => 10,
            'fuzzy' => true,
        ]);
    }

    /**
     * @param string[] $suggestions
     */
    private function assertEverySuggestionHasHits(array $suggestions): void
    {
        $backend = new MySqlBackend();

        foreach ($suggestions as $suggestion) {
            $result = $backend->search(self::INDEX_HANDLE, $suggestion, ['siteId' => self::SITE_ID]);

            self::assertGreaterThan(
                0,
                (int)($result['total'] ?? 0),
                sprintf('coherence invariant violated: suggestion "%s" returns 0 search results', $suggestion),
            );
        }
    }

    private static function fullIndexName(): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName(self::INDEX_HANDLE);
    }

    private static function deleteRowsForIndex(): void
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
                ->delete($table, ['indexHandle' => self::fullIndexName()])
                ->execute();
        }
    }
}
