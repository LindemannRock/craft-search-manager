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
use craft\helpers\Db;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
#[CoversClass(SettingsController::class)]
final class AnalyticsRetentionLifecycleTest extends TestCase
{
    private const PREFIX = '__sm_pr131_';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;
    private ?Connection $originalDatabase = null;
    private ?Connection $isolatedDatabase = null;

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->restoreDatabase();

        parent::tearDown();
    }

    public function testManualAndScheduledServiceCleanupHaveIdenticalLifecycleAndPrimaryCount(): void
    {
        $this->installIsolatedAnalyticsDatabase();
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;
        $this->seedOldAndRecentRows();
        $this->withPostJson();
        $controller = $this->settingsController();

        $response = $controller->actionCleanupAnalytics();
        $manualRemaining = $this->remainingQueries();

        self::assertSame([
            'success' => true,
            'message' => 'Deleted 3 old analytics records',
        ], $response->data);
        self::assertSame([[
            'message' => 'Analytics cleanup completed',
            'params' => [
                'retention_days' => 30,
                'deleted_count' => 3,
            ],
        ]], $controller->infoLogs);
        $this->assertOnlyRecentRowsRemain();

        $this->resetAnalyticsRows();
        $this->seedOldAndRecentRows();
        $scheduledDeleted = SearchManager::$plugin->analytics->cleanupOldAnalytics();

        self::assertSame(3, $scheduledDeleted);
        self::assertSame($manualRemaining, $this->remainingQueries());
        $this->assertOnlyRecentRowsRemain();
    }

    #[DataProvider('invalidRetentionProvider')]
    public function testInvalidRetentionPreservesRowsAndCurrentValidationResponse(int $retention): void
    {
        $this->installIsolatedAnalyticsDatabase();
        SearchManager::$plugin->getSettings()->analyticsRetention = $retention;
        $this->seedOldAndRecentRows();
        $before = $this->tableCounts();
        $this->withPostJson();
        $controller = $this->settingsController();

        $response = $controller->actionCleanupAnalytics();

        self::assertSame([
            'success' => false,
            'error' => 'Analytics retention must be greater than 0 to perform cleanup.',
        ], $response->data);
        self::assertSame([], $controller->infoLogs);
        self::assertSame([], $controller->errorLogs);
        self::assertSame($before, $this->tableCounts());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidRetentionProvider(): iterable
    {
        yield 'zero retention' => [0];
        yield 'negative retention' => [-1];
    }

    #[DataProvider('transactionFailureProvider')]
    public function testRetentionCleanupRollsBackAllTables(string $failingTable): void
    {
        $this->installIsolatedAnalyticsDatabase();
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;
        $this->seedOldAndRecentRows();
        $before = $this->remainingQueries();
        $this->installDeleteFailureTrigger($failingTable);

        try {
            SearchManager::$plugin->analytics->cleanupOldAnalytics();
            self::fail('The injected retention deletion failure should escape after rollback.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('forced retention delete failure', $exception->getMessage());
        }

        self::assertSame($before, $this->remainingQueries());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transactionFailureProvider(): iterable
    {
        yield 'detail deletion' => ['searchmanager_promotion_analytics'];
        yield 'primary deletion' => ['searchmanager_analytics'];
    }

    public function testManualCleanupKeepsGenericProductionFailureAndContextualLog(): void
    {
        $this->installIsolatedAnalyticsDatabase();
        SearchManager::$plugin->getSettings()->analyticsRetention = 30;
        $this->seedOldAndRecentRows();
        $this->installDeleteFailureTrigger('searchmanager_promotion_analytics');
        $this->withPostJson();
        $controller = $this->settingsController();
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalDevMode = $generalConfig->devMode;

        try {
            $generalConfig->devMode = false;
            $response = $controller->actionCleanupAnalytics();
        } finally {
            $generalConfig->devMode = $originalDevMode;
        }

        self::assertSame([
            'success' => false,
            'error' => 'Failed to cleanup analytics. Check logs for details.',
        ], $response->data);
        self::assertCount(1, $controller->errorLogs);
        self::assertSame('Failed to cleanup analytics', $controller->errorLogs[0]['message']);
        self::assertStringContainsString(
            'forced retention delete failure',
            (string)($controller->errorLogs[0]['params']['error'] ?? ''),
        );
        self::assertStringNotContainsString('forced retention delete failure', (string)$response->data['error']);
        self::assertSame([6, 6, 6], $this->tableCounts());
    }

    private function settingsController(): Pr131SettingsController
    {
        return new Pr131SettingsController('settings', SearchManager::$plugin);
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
                    siteId INTEGER NULL,
                    dateCreated DATETIME NOT NULL
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

    private function seedOldAndRecentRows(): void
    {
        $old = Db::prepareDateForDb(new \DateTimeImmutable('-90 days'));
        $recent = Db::prepareDateForDb(new \DateTimeImmutable('-2 days'));

        foreach ([
            'searchmanager_analytics' => 'primary',
            'searchmanager_rule_analytics' => 'rule',
            'searchmanager_promotion_analytics' => 'promotion',
        ] as $table => $type) {
            foreach ([1, 2, 3] as $row) {
                foreach (['old' => $old, 'recent' => $recent] as $age => $dateCreated) {
                    Craft::$app->getDb()->createCommand()->insert("{{%{$table}}}", [
                        'query' => self::PREFIX . "{$type}_{$age}_{$row}",
                        'siteId' => $row,
                        'dateCreated' => $dateCreated,
                    ])->execute();
                }
            }
        }
    }

    private function resetAnalyticsRows(): void
    {
        foreach ([
            '{{%searchmanager_rule_analytics}}',
            '{{%searchmanager_promotion_analytics}}',
            '{{%searchmanager_analytics}}',
        ] as $table) {
            Craft::$app->getDb()->createCommand()->delete($table)->execute();
        }
    }

    private function installDeleteFailureTrigger(string $table): void
    {
        Craft::$app->getDb()->getMasterPdo()->exec(
            "CREATE TRIGGER fail_retention_delete
            BEFORE DELETE ON {$table}
            BEGIN
                SELECT RAISE(ABORT, 'forced retention delete failure');
            END",
        );
    }

    private function withPostJson(): void
    {
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->getHeaders()->set('Accept', 'application/json');
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
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

    private function assertOnlyRecentRowsRemain(): void
    {
        self::assertSame([3, 3, 3], $this->tableCounts());
        foreach ($this->remainingQueries() as $queries) {
            self::assertCount(3, $queries);
            foreach ($queries as $query) {
                self::assertStringContainsString('_recent_', $query);
            }
        }
    }

    /**
     * @return array{list<string>, list<string>, list<string>}
     */
    private function remainingQueries(): array
    {
        $queries = [];
        foreach ([
            '{{%searchmanager_analytics}}',
            '{{%searchmanager_rule_analytics}}',
            '{{%searchmanager_promotion_analytics}}',
        ] as $table) {
            $queries[] = array_map(
                static fn(array $row): string => (string)$row['query'],
                (new Query())->select(['query'])->from($table)->orderBy(['query' => SORT_ASC])->all(),
            );
        }

        return $queries;
    }

    /**
     * @return array{int, int, int}
     */
    private function tableCounts(): array
    {
        return [
            (int)(new Query())->from('{{%searchmanager_analytics}}')->count(),
            (int)(new Query())->from('{{%searchmanager_rule_analytics}}')->count(),
            (int)(new Query())->from('{{%searchmanager_promotion_analytics}}')->count(),
        ];
    }
}

final class Pr131SettingsController extends SettingsController
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $infoLogs = [];

    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $errorLogs = [];

    public function requirePermission(string $permissionName): void
    {
    }

    public function requirePostRequest(): void
    {
    }

    public function requireAcceptsJson(): void
    {
    }

    protected function logInfo(string $message, array $params = []): void
    {
        $this->infoLogs[] = compact('message', 'params');
    }

    protected function logError(string $message, array $params = []): void
    {
        $this->errorLogs[] = compact('message', 'params');
    }
}
