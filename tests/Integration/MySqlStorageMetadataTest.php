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
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for local MySQL search metadata counters.
 *
 * @since 5.47.0
 */
final class MySqlStorageMetadataTest extends TestCase
{
    private const INDEX_HANDLE = 'test_mysql_storage_metadata';

    protected function tearDown(): void
    {
        $this->deleteRowsForIndex();

        parent::tearDown();
    }

    public function testMetadataCountersClampWhenDeletesExceedAdds(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);

        $storage->updateMetadata(1, 25, false);

        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame(1, $storage->getTotalLength(1));
        self::assertSame(1.0, $storage->getAverageDocLength(1));

        $storage->updateMetadata(1, 40, true);
        $storage->updateMetadata(1, 80, false);

        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame(1, $storage->getTotalLength(1));
        self::assertSame(1.0, $storage->getAverageDocLength(1));
    }

    public function testReindexingExistingDocumentReplacesMetadataInsteadOfInflatingIt(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);
        $engine = new SearchEngine($storage, self::INDEX_HANDLE);

        $engine->indexDocument(1, 100001, 'First title', 'alpha beta gamma');
        $firstLength = $storage->getTotalLength(1);

        $engine->indexDocument(1, 100001, 'Second title', 'alpha beta gamma delta epsilon');

        self::assertSame(1, $storage->getTotalDocCount(1));
        self::assertGreaterThan(0, $storage->getTotalLength(1));
        self::assertNotSame($firstLength, $storage->getTotalLength(1));
    }

    private function deleteRowsForIndex(): void
    {
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
        ];

        foreach ($tables as $table) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete($table, ['indexHandle' => self::INDEX_HANDLE])
                ->execute();
        }
    }
}
