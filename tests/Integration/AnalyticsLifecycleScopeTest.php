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
use craft\elements\User;
use craft\errors\MissingComponentException;
use craft\helpers\Db;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\AnalyticsService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
#[CoversClass(AnalyticsController::class)]
#[CoversClass(UtilitiesController::class)]
#[CoversClass(AnalyticsService::class)]
final class AnalyticsLifecycleScopeTest extends TestCase
{
    private const PREFIX = '__sm_pr128_';

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

    public function testRestrictedIdentityReportsAndDestructiveControllersStayWithinEditableSite(): void
    {
        [$editableSiteId, $otherSiteId] = $this->twoSiteIds();
        $this->actAsSiteScopedUser([$editableSiteId]);
        $this->installIsolatedAnalyticsDatabase();
        $ids = $this->seedAnalyticsRows($editableSiteId, $otherSiteId);

        self::assertSame(2, SearchManager::$plugin->analytics->getAnalyticsCount([$editableSiteId]));
        self::assertSame(2, SearchManager::$plugin->analytics->getAnalyticsCount([$otherSiteId]));
        $this->assertControllerReportScope([$editableSiteId], 2);

        $this->withPostJson(['analyticId' => $ids[$editableSiteId][0]]);
        $inScope = $this->analyticsController()->actionDelete();
        self::assertSame(['success' => true], $inScope->data);

        $this->withPostJson(['analyticId' => $ids[$otherSiteId][0]]);
        $outOfScope = $this->analyticsController()->actionDelete();
        $this->withPostJson(['analyticId' => 999999]);
        $missing = $this->analyticsController()->actionDelete();

        self::assertSame($missing->data, $outOfScope->data);
        self::assertSame([
            'success' => false,
            'error' => 'Could not delete analytics record',
        ], $outOfScope->data);
        self::assertSame(1, $this->rowCount('{{%searchmanager_analytics}}', ['siteId' => $editableSiteId]));
        self::assertSame(2, $this->rowCount('{{%searchmanager_analytics}}', ['siteId' => $otherSiteId]));

        $this->withPostJson();
        $utility = $this->utilitiesController()->actionClearAllAnalytics();

        self::assertSame([
            'success' => true,
            'message' => 'All analytics data cleared successfully (1 records deleted)',
        ], $utility->data);
        $this->assertSiteRows($editableSiteId, 0);
        $this->assertSiteRows($otherSiteId, 2);
    }

    public function testZeroEditableSiteIdentityCannotReadOrClearAnalytics(): void
    {
        [$firstSiteId, $secondSiteId] = $this->twoSiteIds();
        $this->actAsSiteScopedUser([]);
        $this->installIsolatedAnalyticsDatabase();
        $this->seedAnalyticsRows($firstSiteId, $secondSiteId);
        $before = $this->allTableCounts();

        self::assertSame(0, SearchManager::$plugin->analytics->getAnalyticsCount([]));
        $this->assertControllerReportScope([], 0);
        self::assertSame(0, SearchManager::$plugin->analytics->clearAnalytics([]));

        $this->withPostJson();
        try {
            $this->analyticsController()->actionClearAll();
            self::fail('The console test harness has no session component.');
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        $this->withPostJson();
        $utilityClear = $this->utilitiesController()->actionClearAllAnalytics();
        self::assertSame([
            'success' => true,
            'message' => 'All analytics data cleared successfully (0 records deleted)',
        ], $utilityClear->data);

        self::assertSame($before, $this->allTableCounts());
    }

    public function testAdministratorUtilityClearRetainsEffectiveAllSiteBehavior(): void
    {
        [$firstSiteId, $secondSiteId] = $this->twoSiteIds();
        $admin = $this->createTestUser(self::PREFIX . 'admin_');
        $admin->admin = true;
        $this->actingAs($admin);
        Craft::$app->getSites()->refreshSites();
        self::assertContains($firstSiteId, Craft::$app->getSites()->getEditableSiteIds());
        self::assertContains($secondSiteId, Craft::$app->getSites()->getEditableSiteIds());

        $this->installIsolatedAnalyticsDatabase();
        $this->seedAnalyticsRows($firstSiteId, $secondSiteId);
        $this->withPostJson();

        $response = $this->utilitiesController()->actionClearAllAnalytics();

        self::assertSame([
            'success' => true,
            'message' => 'All analytics data cleared successfully (4 records deleted)',
        ], $response->data);
        self::assertSame([0, 0, 0], $this->allTableCounts());
    }

    public function testEveryAnalyticsReportFamilyUsesTheCanonicalSiteScopeAuthority(): void
    {
        $expectedCalls = [
            'AnalyticsQueryInsightsService.php' => 10,
            'AnalyticsBreakdownService.php' => 10,
            'AnalyticsPerformanceService.php' => 6,
            'AnalyticsRulesService.php' => 6,
            'AnalyticsExportService.php' => 3,
        ];

        foreach ($expectedCalls as $file => $minimumCalls) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/' . $file);
            self::assertIsString($source);
            self::assertGreaterThanOrEqual($minimumCalls, substr_count($source, 'applySiteScope('), $file);
            self::assertStringNotContainsString('if ($siteId)', $source, $file);
        }

        $trait = file_get_contents(dirname(__DIR__, 2) . '/src/services/analytics/AnalyticsQueryTrait.php');
        self::assertIsString($trait);
        self::assertStringContainsString('if ($siteId !== null)', $trait);
        self::assertStringContainsString("\$query->andWhere(['siteId' => \$siteId]);", $trait);
    }

