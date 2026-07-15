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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\ApiKeysController;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(ApiKeysController::class)]
#[CoversClass(DependencyService::class)]
final class ApiKeyDeleteDependencyGuardTest extends TestCase
{
    private const PREFIX = 'sm-api-key-delete-guard';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeMarkedRows();
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        parent::tearDown();
    }

    public function testApiKeyDeleteIsBlockedWhenResolvedWidgetUsesKey(): void
    {
        $apiKeyId = $this->insertApiKey('used-key', 'Used API Key');
        $this->insertWidget('widget', 'Search Widget', self::PREFIX . '-used-key');
        $this->actWithPermissions(['searchManager:manageApiKeys', 'searchManager:revokeApiKeys']);
        $this->withPostJson(['keyId' => $apiKeyId]);

        $response = (new ApiKeysController('api-keys', SearchManager::$plugin))->actionDelete();
        $data = $response?->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame(
            'Cannot delete “Used API Key” — it is in use by: Widget: Search Widget.',
            $data['error'] ?? null,
        );
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_api_keys}}', ['id' => $apiKeyId]));
    }

    public function testApiKeyDeleteSucceedsWhenNoResolvedWidgetUsesKey(): void
    {
        $apiKeyId = $this->insertApiKey('unused-key', 'Unused API Key');
        $this->actWithPermissions(['searchManager:manageApiKeys', 'searchManager:revokeApiKeys']);
        $this->withPostJson(['keyId' => $apiKeyId]);

        $response = (new ApiKeysController('api-keys', SearchManager::$plugin))->actionDelete();
        $data = $response?->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(0, $this->countMarkedRows('{{%searchmanager_api_keys}}', ['id' => $apiKeyId]));
    }

    public function testBulkApiKeyDeleteDoesNotDeleteAnySelectedKeyWhenOneIsInUse(): void
    {
        $usedKeyId = $this->insertApiKey('bulk-used-key', 'Bulk Used API Key');
        $unusedKeyId = $this->insertApiKey('bulk-unused-key', 'Bulk Unused API Key');
        $this->insertWidget('bulk-widget', 'Bulk Widget', self::PREFIX . '-bulk-used-key');
        $this->actWithPermissions(['searchManager:manageApiKeys', 'searchManager:revokeApiKeys']);
        $this->withPostJson(['ids' => [$usedKeyId, $unusedKeyId]]);

        $response = (new ApiKeysController('api-keys', SearchManager::$plugin))->actionBulkDelete();
        $data = $response?->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame([
            'Cannot delete “Bulk Used API Key” — it is in use by: Widget: Bulk Widget.',
        ], $data['errors'] ?? null);
        self::assertSame('Cannot delete “Bulk Used API Key” — it is in use by: Widget: Bulk Widget.', $data['error'] ?? null);
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_api_keys}}', ['id' => $usedKeyId]));
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_api_keys}}', ['id' => $unusedKeyId]));
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

    private function insertApiKey(string $suffix, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => ApiKey::TYPE_PUBLIC,
            'enabled' => 1,
            'keyHash' => hash('sha256', self::PREFIX . '-' . $suffix),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_pub_' . substr(hash('sha256', self::PREFIX . '-' . $suffix), 0, 8),
            'allowedIndices' => json_encode([ApiKey::ALL_INDICES], JSON_THROW_ON_ERROR),
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

    private function insertWidget(string $suffix, string $name, string $apiKeyHandle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $settings = WidgetConfig::defaultSettings();
        $settings['apiKeyHandle'] = $apiKeyHandle;

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . '-' . $suffix,
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
     * @param array<string, mixed> $condition
     */
    private function countMarkedRows(string $table, array $condition): int
    {
        return (int)(new Query())
            ->from($table)
            ->where($condition)
            ->count();
    }

    private function purgeMarkedRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_api_keys}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
