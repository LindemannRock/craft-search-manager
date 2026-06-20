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
 * @since 5.53.0
 */
final class SearchEngineQueryBoostTest extends TestCase
{
    private const SITE_ID = 1;

    private function makeEngine(): SearchEngine
    {
        return new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'foo' => ['1:1' => 1, '1:2' => 1],
                    'bar' => ['1:2' => 1, '1:3' => 1],
                ],
                titleByElement: [],
                docLengths: ['1:1' => 2, '1:2' => 2, '1:3' => 2],
                totalDocs: 3,
                avgDocLength: 2.0,
            ),
            'test-index',
        );
    }

    public function testBoostedTermOnlyScalesDocumentsThatMatchedThatTerm(): void
    {
        $andUnboosted = $this->makeEngine()->search('foo^1 bar', self::SITE_ID);
        $andBoosted = $this->makeEngine()->search('foo^5 bar', self::SITE_ID);
        $unboosted = $this->makeEngine()->search('foo OR bar', self::SITE_ID);
        $boosted = $this->makeEngine()->search('foo^5 OR bar', self::SITE_ID);

        self::assertSame([2], array_keys($andBoosted), 'foo^5 bar keeps the AND result set');
        self::assertEqualsWithDelta($andUnboosted[2] * 5.0, $andBoosted[2], 0.000001, 'foo^5 bar applies the foo boost to the matching doc');
        self::assertSame($this->sortedKeys($unboosted), $this->sortedKeys($boosted), 'query boosts must not change result membership');
        self::assertEqualsWithDelta($unboosted[1] * 5.0, $boosted[1], 0.000001, 'foo-only doc receives the foo boost');
        self::assertEqualsWithDelta($unboosted[2] * 5.0, $boosted[2], 0.000001, 'foo+bar doc receives the foo boost');
        self::assertEqualsWithDelta($unboosted[3], $boosted[3], 0.000001, 'bar-only doc is not uniformly scaled by the foo boost');
    }

    public function testMultipleBoostedTermsCompoundForDocumentsMatchingBothTerms(): void
    {
        $unboosted = $this->makeEngine()->search('foo OR bar', self::SITE_ID);
        $boosted = $this->makeEngine()->search('foo^2 OR bar^3', self::SITE_ID);

        self::assertSame($this->sortedKeys($unboosted), $this->sortedKeys($boosted));
        self::assertEqualsWithDelta($unboosted[1] * 2.0, $boosted[1], 0.000001);
        self::assertEqualsWithDelta($unboosted[2] * 6.0, $boosted[2], 0.000001);
        self::assertEqualsWithDelta($unboosted[3] * 3.0, $boosted[3], 0.000001);
        self::assertSame(2, array_key_first($boosted), 'doc matching both boosted terms ranks first');
    }

    public function testNoBoostQueryKeepsExpectedScoresAndOrder(): void
    {
        $engine = $this->makeEngine();

        $results = $engine->search('foo OR bar', self::SITE_ID);

        self::assertSame([2, 1, 3], array_keys($results));
        self::assertGreaterThan($results[1], $results[2], 'doc matching both terms ranks above single-term docs');
        self::assertEqualsWithDelta($results[1], $results[3], 0.000001, 'single-term docs keep tied unboosted scores');
    }

    /**
     * @return int[]
     */
    private function sortedKeys(array $results): array
    {
        $keys = array_keys($results);
        sort($keys);

        return $keys;
    }
}
