<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\FuzzyMatcher;
use lindemannrock\searchmanager\search\NgramGenerator;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for the minimum fuzzy-candidate length.
 *
 * @since 5.54.0
 */
final class FuzzyMatcherCandidateFloorTest extends TestCase
{
    public function testRejectsShortCandidateWithoutDroppingLegitimateExpansions(): void
    {
        $matcher = new FuzzyMatcher(new NgramGenerator([2, 3]), 0.25);

        $toolStorage = $this->makeStorage([
            'tools' => 7 / 13,
            'to' => 3 / 11,
        ]);
        self::assertSame(['tools'], $matcher->findMatches('tool', $toolStorage, 1));

        $testStorage = $this->makeStorage([
            'testing' => 0.41,
        ]);
        self::assertSame(['testing'], $matcher->findMatches('test', $testStorage, 1));

        $testingStorage = $this->makeStorage([
            'test' => 0.41,
        ]);
        self::assertSame(['test'], $matcher->findMatches('testing', $testingStorage, 1));
    }

    /**
     * @param array<string, float> $fuzzyCandidates
     */
    private function makeStorage(array $fuzzyCandidates): RecordingStorage
    {
        return new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            fuzzyCandidates: $fuzzyCandidates,
        );
    }
}
