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
use lindemannrock\searchmanager\search\QueryParser;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\PostgreSqlStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for MySQL autocomplete storage performance.
 */
final class MySqlAutocompleteStorageTest extends TestCase
{
    private const INDEX_HANDLE = '__sm_batch10_autocomplete';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeTerms();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeTerms();
        } finally {
            parent::tearDown();
        }
    }

    public function testAutocompleteDoesNotRunDiagnosticDistinctIndexHandleScan(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/MySqlStorage.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('Existing indexHandles in DB', $source);
        self::assertStringNotContainsString("->distinct()\n            ->column()", $source);
    }

    public function testMySqlStorageDefinesGroupedCompoundAutocompleteLookup(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/MySqlStorage.php');

        self::assertIsString($source);
        self::assertStringContainsString('storeCompoundSuggestions', $source);
        self::assertStringContainsString('deleteCompoundSuggestions', $source);
        self::assertStringContainsString('getCompoundSuggestionsForAutocomplete', $source);
        self::assertStringContainsString('{{%searchmanager_search_compounds}}', $source);
        self::assertStringContainsString('->upsert(', $source);
        self::assertStringNotContainsString("->batchInsert(\n                '{{%searchmanager_search_compounds}}'", $source);
        self::assertStringContainsString("->groupBy(['normalizedSuggestion', 'suggestion'])", $source);
        self::assertStringContainsString("'normalizedSuggestion'", $source);
    }

    public function testSqlAutocompleteLanguageFilterIsIdenticalAcrossBothStorageClasses(): void
    {
        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%searchmanager_search_terms}}',
            ['indexHandle', 'term', 'siteId', 'elementId', 'documentKey', 'frequency', 'language'],
            [
                [self::INDEX_HANDLE, 'protein', 1, 101, '101_1', 3, 'en'],
                [self::INDEX_HANDLE, 'protein', 1, 104, '104_1', 1, 'en'],
                [self::INDEX_HANDLE, 'product', 1, 102, '102_1', 2, 'ar'],
                [self::INDEX_HANDLE, 'profile', 1, 103, '103_1', 1, 'en'],
            ],
        )->execute();

        $expectedLanguageTerms = ['protein' => 4, 'profile' => 1];
        $expectedArabicTerms = ['product' => 2];
        $expectedAllTerms = ['protein' => 4, 'product' => 2, 'profile' => 1];

        self::assertSame(
            $expectedLanguageTerms,
            (new MySqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, 'en', 10, 'pro'),
        );
        self::assertSame(
            $expectedLanguageTerms,
            (new PostgreSqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, 'en', 10, 'pro'),
        );
        self::assertSame(
            $expectedArabicTerms,
            (new MySqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, 'ar', 10, 'pro'),
        );
        self::assertSame(
            $expectedArabicTerms,
            (new PostgreSqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, 'ar', 10, 'pro'),
        );
        self::assertSame(
            $expectedAllTerms,
            (new MySqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, null, 10, 'pro'),
        );
        self::assertSame(
            $expectedAllTerms,
            (new PostgreSqlStorage(self::INDEX_HANDLE))->getTermsForAutocomplete(1, null, 10, 'pro'),
        );
    }

    public function testSqlPrefixStoresAcceptZeroIdentically(): void
    {
        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%searchmanager_search_terms}}',
            ['indexHandle', 'term', 'siteId', 'elementId', 'documentKey', 'frequency', 'language'],
            [
                [self::INDEX_HANDLE, '0', 1, 101, '101_1', 3, 'en'],
                [self::INDEX_HANDLE, '00', 1, 102, '102_1', 2, 'en'],
                [self::INDEX_HANDLE, '10', 1, 103, '103_1', 1, 'en'],
            ],
        )->execute();

        self::assertSame(
            ['0', '00'],
            (new MySqlStorage(self::INDEX_HANDLE))->getTermsByPrefix('0', 1),
        );
        self::assertSame(
            ['0', '00'],
            (new PostgreSqlStorage(self::INDEX_HANDLE))->getTermsByPrefix('0', 1),
        );
    }

    public function testSqlPrefixStoresReceiveCanonicalAccentedWildcardPrefixIdentically(): void
    {
        Craft::$app->getDb()->createCommand()->batchInsert(
            '{{%searchmanager_search_terms}}',
            ['indexHandle', 'term', 'siteId', 'elementId', 'documentKey', 'frequency', 'language'],
            [
                [self::INDEX_HANDLE, 'uber', 1, 101, '101_1', 3, 'de'],
                [self::INDEX_HANDLE, 'ubiquitous', 1, 102, '102_1', 2, 'en'],
            ],
        )->execute();
        $prefix = QueryParser::parse('ÜB*')->wildcards[0];

        $mySqlTerms = (new MySqlStorage(self::INDEX_HANDLE))->getTermsByPrefix($prefix, 1);
        $postgreSqlTerms = (new PostgreSqlStorage(self::INDEX_HANDLE))->getTermsByPrefix($prefix, 1);
        sort($mySqlTerms, SORT_STRING);
        sort($postgreSqlTerms, SORT_STRING);

        self::assertSame(['uber', 'ubiquitous'], $mySqlTerms);
        self::assertSame(['uber', 'ubiquitous'], $postgreSqlTerms);
    }

    private function purgeTerms(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_search_terms}}', ['indexHandle' => self::INDEX_HANDLE])
            ->execute();
    }
}
