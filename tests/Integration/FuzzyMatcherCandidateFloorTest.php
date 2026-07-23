<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\TermResolver;
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
        $toolStorage = $this->makeStorage([
            'tools' => 7 / 13,
            'to' => 3 / 11,
        ]);
        self::assertSame(['tools'], $this->resolveTerms('tool', $toolStorage));

        $testStorage = $this->makeStorage([
            'testing' => 0.41,
        ]);
        self::assertSame(['testing'], $this->resolveTerms('test', $testStorage));

        $testingStorage = $this->makeStorage([
            'test' => 0.41,
        ]);
        self::assertSame([], $this->resolveTerms('testing', $testingStorage));
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

    /**
     * @return string[]
     */
    private function resolveTerms(string $query, RecordingStorage $storage): array
    {
        $resolved = (new TermResolver($storage))->resolve($query, 1);

        return array_column($resolved, 'term');
    }
}
