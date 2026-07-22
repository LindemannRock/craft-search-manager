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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #429 and #431-#437.
 *
 * @since 5.54.0
 */
final class AuditClosingBatchRegressionTest extends TestCase
{
    public function testAnalyticsEmptyStateLinksUseRegisteredCreateRoutes(): void
    {
        $queryRules = $this->readPluginFile('src/templates/analytics/_partials/query-rules.twig');
        $promotions = $this->readPluginFile('src/templates/analytics/_partials/promotions.twig');

        self::assertStringContainsString("url('search-manager/query-rules/create')", $queryRules);
        self::assertStringNotContainsString("url('search-manager/query-rules/new')", $queryRules);
        self::assertStringContainsString("url('search-manager/promotions/create')", $promotions);
        self::assertStringNotContainsString("url('search-manager/promotions/new')", $promotions);
    }

    public function testNullTransformerClassPersistsAsEmptyString(): void
    {
        $handle = 'sm_audit_431_transformer';
        Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handle])->execute();

        $index = new SearchIndex([
            'name' => 'Audit 431 Transformer',
            'handle' => $handle,
            'elementType' => Entry::class,
            'criteria' => [],
            'transformerClass' => null,
            'enabled' => false,
        ]);

        try {
            self::assertTrue($index->save(), print_r($index->getErrors(), true));
            self::assertSame('', (new Query())
                ->select(['transformerClass'])
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handle])
                ->scalar());
        } finally {
            Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handle])->execute();
            SearchIndex::clearCache();
        }
    }

    public function testDashboardCardsUseExactPaletteMappings(): void
    {
        $dashboard = $this->readPluginFile('src/templates/dashboard/index.twig');

        foreach (['emerald', 'amber', 'violet', 'sky'] as $palette) {
            self::assertSame(1, substr_count($dashboard, "color: lrPaletteColor('{$palette}').color"));
        }
        foreach (['#059669', '#f59e0b', '#8b5cf6', '#0ea5e9'] as $hex) {
            self::assertStringNotContainsString("color: '{$hex}'", $dashboard);
        }
    }

    public function testIndicesListingReadsExpectedCountOncePerRow(): void
    {
        $template = $this->readPluginFile('src/templates/indices/index.twig');

        self::assertSame(1, substr_count($template, 'item.expectedCount'));
        self::assertStringContainsString('{% set expected = item.expectedCount %}', $template);
        self::assertLessThan(
            strpos($template, '{# Craft (expected count) #}'),
            strpos($template, '{% set expected = item.expectedCount %}'),
        );
    }

    public function testDeadOptionBuildersAreRemoved(): void
    {
        self::assertFalse(method_exists(PromotionService::class, 'getIndexOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getIndexOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getSectionOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getCategoryGroupOptions'));
    }

    public function testOrphanedIndexStylesTemplateIsRemoved(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/templates/_partials/common/index-styles.twig');
    }

    public function testContentGapsUsesSearchesWhileHitsRemainsLiveElsewhere(): void
    {
        $contentGaps = $this->readPluginFile('src/templates/dashboard-widgets/content-gaps/body.twig');
        $analytics = $this->readPluginFile('src/templates/analytics/_partials/searches.twig');

        self::assertStringContainsString('valueHeader: "Searches"|t(\'search-manager\')', $contentGaps);
        self::assertStringNotContainsString('valueHeader: "Hits"|t(\'search-manager\')', $contentGaps);
        self::assertStringContainsString("'Hits'|t('search-manager')", $analytics);
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
