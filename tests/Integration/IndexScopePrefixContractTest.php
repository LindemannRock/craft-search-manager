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
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

/**
 * @since 5.53.0
 */
#[CoversClass(SearchIndex::class)]
#[CoversClass(DeviceDetectionService::class)]
final class IndexScopePrefixContractTest extends TestCase
{
    private const PREFIX = 'audit-housekeeping';
    private const CONFIG_BACKEND = self::PREFIX . '-config-backend';

    private static ?array $settingsRowBeforeSave = null;

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertBackend(self::CONFIG_BACKEND, 'file');
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testManualPrefixConcatsUseSettingsFullIndexNameEquivalently(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->indexPrefix = self::PREFIX . '_';

        self::assertSame(
            ($settings->indexPrefix ?? '') . 'sample',
            $settings->getFullIndexName('sample'),
        );

        $dependencyService = $this->readPluginFile('src/services/DependencyService.php');
        $autocompleteService = $this->readPluginFile('src/services/AutocompleteService.php');

        self::assertStringContainsString("getSettings()->getFullIndexName(\$index->handle)", $dependencyService);
        self::assertStringNotContainsString('$prefix . $index->handle', $dependencyService);
        self::assertStringContainsString('$fullIndexHandle = $settings->getFullIndexName($indexHandle);', $autocompleteService);
        self::assertStringNotContainsString('$indexPrefix . $indexHandle', $autocompleteService);
    }

    #[Depends('testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase')]
    private function insertBackend(string $handle, string $backendType): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Audit Housekeeping ' . $handle,
            'handle' => $handle,
            'backendType' => $backendType,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_search_documents}}', ['like', 'indexHandle', $this->fullHandle(self::PREFIX), false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::PREFIX])
            ->execute();
        SearchIndex::clearCache();
    }

    private function fullHandle(string $handle): string
    {
        return SearchManager::$plugin->getSettings()->getFullIndexName($handle);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