    /**
     * @param int|list<int>|null $scope
     */
    #[DataProvider('explicitScopeProvider')]
    public function testServiceScopesAffectOnlyNamedSites(int|array|null $scope, bool $firstDeleted, bool $secondDeleted): void
    {
        [$firstSiteId, $secondSiteId] = $this->twoSiteIds();
        $this->installIsolatedAnalyticsDatabase();
        $this->seedAnalyticsRows($firstSiteId, $secondSiteId);

        $resolvedScope = match ($scope) {
            1 => $firstSiteId,
            [1] => [$firstSiteId],
            [2] => [$secondSiteId],
            default => null,
        };

        $deleted = SearchManager::$plugin->analytics->clearAnalytics($resolvedScope);

        self::assertSame(($firstDeleted ? 2 : 0) + ($secondDeleted ? 2 : 0), $deleted);
        $this->assertSiteRows($firstSiteId, $firstDeleted ? 0 : 2);
        $this->assertSiteRows($secondSiteId, $secondDeleted ? 0 : 2);
    }

    /**
     * @return iterable<string, array{int|list<int>|null, bool, bool}>
     */
    public static function explicitScopeProvider(): iterable
    {
        yield 'integer scope' => [1, true, false];
        yield 'integer-list first scope' => [[1], true, false];
        yield 'integer-list second scope' => [[2], false, true];
        yield 'intentional global null scope' => [null, true, true];
    }

    #[DataProvider('transactionFailureProvider')]
    public function testClearAnalyticsRollsBackAllTablesWhenAnyDeleteFails(string $failingTable): void
    {
        [$firstSiteId, $secondSiteId] = $this->twoSiteIds();
        $this->installIsolatedAnalyticsDatabase();
        $this->seedAnalyticsRows($firstSiteId, $secondSiteId);
        $before = $this->allTableCounts();
        $this->installDeleteFailureTrigger($failingTable);

        try {
            SearchManager::$plugin->analytics->clearAnalytics([$firstSiteId]);
            self::fail('The injected analytics deletion failure should escape after rollback.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('forced analytics delete failure', $exception->getMessage());
        }

        self::assertSame($before, $this->allTableCounts());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transactionFailureProvider(): iterable
    {
        yield 'detail deletion' => ['searchmanager_promotion_analytics'];
        yield 'primary deletion' => ['searchmanager_analytics'];
    }

    private function analyticsController(): AnalyticsController
    {
        return new class('analytics', SearchManager::$plugin) extends AnalyticsController {
            public function requirePermission(string $permissionName): void
            {
            }

            public function requirePostRequest(): void
            {
            }

            public function requireAcceptsJson(): void
            {
            }
        };
    }

    /**
     * @param list<int> $expectedSiteIds
     */
    private function assertControllerReportScope(array $expectedSiteIds, int $total): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $originalAnalytics = SearchManager::$plugin->get('analytics');
        $recordingAnalytics = new Pr128RecordingAnalyticsService();
        $recordingAnalytics->chartData = [['total' => $total]];
        SearchManager::$plugin->set('analytics', $recordingAnalytics);

        try {
            $this->withPostJson(['type' => 'chart', 'dateRange' => 'all']);
            $report = $this->analyticsController()->actionGetData();
        } finally {
            SearchManager::$plugin->set('analytics', $originalAnalytics);
        }

        self::assertSame(['success' => true, 'data' => ['chartData' => [['total' => $total]]]], $report->data);
        self::assertSame([$expectedSiteIds], $recordingAnalytics->siteScopes);
    }

    private function utilitiesController(): UtilitiesController
    {
        return new class('utilities', SearchManager::$plugin) extends UtilitiesController {
            public function requirePermission(string $permissionName): void
            {
            }

            public function requirePostRequest(): void
            {
            }

            public function requireAcceptsJson(): void
            {
            }
        };
    }

    /**
     * @param list<int> $siteIds
     */
    private function actAsSiteScopedUser(array $siteIds): User
    {
        $permissions = ['accessCp', 'searchManager:viewAnalytics', 'searchManager:clearAnalytics'];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if (in_array((int)$site->id, $siteIds, true)) {
                $permissions[] = "editSite:{$site->uid}";
            }
        }

        $user = $this->createTestUser(self::PREFIX . 'user_');
        $this->grantPermissions($user, $permissions);
        $this->actingAs($user);
        Craft::$app->getSites()->refreshSites();
        self::assertSame($siteIds, Craft::$app->getSites()->getEditableSiteIds());

        return $user;
    }

