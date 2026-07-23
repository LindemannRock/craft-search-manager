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
final class AuditFindings447452RegressionTest extends TestCase
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

    public function testGraphqlMultiSiteMergeKeepsFirstRedirectInScopeOrder(): void
    {
        $stub = $this->installStubBackend();
        $stub->searchResponsesBySiteId = [
            20 => ['hits' => [], 'total' => 0, 'redirect' => '/first-site'],
            10 => ['hits' => [], 'total' => 0, 'redirect' => '/second-site'],
        ];

        $method = new \ReflectionMethod(SearchResolver::class, 'runSearch');
        $method->setAccessible(true);
        $result = $method->invoke(null, [self::DATABASE_INDEX_HANDLE], 'redirect me', [
            'limit' => 20,
            'offset' => 0,
        ], [20, 10]);

        self::assertIsArray($result);
        self::assertSame('/first-site', $result['redirect']);
        self::assertCount(2, $stub->callsFor('search'));
    }

    public function testConsoleIndexClearMatchesControlPanelSideEffects(): void
    {
        $index = SearchIndex::findByHandle(self::DATABASE_INDEX_HANDLE);
        self::assertNotNull($index);

        $backend = $this->installStubBackend();
        $autocomplete = new AuditFindings447452RecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $method = new \ReflectionMethod(IndexController::class, 'clearIndex');
        $method->setAccessible(true);
        $method->invoke(new IndexController('index', SearchManager::$plugin), $index);

        self::assertCount(1, $backend->callsFor('clearIndex'));
        self::assertCount(1, $backend->callsFor('clearSearchCache'));
        self::assertSame([self::DATABASE_INDEX_HANDLE], $autocomplete->clearCacheCalls);
        self::assertSame(0, (int)$this->fetchRow('{{%searchmanager_indices}}', [
            'handle' => self::DATABASE_INDEX_HANDLE,
        ])['documentCount']);
    }

    public function testConsoleStorageResetMatchesControlPanelCountReset(): void
    {
        $controller = new MaintenanceController('maintenance', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, 'resetIndexDocumentCounts');
        $method->setAccessible(true);
        $method->invoke($controller, 'database');

        self::assertSame(0, (int)$this->fetchRow('{{%searchmanager_indices}}', [
            'handle' => self::DATABASE_INDEX_HANDLE,
        ])['documentCount']);
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

    public function testFieldSweepGapBindingsHaveInlineErrors(): void
    {
        $inventories = [
            'src/templates/settings/general.twig' => [
                "settings.getErrors('requireApiKey')",
            ],
            'src/templates/settings/indexing.twig' => [
                "settings.getErrors('autoIndex')",
            ],
            'src/templates/settings/analytics.twig' => [
                "settings.getErrors('enableAnalytics')",
                "settings.getErrors('enableGeoDetection')",
                "settings.getErrors('anonymizeIpAddress')",
            ],
            'src/templates/settings/cache.twig' => [
                "settings.getErrors('enableCache')",
                "settings.getErrors('enableAutocompleteCache')",
                "settings.getErrors('clearCacheOnSave')",
                "settings.getErrors('enableCacheWarming')",
                "settings.getErrors('cacheDeviceDetection')",
            ],
            'src/templates/settings/highlighting.twig' => [
                "settings.getErrors('highlightResultsEnabled')",
            ],
            'src/templates/settings/autocomplete.twig' => [
                "settings.getErrors('enableAutocomplete')",
            ],
            'src/templates/settings/search.twig' => [
                "settings.getErrors('enableFuzzy')",
                "settings.getErrors('replaceNativeSearch')",
            ],
            'src/templates/settings/language.twig' => [
                "settings.getErrors('enableStopWords')",
            ],
            'src/templates/widgets/edit.twig' => [
                "widgetConfig.getErrors('enabled')",
            ],
            'src/templates/widgets/_partials/recently-viewed.twig' => [
                "widgetConfig.getErrors('settings.behavior.recentlyViewedEnabled')",
            ],
            'src/templates/widgets/_partials/snippets.twig' => [
                "widgetConfig.getErrors('settings.behavior.snippetIncludeCodeBlocks')",
                "widgetConfig.getErrors('settings.behavior.snippetCleanMarkdown')",
            ],
            'src/templates/widgets/_partials/modal-trigger.twig' => [
                "widgetConfig.getErrors('settings.behavior.modalPreventBodyScroll')",
                "widgetConfig.getErrors('settings.behavior.loadingIndicatorEnabled')",
                "widgetConfig.getErrors('settings.trigger.triggerEnabled')",
            ],
            'src/templates/widgets/_partials/destination-highlighting.twig' => [
                "widgetConfig.getErrors('settings.behavior.highlightDestinationEnabled')",
                "widgetConfig.getErrors('settings.behavior.highlightDestinationPersistQuery')",
            ],
            'src/templates/widgets/_partials/results.twig' => [
                "widgetConfig.getErrors('settings.behavior.resultsRequireUrl')",
                "widgetConfig.getErrors('settings.behavior.resultsGroupingEnabled')",
            ],
            'src/templates/widgets/styles/edit.twig' => [
                "widgetStyle.getErrors('enabled')",
            ],
            'src/templates/widgets/styles/_partials/modal.twig' => [
                "widgetStyle.getErrors('styles.backdropBlur')",
            ],
            'src/templates/widgets/styles/_partials/results.twig' => [
                "widgetStyle.getErrors('styles.highlightTag')",
                "widgetStyle.getErrors('styles.highlightClass')",
            ],
        ];

        foreach ($inventories as $relativePath => $errorBindings) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
            self::assertIsString($source, $relativePath);

            foreach ($errorBindings as $errorBinding) {
                self::assertStringContainsString($errorBinding, $source, $relativePath);
            }
        }
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

/**
 * @since 5.54.0
 */
final class AuditFindings447452RecordingAutocompleteService extends AutocompleteService
{
    /** @var list<string|null> */
    public array $clearCacheCalls = [];

    /** @inheritdoc */
    public function clearCache(?string $indexHandle = null): void
    {
        $this->clearCacheCalls[] = $indexHandle;
    }
}
