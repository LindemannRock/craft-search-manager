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
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit Batch 5 findings.
 *
 * @since 5.53.0
 */
final class IndexPersistenceRollbackTest extends TestCase
{
    private string $handlePrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handlePrefix = 'audit-batch-5-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', 'audit-batch-5-'])
            ->execute();

        parent::tearDown();
    }

    public function testSearchIndexSaveRollsBackInsertedRowWhenSiteSaveFails(): void
    {
        $handle = $this->handlePrefix . '-partial-index';
        $index = new SearchIndex();
        $index->name = 'Audit Batch 5 Partial Index';
        $index->handle = $handle;
        $index->elementType = Entry::class;
        $index->siteId = [$this->bogusSiteId()];
        $index->source = 'database';

        self::assertFalse($index->save());
        self::assertNull($index->id);
        self::assertSame(0, $this->countRows('{{%searchmanager_indices}}', ['handle' => $handle]));
    }

    private function bogusSiteId(): int
    {
        $ids = array_map(static fn($site): int => (int)$site->id, Craft::$app->getSites()->getAllSites());

        return (empty($ids) ? 0 : max($ids)) + 1000;
    }
}
