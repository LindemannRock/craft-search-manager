<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\elements\Entry;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression coverage for audit #333.
 *
 * @since 5.53.0
 */
final class AuditItem333RegressionTest extends TestCase
{
    /**
     * @return array{handle: string, row: array<string, mixed>}
     */
    public function testDirectIndexingRealMatchingElementPollutesStatsUntilTearDown(): array
    {
        $pair = $this->findRealDatabaseEntryIndexAndElement();
        if ($pair === null) {
            self::markTestSkipped('Requires an enabled real database Entry index with a matching fixture entry.');
        }

        [$index, $entry] = $pair;
        $originalRow = $this->fetchSearchIndexStatsByHandle($index->handle);
        self::assertNotNull($originalRow);

        $this->installStubBackend();
        SearchManager::$plugin->indexing->indexElementNow($entry);

        $pollutedRow = $this->fetchSearchIndexStatsByHandle($index->handle);
        self::assertNotNull($pollutedRow);
        self::assertSame((int)$originalRow['documentCount'] + 1, (int)$pollutedRow['documentCount']);
        self::assertNotSame($originalRow, $pollutedRow);

        return [
            'handle' => $index->handle,
            'row' => $originalRow,
        ];
    }

    /**
     * @param array{handle: string, row: array<string, mixed>} $state
     */
    #[Depends('testDirectIndexingRealMatchingElementPollutesStatsUntilTearDown')]
    public function testStatsRowIsRestoredAfterPollutingPattern(array $state): void
    {
        $restoredRow = $this->fetchSearchIndexStatsByHandle($state['handle']);

        self::assertSame($state['row'], $restoredRow);
    }

    /**
     * @return array{0: SearchIndex, 1: Entry}|null
     */
    private function findRealDatabaseEntryIndexAndElement(): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled) {
                continue;
            }
            if ($index->source !== 'database') {
                continue;
            }
            if ($index->elementType !== Entry::class) {
                continue;
            }
            if ($index->usesSplitSections()) {
                continue;
            }
            if (str_starts_with($index->handle, 'test_')) {
                continue;
            }

            $statsRow = $this->fetchSearchIndexStatsByHandle($index->handle);
            if ($statsRow === null) {
                continue;
            }

            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            foreach ($siteIds as $siteId) {
                $entries = Entry::find()
                    ->siteId((int)$siteId)
                    ->status(null)
                    ->drafts(false)
                    ->revisions(false)
                    ->limit(20)
                    ->all();

                foreach ($entries as $entry) {
                    if ($index->matchesElement($entry)) {
                        return [$index, $entry];
                    }
                }
            }
        }

        return null;
    }
}
