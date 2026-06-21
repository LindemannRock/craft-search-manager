<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins analytics dashboard asset string handling for audits #161-#164.
 */
final class AnalyticsAssetI18nTest extends TestCase
{
    public function testAnalyticsTwigProvidesChartStrings(): void
    {
        $source = $this->readPluginFile('src/templates/analytics/index.twig');

        foreach ([
            'withHits: "With Hits"|t(\'search-manager\')',
            'zeroHits: "Zero Hits"|t(\'search-manager\')',
            'impressions: "Impressions"|t(\'search-manager\')',
            'avgResponseTimeMs: "Avg Response Time (ms)"|t(\'search-manager\')',
            'ms: "ms"|t(\'search-manager\')',
            'positionNumber: "Position #"|t(\'search-manager\')',
            'peakHourTitle: "Peak Hour:"|t(\'search-manager\')',
            'elementNumber: "Element #"|t(\'search-manager\')',
            'frontend: "Frontend"|t(\'search-manager\')',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testAnalyticsSourceReadsChartLabelsFromConfigStrings(): void
    {
        $source = $this->readPluginFile('src/web/assets/analytics/src/analytics.js');

        foreach ([
            'strings.withHits',
            'strings.zeroHits',
            'strings.impressions',
            'strings.avgResponseTimeMs',
            'strings.ms',
            'strings.positionNumber',
            'strings.peakHourTitle',
            'strings.elementNumber',
            'strings.frontend',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        foreach ([
            "label: 'With Hits'",
            "label: 'Zero Hits'",
            "label: 'Impressions'",
            "label: 'Avg Response Time (ms)'",
            "text: 'ms'",
            "'Position #'",
            "'Peak Hour: '",
            "'Element #'",
            "frontend: 'Frontend'",
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testAnalyticsDistDoesNotContainMovedHardcodedLabels(): void
    {
        $source = $this->readPluginFile('src/web/assets/analytics/dist/analytics.js');

        foreach ([
            'With Hits',
            'Zero Hits',
            'Impressions',
            'Avg Response Time (ms)',
            'Position #',
            'Peak Hour:',
            'Element #',
            'Frontend',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testAnalyticsSourceEscapesStoredAnalyticsHtmlInterpolations(): void
    {
        $source = $this->readPluginFile('src/web/assets/analytics/src/analytics.js');

        self::assertStringContainsString('c.queries.slice(0, 3).map(q => Craft.escapeHtml(q)).join(\', \')', $source);
        self::assertStringContainsString('<td>${Craft.escapeHtml(c.lastSearched)}</td>', $source);
        self::assertSame(2, substr_count($source, '<td>${q.siteName ? Craft.escapeHtml(q.siteName) : \'—\'}</td>'));
    }

    public function testAnalyticsDistDoesNotContainUnsafeRawStoredAnalyticsPatterns(): void
    {
        $source = $this->readPluginFile('src/web/assets/analytics/dist/analytics.js');

        foreach ([
            'c.queries.slice(0, 3).join(\', \')',
            '<td>${c.lastSearched}</td>',
            '<td>${q.siteName || \'—\'}</td>',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testSourceBreakdownLabelsUseStaticTranslations(): void
    {
        $source = $this->readPluginFile('src/services/analytics/AnalyticsBreakdownService.php');

        self::assertStringContainsString("'frontend' => Craft::t('search-manager', 'Frontend')", $source);
        self::assertStringContainsString("'cp' => Craft::t('search-manager', 'Control Panel')", $source);
        self::assertStringContainsString("'api' => 'API'", $source);
        self::assertStringContainsString('default => ucfirst($row[\'source\'])', $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
