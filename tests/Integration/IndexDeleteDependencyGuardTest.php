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
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(IndicesController::class)]
#[CoversClass(DependencyService::class)]
final class IndexDeleteDependencyGuardTest extends TestCase
{
    private const PREFIX = 'sm-index-delete-guard';

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

    public function testIndexDeleteIsBlockedWhenResolvedWidgetUsesIndex(): void
    {
        $indexId = $this->insertIndex('widget-index', 'Widget Index');
        $this->insertWidget('widget', 'Search Widget', [self::PREFIX . '-widget-index']);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexId' => $indexId]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame(
            'Cannot delete “Widget Index” — it is in use by: Widget: Search Widget.',
            $data['error'] ?? null,
        );
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $indexId]));
    }

    public function testIndexDeleteIsBlockedWhenApiKeyScopesIndex(): void
    {
        $indexId = $this->insertIndex('api-key-index', 'API Key Index');
        $this->insertApiKey('api-key', 'Public Key', [self::PREFIX . '-api-key-index']);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexId' => $indexId]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame(
            'Cannot delete “API Key Index” — it is in use by: API key: Public Key.',
            $data['error'] ?? null,
        );
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $indexId]));
    }

    public function testIndexDeleteSucceedsWhenOnlyApiKeyAllowsAllIndices(): void
    {
        $indexId = $this->insertIndex('api-key-all-index', 'All Key Index');
        $this->insertApiKey('api-key-all', 'All Indices Key', ['*']);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexId' => $indexId]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(0, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $indexId]));
    }

    public function testIndexDeleteSucceedsWhenNoResolvedDependencyUsesIndex(): void
    {
        $indexId = $this->insertIndex('unused-index', 'Unused Index');
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexId' => $indexId]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(0, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $indexId]));
    }

    public function testBulkIndexDeleteDoesNotDeleteAnySelectedIndexWhenOneIsInUse(): void
    {
        $usedIndexId = $this->insertIndex('bulk-mixed-used', 'Bulk Mixed Used Index');
        $unusedIndexId = $this->insertIndex('bulk-mixed-unused', 'Bulk Mixed Unused Index');
        $this->insertWidget('bulk-widget', 'Bulk Widget', [self::PREFIX . '-bulk-mixed-used']);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexIds' => [$usedIndexId, $unusedIndexId]]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame([
            'Cannot delete “Bulk Mixed Used Index” — it is in use by: Widget: Bulk Widget.',
        ], $data['errors'] ?? null);
        self::assertSame('Cannot delete “Bulk Mixed Used Index” — it is in use by: Widget: Bulk Widget.', $data['error'] ?? null);
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $usedIndexId]));
        self::assertSame(1, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $unusedIndexId]));
    }

    public function testBulkIndexDeleteSucceedsWhenNoResolvedDependencyUsesSelectedIndices(): void
    {
        $firstIndexId = $this->insertIndex('bulk-unused-one', 'Bulk Unused One');
        $secondIndexId = $this->insertIndex('bulk-unused-two', 'Bulk Unused Two');
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:deleteIndices']);
        $this->withPostJson(['indexIds' => [$firstIndexId, $secondIndexId]]);

        $response = (new IndicesController('indices', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(2, $data['count'] ?? null);
        self::assertSame(0, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $firstIndexId]));
        self::assertSame(0, $this->countMarkedRows('{{%searchmanager_indices}}', ['id' => $secondIndexId]));
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

    private function insertIndex(string $suffix, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'backend' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param list<string> $indexHandles
     */
    private function insertWidget(string $suffix, string $name, array $indexHandles): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        $settings = WidgetConfig::defaultSettings();
        $settings['search']['indexHandles'] = $indexHandles;

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
     * @param list<string> $allowedIndices
     */
    private function insertApiKey(string $suffix, string $name, array $allowedIndices): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_api_keys}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'type' => 'public',
            'enabled' => 1,
            'keyHash' => hash('sha256', self::PREFIX . '-' . $suffix),
            'encryptedKey' => null,
            'keyPrefix' => 'sm_pub_' . substr(hash('sha256', self::PREFIX . '-' . $suffix), 0, 8),
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
        $indexIds = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::PREFIX . '%', false])
            ->column();

        if ($indexIds !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $indexIds)])
                ->execute();
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $indexIds)])
                ->execute();
        }

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
