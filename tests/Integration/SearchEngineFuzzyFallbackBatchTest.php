<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the fuzzy-fallback batching fix for the local search engine.
 *
 * When a query token misses an exact match, the engine asks the fuzzy matcher
 * for candidate terms and then needs each candidate's documents. That fetch
 * was previously one getTermDocuments() call per candidate (up to
 * maxFuzzyCandidates, default 100) — an N+1 — and the scoring loop then
 * re-fetched every term again. The candidates are now fetched in one
 * getTermDocumentsBatch() call and reused during scoring.
 *
 * Uses an in-memory {@see RecordingStorage} so the query shape is asserted
 * directly (one batch call, no per-candidate single calls) while ranking
 * stays equivalent.
 *
 * @since 5.47.0
 */
final class SearchEngineFuzzyFallbackBatchTest extends TestCase
{
    private const SITE_ID = 1;

    /**
     * `protein` matches docs 1/2/3; the close variant `protien` matches doc 1
     * only (so doc 1, matched by two candidates, must rank first). Neither the
     * simple query `protine` nor the variants carry a title term.
     */
    private function makeStorage(): RecordingStorage
    {
        return new RecordingStorage(
            termDocs: [
                'protein' => ['1:1' => 3, '1:2' => 3, '1:3' => 3],
                'protien' => ['1:1' => 2],
            ],
            titleByElement: [],
            docLengths: ['1:1' => 10, '1:2' => 10, '1:3' => 10],
            totalDocs: 3,
            avgDocLength: 10.0,
            fuzzyCandidates: ['protein' => 0.6, 'protien' => 0.55],
        );
    }

    public function testSimpleFuzzyFallbackBatchesCandidateLookups(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        // `protine` has no exact match → fuzzy fallback over two candidates.
        $results = $engine->search('protine', self::SITE_ID);

        // Candidates fetched in one batch; the only single lookup is the
        // initial exact-match attempt for the query term itself.
        $this->assertSame(1, $storage->getTermDocumentsBatchCalls, 'fuzzy candidates must be fetched in one batch');
        $this->assertSame([2], $storage->getTermDocumentsBatchSizes, 'both candidates in a single batch call');
        $this->assertSame(1, $storage->getTermDocumentsCalls, 'only the exact-miss attempt; no per-candidate or scoring re-fetch');

        // Ranking preserved: the doc matched by both candidates ranks first.
        $this->assertCount(3, $results);
        $this->assertSame(1, array_key_first($results), 'doc matched by two fuzzy candidates ranks first');
        $this->assertGreaterThan($results[2], $results[1]);
        $this->assertSame($results[2], $results[3], 'docs matched by the same single candidate tie');
    }

    public function testExactMatchPathFetchesTermDocsOncePerTerm(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        // Exact hit: matching fetches once, scoring reuses the cached docs.
        $results = $engine->search('protein', self::SITE_ID);

        $this->assertSame(1, $storage->getTermDocumentsCalls, 'exact term fetched once, reused in scoring (was twice)');
        $this->assertSame(0, $storage->getTermDocumentsBatchCalls, 'no fuzzy fallback on an exact hit');
        $this->assertCount(3, $results);
    }

    public function testAdvancedOperatorFuzzyFallbackBatches(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'test-index');

        // `protine OR protein` routes through QueryParser → searchTerms(): one
        // term misses (fuzzy → batch), one is an exact hit.
        $results = $engine->search('protine OR protein', self::SITE_ID);

        $this->assertSame(1, $storage->getTermDocumentsBatchCalls, 'searchTerms() must batch fuzzy candidates');
        $this->assertSame([2], $storage->getTermDocumentsBatchSizes);
        // Two query terms → two exact-match attempts; candidates never fetched singly.
        $this->assertSame(2, $storage->getTermDocumentsCalls, 'no per-candidate or scoring re-fetch on the operator path');
        $this->assertNotEmpty($results);
        $this->assertSame(1, array_key_first($results), 'doc 1 (exact + fuzzy contributions) ranks first');
    }
}
