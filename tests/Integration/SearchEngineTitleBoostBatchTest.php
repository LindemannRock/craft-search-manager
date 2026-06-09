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
 * Pins the BM25 title-boost batching fix for the local search engine.
 *
 * The title boost previously called StorageInterface::getTitleTerms() inside
 * the per-document scoring loop — one query per matched document per term — so
 * common, high-hit-count terms scaled linearly with the matched set (the slow
 * queries the client saw on the MySQL backend). Title terms are now preloaded
 * once via getTitleTermsBatch().
 *
 * These tests use an in-memory {@see RecordingStorage} so they assert the exact
 * query shape (one batch call, zero per-document calls) regardless of hit count,
 * and that the title boost still ranks a title match first.
 *
 * @since 5.47.0
 */
final class SearchEngineTitleBoostBatchTest extends TestCase
{
    private const SITE_ID = 1;

    /**
     * Build a stub indexed so `protein` matches $docCount documents, with only
     * elementId 1 carrying the term in its title.
     */
    private function makeStorage(int $docCount): RecordingStorage
    {
        $termDocs = [];
        $docLengths = [];
        for ($i = 1; $i <= $docCount; $i++) {
            $docId = self::SITE_ID . ':' . $i;
            $termDocs['protein'][$docId] = 3; // same freq everywhere → equal base score
            $docLengths[$docId] = 10;          // same length everywhere
        }

        return new RecordingStorage(
            termDocs: $termDocs,
            titleByElement: [1 => ['protein']], // only element 1 has it in the title
            docLengths: $docLengths,
            totalDocs: $docCount,
            avgDocLength: 10.0,
        );
    }

    public function testSimplePathBatchesTitleTermsAndKeepsRanking(): void
    {
        $storage = $this->makeStorage(50);
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('protein', self::SITE_ID);

        // One batched lookup for all 50 docs; zero per-document lookups.
        $this->assertSame(1, $storage->getTitleTermsBatchCalls, 'title terms must be fetched in one batch');
        $this->assertSame(0, $storage->getTitleTermsCalls, 'no per-document getTitleTerms() in the scoring loop');
        $this->assertSame([50], $storage->getTitleTermsBatchSizes, 'batch should cover the full matched set');

        // Ranking preserved: the title-match document wins.
        $this->assertCount(50, $results);
        $this->assertSame(1, array_key_first($results), 'title-boosted doc ranks first');
        $this->assertGreaterThan($results[2], $results[1], 'title boost lifts the matching doc above a content-only doc');
        $this->assertSame($results[2], $results[3], 'non-title docs keep equal scores (boost is title-only)');
    }

    public function testTitleTermsBatchScalesO1WithHitCount(): void
    {
        // The whole point of the fix: query cost no longer grows a storage call
        // per matched document. 500 hits → still exactly one title-terms call.
        $storage = $this->makeStorage(500);
        $engine = new SearchEngine($storage, 'test-index');

        $engine->search('protein', self::SITE_ID);

        $this->assertSame(1, $storage->getTitleTermsBatchCalls);
        $this->assertSame(0, $storage->getTitleTermsCalls);
        $this->assertSame([500], $storage->getTitleTermsBatchSizes);
    }

    public function testAdvancedOperatorPathAlsoBatchesTitleTerms(): void
    {
        // `protein OR shake` routes through QueryParser → searchTerms(), the
        // second title-boost site. It must batch too.
        $storage = $this->makeStorage(50);
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('protein OR shake', self::SITE_ID);

        $this->assertSame(0, $storage->getTitleTermsCalls, 'searchTerms() must not do per-document title lookups');
        $this->assertGreaterThanOrEqual(1, $storage->getTitleTermsBatchCalls, 'searchTerms() must use the batch');
        $this->assertNotEmpty($results);
        $this->assertSame(1, array_key_first($results), 'title-boosted doc still ranks first on the operator path');
    }
}
