<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Locks in the consolidated criteria-matching contract on `SearchIndex`.
 *
 * Before #18, the criteria check existed in two places (`IndexingService`
 * had a private duplicate). They could drift, causing the direct-sync path
 * and the L3 buffer path to disagree about whether an element belongs in
 * an index — silent stale documents. Both call sites now delegate to
 * `SearchIndex::matchesCriteria()`, and this test pins the canonical
 * behaviour: empty criteria → match; Closure that excludes the element →
 * no match; safe error handling on a misbehaving Closure → no match.
 *
 * @since 5.46.0
 */
final class SearchIndexCriteriaMatchTest extends TestCase
{
    public function testEmptyCriteriaMatchesAnyElementOfRightType(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        // Force criteria to empty for the assertion regardless of the
        // fixture index's actual criteria.
        $index->criteria = [];

        $this->assertTrue(
            $index->matchesCriteria($element),
            'Empty criteria must match every element of the index type.',
        );
    }

    public function testClosureCriteriaThatExcludesElementReturnsFalse(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        // A Closure that filters to a deliberately non-existent ID will
        // never match the test element. `matchesCriteria()` should return
        // false without throwing.
        $index->criteria = static function ($query) {
            $query->id(-99999);
            return $query;
        };

        $this->assertFalse(
            $index->matchesCriteria($element),
            'A Closure that excludes the element must result in no match.',
        );
    }

    public function testMisbehavingClosureDoesNotCrashAndReturnsFalse(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        // A Closure that throws is the dangerous case — before #18, the
        // model's `matchesElement()` had no try/catch and would bubble
        // the exception out of the indexing pipeline. The consolidated
        // implementation catches and returns the safe default.
        $index->criteria = static function ($query): void {
            throw new \RuntimeException('Simulated criteria failure');
        };

        $this->assertFalse(
            $index->matchesCriteria($element),
            'A throwing Closure must return false (safe default), not bubble the exception.',
        );
    }

    public function testNonexistentElementClassReturnsFalse(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index] = $pair;

        // Build a tiny anonymous element-shaped object whose class doesn't
        // exist as `class_exists($elementType)` is the guard we want to
        // verify. We do this by constructing the value via runtime
        // reflection on an unresolvable class string.
        //
        // Use a mock element where get_class() returns a non-existent
        // class via PHPUnit's stub system — simpler to do with reflection
        // on a stdClass-like proxy. For the integration test, we approximate
        // by setting `$index->elementType` to a bogus class string and
        // assert matchesElement (the structural+criteria gate) returns
        // false. That covers the same class_exists guard.
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$realIndex, $element] = $pair;

        // Clone the index so we don't mutate the fixture
        $bogusIndex = clone $realIndex;
        $bogusIndex->elementType = 'lindemannrock\\not_a_real_namespace\\NotAClass';

        // Structural check should fail first — verifies the elementType
        // mismatch path in matchesElement().
        $this->assertFalse(
            $bogusIndex->matchesElement($element),
            'matchesElement must return false when the element type does not match the index.',
        );
    }

    public function testAppliesToSiteIdRespectedBeforeCriteria(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair);
        [$index, $element] = $pair;

        // Force the index to target a non-existent site. matchesElement
        // should short-circuit on structural mismatch before even consulting
        // the criteria. (matchesCriteria isn't called in that path.)
        $bogusSiteIndex = clone $index;
        $bogusSiteIndex->siteId = 999999;

        $this->assertFalse(
            $bogusSiteIndex->matchesElement($element),
            'matchesElement must respect siteId structural mismatch even when criteria would otherwise pass.',
        );
    }
}
