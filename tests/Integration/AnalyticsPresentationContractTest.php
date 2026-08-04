<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #429 and #431-#437.
 *
 * @since 5.54.0
 */
final class AnalyticsPresentationContractTest extends TestCase
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
