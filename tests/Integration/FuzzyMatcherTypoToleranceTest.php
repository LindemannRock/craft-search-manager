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
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for the fuzzy typo-budget precision filter.
 *
 * @since 5.54.0
 */
final class FuzzyMatcherTypoToleranceTest extends TestCase
{
    #[DataProvider('rejectedCandidates')]
    public function testRejectsCandidatesOutsideTheQueryLengthTypoBudget(string $query, string $candidate): void
    {
        self::assertFalse(FuzzyMatcher::isCandidateWithinTypoBudget($query, $candidate));
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
        self::assertTrue(FuzzyMatcher::isCandidateWithinTypoBudget($query, $candidate));
    }

    public static function prefixExtensions(): iterable
    {
        yield 'tool to tools' => ['tool', 'tools'];
        yield 'test to testing' => ['test', 'testing'];
    }

    #[DataProvider('acceptedTypoCandidates')]
    public function testAcceptsCandidatesWithinTheQueryLengthTypoBudget(string $query, string $candidate): void
    {
        self::assertTrue(FuzzyMatcher::isCandidateWithinTypoBudget($query, $candidate));
    }

    public static function acceptedTypoCandidates(): iterable
    {
        yield 'mid-word one-typo jacket class' => ['jaket', 'jacket'];
        yield 'adjacent transposition costs one' => ['jakcet', 'jacket'];
        yield 'two ordinary typos on a long term' => ['algoritx', 'algorithm'];
        yield 'first-character typo costs two on a long term' => ['xlgorithm', 'algorithm'];
        yield 'Arabic adjacent transposition is mb-safe' => ['اخبتار', 'اختبار'];
    }

}
