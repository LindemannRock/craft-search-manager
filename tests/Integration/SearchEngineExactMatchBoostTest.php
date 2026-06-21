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
final class SearchEngineExactMatchBoostTest extends TestCase
{
    private const SITE_ID = 1;

    public function testExactMatchBoostRequiresOrderedPhraseInTitleOrContent(): void
    {
        $unboosted = $this->makeEngine(1.0)->search('alpha beta', self::SITE_ID);
        $boosted = $this->makeEngine(7.0)->search('alpha beta', self::SITE_ID);

        self::assertSame($this->sortedKeys($unboosted), $this->sortedKeys($boosted), 'exactMatchBoost must not change result membership');

        self::assertEqualsWithDelta($unboosted[1] * 7.0, $boosted[1], 0.000001, 'ordered content sequence receives exactMatchBoost');
        self::assertEqualsWithDelta($unboosted[4] * 7.0, $boosted[4], 0.000001, 'ordered title sequence receives exactMatchBoost');
        self::assertEqualsWithDelta($unboosted[2], $boosted[2], 0.000001, 'non-contiguous all-term match is not exact-boosted');
        self::assertEqualsWithDelta($unboosted[3], $boosted[3], 0.000001, 'reversed all-term match is not exact-boosted');
    }

    private function makeEngine(float $exactMatchBoost): SearchEngine
    {
        return new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'alpha' => ['1:1' => 1, '1:2' => 1, '1:3' => 1, '1:4' => 1],
                    'beta' => ['1:1' => 1, '1:2' => 1, '1:3' => 1, '1:4' => 1],
                ],
                titleByElement: [],
                docLengths: ['1:1' => 3, '1:2' => 3, '1:3' => 3, '1:4' => 3],
                totalDocs: 4,
                avgDocLength: 3.0,
                elementsById: [
                    1 => [
                        'title' => 'Content document',
                        'documentData' => ['content' => 'Alpha beta gamma'],
                    ],
                    2 => [
                        'title' => 'Separated document',
                        'documentData' => ['content' => 'Alpha gamma beta'],
                    ],
                    3 => [
                        'title' => 'Reversed document',
                        'documentData' => ['content' => 'Beta alpha gamma'],
                    ],
                    4 => [
                        'title' => 'Alpha beta title',
                        'documentData' => ['content' => 'Gamma'],
                    ],
                ],
            ),
            'test-index',
            ['exactMatchBoost' => $exactMatchBoost],
        );
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
