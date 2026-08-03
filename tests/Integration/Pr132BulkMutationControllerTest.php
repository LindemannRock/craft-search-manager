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
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\ApiKeyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
#[CoversClass(BackendsController::class)]
#[CoversClass(IndicesController::class)]
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(WidgetsController::class)]
#[CoversClass(ApiKeysController::class)]
final class Pr132BulkMutationControllerTest extends TestCase
{
    private const PREFIX = 'sm-pr132-bulk';
    private const STALE_ID = 2147483000;

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->purgeMarkedRows();

        $this->actor = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($this->actor, [
            'accessCp',
            'searchManager:manageBackends',
            'searchManager:editBackends',
            'searchManager:manageIndices',
            'searchManager:editIndices',
            'searchManager:managePromotions',
            'searchManager:editPromotions',
            'searchManager:manageQueryRules',
            'searchManager:editQueryRules',
            'searchManager:manageWidgetConfigs',
            'searchManager:editWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:editWidgetStyles',
            'searchManager:manageApiKeys',
            'searchManager:editApiKeys',
        ]);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        parent::tearDown();
    }

    public function testBackendPartialResultUsesRealModelValidationFailure(): void
    {
        $validId = $this->insertBackend('backend-valid', 'Valid Backend', true);
        $invalidId = $this->insertBackend('backend-invalid', '', true);

        $data = $this->post(
            ['backendIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new BackendsController('backends', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_backends}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['backendIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new BackendsController('backends', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_backends}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['backendIds' => [$invalidId]],
            static fn(): Response => (new BackendsController('backends', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_backends}}',
            $invalidId,
        );
    }

    public function testIndexPartialResultUsesRealModelValidationFailure(): void
    {
        $this->insertBackend('index-backend', 'Index Backend', true);
        $validId = $this->insertIndex('index-valid', 'Valid Index', true);
        $invalidId = $this->insertIndex('bad handle', 'Invalid Index', true);

        $data = $this->post(
            ['indexIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new IndicesController('indices', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_indices}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['indexIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new IndicesController('indices', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_indices}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['indexIds' => [$invalidId]],
            static fn(): Response => (new IndicesController('indices', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_indices}}',
            $invalidId,
        );
    }

    public function testPromotionPartialResultUsesRealModelValidationFailure(): void
    {
        $validId = $this->insertPromotion('promotion-valid', 'Valid Promotion', self::PREFIX, true);
        $invalidId = $this->insertPromotion('promotion-invalid', 'Invalid Promotion', '', true);

        $data = $this->post(
            ['promotionIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new PromotionsController('promotions', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_promotions}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['promotionIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new PromotionsController('promotions', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_promotions}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['promotionIds' => [$invalidId]],
            static fn(): Response => (new PromotionsController('promotions', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_promotions}}',
            $invalidId,
        );
    }

    public function testQueryRulePartialResultUsesRealModelValidationFailure(): void
    {
        $validId = $this->insertQueryRule('rule-valid', 'Valid Rule', true);
        $invalidId = $this->insertQueryRule('rule-invalid', '', true);

        $data = $this->post(
            ['ruleIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new QueryRulesController('query-rules', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_query_rules}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['ruleIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new QueryRulesController('query-rules', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_query_rules}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['ruleIds' => [$invalidId]],
            static fn(): Response => (new QueryRulesController('query-rules', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_query_rules}}',
            $invalidId,
        );
    }

    public function testWidgetConfigPartialResultUsesRealModelValidationFailure(): void
    {
        $validId = $this->insertWidgetConfig('widget-valid', 'Valid Widget', true);
        $invalidId = $this->insertWidgetConfig('bad handle', 'Invalid Widget', true);

        $data = $this->post(
            ['configIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_widget_configs}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['configIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_widget_configs}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['configIds' => [$invalidId]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_widget_configs}}',
            $invalidId,
        );
    }

    public function testWidgetStylePartialResultUsesRealModelValidationFailure(): void
    {
        $validId = $this->insertWidgetStyle('style-valid', 'Valid Style', true);
        $invalidId = $this->insertWidgetStyle('bad handle', 'Invalid Style', true);

        $data = $this->post(
            ['styleIds' => [$validId, $validId, $invalidId, self::STALE_ID]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDisableStyle(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_widget_styles}}', $validId, $invalidId);
        $this->assertAllSuccessRoundTrip(
            ['styleIds' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkEnableStyle(),
            '{{%searchmanager_widget_styles}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['styleIds' => [$invalidId]],
            static fn(): Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDisableStyle(),
            '{{%searchmanager_widget_styles}}',
            $invalidId,
        );
    }

    public function testApiKeyPartialResultNamesThrownServiceFailure(): void
    {
        $validId = $this->insertApiKey('api-key-valid', 'Valid API Key', true);
        $failureId = $this->insertApiKey('api-key-failure', 'Failing API Key', true);
        $service = new Pr132FailingApiKeyService(['failingId' => $failureId]);
        $this->swapPluginComponent('search-manager', 'apiKeys', $service);

        $data = $this->post(
            ['ids' => [$validId, $validId, $failureId, self::STALE_ID]],
            static fn(): Response => (new ApiKeysController('api-keys', SearchManager::$plugin))
                ->actionBulkDisable(),
        );

        $this->assertPartialContract($data, '{{%searchmanager_api_keys}}', $validId, $failureId);
        self::assertStringContainsString('Failing API Key', implode(' ', $data['errors']));
        $this->assertAllSuccessRoundTrip(
            ['ids' => [$validId, $validId, self::STALE_ID]],
            static fn(): Response => (new ApiKeysController('api-keys', SearchManager::$plugin))
                ->actionBulkEnable(),
            '{{%searchmanager_api_keys}}',
            $validId,
        );
        $this->assertZeroSuccessFailure(
            ['ids' => [$failureId]],
            static fn(): Response => (new ApiKeysController('api-keys', SearchManager::$plugin))
                ->actionBulkDisable(),
            '{{%searchmanager_api_keys}}',
            $failureId,
        );
    }

    public function testConfigBackedIndexCannotEnterTheNumericMutationSet(): void
    {
        $configIndex = new \lindemannrock\searchmanager\models\SearchIndex();
        $configIndex->id = self::STALE_ID - 1;
        $configIndex->name = 'Config Index';
        $configIndex->handle = self::PREFIX . '-config-index';
        $configIndex->source = 'config';
        $configIndex->enabled = true;

        $data = $this->withOnlySearchIndices(
            [$configIndex],
            fn(): array => $this->post(
                ['indexIds' => [$configIndex->id]],
                static fn(): Response => (new IndicesController('indices', SearchManager::$plugin))
                    ->actionBulkDisable(),
            ),
        );

        self::assertSame('failure', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(0, $data['count'] ?? null, json_encode($data));
        self::assertSame(1, $data['skipped'] ?? null, json_encode($data));
        self::assertSame([], $data['errors'] ?? null, json_encode($data));
        self::assertTrue($configIndex->enabled);
    }

    /**
     * @param class-string $controllerClass
     * @param non-empty-string $controllerId
     * @param non-empty-string $action
     * @param non-empty-string $identifierParam
     */
    #[DataProvider('malformedFamilyProvider')]
    public function testEveryFamilyRejectsMalformedIdentifierContainers(
        string $controllerClass,
        string $controllerId,
        string $action,
        string $identifierParam,
    ): void {
        $data = $this->post(
            [$identifierParam => 'not-a-list'],
            static fn(): Response => (new $controllerClass($controllerId, SearchManager::$plugin))
                ->runAction($action),
        );

        self::assertSame('failure', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(0, $data['count'] ?? null, json_encode($data));
        self::assertNotEmpty($data['errors'] ?? [], json_encode($data));
    }

    /**
     * @return iterable<string, array{class-string, non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function malformedFamilyProvider(): iterable
    {
        yield 'backends' => [BackendsController::class, 'backends', 'bulk-enable', 'backendIds'];
        yield 'indices' => [IndicesController::class, 'indices', 'bulk-enable', 'indexIds'];
        yield 'promotions' => [PromotionsController::class, 'promotions', 'bulk-enable', 'promotionIds'];
        yield 'query rules' => [QueryRulesController::class, 'query-rules', 'bulk-enable', 'ruleIds'];
        yield 'widget configs' => [WidgetsController::class, 'widgets', 'bulk-enable', 'configIds'];
        yield 'widget styles' => [WidgetsController::class, 'widgets', 'bulk-enable-style', 'styleIds'];
        yield 'API keys' => [ApiKeysController::class, 'api-keys', 'bulk-enable', 'ids'];
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): Response $action
     * @return array<string, mixed>
     */
    private function post(array $params, callable $action): array
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
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        Craft::$app->set('response', new Response());

        return $action()->data;
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

    /**
     * @param array<string, mixed> $data
     */
    private function assertPartialContract(
        array $data,
        string $table,
        int $successfulId,
        int $failedId,
    ): void {
        self::assertSame('partial', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(1, $data['count'] ?? null, json_encode($data));
        self::assertSame(1, $data['skipped'] ?? null, json_encode($data));
        self::assertNotEmpty($data['errors'] ?? [], json_encode($data));
        self::assertFalse($this->rowEnabled($table, $successfulId));
        self::assertTrue($this->rowEnabled($table, $failedId));
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): Response $action
     */
    private function assertAllSuccessRoundTrip(
        array $params,
        callable $action,
        string $table,
        int $successfulId,
    ): void {
        $data = $this->post($params, $action);

        self::assertSame('success', $data['status'] ?? null, json_encode($data));
        self::assertTrue($data['success'] ?? false, json_encode($data));
        self::assertSame(1, $data['count'] ?? null, json_encode($data));
        self::assertSame(1, $data['skipped'] ?? null, json_encode($data));
        self::assertSame([], $data['errors'] ?? null, json_encode($data));
        self::assertTrue($this->rowEnabled($table, $successfulId));
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): Response $action
     */
    private function assertZeroSuccessFailure(
        array $params,
        callable $action,
        string $table,
        int $failedId,
    ): void {
        $data = $this->post($params, $action);

        self::assertSame('failure', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(0, $data['count'] ?? null, json_encode($data));
        self::assertNotEmpty($data['errors'] ?? [], json_encode($data));
        self::assertTrue($this->rowEnabled($table, $failedId));
    }

    private function rowEnabled(string $table, int $id): bool
    {
        $enabled = Craft::$app->getDb()->createCommand(
            "SELECT [[enabled]] FROM {$table} WHERE [[id]] = :id",
            [':id' => $id],
        )->queryScalar();
        self::assertNotFalse($enabled, "Could not resolve marker row {$id} in {$table}.");
        return (bool)$enabled;
    }

    private function insertBackend(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_backends}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => (int)$enabled,
        ]);
    }

    private function insertIndex(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'elementType' => \craft\elements\Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => self::PREFIX . '-index-backend',
            'enabled' => (int)$enabled,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'documentCount' => 0,
        ]);
    }

    private function insertPromotion(string $suffix, string $title, string $query, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => null,
            'title' => $title,
            'query' => $query,
            'matchType' => 'exact',
            'elementId' => (int)$this->actor->id,
            'elementType' => null,
            'position' => 1,
            'siteId' => null,
            'enabled' => (int)$enabled,
            'uid' => self::PREFIX . '-' . $suffix,
        ]);
    }

    private function insertQueryRule(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_query_rules}}', [
            'name' => $name,
            'indexHandle' => null,
            'matchType' => QueryRule::MATCH_EXACT,
            'matchValue' => self::PREFIX . '-' . $suffix,
            'actionType' => QueryRule::ACTION_SYNONYM,
            'actionValue' => '{"terms":["pr132"]}',
            'priority' => 0,
            'siteId' => null,
            'enabled' => (int)$enabled,
        ]);
    }

    private function insertWidgetConfig(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode(WidgetConfig::defaultSettings(), JSON_THROW_ON_ERROR),
            'enabled' => (int)$enabled,
        ]);
    }

    private function insertWidgetStyle(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_widget_styles}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => (int)$enabled,
        ]);
    }

    private function insertApiKey(string $suffix, string $name, bool $enabled): int
    {
        return $this->insert('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => ApiKey::TYPE_SERVER,
            'enabled' => (int)$enabled,
            'keyHash' => hash('sha256', self::PREFIX . '-' . $suffix),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_srv_' . substr(hash('sha256', $suffix), 0, 8),
            'allowedIndices' => json_encode([ApiKey::ALL_INDICES], JSON_THROW_ON_ERROR),
            'allowedReferrers' => '[]',
            'maxHitsPerPage' => null,
            'validUntil' => null,
            'rateLimit' => null,
            'lastUsedAt' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function insert(string $table, array $attributes): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $attributes['dateCreated'] = $now;
        $attributes['dateUpdated'] = $now;
        $attributes['uid'] ??= StringHelper::UUID();
        Craft::$app->getDb()->createCommand()->insert($table, $attributes)->execute();
        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function purgeMarkedRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::PREFIX, false])
            ->execute();

        foreach ([
            '{{%searchmanager_widget_configs}}' => 'handle',
            '{{%searchmanager_widget_styles}}' => 'handle',
            '{{%searchmanager_api_keys}}' => 'handle',
            '{{%searchmanager_promotions}}' => 'uid',
            '{{%searchmanager_query_rules}}' => 'matchValue',
            '{{%searchmanager_indices}}' => 'handle',
            '{{%searchmanager_backends}}' => 'handle',
        ] as $table => $column) {
            Craft::$app->getDb()->createCommand()
                ->delete($table, ['like', $column, self::PREFIX . '%', false])
                ->execute();
        }
    }
}

final class Pr132FailingApiKeyService extends ApiKeyService
{
    public int $failingId;

    public function bulkSetEnabled(array $ids, bool $enabled): int
    {
        if (in_array($this->failingId, $ids, true)) {
            throw new \RuntimeException('Marker service failure');
        }

        return parent::bulkSetEnabled($ids, $enabled);
    }
}
