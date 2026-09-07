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

    public function testRecentSearchOutcomePresentationPreservesMachineContract(): void
    {
        $searches = $this->readPluginFile('src/templates/analytics/_partials/searches.twig');
        $export = $this->readPluginFile('src/services/analytics/AnalyticsExportService.php');
        $tracking = $this->readPluginFile('src/services/analytics/AnalyticsTrackingService.php');
        $install = $this->readPluginFile('src/migrations/Install.php');

        $rulesPosition = strpos($searches, "'Rules'|t('search-manager')");
        $synonymsPosition = strpos($searches, "'Synonyms'|t('search-manager')");
        $redirectPosition = strpos($searches, "'Redirect'|t('search-manager')");
        $promotionsPosition = strpos($searches, "'Promotions'|t('search-manager')");
        self::assertIsInt($rulesPosition);
        self::assertIsInt($synonymsPosition);
        self::assertIsInt($redirectPosition);
        self::assertIsInt($promotionsPosition);
        self::assertLessThan($synonymsPosition, $rulesPosition);
        self::assertLessThan($redirectPosition, $synonymsPosition);
        self::assertLessThan($promotionsPosition, $redirectPosition);
        foreach (['Rules Matched', 'Synonym expanded', 'Redirected', 'Redirect rule matched', 'Promotions shown', 'Search outcomes'] as $forbiddenHeading) {
            self::assertStringNotContainsString("'{$forbiddenHeading}'|t('search-manager')", $searches);
        }
        foreach (['Rules', 'Synonyms', 'Redirect', 'Promotions'] as $heading) {
            self::assertStringContainsString("Craft::t('search-manager', '{$heading}')", $export);
        }
        self::assertStringNotContainsString("Craft::t('search-manager', 'Rules Matched')", $export);
        self::assertStringNotContainsString("Craft::t('search-manager', 'Redirect rule matched')", $export);
        self::assertStringContainsString("'redirected' => (\$row['wasRedirected'] ?? false) ? 1 : 0", $export);
        self::assertStringContainsString('wasRedirected: Whether a redirect rule matched', $tracking);
        self::assertStringContainsString("'wasRedirected' => \$this->boolean()->notNull()->defaultValue(false)->comment('Did a redirect rule match')", $install);
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
