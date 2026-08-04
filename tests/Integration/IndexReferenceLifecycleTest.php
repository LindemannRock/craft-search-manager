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
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\services\IndexMaintenanceService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for the complete index-reference lifecycle.
 *
 * @since 5.54.0
 */
#[CoversClass(DependencyService::class)]
#[CoversClass(QueryRule::class)]
#[CoversClass(Promotion::class)]
#[CoversClass(SearchIndex::class)]
#[CoversClass(IndicesController::class)]
final class IndexReferenceLifecycleTest extends TestCase
{
    private const PREFIX = 'sm-index-reference-lifecycle';
    private const BACKEND = self::PREFIX . '-backend';

    private mixed $originalConfigCache = null;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeMarkedRows();
        $this->setSearchManagerConfig([]);
        $this->insertBackend();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string<QueryRule|Promotion>}>
     */
    public static function scopedModelProvider(): array
    {
        return [
            'query rule' => [QueryRule::class],
            'promotion' => [Promotion::class],
        ];
    }

    #[DataProvider('scopedModelProvider')]
    public function testSharedReferenceValidationAcceptsGlobalDatabaseConfigAndDisabledIndices(string $modelClass): void
    {
        $databaseHandle = self::PREFIX . '-database';
        $disabledHandle = self::PREFIX . '-disabled';
        $configHandle = self::PREFIX . '-config';

        $this->insertIndex($databaseHandle, 'Database Index', true);
        $this->insertIndex($disabledHandle, 'Disabled Index', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $configHandle => [
                    'name' => 'Config Index',
                    'elementType' => Entry::class,
                    'enabled' => false,
                ],
            ],
        ]);

        foreach ([null, $databaseHandle, $disabledHandle, $configHandle] as $handle) {
            $model = new $modelClass();
            $model->indexHandle = $handle;

            self::assertTrue(
                $model->validate(['indexHandle']),
                sprintf('%s should accept index reference %s: %s', $modelClass, var_export($handle, true), json_encode($model->getErrors())),
            );
        }
    }

    #[DataProvider('scopedModelProvider')]
    public function testSharedReferenceValidationNormalizesEmptyScope(string $modelClass): void
    {
        $global = new $modelClass();
        $global->indexHandle = '';

        self::assertTrue($global->validate(['indexHandle']), json_encode($global->getErrors()));
        self::assertNull($global->indexHandle);
    }

    #[DataProvider('scopedModelProvider')]
    public function testSharedReferenceValidationRejectsMissingHandle(string $modelClass): void
    {
        $missing = new $modelClass();
        $missing->indexHandle = self::PREFIX . '-missing';

        self::assertFalse($missing->validate(['indexHandle']));
        self::assertSame(['Index not found'], $missing->getErrors('indexHandle'));
    }

    public function testResolvedDependencyInventoryCoversAllFourConsumerFamilies(): void
    {
        $handle = self::PREFIX . '-inventory';
        $this->insertIndex($handle, 'Inventory Index');
        $this->insertWidget(self::PREFIX . '-widget', 'Inventory Widget', [$handle]);
        $this->insertApiKey(self::PREFIX . '-key', 'Inventory API Key', [$handle]);
        $this->insertQueryRule('Inventory Rule', $handle);
        $this->insertPromotion('Inventory Promotion', $handle);

        $usages = SearchManager::$plugin->dependencies->getIndexUsages($handle);

        self::assertSame(
            ['widget', 'apiKey', 'queryRule', 'promotion'],
            array_column($usages, 'kind'),
        );
        self::assertSame(
            ['Inventory Widget', 'Inventory API Key', 'Inventory Rule', 'Inventory Promotion'],
            array_column($usages, 'label'),
        );
    }

    public function testWidgetInventoryUsesConfigPrecedenceAndKeepsDatabaseOnlyWidgets(): void
    {
        $targetHandle = self::PREFIX . '-widget-target';
        $shadowedOnlyHandle = self::PREFIX . '-shadowed-only';
        $this->insertIndex($targetHandle, 'Widget Target');
        $this->insertIndex($shadowedOnlyHandle, 'Shadowed Target');

        $shadowedWidgetHandle = self::PREFIX . '-shadowed-widget';
        $this->insertWidget($shadowedWidgetHandle, 'Shadowed Database Widget', [$shadowedOnlyHandle]);
        $this->insertWidget(self::PREFIX . '-database-widget', 'Database Widget', [$targetHandle]);
        $this->setSearchManagerConfig([
            'widgets' => [
                $shadowedWidgetHandle => [
                    'name' => 'Config Widget',
                    'type' => 'modal',
                    'settings' => [
                        'search' => [
                            'indexHandles' => [$targetHandle],
                        ],
                    ],
                    'enabled' => false,
                ],
            ],
        ]);

        $targetLabels = array_column(
            SearchManager::$plugin->dependencies->getIndexUsages($targetHandle),
            'label',
        );
        $shadowedLabels = array_column(
            SearchManager::$plugin->dependencies->getIndexUsages($shadowedOnlyHandle),
            'label',
        );

        self::assertContains('Config Widget', $targetLabels);
        self::assertContains('Database Widget', $targetLabels);
        self::assertNotContains('Shadowed Database Widget', $shadowedLabels);
    }

    public function testUsageFormattingKeepsRuleAndPromotionNamesPermissionSafe(): void
    {
        $handle = self::PREFIX . '-permission-safe';
        $this->insertQueryRule('Private Rule', $handle);
        $this->insertPromotion('Private Promotion', $handle);
        $this->actWithPermissions(['searchManager:manageIndices']);

        $message = SearchManager::$plugin->dependencies->formatInUseError(
            'Permission Safe Index',
            SearchManager::$plugin->dependencies->getIndexUsages($handle),
        );

        self::assertSame(
            'Cannot delete “Permission Safe Index” — it is in use by: 1 rule, 1 promotion.',
            $message,
        );
        self::assertStringNotContainsString('Private Rule', $message);
        self::assertStringNotContainsString('Private Promotion', $message);
    }

    public function testSingleIndexDeleteIsBlockedByQueryRule(): void
    {
        $handle = self::PREFIX . '-single-rule';
        $indexId = $this->insertIndex($handle, 'Rule Index');
        $this->insertQueryRule('Deletion Rule', $handle);
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:deleteIndices',
            'searchManager:manageQueryRules',
        ]);
        $this->withPostJson(['indexId' => $indexId]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionDelete();

        self::assertSame(false, $response->data['success'] ?? true);
        self::assertSame(
            'Cannot delete “Rule Index” — it is in use by: Query Rules: Deletion Rule.',
            $response->data['error'] ?? null,
        );
        self::assertSame(1, $this->countTableRows('{{%searchmanager_indices}}', ['id' => $indexId]));
    }

    public function testBulkIndexDeleteContinuesAfterPromotionPreflightFailure(): void
    {
        $usedHandle = self::PREFIX . '-bulk-promotion';
        $usedIndexId = $this->insertIndex($usedHandle, 'Promotion Index');
        $unusedIndexId = $this->insertIndex(self::PREFIX . '-bulk-unused', 'Unused Index');
        $this->insertPromotion('Deletion Promotion', $usedHandle);
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:deleteIndices',
            'searchManager:managePromotions',
        ]);
        $this->withPostJson(['indexIds' => [$usedIndexId, $unusedIndexId]]);

        $originalMaintenance = SearchManager::$plugin->indexMaintenance;
        SearchManager::$plugin->set('indexMaintenance', new class() extends IndexMaintenanceService {
            protected function clearBackendStorage(SearchIndex $index, string $operation = 'clear'): bool
            {
                return true;
            }

            protected function invalidateIndexCaches(SearchIndex $index): array
            {
                return [];
            }
        });

        try {
            $response = (new IndicesController('indices', SearchManager::$plugin))->actionBulkDelete();
        } finally {
            SearchManager::$plugin->set('indexMaintenance', $originalMaintenance);
        }

        self::assertSame(false, $response->data['success'] ?? true);
        self::assertSame('partial', $response->data['status'] ?? null);
        self::assertSame(1, $response->data['count'] ?? null);
        self::assertSame([
            'Cannot delete “Promotion Index” — it is in use by: Promotions: Deletion Promotion.',
        ], $response->data['errors'] ?? null);
        self::assertSame(1, $this->countTableRows('{{%searchmanager_indices}}', ['id' => $usedIndexId]));
        self::assertSame(0, $this->countTableRows('{{%searchmanager_indices}}', ['id' => $unusedIndexId]));
    }

    public function testHandleChangeIsBlockedBeforePersistenceButUnchangedHandleSaveIsAllowed(): void
    {
        $handle = self::PREFIX . '-rename-used';
        $indexId = $this->insertIndex($handle, 'Rename Used Index', false);
        $this->insertQueryRule('Rename Rule', $handle);
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:manageQueryRules',
        ]);

        $index = SearchIndex::findById($indexId);
        self::assertInstanceOf(SearchIndex::class, $index);
        self::assertTrue($index->save(), json_encode($index->getErrors()));

        $index->handle = self::PREFIX . '-renamed';
        self::assertFalse($index->save());
        self::assertSame(
            ['Cannot change the handle for “Rename Used Index” — it is in use by: Query Rules: Rename Rule.'],
            $index->getErrors('handle'),
        );
        self::assertSame($handle, $this->storedIndexHandle($indexId));
    }

    public function testUnusedIndexHandleCanChange(): void
    {
        $indexId = $this->insertIndex(self::PREFIX . '-rename-unused', 'Rename Unused Index', false);
        $index = SearchIndex::findById($indexId);
        self::assertInstanceOf(SearchIndex::class, $index);

        $index->handle = self::PREFIX . '-rename-unused-new';

        self::assertTrue($index->save(), json_encode($index->getErrors()));
        self::assertSame(self::PREFIX . '-rename-unused-new', $this->storedIndexHandle($indexId));
    }

    public function testHandleChangeGuardCoversEveryConsumerFamily(): void
    {
        $this->actWithPermissions([
            'searchManager:manageIndices',
            'searchManager:manageWidgetConfigs',
            'searchManager:manageApiKeys',
            'searchManager:manageQueryRules',
            'searchManager:managePromotions',
        ]);

        $cases = [
            'widget' => function(string $handle): void {
                $this->insertWidget(self::PREFIX . '-rename-widget', 'Rename Widget', [$handle]);
            },
            'api-key' => function(string $handle): void {
                $this->insertApiKey(self::PREFIX . '-rename-api-key', 'Rename API Key', [$handle]);
            },
            'query-rule' => function(string $handle): void {
                $this->insertQueryRule('Rename Query Rule', $handle);
            },
            'promotion' => function(string $handle): void {
                $this->insertPromotion('Rename Promotion', $handle);
            },
        ];

        foreach ($cases as $consumer => $insertConsumer) {
            $handle = self::PREFIX . '-rename-' . $consumer . '-index';
            $indexId = $this->insertIndex($handle, 'Rename ' . $consumer . ' Index', false);
            $insertConsumer($handle);

            $index = SearchIndex::findById($indexId);
            self::assertInstanceOf(SearchIndex::class, $index);
            $index->handle = $handle . '-changed';

            self::assertFalse($index->save(), $consumer);
            self::assertNotEmpty($index->getErrors('handle'), $consumer);
            self::assertSame($handle, $this->storedIndexHandle($indexId), $consumer);
        }
    }

    public function testMissingReferencePresentationAndEditOptionsShareOneResolvedIdentity(): void
    {
        $databaseHandle = self::PREFIX . '-options-database';
        $disabledHandle = self::PREFIX . '-options-disabled';
        $configHandle = self::PREFIX . '-options-config';
        $missingHandle = self::PREFIX . '-orphan';
        $this->insertIndex($databaseHandle, 'Options Database');
        $this->insertIndex($disabledHandle, 'Options Disabled', false);
        $this->setSearchManagerConfig([
            'indices' => [
                $configHandle => [
                    'name' => 'Options Config',
                    'elementType' => Entry::class,
                    'enabled' => false,
                ],
            ],
        ]);

        $references = SearchManager::$plugin->dependencies->resolveIndexReferences([
            null,
            $databaseHandle,
            $disabledHandle,
            $configHandle,
            $missingHandle,
        ]);
        $options = SearchManager::$plugin->dependencies->getIndexOptions($missingHandle);

        self::assertSame('All Indices', $references['']['identityLabel']);
        self::assertTrue($references['']['global']);
        foreach ([
            $databaseHandle => 'Options Database (' . $databaseHandle . ')',
            $disabledHandle => 'Options Disabled (' . $disabledHandle . ')',
            $configHandle => 'Options Config (' . $configHandle . ')',
        ] as $validHandle => $expectedLabel) {
            self::assertTrue($references[$validHandle]['exists']);
            self::assertSame($expectedLabel, $references[$validHandle]['identityLabel']);
        }
        self::assertSame($missingHandle, $references[$missingHandle]['handle']);
        self::assertFalse($references[$missingHandle]['exists']);
        self::assertSame($missingHandle, $references[$missingHandle]['identityLabel']);
        $labels = array_column($options, 'label', 'value');
        self::assertSame('Options Database (' . $databaseHandle . ')', $labels[$databaseHandle]);
        self::assertSame('Options Disabled (' . $disabledHandle . ') — Disabled', $labels[$disabledHandle]);
        self::assertSame('Options Config (' . $configHandle . ') — Disabled', $labels[$configHandle]);
        self::assertSame($missingHandle . ' — Error', $labels[$missingHandle]);
    }

    public function testMissingReferenceUsesPlainHandleAndSharedErrorStatusBadge(): void
    {
        $missingReference = [
            'handle' => 'category',
            'displayName' => 'category',
            'identityLabel' => 'category',
            'choiceLabel' => 'category — Error',
            'exists' => false,
            'global' => false,
            'referenceable' => false,
            'state' => 'error',
            'enabled' => false,
            'errorTitle' => 'Index not found',
        ];
        $rule = new QueryRule(['enabled' => true]);

        $scopeHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_index-reference',
            ['reference' => $missingReference],
            View::TEMPLATE_MODE_CP,
        );
        $statusHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_effective-status',
            [
                'status' => SearchManager::$plugin->dependencies->resolveEffectiveStatus(
                    $rule->enabled,
                    $missingReference,
                ),
            ],
            View::TEMPLATE_MODE_CP,
        );
        $errorHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_index-reference-error',
            ['reference' => $missingReference, 'entityType' => 'rule'],
            View::TEMPLATE_MODE_CP,
        );

        self::assertStringContainsString('<code>category</code>', $scopeHtml);
        self::assertStringNotContainsString('(not found)', $scopeHtml);
        self::assertStringContainsString('Error', $statusHtml);
        self::assertStringContainsString('Index not found', $statusHtml);
        self::assertStringNotContainsString('Enabled', $statusHtml);
        self::assertStringContainsString('lr-info-box--error', $errorHtml);
        self::assertStringContainsString('lr-info-box--colored', $errorHtml);
        self::assertStringContainsString('<strong>Error:</strong>', $errorHtml);
        self::assertStringContainsString('selected index “category” is unavailable', $errorHtml);
        self::assertStringContainsString('delete this rule', $errorHtml);
    }

    public function testRuleAndPromotionListAndEditPathsUseSharedReferencePresentation(): void
    {
        $root = dirname(__DIR__, 2);
        $queryRuleIndex = file_get_contents($root . '/src/templates/query-rules/index.twig');
        $promotionIndex = file_get_contents($root . '/src/templates/promotions/index.twig');
        $queryRuleEdit = file_get_contents($root . '/src/templates/query-rules/edit.twig');
        $promotionEdit = file_get_contents($root . '/src/templates/promotions/edit.twig');
        $queryRuleController = file_get_contents($root . '/src/controllers/QueryRulesController.php');
        $promotionController = file_get_contents($root . '/src/controllers/PromotionsController.php');

        self::assertIsString($queryRuleIndex);
        self::assertIsString($promotionIndex);
        self::assertIsString($queryRuleEdit);
        self::assertIsString($promotionEdit);
        self::assertIsString($queryRuleController);
        self::assertIsString($promotionController);

        foreach ([$queryRuleIndex, $promotionIndex] as $source) {
            self::assertStringContainsString(
                "search-manager/_components/_index-reference",
                $source,
            );
            self::assertStringContainsString(
                "search-manager/_components/_effective-status",
                $source,
            );
            self::assertStringContainsString('indexReferences', $source);
        }

        foreach ([$queryRuleEdit, $promotionEdit] as $source) {
            self::assertStringContainsString(
                "search-manager/_components/_index-reference-error",
                $source,
            );
            self::assertStringContainsString('reference: indexReference', $source);
        }

        self::assertStringContainsString("'Index'|t('search-manager')", $promotionIndex);
        self::assertStringContainsString(
            'getIndexOptions($rule->indexHandle)',
            $queryRuleController,
        );
        self::assertStringContainsString(
            'resolveIndexReferences([$rule->indexHandle])',
            $queryRuleController,
        );
        self::assertStringContainsString(
            'getIndexOptions($promotion->indexHandle)',
            $promotionController,
        );
        self::assertStringContainsString(
            'resolveIndexReferences([$promotion->indexHandle])',
            $promotionController,
        );
    }

    public function testMissingIndexRecordsStillDoNotFireForAnotherIndex(): void
    {
        $missingHandle = self::PREFIX . '-runtime-orphan';
        $activeHandle = self::PREFIX . '-runtime-active';
        $this->insertQueryRule('Runtime Orphan Rule', $missingHandle, 'runtime-query');
        $this->insertPromotion('Runtime Orphan Promotion', $missingHandle, 'runtime-query');

        self::assertSame([], QueryRule::findMatching('runtime-query', $activeHandle));
        self::assertSame([], Promotion::findMatching('runtime-query', $activeHandle));
    }

    private function setSearchManagerConfig(array $config): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }
        $cache['search-manager'] = $config;
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
        $this->resetWidgetConfigServiceCache();
    }

    private function resetWidgetConfigServiceCache(): void
    {
        foreach (['_configFileConfigs', '_defaultConfig'] as $propertyName) {
            $property = new \ReflectionProperty(SearchManager::$plugin->widgetConfigs, $propertyName);
            $property->setValue(SearchManager::$plugin->widgetConfigs, null);
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(array $permissions): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, array_values(array_unique(array_merge(['accessCp'], $permissions))));
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }
    }

    private function insertIndex(string $handle, string $name, bool $enabled = true): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => (int)$enabled,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'backend' => self::BACKEND,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertBackend(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Index Reference Lifecycle Backend',
            'handle' => self::BACKEND,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    /**
     * @param list<string> $indexHandles
     */
    private function insertWidget(string $handle, string $name, array $indexHandles): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $settings = WidgetConfig::defaultSettings();
        $settings['search']['indexHandles'] = $indexHandles;

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => $handle,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param list<string> $allowedIndices
     */
    private function insertApiKey(string $handle, string $name, array $allowedIndices): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => $handle,
            'type' => 'public',
            'enabled' => 1,
            'keyHash' => hash('sha256', $handle),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_pub_' . substr(hash('sha256', $handle), 0, 8),
            'allowedIndices' => json_encode($allowedIndices, JSON_THROW_ON_ERROR),
            'allowedReferrers' => '[]',
            'maxHitsPerPage' => null,
            'validUntil' => null,
            'rateLimit' => null,
            'lastUsedAt' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertQueryRule(string $name, string $indexHandle, string $matchValue = 'query'): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
            'name' => $name,
            'indexHandle' => $indexHandle,
            'matchType' => QueryRule::MATCH_EXACT,
            'matchValue' => $matchValue,
            'actionType' => QueryRule::ACTION_SYNONYM,
            'actionValue' => '{"terms":["query"]}',
            'priority' => 0,
            'siteId' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertPromotion(string $title, string $indexHandle, string $query = 'query'): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => $indexHandle,
            'title' => $title,
            'query' => $query,
            'matchType' => 'exact',
            'elementId' => 1,
            'elementType' => Entry::class,
            'position' => 1,
            'siteId' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function storedIndexHandle(int $indexId): ?string
    {
        $handle = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_indices}}')
            ->where(['id' => $indexId])
            ->scalar();

        return is_string($handle) ? $handle : null;
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function countTableRows(string $table, array $condition): int
    {
        return (int)(new Query())
            ->from($table)
            ->where($condition)
            ->count();
    }

    private function purgeMarkedRows(): void
    {
        $indexIds = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();

        if ($indexIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $indexIds)])
                ->execute();
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $indexIds)])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['like', 'indexHandle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['like', 'indexHandle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => self::BACKEND])
            ->execute();
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }
}
