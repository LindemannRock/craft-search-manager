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
 * Regression coverage for audit #388 (all-sites autocomplete skipped script-
 * language detection) plus the language-parity gap found alongside it.
 *
 * Search detects the query's script language unconditionally and filters
 * result documents by it; autocomplete only detected inside the explicit-
 * siteId gate, so all-sites suggestions were never language-filtered. And
 * even with a language, only the prefix tier honored it — exact and fuzzy
 * candidates whose postings live solely in other-language documents were
 * still suggested. Such a suggestion is answerable only when fuzzy expansion
 * happens to rescue it through a similar same-language term; with no similar
 * term (this suite's `مرحبا`) it is a guaranteed 0-result query. Fixes:
 *
 *  1. {@see \lindemannrock\searchmanager\services\AutocompleteService} runs
 *     detectScriptLanguage() unconditionally, mirroring search; the
 *     site-language fallback stays gated on an explicit siteId.
 *  2. Suggestion doc-sets are language-filtered the way search filters its
 *     results: exact/fuzzy candidates need a language-matching document, and
 *     the multi-token keystone intersection is narrowed by language.
 *
 * Seed data: an Arabic-language document (`بحث متقدم شامل`) plus an
 * English-language document carrying Arabic-script terms (`مرحبا`, `مدونة`,
 * and the pair `بحث سريع`) — the shapes that made wrong-language suggestions
 * reachable through the prefix, exact, fuzzy, and keystone paths.
 *
 * @since 5.53.0
 */
final class AuditItem388AllSitesLanguageTest extends TestCase
{
    private const INDEX_HANDLE = 'test_ac_allsites_lang';
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

            $engine = $this->engine();
            $engine->indexDocument(self::SITE_ID, 400001, 'البحث المتقدم', 'بحث متقدم شامل', 'ar');
            $engine->indexDocument(self::SITE_ID, 400002, 'English Notes', 'مرحبا مدونة بحث سريع', 'en');
            $engine->indexDocument(self::SITE_ID, 400003, 'Kraken Guide', 'krakens roam here', 'en');
            $engine->indexDocument(self::SITE_ID, 400004, 'Krakenberg', 'krakenberg liegt hier', 'de');

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

    // ---- #388: all-sites queries detect script language like search ----------

    public function testAllSitesArabicPrefixOnlySuggestsArabicLanguageTerms(): void
    {
        self::assertContains('بحث', $this->suggestAllSites('بح'));
        self::assertNotContains(
            'مرحبا',
            $this->suggestAllSites('مر'),
            'a term posted only by an English-language document must not complete an Arabic query',
        );
    }

    public function testWrongLanguageSuggestionWouldViolateTheSearchInvariant(): void
    {
        // The invariant the filter protects: search detects 'ar' and filters
        // documents by it; with no similar Arabic-language term to rescue it
        // via fuzzy expansion, the EN-posted term is a guaranteed 0-result.
        $result = (new MySqlBackend())->search(self::INDEX_HANDLE, 'مرحبا', ['siteId' => self::SITE_ID]);

        self::assertSame(0, (int)($result['total'] ?? -1));
    }

    public function testAllSitesLatinQueryStaysUnfiltered(): void
    {
        // Latin script is inconclusive → no detected language → no filter,
        // exactly like search's null languageOverride under all-sites.
        $suggestions = $this->suggestAllSites('krak');

        self::assertContains('krakens', $suggestions);
        self::assertContains('krakenberg', $suggestions);
    }

    // ---- Language parity: exact/fuzzy tiers + keystone intersection ----------

    public function testExactTermPostedOnlyInWrongLanguageIsNotSuggestedAllSites(): void
    {
        self::assertNotContains(
            'مرحبا',
            $this->suggestAllSites('مرحبا'),
            'an exact candidate without a language-matching document must be dropped',
        );
    }

    public function testExactTermPostedOnlyInWrongLanguageIsNotSuggestedSingleSite(): void
    {
        self::assertNotContains(
            'مرحبا',
            $this->suggest('مرحبا', self::SITE_ID),
            'the parity fix applies to the explicit-siteId path too',
        );
    }

    public function testFuzzyCandidateInWrongLanguageIsDropped(): void
    {
        self::assertNotContains(
            'مرحبا',
            $this->suggestAllSites('مرحبان'),
            'a fuzzy candidate without a language-matching document must be dropped',
        );
    }

    public function testFuzzyCandidateWithMatchingLanguageSurvives(): void
    {
        self::assertContains(
            'بحث',
            $this->suggestAllSites('بحثث'),
            'fuzzy candidates with a language-matching document must survive the filter',
        );
    }

    public function testMultiTokenCompletionRequiresLanguageMatchingCoOccurrence(): void
    {
        self::assertNotContains(
            'بحث سريع',
            $this->suggestAllSites('بحث سر'),
            'the only co-occurrence document is English-language — no Arabic strict-AND result exists',
        );
    }

    public function testMultiTokenCompletionWithMatchingLanguageSurvives(): void
    {
        self::assertContains('بحث متقدم', $this->suggestAllSites('بحث مت'));
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * @return string[]
     */
    private function suggestAllSites(string $query): array
    {
        return SearchManager::$plugin->autocomplete->suggest($query, self::INDEX_HANDLE, [
            'minLength' => 2,
            'limit' => 10,
            'fuzzy' => true,
        ]);
    }

    /**
     * @return string[]
     */
    private function suggest(string $query, int $siteId): array
    {
        return SearchManager::$plugin->autocomplete->suggest($query, self::INDEX_HANDLE, [
            'siteId' => $siteId,
            'minLength' => 2,
            'limit' => 10,
            'fuzzy' => true,
        ]);
    }

    private function engine(): SearchEngine
    {
        $settings = SearchManager::$plugin->getSettings();

        return new SearchEngine(new MySqlStorage(self::fullIndexName()), self::fullIndexName(), [
            'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
            'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
            'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
        ]);
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
