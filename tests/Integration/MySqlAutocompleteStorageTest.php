<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for MySQL autocomplete storage performance.
 */
final class MySqlAutocompleteStorageTest extends TestCase
{
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
        self::assertStringContainsString("->groupBy(['suggestion'])", $source);
        self::assertStringContainsString("'normalizedSuggestion'", $source);
    }
}
