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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DeviceDetectionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;

/**
 * @since 5.53.0
 */
#[CoversClass(SearchIndex::class)]
#[CoversClass(DeviceDetectionService::class)]
final class SettingsCacheInvalidationOrderTest extends TestCase
{
    private const PREFIX = 'audit-housekeeping';
    private const CONFIG_BACKEND = self::PREFIX . '-config-backend';

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

    public function testSettingsSaveClearsDeviceDetectionCacheAfterExistingCacheClears(): void
    {
        $settingsController = $this->readPluginFile('src/controllers/SettingsController.php');
        $deviceService = $this->readPluginFile('src/services/DeviceDetectionService.php');

        $body = $this->methodBody($settingsController, 'actionSave');
        $searchClear = strpos($body, 'SearchManager::$plugin->backend->clearAllSearchCache();');
        $autocompleteClear = strpos($body, 'SearchManager::$plugin->autocomplete->clearCache();');
        $deviceClear = strpos($body, 'SearchManager::$plugin->deviceDetection->clearCache();');

        self::assertIsInt($searchClear);
        self::assertIsInt($autocompleteClear);
        self::assertIsInt($deviceClear);
        self::assertLessThan($deviceClear, $autocompleteClear);
        self::assertStringContainsString("clearTrackedRedisKeys(SearchManager::\$plugin->id, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCachePath(SearchManager::\$plugin, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCacheKeyPrefix(SearchManager::\$plugin->id, 'device')", $deviceService);
        self::assertStringContainsString("PluginHelper::getCacheKeySet(SearchManager::\$plugin->id, 'device')", $deviceService);
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

    private function methodBody(string $source, string $method, string $visibility = 'public'): string
    {
        preg_match('/' . $visibility . ' function ' . preg_quote($method, '/') . '\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, $method . ' source should be found.');

        return $matches[0];
    }
}
