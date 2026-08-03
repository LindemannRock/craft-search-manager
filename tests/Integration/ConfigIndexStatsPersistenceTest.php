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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\console\controllers\IndexController;
use lindemannrock\searchmanager\console\controllers\MaintenanceController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\services\WidgetStyleService;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit findings #447-#452.
 *
 * @since 5.54.0
 */
final class ConfigIndexStatsPersistenceTest extends TestCase
{
    private const DATABASE_INDEX_HANDLE = '__sm_audit_batch8_database';
    private const CONFIG_INDEX_HANDLE = '__sm_audit_batch8_config';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseIndex();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();

        parent::tearDown();
    }

    public function testConfigIndexStatsUpsertUsesCompleteConfigPersistenceColumns(): void
    {
        $this->withConfigSections([
            'indices' => [
                self::CONFIG_INDEX_HANDLE => [
                    'name' => 'Batch 8 Config Index',
                    'elementType' => Entry::class,
                    'backend' => 'secondary-mysql',
                    'enableAnalytics' => false,
                    'skipEntriesWithoutUrl' => true,
                    'splitSections' => true,
                    'enabled' => true,
                ],
            ],
        ]);

        $index = new SearchIndex();
        $index->handle = self::CONFIG_INDEX_HANDLE;
        $index->name = 'Stale Name';
        $index->elementType = Entry::class;
        $index->source = 'config';

        self::assertTrue($index->updateStats(7));

        $row = $this->fetchRow('{{%searchmanager_indices}}', ['handle' => self::CONFIG_INDEX_HANDLE]);
        self::assertNotNull($row);
        self::assertSame('secondary-mysql', $row['backend']);
        self::assertSame(0, (int)$row['enableAnalytics']);
        self::assertSame(1, (int)$row['skipEntriesWithoutUrl']);
        self::assertSame(1, (int)$row['splitSections']);
        self::assertSame(7, (int)$row['documentCount']);
    }

    /**
     * @param array<string, mixed> $sections
     */
    private function withConfigSections(array $sections): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }

        $pluginConfig = $cache['search-manager'] ?? [];
        $cache['search-manager'] = array_merge(is_array($pluginConfig) ? $pluginConfig : [], $sections);
        $this->setConfigCache($cache);
        BaseConfigFileHelper::clearCache('search-manager');

        // Restore the injected cache after clearCache() invalidates it.
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    private function insertDatabaseIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 8 Database Index',
            'handle' => self::DATABASE_INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 9,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => [
                self::DATABASE_INDEX_HANDLE,
                self::CONFIG_INDEX_HANDLE,
            ]])
            ->execute();
        SearchIndex::clearCache();
    }
}
