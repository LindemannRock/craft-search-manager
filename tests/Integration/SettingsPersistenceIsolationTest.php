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
final class SettingsPersistenceIsolationTest extends TestCase
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

    public function testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase(): void
    {
        self::$settingsRowBeforeSave = $this->fetchSettingsRow();
        self::assertNotNull(self::$settingsRowBeforeSave);

        $settings = SearchManager::$plugin->getSettings();
        $settings->pluginName = 'Search Manager ' . self::PREFIX;
        $settings->defaultWidgetHandle = null;

        self::assertTrue($settings->saveToDatabase(['pluginName', 'defaultWidgetHandle']));
        self::assertNotSame(self::$settingsRowBeforeSave, $this->fetchSettingsRow());
    }

    #[Depends('testSettingsRowSnapshotRestoresByteIdenticallyAfterSaveToDatabase')]
    public function testSettingsRowIsByteIdenticalAfterPreviousTestTeardown(): void
    {
        self::assertNotNull(self::$settingsRowBeforeSave);
        self::assertSame(self::$settingsRowBeforeSave, $this->fetchSettingsRow());

        $widgetTest = $this->methodBody(
            $this->readPluginFile('tests/Integration/WidgetConfigServiceDeleteTest.php'),
            'tearDown',
            'protected',
        );
        $duplicateTest = $this->methodBody(
            $this->readPluginFile('tests/Integration/DuplicateCpObjectsTest.php'),
            'tearDown',
            'protected',
        );

        self::assertStringNotContainsString('saveToDatabase', $widgetTest);
        self::assertStringNotContainsString('saveToDatabase', $duplicateTest);
    }

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
