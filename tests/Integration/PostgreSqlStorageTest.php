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
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\PostgreSqlStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * PostgreSQL local-search storage coverage for audit #123.
 */
final class PostgreSqlStorageTest extends TestCase
{
    public function testBackendCreatesPostgreSqlStorage(): void
    {
        $backend = new PostgreSqlBackend();
        $method = new \ReflectionMethod($backend, 'createStorage');
        $method->setAccessible(true);

        $storage = $method->invoke($backend, 'test_postgresql_backend_storage');

        self::assertInstanceOf(PostgreSqlStorage::class, $storage);
    }

    public function testPostgreSqlStorageSourceUsesPostgreSqlSql(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/PostgreSqlStorage.php');
        self::assertIsString($source);

        self::assertStringNotContainsString('REPLACE INTO', $source);
        self::assertStringNotContainsString('INSERT IGNORE', $source);
        self::assertDoesNotMatchRegularExpression('/CAST\s*\([^)]*\s+AS\s+SIGNED\)/i', $source);
        self::assertStringNotContainsString('Duplicate entry', $source);
        self::assertStringContainsString('ON CONFLICT', $source);
        self::assertStringContainsString('CAST({{%searchmanager_search_metadata}}."metaValue" AS INTEGER)', $source);
        self::assertStringContainsString('GREATEST(CAST({{%searchmanager_search_metadata}}."metaValue" AS INTEGER) + :increment, :minimum)', $source);
    }

    public function testPostgreSqlRuntimeStorageAndSearch(): void
    {
        if (Craft::$app->getDb()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL runtime coverage requires Craft DB driver pgsql.');
        }

        $storage = new PostgreSqlStorage('test_pgsql_storage_' . uniqid());
        $storage->clearAll();

        try {
            $storage->storeDocument(1, 1001, ['protein' => 2, 'powder' => 1], 3, 'en');
            $storage->storeTermDocument('protein', 1, 1001, 2, 'en');
            $storage->storeTermDocument('powder', 1, 1001, 1, 'en');
            $storage->storeTitleTerms(1, 1001, ['protein']);
            $storage->storeElement(1, 1001, 'Protein Powder', 'entry');
            $storage->updateMetadata(1, 3, true);

            // Re-store same rows to prove PostgreSQL upserts/conflict handling.
            $storage->storeDocument(1, 1001, ['protein' => 3, 'powder' => 1], 4, 'en');
            $storage->storeTermDocument('protein', 1, 1001, 3, 'en');
            $storage->storeTitleTerms(1, 1001, ['protein']);

            self::assertSame(4, $storage->getDocumentLength(1, 1001));
            self::assertSame(['protein' => 3, 'powder' => 1], $storage->getDocumentTerms(1, 1001));
            self::assertSame(['1:1001' => 3], $storage->getTermDocuments('protein', 1));
            self::assertSame(['protein'], $storage->getTitleTerms(1, 1001));
            self::assertSame(['protein'], $storage->getTermsByPrefix('pro', 1));

            $engine = new SearchEngine($storage, 'test_pgsql_storage', ['enableStopWords' => false]);
            self::assertSame([1001], array_keys($engine->search('protein', 1)));
            self::assertSame([1001], array_keys($engine->search('pro*', 1)));

            $storage->updateMetadata(1, 99, false);
            self::assertSame(0, $storage->getTotalDocCount(1));
            self::assertSame(1, $storage->getTotalLength(1));

            $storage->deleteDocument(1, 1001);
            $storage->storeDocument(1, 1001, ['protein' => 1], 1, 'en');
            $storage->storeTermDocument('protein', 1, 1001, 1, 'en');
            $storage->storeTitleTerms(1, 1001, ['protein']);
            $storage->updateMetadata(1, 1, true);

            self::assertSame([1001], array_keys($engine->search('protein', 1)));
        } finally {
            $storage->clearAll();
        }
    }
}
