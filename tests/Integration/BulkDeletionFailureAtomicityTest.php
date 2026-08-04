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
use craft\db\Command;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\controllers\QueryRulesController;
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.54.0
 */
#[CoversClass(BackendsController::class)]
#[CoversClass(PromotionsController::class)]
#[CoversClass(QueryRulesController::class)]
#[CoversClass(WidgetsController::class)]
#[CoversClass(ApiKeysController::class)]
final class BulkDeletionFailureAtomicityTest extends TestCase
{
    private const PREFIX = 'sm-pr132-delete-failure';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->purgeMarkedRows();

        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, [
            'accessCp',
            'searchManager:manageBackends',
            'searchManager:deleteBackends',
            'searchManager:managePromotions',
            'searchManager:deletePromotions',
            'searchManager:manageQueryRules',
            'searchManager:deleteQueryRules',
            'searchManager:manageWidgetConfigs',
            'searchManager:deleteWidgetConfigs',
            'searchManager:manageWidgetStyles',
            'searchManager:deleteWidgetStyles',
            'searchManager:manageApiKeys',
            'searchManager:revokeApiKeys',
        ]);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        BulkDeletionFailingDeleteCommand::$plan = null;
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        parent::tearDown();
    }

    public function testPromotionDeleteReportsPartialAfterLaterMutationFailure(): void
    {
        $firstId = $this->insertPromotion('promotion-first', 'First Promotion');
        $failedId = $this->insertPromotion('promotion-failed', 'Failed Promotion');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_promotions}}',
            'searchmanager_promotions',
            $firstId,
            $failedId,
            ['promotionIds' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new PromotionsController('promotions', SearchManager::$plugin))
                ->actionBulkDelete(),
        );

        $this->assertPartialDeleteResult($data, 'Failed Promotion');
        self::assertSame(0, $this->rowCount('{{%searchmanager_promotions}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_promotions}}', $failedId));
    }

    public function testQueryRuleDeleteReportsPartialAfterLaterMutationFailure(): void
    {
        $firstId = $this->insertQueryRule('rule-first', 'First Rule');
        $failedId = $this->insertQueryRule('rule-failed', 'Failed Rule');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_query_rules}}',
            'searchmanager_query_rules',
            $firstId,
            $failedId,
            ['ruleIds' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new QueryRulesController('query-rules', SearchManager::$plugin))
                ->actionBulkDelete(),
        );

        $this->assertPartialDeleteResult($data, 'Failed Rule');
        self::assertSame(0, $this->rowCount('{{%searchmanager_query_rules}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_query_rules}}', $failedId));
    }

    public function testBackendDeleteRollsBackFirstMutationWhenLaterMutationFails(): void
    {
        $firstId = $this->insertBackend('backend-first', 'First Backend');
        $failedId = $this->insertBackend('backend-failed', 'Failed Backend');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_backends}}',
            'searchmanager_backends',
            $firstId,
            $failedId,
            ['backendIds' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new BackendsController('backends', SearchManager::$plugin))
                ->actionBulkDelete(),
        );

        $this->assertTransactionalFailureResult($data, 'Failed Backend');
        self::assertSame(1, $this->rowCount('{{%searchmanager_backends}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_backends}}', $failedId));
    }

    public function testWidgetConfigDeleteRollsBackFirstMutationWhenLaterMutationFails(): void
    {
        $firstId = $this->insertWidgetConfig('widget-first', 'First Widget');
        $failedId = $this->insertWidgetConfig('widget-failed', 'Failed Widget');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_widget_configs}}',
            'searchmanager_widget_configs',
            $firstId,
            $failedId,
            ['configIds' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDelete(),
        );

        $this->assertTransactionalFailureResult($data, 'Failed Widget');
        self::assertSame(1, $this->rowCount('{{%searchmanager_widget_configs}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_widget_configs}}', $failedId));
    }

    public function testWidgetStyleDeleteRollsBackFirstMutationWhenLaterMutationFails(): void
    {
        $firstId = $this->insertWidgetStyle('style-first', 'First Style');
        $failedId = $this->insertWidgetStyle('style-failed', 'Failed Style');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_widget_styles}}',
            'searchmanager_widget_styles',
            $firstId,
            $failedId,
            ['styleIds' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new WidgetsController('widgets', SearchManager::$plugin))
                ->actionBulkDeleteStyle(),
        );

        $this->assertTransactionalFailureResult($data, 'Failed Style');
        self::assertSame(1, $this->rowCount('{{%searchmanager_widget_styles}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_widget_styles}}', $failedId));
    }

    public function testApiKeyDeleteRollsBackFirstMutationWhenLaterMutationFails(): void
    {
        $firstId = $this->insertApiKey('api-key-first', 'First API Key');
        $failedId = $this->insertApiKey('api-key-failed', 'Failed API Key');

        $data = $this->postWithSecondDeleteFailure(
            '{{%searchmanager_api_keys}}',
            'searchmanager_api_keys',
            $firstId,
            $failedId,
            ['ids' => [$firstId, $failedId]],
            static fn(): \yii\web\Response => (new ApiKeysController('api-keys', SearchManager::$plugin))
                ->actionBulkDelete(),
        );

        $this->assertTransactionalFailureResult($data, 'Failed API Key');
        self::assertSame(1, $this->rowCount('{{%searchmanager_api_keys}}', $firstId));
        self::assertSame(1, $this->rowCount('{{%searchmanager_api_keys}}', $failedId));
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): \yii\web\Response $action
     * @return array<string, mixed>
     */
    private function postWithSecondDeleteFailure(
        string $queryTable,
        string $rawTable,
        int $firstId,
        int $failedId,
        array $params,
        callable $action,
    ): array {
        $db = Craft::$app->getDb();
        $originalCommandClass = $db->commandClass;
        $plan = new BulkDeletionDeleteFailurePlan($queryTable, $rawTable, $firstId, $failedId);
        BulkDeletionFailingDeleteCommand::$plan = $plan;
        $db->commandClass = BulkDeletionFailingDeleteCommand::class;

        try {
            $data = $this->post($params, $action);
        } finally {
            $db->commandClass = $originalCommandClass;
            BulkDeletionFailingDeleteCommand::$plan = null;
        }

        self::assertSame($originalCommandClass, $db->commandClass);
        self::assertNull(BulkDeletionFailingDeleteCommand::$plan);
        self::assertSame(2, $plan->matchingDeletes, 'The failure boundary must be reached on the second delete.');
        self::assertTrue($plan->firstDeleteExecuted, 'The first delete statement must execute successfully.');
        self::assertTrue(
            $plan->firstRowMissingAtFailure,
            'The first row must be absent in the active connection before the second delete fails.',
        );
        self::assertTrue($plan->failedTargetMatched, 'The injected failure must target the later selected row.');

        return $data;
    }

    /**
     * @param array<string, mixed> $params
     * @param callable(): \yii\web\Response $action
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
    private function assertPartialDeleteResult(array $data, string $failedName): void
    {
        self::assertSame('partial', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(1, $data['count'] ?? null, json_encode($data));
        self::assertSame(0, $data['skipped'] ?? null, json_encode($data));
        self::assertStringContainsString($failedName, implode(' ', $data['errors'] ?? []));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertTransactionalFailureResult(array $data, string $failedName): void
    {
        self::assertSame('failure', $data['status'] ?? null, json_encode($data));
        self::assertFalse($data['success'] ?? true, json_encode($data));
        self::assertSame(0, $data['count'] ?? null, json_encode($data));
        self::assertSame(0, $data['skipped'] ?? null, json_encode($data));
        self::assertStringContainsString($failedName, implode(' ', $data['errors'] ?? []));
    }

    private function rowCount(string $table, int $id): int
    {
        return (int)(new Query())
            ->from($table)
            ->where(['id' => $id])
            ->count();
    }

    private function insertPromotion(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => null,
            'title' => $name,
            'query' => self::PREFIX . '-' . $suffix,
            'matchType' => 'exact',
            'elementId' => 1,
            'elementType' => null,
            'position' => 1,
            'siteId' => null,
            'enabled' => 0,
        ]);
    }

    private function insertQueryRule(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_query_rules}}', [
            'name' => $name,
            'indexHandle' => null,
            'matchType' => QueryRule::MATCH_EXACT,
            'matchValue' => self::PREFIX . '-' . $suffix,
            'actionType' => QueryRule::ACTION_SYNONYM,
            'actionValue' => '{"terms":["pr132-delete"]}',
            'priority' => 0,
            'siteId' => null,
            'enabled' => 0,
        ]);
    }

    private function insertBackend(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_backends}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 0,
        ]);
    }

    private function insertWidgetConfig(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode(WidgetConfig::defaultSettings(), JSON_THROW_ON_ERROR),
            'enabled' => 0,
        ]);
    }

    private function insertWidgetStyle(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_widget_styles}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styles' => '{}',
            'enabled' => 0,
        ]);
    }

    private function insertApiKey(string $suffix, string $name): int
    {
        return $this->insert('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => ApiKey::TYPE_SERVER,
            'enabled' => 0,
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
        foreach ([
            '{{%searchmanager_widget_configs}}' => 'handle',
            '{{%searchmanager_widget_styles}}' => 'handle',
            '{{%searchmanager_api_keys}}' => 'handle',
            '{{%searchmanager_promotions}}' => 'query',
            '{{%searchmanager_query_rules}}' => 'matchValue',
            '{{%searchmanager_backends}}' => 'handle',
        ] as $table => $column) {
            Craft::$app->getDb()->createCommand()
                ->delete($table, ['like', $column, self::PREFIX . '%', false])
                ->execute();
        }
    }
}

final class BulkDeletionDeleteFailurePlan
{
    public int $matchingDeletes = 0;
    public bool $firstDeleteExecuted = false;
    public bool $firstRowMissingAtFailure = false;
    public bool $failedTargetMatched = false;

    public function __construct(
        public readonly string $queryTable,
        public readonly string $rawTable,
        public readonly int $firstId,
        public readonly int $failedId,
    ) {
    }
}

final class BulkDeletionFailingDeleteCommand extends Command
{
    public static ?BulkDeletionDeleteFailurePlan $plan = null;

    public function execute()
    {
        $plan = self::$plan;
        $rawSql = $this->getRawSql();
        if (
            $plan === null
            || preg_match('/^\s*DELETE\s+FROM\b/i', $rawSql) !== 1
            || !str_contains($rawSql, $plan->rawTable)
        ) {
            return parent::execute();
        }

        $plan->matchingDeletes++;
        if ($plan->matchingDeletes === 2) {
            $plan->firstRowMissingAtFailure = !(new Query())
                ->from($plan->queryTable)
                ->where(['id' => $plan->firstId])
                ->exists();
            $plan->failedTargetMatched = preg_match(
                '/\b' . preg_quote((string)$plan->failedId, '/') . '\b/',
                $rawSql,
            ) === 1;

            throw new \RuntimeException('Injected bulk-deletion second-delete failure.');
        }

        $result = parent::execute();
        $plan->firstDeleteExecuted = $result === 1;
        return $result;
    }
}
