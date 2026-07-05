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
 * Pins frontend widget hardening regressions for audit #242, #243, and #245.
 */
final class WidgetFrontendHardeningTest extends TestCase
{
    public function testHierarchyHeadingLevelIsNumericAndBounded(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/modules/ResultRenderer.js');

        self::assertStringContainsString('const parsedLevel = Number.parseInt(heading.level, 10);', $source);
        self::assertStringContainsString('const level = Number.isFinite(parsedLevel) ? Math.min(Math.max(parsedLevel, 1), 6) : 2;', $source);
        self::assertStringNotContainsString('const level = heading.level || 2;', $source);
    }

    public function testModalConfigValuesAreEscapedBeforeShadowDomHtml(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/widgets/SearchModalWidget.js');

        self::assertStringContainsString("import { escapeHtml } from '../modules/Highlighter.js';", $source);
        self::assertStringContainsString('const hotkeyDisplay = escapeHtml(this.getHotkeyDisplay());', $source);
        self::assertStringContainsString("const safePlaceholder = escapeHtml(placeholder || '');", $source);
        self::assertStringContainsString('<kbd class="sm-trigger-kbd" aria-hidden="true">${hotkeyDisplay}</kbd>', $source);
        self::assertStringContainsString('placeholder="${safePlaceholder}"', $source);
        self::assertStringNotContainsString('<kbd class="sm-trigger-kbd" aria-hidden="true">${this.getHotkeyDisplay()}</kbd>', $source);
        self::assertStringNotContainsString('placeholder="${placeholder}"', $source);
    }

    public function testWidgetPreviewDoesNotAccumulatePollingIntervals(): void
    {
        $source = $this->readPluginFile('src/web/assets/widgetpreview/src/widget-preview.js');

        self::assertStringContainsString('var syncInterval = null;', $source);
        self::assertStringContainsString('clearInterval(syncInterval);', $source);
        self::assertStringContainsString('syncInterval = setInterval(syncAll, 100);', $source);
        self::assertStringNotContainsString("\n\t\t\tsetInterval(syncAll, 100);", $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
