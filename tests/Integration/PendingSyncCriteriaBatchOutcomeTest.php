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
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins pending-sync upsert/delete outcomes while criteria checks are batched.
 *
 * @since 5.54.0
 */
final class PendingSyncCriteriaBatchOutcomeTest extends TestCase
{
    private const INDEX_HANDLE = 'sm-test-pending-criteria-batch';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testMixedCriteriaBatchKeepsUpsertAndDeleteOutcomes(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $elements = Entry::find()
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->andWhere(['entries.primaryOwnerId' => null])
            ->limit(2)
            ->all();

        if (count($elements) < 2) {
            self::markTestSkipped('Requires two primary-site entries to pin mixed criteria outcomes.');
        }

        $includedId = (int)$elements[0]->id;
        $excludedId = (int)$elements[1]->id;
        $this->withConfigFileIndices([
            self::INDEX_HANDLE => [
                'name' => 'Pending Criteria Batch Regression',
                'elementType' => Entry::class,
                'siteId' => $siteId,
                'criteria' => static function ($query) use ($includedId) {
                    $query->andWhere(['elements.id' => $includedId]);
                    return $query;
                },
                'enabled' => true,
            ],
        ]);

        $backend = $this->installStubBackend();
        $result = $this->processor->process([
            $this->row(410001, $includedId, $siteId),
            $this->row(410002, $excludedId, $siteId),
        ]);

        self::assertSame([410001, 410002], $result['success']);
        self::assertSame([], $result['failures']);

        $indexCalls = $backend->callsFor('batchIndex');
        self::assertCount(1, $indexCalls);
        self::assertSame([$includedId], array_column($indexCalls[0]['items'] ?? [], 'elementId'));

        $deleteCalls = $backend->callsFor('batchDelete');
        self::assertCount(1, $deleteCalls);
        self::assertSame([$excludedId], array_column($deleteCalls[0]['items'] ?? [], 'elementId'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $rowId, int $elementId, int $siteId): array
    {
        return [
            'id' => $rowId,
            'indexHandle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'op' => PendingSyncRepository::OP_UPSERT,
        ];
    }
}
