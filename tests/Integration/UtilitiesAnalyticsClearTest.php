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
use craft\db\Connection;
use craft\db\Query;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.54.0
 */
#[CoversClass(UtilitiesController::class)]
final class UtilitiesAnalyticsClearTest extends TestCase
{
    private const PREFIX = '__sm_utilities_clear_all_';
    private const PRIMARY_ROW_COUNT = 2;

    private int $siteId;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?Connection $originalDatabase = null;
    private ?Connection $isolatedDatabase = null;

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->restoreDatabase();

        try {
            parent::tearDown();
        } finally {
            Craft::$app->getSites()->refreshSites();
        }
    }

    public function testClearAllAnalyticsPurgesPrimaryAndDetailTables(): void
    {
        $this->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $admin = $this->createTestUser(self::PREFIX . 'admin_');
        $admin->admin = true;
        $this->actingAs($admin);
        Craft::$app->getSites()->refreshSites();
        self::assertContains($this->siteId, Craft::$app->getSites()->getEditableSiteIds());
        $this->installIsolatedAnalyticsDatabase();
        $this->seedAnalyticsRows();
        $this->withPostJson();

        self::assertSame(self::PRIMARY_ROW_COUNT, $this->tableCount('{{%searchmanager_analytics}}'));
        self::assertSame(1, $this->tableCount('{{%searchmanager_rule_analytics}}'));
        self::assertSame(1, $this->tableCount('{{%searchmanager_promotion_analytics}}'));

        $response = (new UtilitiesController('utilities', SearchManager::$plugin))->actionClearAllAnalytics();

        self::assertSame([
            'success' => true,
            'message' => 'All analytics data cleared successfully (2 records deleted)',
        ], $response->data);
        self::assertSame(0, $this->tableCount('{{%searchmanager_analytics}}'));
        self::assertSame(0, $this->tableCount('{{%searchmanager_rule_analytics}}'));
        self::assertSame(0, $this->tableCount('{{%searchmanager_promotion_analytics}}'));
    }

    private function installIsolatedAnalyticsDatabase(): void
    {
        $this->originalDatabase = Craft::$app->getDb();
        $this->isolatedDatabase = new Connection(['dsn' => 'sqlite::memory:']);
        $this->isolatedDatabase->open();

        foreach ([
            'searchmanager_analytics',
            'searchmanager_rule_analytics',
            'searchmanager_promotion_analytics',
        ] as $table) {
            $this->isolatedDatabase->createCommand(
                "CREATE TABLE {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    query VARCHAR(500) NOT NULL,
                    siteId INTEGER NULL
                )",
            )->execute();
        }

        Craft::$app->set('db', $this->isolatedDatabase);
    }

    private function restoreDatabase(): void
    {
        if ($this->originalDatabase !== null) {
            Craft::$app->set('db', $this->originalDatabase);
            $this->originalDatabase = null;
        }

        if ($this->isolatedDatabase !== null) {
            $this->isolatedDatabase->close();
            $this->isolatedDatabase = null;
        }
    }

    private function seedAnalyticsRows(): void
    {
        foreach (range(1, self::PRIMARY_ROW_COUNT) as $row) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
                'query' => self::PREFIX . "primary_{$row}",
                'siteId' => $this->siteId,
            ])->execute();
        }

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_rule_analytics}}', [
            'query' => self::PREFIX . 'rule',
            'siteId' => $this->siteId,
        ])->execute();
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotion_analytics}}', [
            'query' => self::PREFIX . 'promotion',
            'siteId' => $this->siteId,
        ])->execute();
    }

    private function withPostJson(): void
    {
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
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

    private function tableCount(string $table): int
    {
        return (int)(new Query())->from($table)->count();
    }
}
