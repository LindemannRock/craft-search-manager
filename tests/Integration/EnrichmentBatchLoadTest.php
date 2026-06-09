<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the EnrichmentService element-hydration batching fix.
 *
 * `enrichResults()` previously called Craft::$app->elements->getElementById()
 * inside the raw-hit loop — one element load per hit, worst on enrich=1 API /
 * widget searches at hitsPerPage=100. Elements are now batch-loaded up front,
 * grouped by element type and site.
 *
 * Verified against live data: resolved hits must load via the batched element
 * query (zero per-hit getElementById calls) with output preserved, and hits
 * whose index/type can't be resolved must still fall back to getElementById.
 *
 * @since 5.47.0
 */
final class EnrichmentBatchLoadTest extends TestCase
{
    private ?object $originalElements = null;

    protected function tearDown(): void
    {
        if ($this->originalElements !== null) {
            Craft::$app->set('elements', $this->originalElements);
            $this->originalElements = null;
        }
        parent::tearDown();
    }

    /**
     * Find an enabled Entry index that has at least $min live entries on its
     * first site, or null if the install has none.
     *
     * @return array{0: SearchIndex, 1: Entry[]}|null
     */
    private function findLiveEntries(int $min = 1, int $limit = 3): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== Entry::class) {
                continue;
            }
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }
            $entries = Entry::find()->siteId($siteId)->status('live')->limit($limit)->all();
            if (count($entries) >= $min) {
                return [$index, $entries];
            }
        }

        return null;
    }

    /**
     * Swap a counting Elements service so the test can assert how many per-hit
     * getElementById() calls enrichResults makes. Restored in tearDown.
     */
    private function installCountingElements(): object
    {
        $this->originalElements = Craft::$app->get('elements');

        $counting = new class extends \craft\services\Elements {
            public int $getByIdCalls = 0;

            public function getElementById(
                int $elementId,
                ?string $elementType = null,
                array|string|int|null $siteId = null,
                array $criteria = [],
            ): ?ElementInterface {
                $this->getByIdCalls++;

                return parent::getElementById($elementId, $elementType, $siteId, $criteria);
            }
        };

        Craft::$app->set('elements', $counting);

        return $counting;
    }

    public function testResolvedHitsLoadInBatchWithoutPerHitGetElementById(): void
    {
        $found = $this->findLiveEntries(1, 3);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [$index, $entries] = $found;
        $siteId = (int) $entries[0]->siteId;

        // Mix explicit _index with the indexHandles[0] fallback: every hit
        // resolves the same handle, which must be resolved once via the cached
        // map rather than per hit (no findByHandle N+1).
        $hits = [];
        foreach ($entries as $i => $entry) {
            $hit = ['id' => $entry->id, 'siteId' => $siteId];
            if ($i % 2 === 0) {
                $hit['_index'] = $index->handle;
            }
            $hits[] = $hit;
        }

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults($hits, '', [$index->handle], ['siteId' => $siteId]);

        // Output preserved: one enriched result per hit, in order, correct data.
        $this->assertCount(count($entries), $results);
        foreach ($entries as $i => $entry) {
            $this->assertSame((int) $entry->id, $results[$i]['id']);
            // enrichResults falls back to 'Untitled' when the element has no title.
            $this->assertSame($entry->title ?? 'Untitled', $results[$i]['title']);
        }

        // Batched: resolved hits never touch getElementById (the removed N+1) —
        // and crucially the call count does not grow with the number of hits.
        $this->assertSame(0, $counting->getByIdCalls, 'resolved hits load via the batched element query, not per-hit getElementById');
    }

    public function testUnresolvedIndexFallsBackToGetElementById(): void
    {
        $found = $this->findLiveEntries(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [, $entries] = $found;
        $entry = $entries[0];
        $siteId = (int) $entry->siteId;

        // Unresolvable index + no indexHandles → element type unknown → the
        // defensive per-element getElementById() fallback must still load it.
        $hits = [['id' => $entry->id, 'siteId' => $siteId, '_index' => '__sm_no_such_index']];

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults($hits, '', [], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame((int) $entry->id, $results[0]['id']);
        $this->assertGreaterThanOrEqual(1, $counting->getByIdCalls, 'unresolved hits still load via the getElementById fallback');
    }
}
