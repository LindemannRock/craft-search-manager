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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #165 through #170.
 *
 * @since 5.53.0
 */
final class DashboardQueryEfficiencyTest extends TestCase
{
    private const MARKER = 'audit-pass-29';

    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_promotions}}', ['query' => self::MARKER])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_query_rules}}', ['matchValue' => self::MARKER])
            ->execute();

        parent::tearDown();
    }

    public function testDashboardCountsUseDatabaseCountsAndPreserveEnabledFilters(): void
    {
        $promotions = SearchManager::$plugin->promotions;
        $queryRules = SearchManager::$plugin->queryRules;

        $promotionTotals = [
            'all' => $promotions->getPromotionCount(),
            'enabled' => $promotions->getPromotionCount(true),
            'disabled' => $promotions->getPromotionCount(false),
        ];
        $ruleTotals = [
            'all' => $queryRules->getQueryRuleCount(),
            'enabled' => $queryRules->getQueryRuleCount(true),
            'disabled' => $queryRules->getQueryRuleCount(false),
        ];

        $this->seedPromotion(true);
        $this->seedPromotion(false);
        $this->seedQueryRule(true);
        $this->seedQueryRule(false);

        self::assertSame($promotionTotals['all'] + 2, $promotions->getPromotionCount());
        self::assertSame($promotionTotals['enabled'] + 1, $promotions->getPromotionCount(true));
        self::assertSame($promotionTotals['disabled'] + 1, $promotions->getPromotionCount(false));
        self::assertSame($ruleTotals['all'] + 2, $queryRules->getQueryRuleCount());
        self::assertSame($ruleTotals['enabled'] + 1, $queryRules->getQueryRuleCount(true));
        self::assertSame($ruleTotals['disabled'] + 1, $queryRules->getQueryRuleCount(false));

        $promotionSource = $this->methodSource(
            $this->readPluginFile('src/services/PromotionService.php'),
            'getPromotionCount',
        );
        $queryRuleSource = $this->methodSource(
            $this->readPluginFile('src/services/QueryRuleService.php'),
            'getQueryRuleCount',
        );
        self::assertStringContainsString('->count()', $promotionSource);
        self::assertStringContainsString('->count()', $queryRuleSource);
        self::assertStringNotContainsString('findAll()', $promotionSource);
        self::assertStringNotContainsString('findAll()', $queryRuleSource);
    }

    private function seedPromotion(bool $enabled): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_promotions}}', [
            'indexHandle' => null,
            'title' => self::MARKER,
            'query' => self::MARKER,
            'matchType' => 'exact',
            'elementId' => 1,
            'elementType' => null,
            'position' => 1,
            'siteId' => null,
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function seedQueryRule(bool $enabled): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_query_rules}}', [
            'name' => self::MARKER,
            'indexHandle' => null,
            'matchType' => 'exact',
            'matchValue' => self::MARKER,
            'actionType' => 'synonym',
            'actionValue' => json_encode(['terms' => ['audit pass 29']], JSON_THROW_ON_ERROR),
            'priority' => 0,
            'siteId' => null,
            'enabled' => $enabled ? 1 : 0,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            self::fail('Unable to read plugin file: ' . $path);
        }

        return $contents;
    }

    private function methodSource(string $source, string $needle): string
    {
        $start = strpos($source, $needle);

        if ($start === false) {
            self::fail('Unable to find source snippet: ' . $needle);
        }

        $next = strpos($source, "\n    /**", $start + strlen($needle));

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }
}
