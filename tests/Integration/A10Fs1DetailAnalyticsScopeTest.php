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
use craft\elements\User;
use craft\helpers\Db;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;

/**
 * @since 5.54.0
 */
final class A10Fs1DetailAnalyticsScopeTest extends TestCase
{
    private const PREFIX = '__sm_a10_fs1__';

    private int $ruleId;
    private int $promotionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
        $this->deleteRows();
        $this->ruleId = random_int(100000000, 999999999);
        $this->promotionId = random_int(100000000, 999999999);
    }

    protected function tearDown(): void
    {
        $this->deleteRows();
        parent::tearDown();
        Craft::$app->getSites()->refreshSites();
    }

    public function testDetailAnalyticsHonorEditableEmptyAdminAndGlobalScopes(): void
    {
        [$editableSiteId, $otherSiteId] = $this->twoSiteIds();
        $this->seedDetailRows($editableSiteId, $otherSiteId);

        $this->actAsSiteScopedUser([$editableSiteId]);
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        self::assertSame([$editableSiteId], $editableSiteIds);
        $this->assertScopedDetails($editableSiteIds, 1, [$editableSiteId]);

        $twigVariable = new SearchManagerVariable();
        self::assertSame(
            2,
            $twigVariable->getRuleAnalytics($this->ruleId, 'all')['totalTriggers'],
            'Omitting the optional scope preserves trusted-template global compatibility.',
        );
        self::assertSame(
            2,
            $twigVariable->getPromotionAnalytics($this->promotionId, 'all', null)['totalImpressions'],
        );
        $this->assertScopedDetails($otherSiteId, 1, [$otherSiteId]);

        $this->actAsSiteScopedUser([]);
        self::assertSame([], Craft::$app->getSites()->getEditableSiteIds());
        $this->assertScopedDetails([], 0, []);

        $admin = $this->createTestUser(self::PREFIX . 'admin_');
        $admin->admin = true;
        $this->actingAs($admin);
        Craft::$app->getSites()->refreshSites();
        $adminSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        self::assertContains($editableSiteId, $adminSiteIds);
        self::assertContains($otherSiteId, $adminSiteIds);
        $this->assertScopedDetails($adminSiteIds, 2, [$editableSiteId, $otherSiteId]);
        $this->assertScopedDetails(null, 2, [$editableSiteId, $otherSiteId]);
    }

    /**
     * @param int|list<int>|null $siteScope
     * @param list<int> $expectedSiteIds
     */
    private function assertScopedDetails(int|array|null $siteScope, int $expectedCount, array $expectedSiteIds): void
    {
        $rule = SearchManager::$plugin->analytics->getRuleAnalytics($this->ruleId, 'all', $siteScope);
        $promotion = SearchManager::$plugin->analytics->getPromotionAnalytics($this->promotionId, 'all', $siteScope);

        self::assertSame($expectedCount, $rule['totalTriggers']);
        self::assertSame($expectedCount, $rule['uniqueQueries']);
        self::assertSame($expectedCount === 0 ? 0.0 : 3.0, $rule['avgResultsAfter']);
        self::assertSame($expectedCount, array_sum(array_column($rule['dailyTriggers'], 'count')));
        self::assertEqualsCanonicalizing($expectedSiteIds, array_map('intval', array_column($rule['recentTriggers'], 'siteId')));

        self::assertSame($expectedCount, $promotion['totalImpressions']);
        self::assertSame($expectedCount, $promotion['uniqueQueries']);
        self::assertSame($expectedCount === 0 ? 0.0 : 1.0, $promotion['avgPosition']);
        self::assertSame($expectedCount, array_sum(array_column($promotion['dailyImpressions'], 'count')));
        self::assertEqualsCanonicalizing($expectedSiteIds, array_map('intval', array_column($promotion['recentImpressions'], 'siteId')));

        $ruleExport = (new Query())
            ->from('{{%searchmanager_rule_analytics}}')
            ->where(['queryRuleId' => $this->ruleId]);
        $promotionExport = (new Query())
            ->from('{{%searchmanager_promotion_analytics}}')
            ->where(['promotionId' => $this->promotionId]);
        if ($siteScope !== null) {
            $ruleExport->andWhere(['siteId' => $siteScope]);
            $promotionExport->andWhere(['siteId' => $siteScope]);
        }
        self::assertSame($expectedCount, (int)$ruleExport->count(), 'Rule detail and focused export scope must agree.');
        self::assertSame($expectedCount, (int)$promotionExport->count(), 'Promotion detail and focused export scope must agree.');
    }

    private function seedDetailRows(int ...$siteIds): void
    {
        $dateCreated = Db::prepareDateForDb(new \DateTimeImmutable());
        foreach ($siteIds as $siteId) {
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_rule_analytics}}', [
                'queryRuleId' => $this->ruleId,
                'ruleName' => 'Scoped rule',
                'actionType' => 'redirect',
                'query' => self::PREFIX . "rule_{$siteId}",
                'indexHandle' => 'scoped-index',
                'siteId' => $siteId,
                'resultsCount' => 3,
                'dateCreated' => $dateCreated,
                'uid' => Craft::$app->getSecurity()->generateRandomString(36),
            ])->execute();
            Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotion_analytics}}', [
                'promotionId' => $this->promotionId,
                'elementId' => 7503,
                'elementTitle' => 'Scoped promotion',
                'query' => self::PREFIX . "promotion_{$siteId}",
                'position' => 1,
                'indexHandle' => 'scoped-index',
                'siteId' => $siteId,
                'dateCreated' => $dateCreated,
                'uid' => Craft::$app->getSecurity()->generateRandomString(36),
            ])->execute();
        }
    }

    private function actAsSiteScopedUser(array $siteIds): User
    {
        $permissions = ['accessCp', 'searchManager:viewAnalytics'];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if (in_array((int)$site->id, $siteIds, true)) {
                $permissions[] = "editSite:{$site->uid}";
            }
        }

        $user = $this->createTestUser(self::PREFIX . 'user_');
        $this->grantPermissions($user, $permissions);
        $this->actingAs($user);
        Craft::$app->getSites()->refreshSites();

        return $user;
    }

    /**
     * @return array{int, int}
     */
    private function twoSiteIds(): array
    {
        $siteIds = Craft::$app->getSites()->getAllSiteIds(true);
        if (count($siteIds) < 2) {
            self::markTestSkipped('A10-FS1 site-scope coverage requires at least two configured Craft sites.');
        }

        return [(int)$siteIds[0], (int)$siteIds[1]];
    }

    private function deleteRows(): void
    {
        foreach (['{{%searchmanager_rule_analytics}}', '{{%searchmanager_promotion_analytics}}'] as $table) {
            Craft::$app->getDb()->createCommand()->delete(
                $table,
                ['like', 'query', self::PREFIX . '%', false],
            )->execute();
        }
    }
}
