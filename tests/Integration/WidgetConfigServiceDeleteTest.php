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
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetConfigService::class)]
final class WidgetConfigServiceDeleteTest extends TestCase
{
    private string $prefix = 'sm-widget-delete-guard';
    private ?string $originalDefaultWidgetHandle = null;
    private ?object $originalWidgetConfigService = null;
    /**
     * @var array<int, bool>
     */
    private array $originalWidgetEnabledStates = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultWidgetHandle = SearchManager::$plugin->getSettings()->defaultWidgetHandle;
        $this->disableExistingWidgets();
        $this->deleteTestRows();
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        $this->restoreWidgetConfigService();
        $this->restoreExistingWidgets();

        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultWidgetHandle = $this->originalDefaultWidgetHandle;
        $settings->saveToDatabase();

        parent::tearDown();
    }

    public function testDeletingDefaultDbWidgetIsAllowedWhenConfigWidgetRemainsAvailable(): void
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
        self::assertTrue($service->delete($dbWidget));
        self::assertSame(0, $this->countWidgetConfigs($this->prefix . '-db'));
        self::assertNotSame($this->prefix . '-db', $settings->defaultWidgetHandle);
        self::assertNotNull($service->getByHandle($this->prefix . '-config'));
    }

    public function testDeletingIsBlockedWhenItWouldLeaveNoUsableWidgetConfiguration(): void
    {
        $service = $this->makeService();
        $this->installWidgetConfigService($service);
        $dbId = $this->insertWidgetConfig($this->prefix . '-only');
        SearchManager::$plugin->getSettings()->defaultWidgetHandle = $this->prefix . '-only';
        $dbWidget = $service->getById($dbId);

        self::assertNotNull($dbWidget);
        self::assertFalse($service->delete($dbWidget));
        self::assertSame(1, $this->countWidgetConfigs($this->prefix . '-only'));
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

    /**
     * @param array<string, WidgetConfig> $configWidgets
     */
    private function makeService(array $configWidgets = []): WidgetConfigService
    {
        $configWidgets = array_column($configWidgets, null, 'handle');

        return new class($configWidgets) extends WidgetConfigService {
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
