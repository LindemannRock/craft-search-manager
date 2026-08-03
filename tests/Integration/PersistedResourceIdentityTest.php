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
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\WidgetConfigService;
use lindemannrock\searchmanager\services\WidgetStyleService;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Model;

/**
 * Stored-identity and stable-handle regressions for A4 Fix Session 1.
 *
 * @since 5.54.0
 */
#[CoversClass(ConfiguredBackend::class)]
#[CoversClass(ApiKey::class)]
#[CoversClass(QueryRule::class)]
#[CoversClass(Promotion::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(WidgetConfigService::class)]
#[CoversClass(WidgetStyleService::class)]
final class PersistedResourceIdentityTest extends TestCase
{
    private const PREFIX = 'sm-a4-identity';

    private mixed $originalConfigCache = null;
    private ?object $originalConfigService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set('config', new SearchManagerConfigServiceStub($this->originalConfigService));
        $this->originalConfigCache = $this->configCache();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->purgeMarkedRows();
        $this->setSearchManagerConfig([]);
    }

    protected function tearDown(): void
    {
        $this->purgeMarkedRows();
        $this->setConfigCache($this->originalConfigCache);
        $this->resetServiceCaches();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function persistenceFamilies(): iterable
    {
        yield 'configured backend' => ['backend'];
        yield 'API key' => ['apiKey'];
        yield 'query rule' => ['queryRule'];
        yield 'promotion' => ['promotion'];
        yield 'search index' => ['index'];
        yield 'widget config' => ['widgetConfig'];
        yield 'widget style' => ['widgetStyle'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uniqueHandleFamilies(): iterable
    {
        yield 'configured backend' => ['backend'];
        yield 'API key' => ['apiKey'];
        yield 'search index' => ['index'];
        yield 'widget config' => ['widgetConfig'];
        yield 'widget style' => ['widgetStyle'];
    }

    #[DataProvider('persistenceFamilies')]
    public function testMissingStoredRowsCannotReportSaveSuccess(string $family): void
    {
        [$table, $id, $save] = $this->createLoadedSaveCase($family);

        if ($family === 'index') {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $id])
                ->execute();
        }
        Craft::$app->getDb()->createCommand()->delete($table, ['id' => $id])->execute();

        self::assertFalse($save(), $family . ' reported success after its stored row was deleted.');
        self::assertSame(0, $this->countRows($table, ['id' => $id]));
    }

    public function testReferencedBackendHandleCannotBeRenamed(): void
    {
        $backendId = $this->insertBackend('referenced-backend', 'Referenced Backend');
        $this->insertIndex('backend-consumer', 'Backend Consumer', self::PREFIX . '-referenced-backend');
        $backend = ConfiguredBackend::findById($backendId);

        self::assertNotNull($backend);
        $backend->handle = self::PREFIX . '-renamed-backend';

        self::assertFalse($backend->save());
        self::assertSame(
            self::PREFIX . '-referenced-backend',
            $this->storedValue('{{%searchmanager_backends}}', $backendId, 'handle'),
        );
        self::assertSame(
            self::PREFIX . '-referenced-backend',
            $this->storedValue(
                '{{%searchmanager_indices}}',
                $this->markedId('{{%searchmanager_indices}}', 'backend-consumer'),
                'backend',
            ),
        );
    }

    public function testActiveDefaultBackendHandleCannotBeRenamedByDirectModelSave(): void
    {
        $backendId = $this->insertBackend('default-backend', 'Default Backend');
        $this->setDefault('backend', self::PREFIX . '-default-backend');
        $backend = ConfiguredBackend::findById($backendId);

        self::assertNotNull($backend);
        self::assertSame(self::PREFIX . '-default-backend', SearchManager::$plugin->getSettings()->defaultBackendHandle);
        $backend->handle = self::PREFIX . '-renamed-default-backend';

        self::assertFalse($backend->save());
        self::assertSame(self::PREFIX . '-default-backend', SearchManager::$plugin->getSettings()->defaultBackendHandle);
        self::assertSame(
            self::PREFIX . '-default-backend',
            $this->storedValue('{{%searchmanager_backends}}', $backendId, 'handle'),
        );
    }

    public function testActiveDefaultWidgetHandleCannotBeRenamedByDirectServiceSave(): void
    {
        $widgetId = $this->insertWidget('default-widget', 'Default Widget');
        $this->setDefault('widget', self::PREFIX . '-default-widget');
        $widget = SearchManager::$plugin->widgetConfigs->getById($widgetId);

        self::assertNotNull($widget);
        self::assertSame(self::PREFIX . '-default-widget', SearchManager::$plugin->getSettings()->defaultWidgetHandle);
        $widget->handle = self::PREFIX . '-renamed-default-widget';

        self::assertFalse(SearchManager::$plugin->widgetConfigs->save($widget));
        self::assertSame(self::PREFIX . '-default-widget', SearchManager::$plugin->getSettings()->defaultWidgetHandle);
        self::assertSame(
            self::PREFIX . '-default-widget',
            $this->storedValue('{{%searchmanager_widget_configs}}', $widgetId, 'handle'),
        );
    }

    public function testReferencedWidgetStyleHandleCannotBeRenamed(): void
    {
        $styleId = $this->insertStyle('referenced-style', 'Referenced Style');
        $widgetId = $this->insertWidget(
            'style-consumer',
            'Style Consumer',
            self::PREFIX . '-referenced-style',
        );
        $style = SearchManager::$plugin->widgetStyles->getById($styleId);

        self::assertNotNull($style);
        $style->handle = self::PREFIX . '-renamed-style';

        self::assertFalse(SearchManager::$plugin->widgetStyles->save($style));
        self::assertSame(
            self::PREFIX . '-referenced-style',
            $this->storedValue('{{%searchmanager_widget_styles}}', $styleId, 'handle'),
        );
        self::assertSame(
            self::PREFIX . '-referenced-style',
            $this->storedValue('{{%searchmanager_widget_configs}}', $widgetId, 'styleHandle'),
        );
    }

    public function testReferencedApiKeyHandleCannotBeRenamedByDirectModelSave(): void
    {
        $apiKeyId = $this->insertApiKey('referenced-key', 'Referenced Key');
        $widgetId = $this->insertWidget(
            'key-consumer',
            'Key Consumer',
            null,
            self::PREFIX . '-referenced-key',
        );
        $apiKey = ApiKey::findById($apiKeyId);

        self::assertNotNull($apiKey);
        $apiKey->handle = self::PREFIX . '-renamed-key';

        self::assertFalse($apiKey->save());
        self::assertSame(
            self::PREFIX . '-referenced-key',
            $this->storedValue('{{%searchmanager_api_keys}}', $apiKeyId, 'handle'),
        );
        $widget = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertNotNull($widget);
        self::assertSame(self::PREFIX . '-referenced-key', $widget->getApiKeyHandle());
    }

    public function testStoredConfigIndexSourceCannotBeBypassedByCallerMutation(): void
    {
        $handle = self::PREFIX . '-config-index';
        $indexId = $this->insertIndex('config-index', 'Config Index', null, 'config');
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => [
                    'name' => 'Config Index',
                    'elementType' => Entry::class,
                    'enabled' => true,
                ],
            ],
        ]);
        $index = SearchIndex::findById($indexId);

        self::assertNotNull($index);
        $index->source = 'database';
        $index->name = 'Caller Mutated Config Index';

        self::assertFalse($index->save());
        self::assertSame('config', $this->storedValue('{{%searchmanager_indices}}', $indexId, 'source'));
        self::assertSame('Config Index', $this->storedValue('{{%searchmanager_indices}}', $indexId, 'name'));
    }

    public function testUpdateStatsRejectsLoadedIndexRedirectedToForeignStoredId(): void
    {
        $originalId = $this->insertIndex('stats-original', 'Stats Original');
        $foreignId = $this->insertIndex('stats-foreign', 'Stats Foreign');
        $this->setIndexStats($originalId, 11);
        $this->setIndexStats($foreignId, 22);
        $index = SearchIndex::findById($originalId);
        self::assertNotNull($index);
        SearchIndex::findAll();

        $originalRow = $this->indexRow($originalId);
        $foreignRow = $this->indexRow($foreignId);
        $modelLastIndexed = $index->lastIndexed;
        $modelDocumentCount = $index->documentCount;
        $cacheState = $this->searchIndexCacheState();
        $index->id = $foreignId;

        self::assertFalse($index->updateStats(99));
        self::assertSame([Craft::t('search-manager', 'Index not found')], $index->getErrors('id'));
        self::assertSame($originalRow, $this->indexRow($originalId));
        self::assertSame($foreignRow, $this->indexRow($foreignId));
        self::assertEquals($modelLastIndexed, $index->lastIndexed);
        self::assertSame($modelDocumentCount, $index->documentCount);
        self::assertSame($cacheState, $this->searchIndexCacheState());
    }

    public function testUpdateStatsRejectsLoadedIndexRedirectedToIdlessConfigMaterialization(): void
    {
        $configHandle = self::PREFIX . '-stats-config-target';
        $this->setSearchManagerConfig([
            'indices' => [
                $configHandle => $this->configIndexDefinition('Stats Config Target'),
            ],
        ]);
        $indexId = $this->insertIndex('stats-database-source', 'Stats Database Source');
        $index = SearchIndex::findById($indexId);
        self::assertNotNull($index);
        SearchIndex::findAll();

        $rows = $this->markedIndexRows();
        $siteRows = $this->markedIndexSiteRows();
        $cacheState = $this->searchIndexCacheState();
        $index->id = null;
        $index->source = 'config';
        $index->handle = $configHandle;
        $modelState = $index->getAttributes();

        self::assertFalse($index->updateStats(77));
        self::assertSame([Craft::t('search-manager', 'Index not found')], $index->getErrors('id'));
        self::assertSame($rows, $this->markedIndexRows());
        self::assertSame($siteRows, $this->markedIndexSiteRows());
        self::assertSame($modelState, $index->getAttributes());
        self::assertSame($cacheState, $this->searchIndexCacheState());
    }

    public function testConfigSyncRejectsLoadedIndexRedirectedToForeignStoredIdAndHandle(): void
    {
        $originalHandle = self::PREFIX . '-sync-config-original';
        $foreignHandle = self::PREFIX . '-sync-config-foreign';
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $this->setSearchManagerConfig([
            'indices' => [
                $originalHandle => $this->configIndexDefinition('Sync Config Original'),
                $foreignHandle => $this->configIndexDefinition('Sync Config Foreign', [
                    'criteria' => ['section' => 'news'],
                    'siteId' => [$siteId],
                ]),
            ],
        ]);
        $originalId = $this->insertIndex('sync-config-original', 'Stored Config Original', null, 'config');
        $foreignId = $this->insertIndex('sync-config-foreign', 'Stored Config Foreign', null, 'config');
        $index = SearchIndex::findByHandle($originalHandle);
        self::assertNotNull($index);
        self::assertSame($originalId, $index->id);
        SearchIndex::findAll();

        $originalRow = $this->indexRow($originalId);
        $foreignRow = $this->indexRow($foreignId);
        $originalSites = $this->indexSiteRows($originalId);
        $foreignSites = $this->indexSiteRows($foreignId);
        $queueRows = $this->markedQueueRows();
        $cacheState = $this->searchIndexCacheState();
        $index->id = $foreignId;
        $index->handle = $foreignHandle;
        $modelState = $index->getAttributes();

        self::assertFalse($index->syncMetadataFromConfig());
        self::assertSame([Craft::t('search-manager', 'Index not found')], $index->getErrors('id'));
        self::assertSame($originalRow, $this->indexRow($originalId));
        self::assertSame($foreignRow, $this->indexRow($foreignId));
        self::assertSame($originalSites, $this->indexSiteRows($originalId));
        self::assertSame($foreignSites, $this->indexSiteRows($foreignId));
        self::assertSame($queueRows, $this->markedQueueRows());
        self::assertSame($modelState, $index->getAttributes());
        self::assertSame($cacheState, $this->searchIndexCacheState());
    }

    public function testConfigSyncRejectsLoadedIndexWhoseIdWasCleared(): void
    {
        $handle = self::PREFIX . '-sync-config-cleared-id';
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => $this->configIndexDefinition('Sync Config Cleared ID'),
            ],
        ]);
        $indexId = $this->insertIndex('sync-config-cleared-id', 'Sync Config Cleared ID', null, 'config');
        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);
        SearchIndex::findAll();

        $row = $this->indexRow($indexId);
        $siteRows = $this->indexSiteRows($indexId);
        $queueRows = $this->markedQueueRows();
        $cacheState = $this->searchIndexCacheState();
        $index->id = null;
        $modelState = $index->getAttributes();

        self::assertFalse($index->syncMetadataFromConfig());
        self::assertSame([Craft::t('search-manager', 'Index not found')], $index->getErrors('id'));
        self::assertSame($row, $this->indexRow($indexId));
        self::assertSame($siteRows, $this->indexSiteRows($indexId));
        self::assertSame($queueRows, $this->markedQueueRows());
        self::assertSame($modelState, $index->getAttributes());
        self::assertSame($cacheState, $this->searchIndexCacheState());
    }

    public function testGenuineIdlessConfigIndexStillMaterializesAndSynchronizes(): void
    {
        $handle = self::PREFIX . '-sync-config-idless';
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => $this->configIndexDefinition('Sync Config ID-less'),
            ],
        ]);
        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);
        self::assertNull($index->id);

        self::assertTrue($index->syncMetadataFromConfig(), print_r($index->getErrors(), true));
        self::assertNotNull($index->id);
        self::assertSame('config', $this->storedValue('{{%searchmanager_indices}}', $index->id, 'source'));
    }

    public function testHydratedConfigIndexWithUnchangedIdStillSynchronizes(): void
    {
        $handle = self::PREFIX . '-sync-config-unchanged-id';
        $this->setSearchManagerConfig([
            'indices' => [
                $handle => $this->configIndexDefinition('Sync Config Unchanged ID', [
                    'criteria' => ['section' => 'news'],
                ]),
            ],
        ]);
        $indexId = $this->insertIndex('sync-config-unchanged-id', 'Stored Config Unchanged ID', null, 'config');
        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);
        self::assertSame($indexId, $index->id);

        self::assertTrue($index->syncMetadataFromConfig(), print_r($index->getErrors(), true));
        self::assertSame($indexId, $index->id);
        self::assertSame('Sync Config Unchanged ID', $this->storedValue('{{%searchmanager_indices}}', $indexId, 'name'));
    }

    public function testHydratedDatabaseIndexWithUnchangedIdStillUpdatesStats(): void
    {
        $indexId = $this->insertIndex('stats-unchanged-id', 'Stats Unchanged ID');
        $index = SearchIndex::findById($indexId);
        self::assertNotNull($index);

        self::assertTrue($index->updateStats(33), print_r($index->getErrors(), true));
        self::assertSame($indexId, $index->id);
        self::assertSame(33, $index->documentCount);
        self::assertSame(33, (int)$this->storedValue('{{%searchmanager_indices}}', $indexId, 'documentCount'));
    }

    #[DataProvider('persistenceFamilies')]
    public function testExistingUnchangedRowsRemainValidNoOpSaves(string $family): void
    {
        [$table, $id, $save] = $this->createLoadedSaveCase($family, false);

        self::assertTrue($save(), $family . ' rejected an authoritative unchanged row.');
        self::assertSame(1, $this->countRows($table, ['id' => $id]));
    }

    public function testUnusedStableHandlesRemainRenameable(): void
    {
        $backendId = $this->insertBackend('unused-backend', 'Unused Backend');
        $backend = ConfiguredBackend::findById($backendId);
        self::assertNotNull($backend);
        $backend->handle = self::PREFIX . '-unused-backend-renamed';
        self::assertTrue($backend->save(), json_encode($backend->getErrors()));

        $widgetId = $this->insertWidget('unused-widget', 'Unused Widget');
        $widget = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertNotNull($widget);
        $widget->handle = self::PREFIX . '-unused-widget-renamed';
        self::assertTrue(SearchManager::$plugin->widgetConfigs->save($widget), json_encode($widget->getErrors()));

        $styleId = $this->insertStyle('unused-style', 'Unused Style');
        $style = SearchManager::$plugin->widgetStyles->getById($styleId);
        self::assertNotNull($style);
        $style->handle = self::PREFIX . '-unused-style-renamed';
        self::assertTrue(SearchManager::$plugin->widgetStyles->save($style), json_encode($style->getErrors()));

        $apiKeyId = $this->insertApiKey('unused-key', 'Unused Key');
        $apiKey = ApiKey::findById($apiKeyId);
        self::assertNotNull($apiKey);
        $apiKey->handle = self::PREFIX . '-unused-key-renamed';
        self::assertTrue($apiKey->save(), json_encode($apiKey->getErrors()));

        self::assertSame(
            self::PREFIX . '-unused-backend-renamed',
            $this->storedValue('{{%searchmanager_backends}}', $backendId, 'handle'),
        );
        self::assertSame(
            self::PREFIX . '-unused-widget-renamed',
            $this->storedValue('{{%searchmanager_widget_configs}}', $widgetId, 'handle'),
        );
        self::assertSame(
            self::PREFIX . '-unused-style-renamed',
            $this->storedValue('{{%searchmanager_widget_styles}}', $styleId, 'handle'),
        );
        self::assertSame(
            self::PREFIX . '-unused-key-renamed',
            $this->storedValue('{{%searchmanager_api_keys}}', $apiKeyId, 'handle'),
        );
    }

    public function testEffectiveConfigDependenciesBlockRenamesAndShadowDatabaseCollisions(): void
    {
        $backendId = $this->insertBackend('config-backend', 'Config Backend');
        $styleId = $this->insertStyle('config-style', 'Config Style');
        $apiKeyId = $this->insertApiKey('config-key', 'Config Key');
        $this->insertIndex('collision-index', 'Shadowed Database Index');
        $this->insertWidget('collision-widget', 'Shadowed Database Widget');
        $this->setSearchManagerConfig([
            'indices' => [
                self::PREFIX . '-collision-index' => [
                    'name' => 'Effective Config Index',
                    'elementType' => Entry::class,
                    'backend' => self::PREFIX . '-config-backend',
                    'enabled' => false,
                ],
            ],
            'widgets' => [
                self::PREFIX . '-collision-widget' => [
                    'name' => 'Effective Config Widget',
                    'type' => 'modal',
                    'styleHandle' => self::PREFIX . '-config-style',
                    'settings' => [
                        'apiKeyHandle' => self::PREFIX . '-config-key',
                    ],
                    'enabled' => false,
                ],
            ],
        ]);

        $backend = ConfiguredBackend::findById($backendId);
        $style = SearchManager::$plugin->widgetStyles->getById($styleId);
        $apiKey = ApiKey::findById($apiKeyId);
        self::assertNotNull($backend);
        self::assertNotNull($style);
        self::assertNotNull($apiKey);
        $backend->handle = self::PREFIX . '-config-backend-renamed';
        $style->handle = self::PREFIX . '-config-style-renamed';
        $apiKey->handle = self::PREFIX . '-config-key-renamed';

        self::assertFalse($backend->save());
        self::assertFalse(SearchManager::$plugin->widgetStyles->save($style));
        self::assertFalse($apiKey->save());
        self::assertStringContainsString('1 index', (string)$backend->getFirstError('handle'));
        self::assertStringContainsString('1 widget', (string)$style->getFirstError('handle'));
        self::assertStringContainsString('1 widget', (string)$apiKey->getFirstError('handle'));
    }

    #[DataProvider('persistenceFamilies')]
    public function testConcurrentDeletionOutcomeCannotReportSuccess(string $family): void
    {
        [$table, $id, $save, $model] = $this->createLoadedSaveCase($family);
        $deleted = false;
        $model->on(Model::EVENT_BEFORE_VALIDATE, function() use (&$deleted, $family, $table, $id): void {
            if ($deleted) {
                return;
            }
            $deleted = true;
            if ($family === 'index') {
                Craft::$app->getDb()->createCommand()
                    ->delete('{{%searchmanager_index_sites}}', ['indexId' => $id])
                    ->execute();
            }
            Craft::$app->getDb()->createCommand()->delete($table, ['id' => $id])->execute();
        });

        self::assertFalse($save(), $family . ' reported success after deletion between lookup and update.');
    }

    #[DataProvider('persistenceFamilies')]
    public function testLoadedIdentityCannotBeRedirectedToForeignStoredRow(string $family): void
    {
        [$table, $originalId, $save, $model] = $this->createLoadedSaveCase($family);
        [$foreignId, $identityColumn] = $this->insertForeignSaveTarget($family);
        $originalIdentity = $this->storedValue($table, $originalId, $identityColumn);
        $foreignIdentity = $this->storedValue($table, $foreignId, $identityColumn);

        $model->setAttributes(['id' => $foreignId], false);
        if (property_exists($model, 'handle')) {
            $model->setAttributes(['handle' => self::PREFIX . '-redirected-foreign'], false);
        } elseif ($family === 'promotion') {
            $model->setAttributes(['title' => 'Redirected Foreign Promotion'], false);
        } else {
            $model->setAttributes(['name' => 'Redirected Foreign Rule'], false);
        }

        self::assertFalse($save(), $family . ' redirected a loaded model to a foreign stored ID.');
        self::assertNotEmpty($model->getErrors('id'));
        self::assertSame($originalIdentity, $this->storedValue($table, $originalId, $identityColumn));
        self::assertSame($foreignIdentity, $this->storedValue($table, $foreignId, $identityColumn));
    }

    #[DataProvider('uniqueHandleFamilies')]
    public function testPersistenceExceptionsUseFalseAndModelErrorContract(string $family): void
    {
        [$table, $id, $save, $model] = $this->createLoadedSaveCase($family);
        $requestedHandle = self::PREFIX . '-race-' . $family;
        $model->handle = $requestedHandle;
        $insertedCollision = false;
        $model->on(Model::EVENT_AFTER_VALIDATE, function() use (&$insertedCollision, $family): void {
            if ($insertedCollision) {
                return;
            }
            $insertedCollision = true;
            match ($family) {
                'backend' => $this->insertBackend('race-backend', 'Race Backend'),
                'apiKey' => $this->insertApiKey('race-apiKey', 'Race API Key'),
                'index' => $this->insertIndex('race-index', 'Race Index'),
                'widgetConfig' => $this->insertWidget('race-widgetConfig', 'Race Widget'),
                'widgetStyle' => $this->insertStyle('race-widgetStyle', 'Race Style'),
            };
        });

        self::assertFalse($save(), $family . ' leaked or accepted a persistence exception.');
        self::assertSame(1, $this->countRows($table, ['id' => $id]));
        self::assertNotEmpty($model->getErrors());
    }

    public function testFailedRuleAndPromotionSavesDoNotClearSearchCaches(): void
    {
        [, $ruleId, , $rule] = $this->createLoadedSaveCase('queryRule');
        [, $promotionId, , $promotion] = $this->createLoadedSaveCase('promotion');
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['id' => $ruleId])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['id' => $promotionId])
            ->execute();
        $backend = $this->installStubBackend();

        self::assertFalse(SearchManager::$plugin->queryRules->save($rule));
        self::assertFalse(SearchManager::$plugin->promotions->save($promotion));
        self::assertSame([], $backend->callsFor('clearAllSearchCache'));
    }

    public function testFailedWidgetAndIndexSavesPreserveCachedAndQueuedState(): void
    {
        $widgetId = $this->insertWidget('cached-default', 'Cached Default');
        $this->setDefault('widget', self::PREFIX . '-cached-default');
        $cachedDefault = SearchManager::$plugin->widgetConfigs->getDefault();
        $widget = SearchManager::$plugin->widgetConfigs->getById($widgetId);
        self::assertNotNull($cachedDefault);
        self::assertNotNull($widget);
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['id' => $widgetId])
            ->execute();

        self::assertFalse(SearchManager::$plugin->widgetConfigs->save($widget));
        $defaultProperty = new \ReflectionProperty(SearchManager::$plugin->widgetConfigs, '_defaultConfig');
        $defaultProperty->setAccessible(true);
        self::assertSame($cachedDefault, $defaultProperty->getValue(SearchManager::$plugin->widgetConfigs));

        $indexId = $this->insertIndex('cached-index', 'Cached Index');
        SearchIndex::findAll();
        $cacheProperty = new \ReflectionProperty(SearchIndex::class, 'allCache');
        $cacheProperty->setAccessible(true);
        $expiryProperty = new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt');
        $expiryProperty->setAccessible(true);
        $cachedIndices = $cacheProperty->getValue();
        $cachedExpiry = $expiryProperty->getValue();
        $index = SearchIndex::findById($indexId);
        self::assertNotNull($index);
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['id' => $indexId])
            ->execute();

        self::assertFalse($index->save());
        self::assertFalse($index->wasRebuildQueuedOnLastSave());
        self::assertSame($cachedIndices, $cacheProperty->getValue());
        self::assertSame($cachedExpiry, $expiryProperty->getValue());
        self::assertSame(
            0,
            (int)(new Query())
                ->from($this->queueTable())
                ->where(['like', 'job', self::PREFIX . '-cached-index'])
                ->count(),
        );
    }

    /**
     * @return array{string, int, callable(): bool, Model}
     */
    private function createLoadedSaveCase(string $family, bool $mutate = true): array
    {
        return match ($family) {
            'backend' => $this->backendSaveCase($mutate),
            'apiKey' => $this->apiKeySaveCase($mutate),
            'queryRule' => $this->queryRuleSaveCase($mutate),
            'promotion' => $this->promotionSaveCase($mutate),
            'index' => $this->indexSaveCase($mutate),
            'widgetConfig' => $this->widgetConfigSaveCase($mutate),
            'widgetStyle' => $this->widgetStyleSaveCase($mutate),
            default => throw new \InvalidArgumentException('Unknown persistence family: ' . $family),
        };
    }

    /**
     * @return array{string, int, callable(): bool, ConfiguredBackend}
     */
    private function backendSaveCase(bool $mutate): array
    {
        $id = $this->insertBackend('stale-backend', 'Stale Backend');
        $model = ConfiguredBackend::findById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Backend Updated';
        }

        return ['{{%searchmanager_backends}}', $id, static fn(): bool => $model->save(), $model];
    }

    /**
     * @return array{string, int, callable(): bool, ApiKey}
     */
    private function apiKeySaveCase(bool $mutate): array
    {
        $id = $this->insertApiKey('stale-key', 'Stale Key');
        $model = ApiKey::findById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Key Updated';
        }

        return ['{{%searchmanager_api_keys}}', $id, static fn(): bool => $model->save(), $model];
    }

    /**
     * @return array{string, int, callable(): bool, QueryRule}
     */
    private function queryRuleSaveCase(bool $mutate): array
    {
        $id = $this->insertQueryRule('stale-rule', 'Stale Rule');
        $model = QueryRule::findById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Rule Updated';
        }

        return ['{{%searchmanager_query_rules}}', $id, static fn(): bool => $model->save(), $model];
    }

    /**
     * @return array{string, int, callable(): bool, Promotion}
     */
    private function promotionSaveCase(bool $mutate): array
    {
        $id = $this->insertPromotion('stale-promotion', 'Stale Promotion');
        $model = Promotion::findById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->title = 'Stale Promotion Updated';
        }

        return ['{{%searchmanager_promotions}}', $id, static fn(): bool => $model->save(), $model];
    }

    /**
     * @return array{string, int, callable(): bool, SearchIndex}
     */
    private function indexSaveCase(bool $mutate): array
    {
        $id = $this->insertIndex('stale-index', 'Stale Index');
        $model = SearchIndex::findById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Index Updated';
        }

        return ['{{%searchmanager_indices}}', $id, static fn(): bool => $model->save(), $model];
    }

    /**
     * @return array{string, int, callable(): bool, WidgetConfig}
     */
    private function widgetConfigSaveCase(bool $mutate): array
    {
        $id = $this->insertWidget('stale-widget', 'Stale Widget');
        $model = SearchManager::$plugin->widgetConfigs->getById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Widget Updated';
        }

        return [
            '{{%searchmanager_widget_configs}}',
            $id,
            static fn(): bool => SearchManager::$plugin->widgetConfigs->save($model),
            $model,
        ];
    }

    /**
     * @return array{string, int, callable(): bool, \lindemannrock\searchmanager\models\WidgetStyle}
     */
    private function widgetStyleSaveCase(bool $mutate): array
    {
        $id = $this->insertStyle('stale-style', 'Stale Style');
        $model = SearchManager::$plugin->widgetStyles->getById($id);
        self::assertNotNull($model);
        if ($mutate) {
            $model->name = 'Stale Style Updated';
        }

        return [
            '{{%searchmanager_widget_styles}}',
            $id,
            static fn(): bool => SearchManager::$plugin->widgetStyles->save($model),
            $model,
        ];
    }

    /**
     * @return array{int, string}
     */
    private function insertForeignSaveTarget(string $family): array
    {
        return match ($family) {
            'backend' => [$this->insertBackend('foreign-backend', 'Foreign Backend'), 'handle'],
            'apiKey' => [$this->insertApiKey('foreign-key', 'Foreign Key'), 'handle'],
            'queryRule' => [$this->insertQueryRule('foreign-rule', 'Foreign Rule'), 'name'],
            'promotion' => [$this->insertPromotion('foreign-promotion', 'Foreign Promotion'), 'title'],
            'index' => [$this->insertIndex('foreign-index', 'Foreign Index'), 'handle'],
            'widgetConfig' => [$this->insertWidget('foreign-widget', 'Foreign Widget'), 'handle'],
            'widgetStyle' => [$this->insertStyle('foreign-style', 'Foreign Style'), 'handle'],
            default => throw new \InvalidArgumentException('Unknown persistence family: ' . $family),
        };
    }

    private function insertBackend(string $suffix, string $name): int
    {
        return $this->insertRow('{{%searchmanager_backends}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => 'mysql',
            'settings' => null,
            'enabled' => 1,
        ]);
    }

    private function insertIndex(
        string $suffix,
        string $name,
        ?string $backend = null,
        string $source = 'database',
    ): int {
        return $this->insertRow('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => $backend,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => $source,
            'lastIndexed' => null,
            'documentCount' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function configIndexDefinition(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => [],
            'transformer' => null,
            'headingLevels' => null,
            'language' => null,
            'backend' => null,
            'enabled' => true,
            'enableAnalytics' => true,
            'disableStopWords' => false,
            'skipEntriesWithoutUrl' => false,
            'splitSections' => false,
            'retrievableFields' => ['*'],
        ], $overrides);
    }

    private function setIndexStats(int $id, int $documentCount): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_indices}}', [
                'lastIndexed' => Db::prepareDateForDb(new \DateTimeImmutable('2026-01-01 00:00:00 UTC')),
                'documentCount' => $documentCount,
            ], ['id' => $id])
            ->execute();
        SearchIndex::clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    private function indexRow(int $id): array
    {
        $row = (new Query())
            ->from('{{%searchmanager_indices}}')
            ->where(['id' => $id])
            ->one();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function indexSiteRows(int $indexId): array
    {
        return (new Query())
            ->from('{{%searchmanager_index_sites}}')
            ->where(['indexId' => $indexId])
            ->orderBy(['siteId' => SORT_ASC])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function markedIndexRows(): array
    {
        return (new Query())
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function markedIndexSiteRows(): array
    {
        $ids = array_map(
            static fn(array $row): int => (int)$row['id'],
            $this->markedIndexRows(),
        );
        if ($ids === []) {
            return [];
        }

        return (new Query())
            ->from('{{%searchmanager_index_sites}}')
            ->where(['indexId' => $ids])
            ->orderBy(['indexId' => SORT_ASC, 'siteId' => SORT_ASC])
            ->all();
    }

    /**
     * @return array{mixed, mixed}
     */
    private function searchIndexCacheState(): array
    {
        $cache = new \ReflectionProperty(SearchIndex::class, 'allCache');
        $cache->setAccessible(true);
        $expiresAt = new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt');
        $expiresAt->setAccessible(true);

        return [$cache->getValue(), $expiresAt->getValue()];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function markedQueueRows(): array
    {
        return (new Query())
            ->select(['id', 'job', 'description', 'fail', 'timeUpdated'])
            ->from($this->queueTable())
            ->where(['like', 'job', self::PREFIX])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function insertApiKey(string $suffix, string $name): int
    {
        $handle = self::PREFIX . '-' . $suffix;

        return $this->insertRow('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => $handle,
            'type' => ApiKey::TYPE_PUBLIC,
            'enabled' => 1,
            'keyHash' => hash('sha256', $handle),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_pub_' . substr(hash('sha256', $handle), 0, 8),
            'allowedIndices' => '["*"]',
            'allowedReferrers' => '[]',
            'maxHitsPerPage' => null,
            'validUntil' => null,
            'rateLimit' => null,
            'lastUsedAt' => null,
        ]);
    }

    private function insertQueryRule(string $suffix, string $name): int
    {
        return $this->insertRow('{{%searchmanager_query_rules}}', [
            'name' => $name,
            'indexHandle' => null,
            'matchType' => QueryRule::MATCH_EXACT,
            'matchValue' => self::PREFIX . '-' . $suffix,
            'actionType' => QueryRule::ACTION_SYNONYM,
            'actionValue' => '{"terms":["query"]}',
            'priority' => 0,
            'siteId' => null,
            'enabled' => 1,
        ]);
    }

    private function insertPromotion(string $suffix, string $title): int
    {
        $entry = Entry::find()->status(null)->one();
        self::assertNotNull($entry, 'The Promotion persistence fixture requires one existing Entry.');

        return $this->insertRow('{{%searchmanager_promotions}}', [
            'indexHandle' => null,
            'title' => $title,
            'query' => self::PREFIX . '-' . $suffix,
            'matchType' => 'exact',
            'elementId' => $entry->id,
            'elementType' => Entry::class,
            'position' => 1,
            'siteId' => null,
            'enabled' => 1,
        ]);
    }

    private function insertWidget(
        string $suffix,
        string $name,
        ?string $styleHandle = null,
        ?string $apiKeyHandle = null,
    ): int {
        $settings = WidgetConfig::defaultSettings();
        if ($apiKeyHandle !== null) {
            $settings['apiKeyHandle'] = $apiKeyHandle;
        }

        return $this->insertRow('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => $styleHandle,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'enabled' => 1,
        ]);
    }

    private function insertStyle(string $suffix, string $name): int
    {
        return $this->insertRow('{{%searchmanager_widget_styles}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => 1,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function insertRow(string $table, array $attributes): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $attributes['dateCreated'] = $now;
        $attributes['dateUpdated'] = $now;
        $attributes['uid'] = StringHelper::UUID();

        Craft::$app->getDb()->createCommand()->insert($table, $attributes)->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function setDefault(string $family, ?string $handle): void
    {
        $column = $family === 'backend' ? 'defaultBackendHandle' : 'defaultWidgetHandle';
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_settings}}', [$column => $handle], ['id' => 1])
            ->execute();

        $settings = SearchManager::$plugin->getSettings();
        $fresh = Settings::loadFromDatabase();
        $settings->setAttributes($fresh->getAttributes(), false);
        $this->resetServiceCaches();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setSearchManagerConfig(array $config): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = $config;
        $this->setConfigCache($cache);
        $this->resetServiceCaches();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function resetServiceCaches(): void
    {
        foreach ([
            [SearchManager::$plugin->widgetConfigs, '_configFileConfigs'],
            [SearchManager::$plugin->widgetConfigs, '_defaultConfig'],
            [SearchManager::$plugin->widgetStyles, '_configFileStyles'],
        ] as [$service, $propertyName]) {
            $property = new \ReflectionProperty($service, $propertyName);
            $property->setAccessible(true);
            $property->setValue($service, null);
        }
    }

    private function markedId(string $table, string $suffix): int
    {
        return (int)(new Query())
            ->select('id')
            ->from($table)
            ->where(['handle' => self::PREFIX . '-' . $suffix])
            ->scalar();
    }

    private function storedValue(string $table, int $id, string $column): mixed
    {
        return (new Query())
            ->select($column)
            ->from($table)
            ->where(['id' => $id])
            ->scalar();
    }

    private function purgeMarkedRows(): void
    {
        $indexIds = (new Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();
        if ($indexIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $indexIds)])
                ->execute();
        }

        foreach ([
            '{{%searchmanager_widget_configs}}',
            '{{%searchmanager_widget_styles}}',
            '{{%searchmanager_api_keys}}',
            '{{%searchmanager_query_rules}}',
            '{{%searchmanager_promotions}}',
            '{{%searchmanager_indices}}',
            '{{%searchmanager_backends}}',
        ] as $table) {
            $column = in_array($table, [
                '{{%searchmanager_query_rules}}',
                '{{%searchmanager_promotions}}',
            ], true) ? ($table === '{{%searchmanager_query_rules}}' ? 'matchValue' : 'query') : 'handle';
            Craft::$app->getDb()->createCommand()
                ->delete($table, ['like', $column, self::PREFIX . '%', false])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::PREFIX])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_pending_syncs}}', ['like', 'indexHandle', self::PREFIX . '%', false])
            ->execute();
        foreach ([
            '{{%searchmanager_search_compounds}}',
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
        ] as $table) {
            Craft::$app->getDb()->createCommand()
                ->delete($table, ['like', 'indexHandle', self::PREFIX . '%', false])
                ->execute();
        }

        $settings = SearchManager::$plugin->getSettings();
        if (str_starts_with((string)$settings->defaultBackendHandle, self::PREFIX)) {
            $this->setDefault('backend', null);
        }
        if (str_starts_with((string)$settings->defaultWidgetHandle, self::PREFIX)) {
            $this->setDefault('widget', null);
        }
    }
}
