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
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetConfigService::class)]
final class WidgetConfigServiceDeleteTest extends TestCase
{
    private string $prefix = 'sm-widget-delete-guard';
    private ?object $originalWidgetConfigService = null;
    private ?object $originalConfigService = null;
    /**
     * @var array<int, bool>
     */
    private array $originalWidgetEnabledStates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installDatabaseManagedDefaults();
        $this->disableExistingWidgets();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        $this->restoreWidgetConfigService();
        $this->restoreExistingWidgets();
        $this->restoreConfigService();

        parent::tearDown();
    }

    public function testDeletingDefaultDbWidgetIsRejectedWhenConfigWidgetRemainsAvailable(): void
    {
        $service = $this->makeService([
            $this->makeConfigWidget($this->prefix . '-config', 'Config Widget'),
        ]);
        $this->installWidgetConfigService($service);
        $dbId = $this->insertWidgetConfig($this->prefix . '-db');
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $this->prefix . '-db';
        $dbWidget = $service->getById($dbId);

        self::assertNotNull($dbWidget);
        self::assertFalse($service->delete($dbWidget));
        self::assertSame(1, $this->countWidgetConfigs($this->prefix . '-db'));
        self::assertSame($this->prefix . '-db', $settings->defaultWidgetHandle);
        self::assertNotNull($service->getByHandle($this->prefix . '-config'));
    }

    public function testDeletingIsBlockedWhenItWouldLeaveNoUsableWidgetConfiguration(): void
    {
        $service = $this->makeService();
        $this->installWidgetConfigService($service);
        $dbId = $this->insertWidgetConfig($this->prefix . '-only');
        SearchManager::$plugin->getSettings()->defaultWidgetHandle = $this->prefix . '-missing-default';
        $dbWidget = $service->getById($dbId);

        self::assertNotNull($dbWidget);
        self::assertFalse($service->delete($dbWidget));
        self::assertSame(1, $this->countWidgetConfigs($this->prefix . '-only'));
    }

    public function testMutatedWidgetCannotBypassPersistedDefaultAndLastEnabledGuards(): void
    {
        $service = $this->makeService();
        $this->installWidgetConfigService($service);
        $storedHandle = $this->prefix . '-mutated-default';
        $dbId = $this->insertWidgetConfig($storedHandle);
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $storedHandle;
        $dbWidget = $service->getById($dbId);

        self::assertNotNull($dbWidget);
        $dbWidget->handle = $this->prefix . '-caller-mutated';
        $dbWidget->enabled = false;

        self::assertFalse($service->delete($dbWidget));
        $persisted = $service->getById($dbId);
        self::assertNotNull($persisted);
        self::assertSame($storedHandle, $persisted->handle);
        self::assertTrue($persisted->enabled);
        self::assertSame($storedHandle, $settings->defaultWidgetHandle);
    }

    public function testConfigWidgetsAreCountedForGuardButNotDeletedByDbDeletePath(): void
    {
        $configHandle = $this->prefix . '-config';
        $service = $this->makeService([
            $this->makeConfigWidget($configHandle, 'Config Widget'),
        ]);
        $this->installWidgetConfigService($service);
        $dbId = $this->insertWidgetConfig($this->prefix . '-db');
        $dbWidget = $service->getById($dbId);

        self::assertNotNull($dbWidget);
        self::assertTrue($service->delete($dbWidget));
        self::assertSame(0, $this->countWidgetConfigs($this->prefix . '-db'));
        self::assertNotNull($service->getByHandle($configHandle));

        $configWidget = $service->getByHandle($configHandle);
        self::assertNotNull($configWidget);
        self::assertFalse($service->delete($configWidget));
        self::assertNotNull($service->getByHandle($configHandle));
    }

    public function testDeletingNonDefaultDbWidgetSucceedsAndLeavesDefaultUnchanged(): void
    {
        $service = $this->makeService();
        $this->installWidgetConfigService($service);
        $defaultId = $this->insertWidgetConfig($this->prefix . '-default');
        $targetId = $this->insertWidgetConfig($this->prefix . '-target');
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $this->prefix . '-default';
        $target = $service->getById($targetId);

        self::assertNotNull($target);
        self::assertTrue($service->delete($target));
        self::assertSame(1, $this->countWidgetConfigs($this->prefix . '-default'));
        self::assertSame(0, $this->countWidgetConfigs($this->prefix . '-target'));
        self::assertSame($this->prefix . '-default', $settings->defaultWidgetHandle);
        self::assertNotNull($service->getById($defaultId));
    }

    /**
     * @param list<WidgetConfig> $configWidgets
     */
    private function makeService(array $configWidgets = []): WidgetConfigService
    {
        $indexedWidgets = array_column($configWidgets, null, 'handle');

        return new class($indexedWidgets) extends WidgetConfigService {
            /**
             * @param array<string, WidgetConfig> $configWidgets
             */
            public function __construct(private readonly array $configWidgets)
            {
                parent::__construct();
            }

            public function getConfigFileConfigs(): array
            {
                return $this->configWidgets;
            }
        };
    }

    private function makeConfigWidget(string $handle, string $name): WidgetConfig
    {
        $config = new WidgetConfig();
        $config->handle = $handle;
        $config->name = $name;
        $config->type = 'modal';
        $config->enabled = true;
        $config->source = 'config';
        $config->settings = WidgetConfig::defaultSettings();

        return $config;
    }

    private function insertWidgetConfig(string $handle, bool $enabled = true): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $handle,
            'name' => 'Test Widget',
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => '{}',
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function installWidgetConfigService(WidgetConfigService $service): void
    {
        if ($this->originalWidgetConfigService === null) {
            $this->originalWidgetConfigService = SearchManager::$plugin->get('widgetConfigs');
        }

        SearchManager::$plugin->set('widgetConfigs', $service);
    }

    private function restoreWidgetConfigService(): void
    {
        if ($this->originalWidgetConfigService === null) {
            return;
        }

        SearchManager::$plugin->set('widgetConfigs', $this->originalWidgetConfigService);
        $this->originalWidgetConfigService = null;
    }

    private function installDatabaseManagedDefaults(): void
    {
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set(
            'config',
            new SearchManagerConfigServiceStub($this->originalConfigService),
        );
    }

    private function restoreConfigService(): void
    {
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
            $this->originalConfigService = null;
        }
    }

    private function countWidgetConfigs(string $handle): int
    {
        return (int)Craft::$app->getDb()
            ->createCommand('SELECT COUNT(*) FROM {{%searchmanager_widget_configs}} WHERE [[handle]] = :handle', [
                'handle' => $handle,
            ])
            ->queryScalar();
    }

    private function deleteTestRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', $this->prefix . '%', false])
            ->execute();
    }

    private function disableExistingWidgets(): void
    {
        $rows = (new \craft\db\Query())
            ->select(['id', 'enabled'])
            ->from('{{%searchmanager_widget_configs}}')
            ->where(['not like', 'handle', $this->prefix . '%', false])
            ->all();

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $this->originalWidgetEnabledStates[$id] = (bool)$row['enabled'];
        }

        if ($this->originalWidgetEnabledStates === []) {
            return;
        }

        Craft::$app->getDb()
            ->createCommand()
            ->update('{{%searchmanager_widget_configs}}', ['enabled' => 0], ['id' => array_keys($this->originalWidgetEnabledStates)])
            ->execute();
    }

    private function restoreExistingWidgets(): void
    {
        foreach ($this->originalWidgetEnabledStates as $id => $enabled) {
            Craft::$app->getDb()
                ->createCommand()
                ->update('{{%searchmanager_widget_configs}}', ['enabled' => $enabled ? 1 : 0], ['id' => $id])
                ->execute();
        }

        $this->originalWidgetEnabledStates = [];
    }
}
