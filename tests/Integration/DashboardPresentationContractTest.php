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
final class DashboardPresentationContractTest extends TestCase
{
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

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
