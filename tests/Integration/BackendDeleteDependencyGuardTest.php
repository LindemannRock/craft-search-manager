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
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\DependencyService;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(BackendsController::class)]
#[CoversClass(DependencyService::class)]
final class BackendDeleteDependencyGuardTest extends TestCase
{
    private const PREFIX = 'sm-backend-delete-guard';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?object $originalConfigService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set(
            'config',
            new SearchManagerConfigServiceStub($this->originalConfigService),
        );
        $this->purgeMarkedRows();
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeMarkedRows();
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
            $this->originalConfigService = null;
        }
        parent::tearDown();
    }

    public function testBackendDeleteIsBlockedWhenResolvedIndexUsesBackend(): void
    {
        $defaultBackendId = $this->insertBackend('default', 'Default Backend');
        $targetBackendId = $this->insertBackend('target', 'Target Backend');
        $this->insertIndex('referencing-index', 'Blog Search', self::PREFIX . '-target');
        $this->setDefaultBackend(self::PREFIX . '-default');
        $this->actWithPermissions(['searchManager:manageBackends', 'searchManager:deleteBackends', 'searchManager:manageIndices']);
        $this->withPostJson(['backendId' => $targetBackendId]);

        $response = (new BackendsController('backends', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame(
            'Cannot delete “Target Backend” — it is in use by: Index: Blog Search (sm-backend-delete-guard-referencing-index).',
            $data['error'] ?? null,
        );
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $defaultBackendId]));
    }

    public function testBackendDeleteSucceedsWhenNoResolvedIndexUsesBackend(): void
    {
        $targetBackendId = $this->insertBackend('unused', 'Unused Backend');
        $this->setDefaultBackend(null);
        $this->actWithPermissions(['searchManager:manageBackends', 'searchManager:deleteBackends', 'searchManager:manageIndices']);
        $this->withPostJson(['backendId' => $targetBackendId]);

        $response = (new BackendsController('backends', SearchManager::$plugin))->actionDelete();
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(0, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
    }

    public function testDirectModelDeleteRejectsActiveDefault(): void
    {
        $targetBackendId = $this->insertBackend('direct-default', 'Direct Default Backend');
        $this->setDefaultBackend(self::PREFIX . '-direct-default');
        $backend = ConfiguredBackend::findById($targetBackendId);

        self::assertNotNull($backend);
        self::assertFalse($backend->delete());
        self::assertSame(
            'Cannot delete the default backend. Set another backend as default first.',
            $backend->getFirstError('handle'),
        );
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
    }

    public function testMutatedModelHandleCannotBypassActiveDefaultGuard(): void
    {
        $storedHandle = self::PREFIX . '-mutated-default';
        $targetBackendId = $this->insertBackend('mutated-default', 'Mutated Default Backend');
        $this->setDefaultBackend($storedHandle);
        $settings = SearchManager::$plugin->getSettings();
        $backend = ConfiguredBackend::findById($targetBackendId);

        self::assertNotNull($backend);
        $backend->handle = self::PREFIX . '-caller-mutated';

        self::assertFalse($backend->delete());
        self::assertSame(
            'Cannot delete the default backend. Set another backend as default first.',
            $backend->getFirstError('handle'),
        );
        self::assertSame(
            $storedHandle,
            (new Query())
                ->select('handle')
                ->from('{{%searchmanager_backends}}')
                ->where(['id' => $targetBackendId])
                ->scalar(),
        );
        self::assertSame($storedHandle, $settings->defaultBackendHandle);
    }

    public function testDirectModelDeleteAllowsNonDefaultBackend(): void
    {
        $defaultBackendId = $this->insertBackend('direct-kept-default', 'Direct Kept Default');
        $targetBackendId = $this->insertBackend('direct-target', 'Direct Target');
        $this->setDefaultBackend(self::PREFIX . '-direct-kept-default');
        $target = ConfiguredBackend::findById($targetBackendId);

        self::assertNotNull($target);
        self::assertTrue($target->delete(), print_r($target->getErrors(), true));
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $defaultBackendId]));
        self::assertSame(0, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
        self::assertSame(
            self::PREFIX . '-direct-kept-default',
            SearchManager::$plugin->getSettings()->defaultBackendHandle,
        );
    }

    public function testBulkBackendDeleteIsBlockedWhenResolvedIndexUsesBackend(): void
    {
        $targetBackendId = $this->insertBackend('bulk-target', 'Bulk Target Backend');
        $this->insertIndex('bulk-referencing-index', 'Bulk Blog Search', self::PREFIX . '-bulk-target');
        $this->setDefaultBackend(null);
        $this->actWithPermissions(['searchManager:manageBackends', 'searchManager:deleteBackends', 'searchManager:manageIndices']);
        $this->withPostJson(['backendIds' => [$targetBackendId]]);

        $response = (new BackendsController('backends', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame([
            'Cannot delete “Bulk Target Backend” — it is in use by: Index: Bulk Blog Search (sm-backend-delete-guard-bulk-referencing-index).',
        ], $data['errors'] ?? null);
        self::assertSame('Cannot delete “Bulk Target Backend” — it is in use by: Index: Bulk Blog Search (sm-backend-delete-guard-bulk-referencing-index).', $data['error'] ?? null);
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
    }

    public function testBulkBackendDeleteDoesNotDeleteAnySelectedBackendWhenOneIsInUse(): void
    {
        $usedBackendId = $this->insertBackend('bulk-mixed-used', 'Bulk Mixed Used Backend');
        $unusedBackendId = $this->insertBackend('bulk-mixed-unused', 'Bulk Mixed Unused Backend');
        $this->insertIndex('bulk-mixed-referencing-index', 'Bulk Mixed Blog Search', self::PREFIX . '-bulk-mixed-used');
        $this->setDefaultBackend(null);
        $this->actWithPermissions(['searchManager:manageBackends', 'searchManager:deleteBackends', 'searchManager:manageIndices']);
        $this->withPostJson(['backendIds' => [$usedBackendId, $unusedBackendId]]);

        $response = (new BackendsController('backends', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true);
        self::assertSame([
            'Cannot delete “Bulk Mixed Used Backend” — it is in use by: Index: Bulk Mixed Blog Search (sm-backend-delete-guard-bulk-mixed-referencing-index).',
        ], $data['errors'] ?? null);
        self::assertSame('Cannot delete “Bulk Mixed Used Backend” — it is in use by: Index: Bulk Mixed Blog Search (sm-backend-delete-guard-bulk-mixed-referencing-index).', $data['error'] ?? null);
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $usedBackendId]));
        self::assertSame(1, $this->countRows('{{%searchmanager_backends}}', ['id' => $unusedBackendId]));
    }

    public function testBulkBackendDeleteSucceedsWhenNoResolvedIndexUsesBackend(): void
    {
        $targetBackendId = $this->insertBackend('bulk-unused', 'Bulk Unused Backend');
        $this->setDefaultBackend(null);
        $this->actWithPermissions(['searchManager:manageBackends', 'searchManager:deleteBackends', 'searchManager:manageIndices']);
        $this->withPostJson(['backendIds' => [$targetBackendId]]);

        $response = (new BackendsController('backends', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? false, json_encode($data));
        self::assertSame(1, $data['count'] ?? null);
        self::assertSame(0, $this->countRows('{{%searchmanager_backends}}', ['id' => $targetBackendId]));
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

    private function insertBackend(string $suffix, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => $name,
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function insertIndex(string $suffix, string $name, string $backend): int
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
            'backend' => $backend,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function setDefaultBackend(?string $handle): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->defaultBackendHandle = $handle;
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
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
