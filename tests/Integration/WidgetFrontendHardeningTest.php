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

    public function testWidgetHighlighterNormalizesTagAndClassBeforeRenderingMarkup(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/modules/Highlighter.js');

        self::assertStringContainsString("const ALLOWED_HIGHLIGHT_TAGS = new Set(['mark', 'em', 'strong', 'u', 'b', 'i', 'span']);", $source);
        self::assertStringContainsString('const CSS_CLASS_TOKEN_PATTERN = /^[A-Za-z0-9_-]+$/;', $source);
        self::assertStringContainsString('const safeTag = normalizeHighlightTag(tag);', $source);
        self::assertStringContainsString('const classTokens = normalizeClassTokens(className);', $source);
        self::assertStringContainsString('const classAttr = ` class="${escapeHtml(classes.join(\' \'))}"`;', $source);
        self::assertStringContainsString('return applyHighlightRanges(text, termList, safeTag, classAttr);', $source);
        self::assertStringNotContainsString('classes.push(className);', $source);
        self::assertStringNotContainsString('return applyHighlightRanges(text, termList, tag, classAttr);', $source);
    }

    public function testWidgetUsesPublicHeadingsAndClientSnippetHighlighting(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/modules/ResultRenderer.js');

        self::assertStringContainsString('const headings = result.headings || [];', $source);
        self::assertStringContainsString('const hasHeadings = result.headings && result.headings.length > 0;', $source);
        self::assertStringContainsString('const snippet = heading.snippet || \'\';', $source);
        self::assertStringContainsString('return highlightMatches(snippet, query, highlightOptions);', $source);
        self::assertStringContainsString('const rawText = heading.title || heading.text || \'\';', $source);
        self::assertStringNotContainsString('result._matchedHeadings', $source);
        self::assertStringNotContainsString('heading.description', $source);
        self::assertStringNotContainsString('result.excerpt', $source);
    }

    public function testPromotedBadgePositionIsAllowlistedBeforeRenderingClassName(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/modules/ResultRenderer.js');

        self::assertStringContainsString("const allowedPositions = new Set(['top-right', 'top-left', 'inline']);", $source);
        self::assertStringContainsString("const safeBadgePosition = allowedPositions.has(badgePosition) ? badgePosition : 'top-right';", $source);
        self::assertStringContainsString('const positionClass = `sm-promoted-badge--${safeBadgePosition}`;', $source);
        self::assertStringNotContainsString('const positionClass = `sm-promoted-badge--${badgePosition}`;', $source);
    }

    public function testWidgetSearchServiceDoesNotSendHighlightMarkupOptions(): void
    {
        $source = $this->readPluginFile('src/web/assets/searchwidget/src/modules/SearchService.js');

        self::assertStringNotContainsString("params.append('highlightTag'", $source);
        self::assertStringNotContainsString("params.append('highlightClass'", $source);
    }

    public function testPublicWidgetTemplateUsesProductionStyleResolver(): void
    {
        $source = $this->readPluginFile('src/templates/_widget/search-modal.twig');

        self::assertStringContainsString('{% set styleOverrides = styles is defined and styles is iterable ? styles : {} %}', $source);
        self::assertStringContainsString('{% set resolvedStyles = widgetConfig.getStylesForRender(styleOverrides) %}', $source);
        self::assertStringContainsString('highlight-tag="{{ highlightTag|e(\'html_attr\') }}"', $source);
        self::assertStringContainsString('{% if highlightClass %}highlight-class="{{ highlightClass|e(\'html_attr\') }}"{% endif %}', $source);
        self::assertStringNotContainsString('widgetConfig.getStylesForPreview()', $source);
        self::assertStringNotContainsString('resolvedStyles = resolvedStyles|merge(styles)', $source);
    }

    public function testPublicWidgetTemplateOnlyEmitsPublicApiKeys(): void
    {
        $source = $this->readPluginFile('src/templates/_widget/search-modal.twig');

        self::assertStringContainsString("{% set apiKey = apiKey is string and apiKey|trim starts with 'sm_pub_' ? apiKey|trim : '' %}", $source);
        self::assertStringContainsString('{% if apiKey %}api-key="{{ apiKey|e(\'html_attr\') }}"{% endif %}', $source);
    }

    public function testCpWidgetPreviewTemplateKeepsPreviewStyleResolver(): void
    {
        $source = $this->readPluginFile('src/templates/widgets/_shared/preview.twig');

        self::assertStringContainsString('{% set styles = widgetConfig.getStylesForPreview() %}', $source);
        self::assertStringNotContainsString('getStylesForRender', $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