    /**
     * @return array{int, int}
     */
    private function twoSiteIds(): array
    {
        $siteIds = Craft::$app->getSites()->getAllSiteIds(true);
        if (count($siteIds) < 2) {
            self::markTestSkipped('PR1.28 site-scope coverage requires at least two configured Craft sites.');
        }

        return [(int)$siteIds[0], (int)$siteIds[1]];
    }

    private function installIsolatedAnalyticsDatabase(): void
    {
        $this->originalDatabase = Craft::$app->getDb();
        $this->isolatedDatabase = new Connection(['dsn' => 'sqlite::memory:']);
        $this->isolatedDatabase->open();

        $this->isolatedDatabase->createCommand(
            'CREATE TABLE searchmanager_analytics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                siteId INTEGER NULL,
                query VARCHAR(500) NOT NULL,
                sessionId VARCHAR(36) NULL,
                isHit INTEGER NOT NULL DEFAULT 0,
                wasRedirected INTEGER NOT NULL DEFAULT 0,
                promotionsShown INTEGER NOT NULL DEFAULT 0,
                dateCreated DATETIME NOT NULL
            )',
        )->execute();

        foreach (['searchmanager_rule_analytics', 'searchmanager_promotion_analytics'] as $table) {
            $this->isolatedDatabase->createCommand(
                "CREATE TABLE {$table} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    siteId INTEGER NULL,
                    query VARCHAR(500) NOT NULL,
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

    /**
     * @return array<int, list<int>>
     */
    private function seedAnalyticsRows(int ...$siteIds): array
    {
        $ids = [];
        $dateCreated = Db::prepareDateForDb(new \DateTimeImmutable());

        foreach ($siteIds as $siteId) {
            foreach ([1, 2] as $row) {
                Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_analytics}}', [
                    'siteId' => $siteId,
                    'query' => self::PREFIX . "{$siteId}_primary_{$row}",
                    'isHit' => 1,
                    'dateCreated' => $dateCreated,
                ])->execute();
                $ids[$siteId][] = (int)Craft::$app->getDb()->getLastInsertID();
            }

            foreach (['rule', 'promotion'] as $type) {
                Craft::$app->getDb()->createCommand()->insert("{{%searchmanager_{$type}_analytics}}", [
                    'siteId' => $siteId,
                    'query' => self::PREFIX . "{$siteId}_{$type}",
                    'dateCreated' => $dateCreated,
                ])->execute();
            }
        }

        return $ids;
    }

    private function installDeleteFailureTrigger(string $table): void
    {
        Craft::$app->getDb()->getMasterPdo()->exec(
            "CREATE TRIGGER fail_analytics_delete
            BEFORE DELETE ON {$table}
            BEGIN
                SELECT RAISE(ABORT, 'forced analytics delete failure');
            END",
        );
    }

    private function withPostJson(array $body = []): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            $this->originalResponse = Craft::$app->getResponse();
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams($body);
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

    private function assertSiteRows(int $siteId, int $primaryCount): void
    {
        self::assertSame($primaryCount, $this->rowCount('{{%searchmanager_analytics}}', ['siteId' => $siteId]));
        $detailCount = $primaryCount === 0 ? 0 : 1;
        self::assertSame($detailCount, $this->rowCount('{{%searchmanager_rule_analytics}}', ['siteId' => $siteId]));
        self::assertSame($detailCount, $this->rowCount('{{%searchmanager_promotion_analytics}}', ['siteId' => $siteId]));
    }

    /**
     * @param array<string, mixed> $condition
     */
    private function rowCount(string $table, array $condition = []): int
    {
        $query = (new Query())->from($table);
        if ($condition !== []) {
            $query->where($condition);
        }

        return (int)$query->count();
    }

    /**
     * @return array{int, int, int}
     */
    private function allTableCounts(): array
    {
        return [
            $this->rowCount('{{%searchmanager_analytics}}'),
            $this->rowCount('{{%searchmanager_rule_analytics}}'),
            $this->rowCount('{{%searchmanager_promotion_analytics}}'),
        ];
    }
}

final class Pr128RecordingAnalyticsService extends AnalyticsService
{
    /**
     * @var list<int|list<int>|null>
     */
    public array $siteScopes = [];

    /**
     * @var list<array{total: int}>
     */
    public array $chartData = [];

    public function getChartData(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $this->siteScopes[] = $siteId;

        return $this->chartData;
    }
}
