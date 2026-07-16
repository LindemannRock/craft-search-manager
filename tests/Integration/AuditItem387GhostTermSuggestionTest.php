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
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\search\NgramGenerator;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #387 (ghost-term autocomplete suggestions)
 * plus the orphaned-ngram hygiene leak found alongside it.
 *
 * A term whose last posting is removed used to keep its ngram signature
 * forever (only clearSite/clearAll deleted ngram rows), so the fuzzy tier
 * could resolve it and the single-token autocomplete branch suggested it —
 * a suggestion search returns 0 results for. Two fixes pinned here:
 *
 *  1. {@see \lindemannrock\searchmanager\services\AutocompleteService} doc-checks
 *     fuzzy candidates (the only tier that can produce a ghost) before
 *     emitting single-token suggestions, filtering before the limit slice.
 *  2. {@see SearchEngine} deletes a term's ngram signature when a document
 *     removal or re-index leaves the term with no postings for the site.
 *
 * @since 5.53.0
 */
final class AuditItem387GhostTermSuggestionTest extends TestCase
{
    private const INDEX_HANDLE = 'test_ac_ghost_terms';
    private const SITE_ID = 1;

    private bool $originalEnableAutocompleteCache;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableAutocompleteCache = (bool)$settings->enableAutocompleteCache;
        $settings->enableAutocompleteCache = false;

        self::deleteRowsForIndex();
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->enableAutocompleteCache = $this->originalEnableAutocompleteCache;
        self::deleteRowsForIndex();

        parent::tearDown();
    }

    // ---- Fix 1: autocomplete never suggests a ghost ---------------------------

    public function testGhostTermIsNotSuggestedAsFuzzyCandidate(): void
    {
        // A live document keeps the index non-empty; the ghost term has an
        // ngram signature but no posting (the state a pre-fix delete left
        // behind, and the race window even with engine-side hygiene).
        $this->engine()->indexDocument(self::SITE_ID, 300001, 'Live Doc', 'krakens roam here');
        $this->seedGhost('krakenzz');

        $suggestions = $this->suggest('krakenz');

        self::assertNotContains('krakenzz', $suggestions, 'a term with no postings must never be suggested');
    }

    public function testLiveFuzzyNeighborIsStillSuggested(): void
    {
        $this->engine()->indexDocument(self::SITE_ID, 300001, 'Live Doc', 'krakens roam here');

        $suggestions = $this->suggest('krakenz');

        self::assertContains('krakens', $suggestions, 'live fuzzy candidates must survive the ghost filter');
    }

    public function testGhostDoesNotEatSuggestionSlots(): void
    {
        // The ghost out-similarities the live term for this typo, so a
        // slice-then-filter implementation would return an empty list.
        $this->engine()->indexDocument(self::SITE_ID, 300001, 'Live Doc', 'krakens roam here');
        $this->seedGhost('krakenzz');

        $suggestions = SearchManager::$plugin->autocomplete->suggest('krakenz', self::INDEX_HANDLE, [
            'siteId' => self::SITE_ID,
            'minLength' => 2,
            'limit' => 1,
            'fuzzy' => true,
        ]);

        self::assertSame(['krakens'], $suggestions, 'ghosts must be filtered before the limit slice, not after');
    }

    // ---- Fix 2: engine cleans orphaned ngram signatures -----------------------

    public function testDocumentDeleteCleansOrphanedTermNgrams(): void
    {
        $engine = $this->engine();
        $engine->indexDocument(self::SITE_ID, 300010, 'Vanishing Doc', 'vanisher content here');
        self::assertTrue($this->storage()->termHasNgrams('vanisher', self::SITE_ID));

        $engine->deleteDocumentByKey(
            self::SITE_ID,
            300010,
            SearchHitIdentityHelper::pageDocumentId(300010, self::SITE_ID),
        );

        self::assertFalse(
            $this->storage()->termHasNgrams('vanisher', self::SITE_ID),
            'deleting a term\'s last posting must delete its ngram signature',
        );
        self::assertSame(0, $this->countNgramRows('vanisher'));
    }

    public function testReindexCleansNgramsOfDroppedTerms(): void
    {
        $engine = $this->engine();
        $engine->indexDocument(self::SITE_ID, 300011, 'Changing Doc', 'vanisher content here');
        self::assertTrue($this->storage()->termHasNgrams('vanisher', self::SITE_ID));

        $engine->indexDocument(self::SITE_ID, 300011, 'Changing Doc', 'replacement content here');

        self::assertFalse(
            $this->storage()->termHasNgrams('vanisher', self::SITE_ID),
            're-indexing a document without a term must delete the term\'s orphaned ngram signature',
        );
        self::assertTrue($this->storage()->termHasNgrams('replacement', self::SITE_ID));
    }

    public function testSharedTermNgramsSurviveWhileAnotherDocumentUsesIt(): void
    {
        $engine = $this->engine();
        $engine->indexDocument(self::SITE_ID, 300020, 'Doc One', 'shared krakens content');
        $engine->indexDocument(self::SITE_ID, 300021, 'Doc Two', 'krakens elsewhere too');

        $engine->deleteDocumentByKey(
            self::SITE_ID,
            300020,
            SearchHitIdentityHelper::pageDocumentId(300020, self::SITE_ID),
        );
        self::assertTrue(
            $this->storage()->termHasNgrams('krakens', self::SITE_ID),
            'a term still posted by another document must keep its ngram signature',
        );

        $engine->deleteDocumentByKey(
            self::SITE_ID,
            300021,
            SearchHitIdentityHelper::pageDocumentId(300021, self::SITE_ID),
        );
        self::assertFalse($this->storage()->termHasNgrams('krakens', self::SITE_ID));
    }

    // ---- Helpers ------------------------------------------------------------

    private function engine(): SearchEngine
    {
        $settings = SearchManager::$plugin->getSettings();

        return new SearchEngine(new MySqlStorage(self::fullIndexName()), self::fullIndexName(), [
            'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
            'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
            'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
        ]);
    }

    private function storage(): MySqlStorage
    {
        return new MySqlStorage(self::fullIndexName());
    }

    /**
     * Store an ngram signature with no posting — the ghost state.
     */
    private function seedGhost(string $term): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $generator = new NgramGenerator(explode(',', $settings->ngramSizes ?? '2,3'));

        $this->storage()->storeTermNgrams($term, $generator->generate($term), self::SITE_ID);
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

    private function countNgramRows(string $term): int
    {
        return (int)(new \craft\db\Query())
            ->from('{{%searchmanager_search_ngrams}}')
            ->where(['indexHandle' => self::fullIndexName(), 'term' => $term, 'siteId' => self::SITE_ID])
            ->count();
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
