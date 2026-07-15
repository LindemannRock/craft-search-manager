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
use lindemannrock\searchmanager\controllers\WidgetsController;
use lindemannrock\searchmanager\models\WidgetConfig;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(WidgetsController::class)]
final class WidgetConfigBulkDeleteGuardTest extends TestCase
{
    private const PREFIX = 'sm-widget-config-bulk-delete';

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

    public function testBulkWidgetConfigDeleteDoesNotDeleteAnySelectedConfigWhenOneIsDefault(): void
    {
        $defaultHandle = SearchManager::$plugin->getSettings()->defaultWidgetHandle;
        self::assertNotEmpty($defaultHandle);
        $defaultConfig = SearchManager::$plugin->widgetConfigs->getByHandle((string)$defaultHandle);
        self::assertNotNull($defaultConfig);
        self::assertNotNull($defaultConfig->id);

        $unusedConfigId = $this->insertWidget('unused-widget', 'Unused Widget');
        $this->actWithPermissions(['searchManager:manageWidgetConfigs', 'searchManager:deleteWidgetConfigs']);
        $this->withPostJson(['configIds' => [$defaultConfig->id, $unusedConfigId]]);

        $response = (new WidgetsController('widgets', SearchManager::$plugin))->actionBulkDelete();
        $data = $response->data;

        self::assertSame(false, $data['success'] ?? true, json_encode($data));
        self::assertSame([
            'Cannot delete the default widget. Set another widget as default first.',
        ], $data['errors'] ?? null);
        self::assertSame('Cannot delete the default widget. Set another widget as default first.', $data['error'] ?? null);
        self::assertSame(1, $this->countMarkedRows(['id' => $defaultConfig->id]));
        self::assertSame(1, $this->countMarkedRows(['id' => $unusedConfigId]));
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

    private function insertWidget(string $suffix, string $name): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_widget_configs}}', [
            'handle' => self::PREFIX . '-' . $suffix,
            'name' => $name,
            'type' => 'modal',
            'styleHandle' => null,
            'settings' => json_encode(WidgetConfig::defaultSettings(), JSON_THROW_ON_ERROR),
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
    private function countMarkedRows(array $condition): int
    {
        return (int)(new Query())
            ->from('{{%searchmanager_widget_configs}}')
            ->where($condition)
            ->count();
    }

    private function purgeMarkedRows(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_widget_configs}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}
