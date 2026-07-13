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
    public function testDatabaseClearSurfacesCoverStorageLayerTables(): void
    {
        $maintenanceSource = file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/MaintenanceController.php');
        $utilitiesSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/UtilitiesController.php');
        $mysqlSource = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/MySqlStorage.php');
        $postgresSource = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/PostgreSqlStorage.php');
        self::assertIsString($maintenanceSource);
        self::assertIsString($utilitiesSource);
        self::assertIsString($mysqlSource);
        self::assertIsString($postgresSource);

        $maintenanceTables = self::tablesInMethod($maintenanceSource, 'clearDatabaseStorage');
        $utilitiesTables = self::tablesInMethod($utilitiesSource, 'clearDatabaseStorage');
        $mysqlTables = self::tablesInMethod($mysqlSource, 'clearAll');
        $postgresTables = self::tablesInMethod($postgresSource, 'clearAll');

        self::assertContains('{{%searchmanager_search_compounds}}', $maintenanceTables);
        self::assertContains('{{%searchmanager_search_compounds}}', $utilitiesTables);
        self::assertSame($mysqlTables, $maintenanceTables);
        self::assertSame($mysqlTables, $utilitiesTables);
        self::assertSame($postgresTables, $maintenanceTables);
        self::assertSame($postgresTables, $utilitiesTables);
    }

    public function testDatabaseStatsSurfacesCountCompounds(): void
    {
        $maintenanceSource = file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/MaintenanceController.php');
        $utilitiesSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/UtilitiesController.php');
        self::assertIsString($maintenanceSource);
        self::assertIsString($utilitiesSource);

        foreach ([
            'console maintenance' => $maintenanceSource,
            'CP utilities' => $utilitiesSource,
        ] as $label => $source) {
            $methodSource = self::methodSource($source, 'getDatabaseStats');

            self::assertStringContainsString('SELECT COUNT(*) FROM {{%searchmanager_search_compounds}}', $methodSource, $label);
            self::assertStringContainsString("'compoundRows' => \$compoundRows", $methodSource, $label);
            self::assertStringContainsString('SELECT indexHandle FROM {{%searchmanager_search_compounds}}', $methodSource, $label);
        }

        self::assertStringContainsString("'totalRows' => \$documentRows + \$termRows + \$compoundRows", self::methodSource($utilitiesSource, 'getDatabaseStats'));
    }

    /**
     * @return list<string>
     */
    private static function tablesInMethod(string $source, string $method): array
    {
        $methodSource = self::methodSource($source, $method);

        preg_match_all('/\'(\{\{%searchmanager_search_[^\']+\}\})\'/', $methodSource, $tableMatches);
        $tables = array_values(array_unique($tableMatches[1] ?? []));
        sort($tables, SORT_STRING);

        return $tables;
    }

    private static function methodSource(string $source, string $method): string
    {
        preg_match('/function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $methodMatches);
        self::assertNotEmpty($methodMatches, $method . ' source should be found.');

        return $methodMatches[0];
    }
}
