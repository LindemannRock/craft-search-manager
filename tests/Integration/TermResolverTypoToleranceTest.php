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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for typo-budget enforcement in the shared resolver.
 *
 * @since 5.54.0
 */
final class TermResolverTypoToleranceTest extends TestCase
{
    #[DataProvider('rejectedCandidates')]
    public function testRejectsCandidatesOutsideTheQueryLengthTypoBudget(string $query, string $candidate): void
    {
        self::assertSame([], $this->resolveTerms($query, $candidate));
    }

    public static function rejectedCandidates(): iterable
    {
        yield 'test does not match best' => ['test', 'best'];
        yield 'best does not match test' => ['best', 'test'];
        yield 'testing does not match hosting' => ['testing', 'hosting'];
        yield 'gest does not match test' => ['gest', 'test'];
    }

    #[DataProvider('prefixExtensions')]
    public function testAlwaysAcceptsPrefixExtensions(string $query, string $candidate): void
    {
        self::assertSame([$candidate], $this->resolveTerms($query, $candidate));
    }

    public static function prefixExtensions(): iterable
    {
        yield 'tool to tools' => ['tool', 'tools'];
        yield 'test to testing' => ['test', 'testing'];
    }

    #[DataProvider('acceptedTypoCandidates')]
    public function testAcceptsCandidatesWithinTheQueryLengthTypoBudget(string $query, string $candidate): void
    {
        self::assertSame([$candidate], $this->resolveTerms($query, $candidate));
    }

    public static function acceptedTypoCandidates(): iterable
    {
        yield 'mid-word one-typo jacket class' => ['jaket', 'jacket'];
        yield 'adjacent transposition costs one' => ['jakcet', 'jacket'];
        yield 'two ordinary typos on a long term' => ['algoritx', 'algorithm'];
        yield 'first-character typo costs two on a long term' => ['xlgorithm', 'algorithm'];
        yield 'Arabic adjacent transposition is mb-safe' => ['اخبتار', 'اختبار'];
    }

    /**
     * @return string[]
     */
    private function resolveTerms(string $query, string $candidate): array
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            fuzzyCandidates: [$candidate => 0.5],
        );
        $resolved = (new TermResolver($storage))->resolve($query, 1);

        return array_column($resolved, 'term');
    }
}
