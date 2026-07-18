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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\console\controllers\MaintenanceController;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins maintenance storage clear table coverage.
 *
 * @since 5.53.0
 */
final class MaintenanceStorageClearTest extends TestCase
{
    private const OTHER_PREFIX = 'otherenv_';
    private const LIVE_DB_HANDLE = 'audit334-live-db';
    private const LIVE_CONFIG_HANDLE = 'audit334-live-config';
    private const ORPHAN_HANDLE = 'audit334-orphan';
    private const DRY_RUN_HANDLE = 'audit334-dry-run';
    private const OTHER_FULL_HANDLE = self::OTHER_PREFIX . 'audit334-orphan';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeAuditRows();
    }

    protected function tearDown(): void
    {
        $this->purgeAuditRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();

        parent::tearDown();
    }

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

        $maintenanceTables = self::tablesInMethod($maintenanceSource, 'databaseStorageTables');
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
            // [[...]]-bracketed so the identifier keeps its case on PostgreSQL.
            self::assertStringContainsString('SELECT [[indexHandle]] FROM {{%searchmanager_search_compounds}}', $methodSource, $label);
        }

        self::assertStringContainsString("'totalRows' => \$documentRows + \$termRows + \$compoundRows", self::methodSource($utilitiesSource, 'getDatabaseStats'));
    }

    public function testOrphanedStoragePurgeKeepsOtherPrefixesAndLiveHandles(): void
    {
        $this->withConfigFileIndices([
            self::LIVE_CONFIG_HANDLE => [
                'name' => 'Audit 334 Config Live',
                'elementType' => Entry::class,
                'enabled' => true,
            ],
        ]);
        $this->saveDatabaseIndex(self::LIVE_DB_HANDLE);

        $orphanFullHandle = $this->fullHandle(self::ORPHAN_HANDLE);
        $liveDbFullHandle = $this->fullHandle(self::LIVE_DB_HANDLE);
        $liveConfigFullHandle = $this->fullHandle(self::LIVE_CONFIG_HANDLE);

        foreach ([$orphanFullHandle, self::OTHER_FULL_HANDLE, $liveDbFullHandle, $liveConfigFullHandle] as $handle) {
            $this->insertDocumentRow($handle);
        }

        $controller = new MaintenanceController('maintenance', SearchManager::$plugin);
        $orphans = $this->invokePrivate($controller, 'getOrphanedStorageHandlesByType', ['database']);

        self::assertContains($orphanFullHandle, $orphans);
        self::assertNotContains(self::OTHER_FULL_HANDLE, $orphans, 'Rows under a different environment prefix must never be candidates.');
        self::assertNotContains($liveDbFullHandle, $orphans, 'Live database-source index storage must survive.');
        self::assertNotContains($liveConfigFullHandle, $orphans, 'Live config-source index storage must survive.');

        $this->invokePrivate($controller, 'clearOrphanedStorageHandle', ['database', $orphanFullHandle]);

        self::assertSame(0, $this->documentRowsForHandle($orphanFullHandle));
        self::assertSame(1, $this->documentRowsForHandle(self::OTHER_FULL_HANDLE));
        self::assertSame(1, $this->documentRowsForHandle($liveDbFullHandle));
        self::assertSame(1, $this->documentRowsForHandle($liveConfigFullHandle));
    }

    public function testOrphanedStorageDryRunDeletesNothing(): void
    {
        $dryRunFullHandle = $this->fullHandle(self::DRY_RUN_HANDLE);
        $this->insertDocumentRow($dryRunFullHandle);

        $controller = new MaintenanceController('maintenance', SearchManager::$plugin);
        $controller->type = 'database';
        $controller->dryRun = true;

        self::assertSame(0, $controller->actionPurgeOrphanedStorage());
        self::assertSame(1, $this->documentRowsForHandle($dryRunFullHandle));
    }

    public function testOrphanedStorageCommandUsesStorageClearSurfacesForAllDrivers(): void
    {
        $maintenanceSource = file_get_contents(dirname(__DIR__, 2) . '/src/console/controllers/MaintenanceController.php');
        self::assertIsString($maintenanceSource);

        $clearBody = self::methodSource($maintenanceSource, 'clearOrphanedStorageHandle');
        self::assertStringContainsString('createDatabaseStorage($fullIndexHandle)->clearAll()', $clearBody);
        self::assertStringContainsString('new RedisStorage($fullIndexHandle, $target[\'settings\'])', $clearBody);
        self::assertStringContainsString('new FileStorage($fullIndexHandle, $target[\'configuredPath\'])', $clearBody);
        self::assertSame(3, substr_count($clearBody, '->clearAll();'));
        self::assertStringNotContainsString('createCommand()->delete', $clearBody);

        $databaseStorageBody = self::methodSource($maintenanceSource, 'createDatabaseStorage');
        self::assertStringContainsString('new PostgreSqlStorage($fullIndexHandle)', $databaseStorageBody);
        self::assertStringContainsString('new MySqlStorage($fullIndexHandle)', $databaseStorageBody);
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

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }

    private function saveDatabaseIndex(string $handle): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Audit 334 ' . $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => json_encode([2, 3], JSON_THROW_ON_ERROR),
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
    }

    private function insertDocumentRow(string $indexHandle): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_search_documents}}', [
            'indexHandle' => $indexHandle,
            'siteId' => 1,
            'elementId' => abs((int)crc32($indexHandle)) % 1000000,
            'documentKey' => $indexHandle . ':1',
            'term' => '_length',
            'frequency' => 1,
            'language' => 'en',
        ])->execute();
    }

    private function documentRowsForHandle(string $indexHandle): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_search_documents}}')
            ->where(['indexHandle' => $indexHandle])
            ->count();
    }

    private function fullHandle(string $handle): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($handle);
    }

    /**
     * @param array<string, mixed> $indices
     */
    private function withConfigFileIndices(array $indices): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = ['indices' => $indices];
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    private function configCache(): mixed
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);

        return $property->getValue();
    }

    private function setConfigCache(mixed $cache): void
    {
        $reflection = new \ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }

    private function purgeAuditRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_search_documents}}', ['or',
                ['like', 'indexHandle', $this->fullHandle('audit334') . '%', false],
                ['like', 'indexHandle', self::OTHER_PREFIX . '%', false],
            ])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::LIVE_DB_HANDLE])
            ->execute();
        SearchIndex::clearCache();
    }
}
