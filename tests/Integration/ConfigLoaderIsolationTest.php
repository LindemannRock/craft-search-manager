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
final class ConfigLoaderIsolationTest extends TestCase
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

    public function testBadConfigWidgetDoesNotBlockGoodWidgetOrFrontendRender(): void
    {
        $this->withConfigSections([
            'widgets' => [
                'bad-widget' => [
                    'name' => 'Bad Widget',
                    'type' => 'inline',
                ],
                'good-widget' => [
                    'name' => 'Good Widget',
                    'type' => 'modal',
                    'settings' => [
                        'search' => ['placeholder' => 'Batch 8 working widget'],
                    ],
                ],
            ],
        ]);

        $service = new WidgetConfigService();
        $this->swapPluginComponent('search-manager', 'widgetConfigs', $service);

        self::assertNull($service->getConfigFileByHandle('bad-widget'));
        self::assertSame('Good Widget', $service->getConfigFileByHandle('good-widget')?->name);

        $html = Craft::$app->getView()->renderTemplate('search-manager/_widget/search-modal', [
            'configHandle' => 'good-widget',
        ]);

        self::assertStringContainsString('<search-modal', $html);
        self::assertStringContainsString('placeholder="Batch 8 working widget"', $html);
    }

    public function testSiblingConfigLoadersKeepValidItemsAfterMalformedItems(): void
    {
        $this->withConfigSections([
            'widgetStyles' => [
                'bad-style' => ['name' => ['not', 'a', 'string']],
                'good-style' => ['name' => 'Good Style', 'type' => 'modal'],
            ],
            'backends' => [
                'bad-backend' => ['name' => ['not', 'a', 'string']],
                'good-backend' => [
                    'name' => 'Good Backend',
                    'backendType' => 'mysql',
                    'settings' => [],
                ],
            ],
        ]);

        $styles = (new WidgetStyleService())->getConfigFileStyles();
        self::assertArrayNotHasKey('bad-style', $styles);
        self::assertSame('Good Style', $styles['good-style']->name);

        $backends = ConfiguredBackend::findAllFromConfig();
        self::assertSame(['good-backend'], array_column($backends, 'handle'));
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
