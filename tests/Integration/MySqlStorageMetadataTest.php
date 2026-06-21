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

    public function testDeletingMissingDocumentDoesNotDecrementMetadata(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);
        $engine = new SearchEngine($storage, self::INDEX_HANDLE);

        $engine->indexDocument(1, 100001, 'Only document', 'alpha beta gamma');

        self::assertSame(1, $storage->getTotalDocCount(1));
        $lengthBefore = $storage->getTotalLength(1);

        // Pending-sync deletes are sent unconditionally (no documentExists
        // probe), so deleting a document that was never indexed must be a
        // metadata no-op — otherwise doc_count drains to 0 while indexed
        // rows remain and every search early-returns empty.
        self::assertTrue($engine->deleteDocument(1, 999999));

        self::assertSame(1, $storage->getTotalDocCount(1));
        self::assertSame($lengthBefore, $storage->getTotalLength(1));
    }

    public function testDeletingExistingDocumentRemovesRowsAndMetadata(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);
        $engine = new SearchEngine($storage, self::INDEX_HANDLE);

        $engine->indexDocument(1, 100001, 'Only document', 'alpha beta gamma');

        self::assertSame(1, $storage->getTotalDocCount(1));

        self::assertTrue($engine->deleteDocument(1, 100001));

        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame(0, $storage->getDocumentLength(1, 100001));
        self::assertSame([], $storage->getDocumentTerms(1, 100001));
        self::assertSame([], $storage->getTermDocuments('alpha', 1));
    }

    public function testDocumentTermsExcludeSpecialLanguageAndLengthRows(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);

        $storage->storeDocument(1, 100001, ['alpha' => 2], 5, 'de');

        self::assertSame(['alpha' => 2], $storage->getDocumentTerms(1, 100001));
        self::assertSame([100001 => ['alpha' => 2]], $storage->getDocumentTermsBatch(1, [100001]));
    }

    public function testSearchReturnsNothingWhenDocCountIsZeroDespiteIndexedRows(): void
    {
        $storage = new MySqlStorage(self::INDEX_HANDLE);
        $engine = new SearchEngine($storage, self::INDEX_HANDLE);

        $engine->indexDocument(1, 100001, 'Drifted document', 'alpha beta gamma');

        self::assertArrayHasKey(100001, $engine->search('alpha', 1));

        // Simulate the observed drift: indexed rows exist but doc_count was
        // decremented to 0. Search must early-return empty on doc_count=0,
        // which is the user-visible symptom of the metadata drift.
        Craft::$app->getDb()->createCommand()
            ->update(
                '{{%searchmanager_search_metadata}}',
                ['metaValue' => '0'],
                [
                    'indexHandle' => self::INDEX_HANDLE,
                    'siteId' => 1,
                    'metaKey' => 'doc_count',
                ]
            )
            ->execute();

        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame([], $engine->search('alpha', 1));
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
