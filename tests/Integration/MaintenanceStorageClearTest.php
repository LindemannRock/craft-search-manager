<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins maintenance storage clear table coverage.
 *
 * @since 5.53.0
 */
final class MaintenanceStorageClearTest extends TestCase
{
    public function testConsoleDatabaseClearCoversStorageLayerTables(): void
    {
        $maintenanceSource = file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/MaintenanceController.php');
        $mysqlSource = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/MySqlStorage.php');
        $postgresSource = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/PostgreSqlStorage.php');
        self::assertIsString($maintenanceSource);
        self::assertIsString($mysqlSource);
        self::assertIsString($postgresSource);

        $maintenanceTables = self::tablesInMethod($maintenanceSource, 'clearDatabaseStorage');
        $mysqlTables = self::tablesInMethod($mysqlSource, 'clearAll');
        $postgresTables = self::tablesInMethod($postgresSource, 'clearAll');

        self::assertContains('{{%searchmanager_search_compounds}}', $maintenanceTables);
        self::assertSame($mysqlTables, $maintenanceTables);
        self::assertSame($postgresTables, $maintenanceTables);
    }

    /**
     * @return list<string>
     */
    private static function tablesInMethod(string $source, string $method): array
    {
        preg_match('/function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $methodMatches);
        self::assertNotEmpty($methodMatches, $method . ' source should be found.');

        preg_match_all('/\'(\{\{%searchmanager_search_[^\']+\}\})\'/', $methodMatches[0], $tableMatches);
        $tables = array_values(array_unique($tableMatches[1] ?? []));
        sort($tables, SORT_STRING);

        return $tables;
    }
}
