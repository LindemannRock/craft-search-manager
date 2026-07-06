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
 * Focused regression coverage for audit #252.
 *
 * @since 5.53.0
 */
final class AuditItem252RegressionTest extends TestCase
{
    public function testSearchResultUrlsAndThumbnailsUseSchemeGuardedAttributeHelper(): void
    {
        $source = $this->testToolJs();

        self::assertStringContainsString('function safeUrlAttribute(value)', $source);
        self::assertStringContainsString("if (/^(javascript|data|vbscript):/.test(schemeProbe))", $source);
        self::assertStringContainsString("if (schemeMatch && !['http', 'https'].includes(schemeMatch[1].toLowerCase()))", $source);
        self::assertStringContainsString('if (schemeMatch && !/^https?:\\/\\//i.test(raw))', $source);
        self::assertStringContainsString("if (raw.startsWith('//') || raw.includes('\\\\'))", $source);
        self::assertStringContainsString('return Craft.escapeHtml(raw);', $source);

        self::assertStringContainsString('const url = safeUrlAttribute(hit.url);', $source);
        self::assertStringContainsString('const thumbnail = safeUrlAttribute(hit.thumbnail);', $source);
        self::assertStringContainsString('${thumbnail ? `<img src="${thumbnail}"', $source);
        self::assertStringContainsString('${url ? `<div style="font-size: 12px; color: #6b7280; margin-top: 4px;"><a href="${url}"', $source);

        self::assertStringNotContainsString('const url = hit.url || \'\';', $source);
        self::assertStringNotContainsString('const thumbnail = hit.thumbnail || null;', $source);
        self::assertStringNotContainsString('<img src="${hit.thumbnail}', $source);
        self::assertStringNotContainsString('<a href="${hit.url}', $source);
    }

    public function testSearchResultMetadataUsesDisplayEscaperBeforeInnerHtmlInsertion(): void
    {
        $source = $this->testToolJs();

        self::assertStringContainsString('function escapeDisplay(value)', $source);
        self::assertStringContainsString("return Craft.escapeHtml(String(value === undefined || value === null ? '' : value));", $source);

        foreach ([
            'const urlText = hit.url ? escapeDisplay(hit.url) : \'\';',
            'const matchedIn = hit.matchedIn && hit.matchedIn.length > 0 ? hit.matchedIn.map(escapeDisplay).join(\', \') : null;',
            'const indexHandle = hit._index ? escapeDisplay(hit._index) : null;',
            'const objectId = hit.objectID || hit.id;',
            'const objectIdDisplay = objectId ? escapeDisplay(objectId) : \'\';',
            'const type = escapeDisplay(hit.type || T.entry);',
            'const section = hit.section ? escapeDisplay(hit.section) : \'\';',
            'const siteName = escapeDisplay(hit.siteName || T.unknown);',
            "const language = escapeDisplay(hit.language || '??');",
            '<a href="${url}" target="_blank" style="color: #0d78f2;">${urlText}</a>',
            'ID: #${objectIdDisplay} &bull; ${T.typeLabel} ${type}${section ?',
            '${indexHandle ? \' &bull; \' + T.indexLabel + \' <code>\' + indexHandle + \'</code>\' : \'\'}',
            '${T.siteLabel} ${siteName} (${language})',
            '${matchedIn ? `<div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;"><strong>${T.matchedInLabel}</strong> <code>${matchedIn}</code></div>` : \'\'}',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        foreach ([
            '${url}</a>',
            '${hit.type || T.entry}',
            '+ hit.section',
            '${hit.siteName || T.unknown}',
            '${hit.language || \'??\'}',
            'const matchedIn = hit.matchedIn && hit.matchedIn.length > 0 ? hit.matchedIn.join(\', \') : null;',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testSearchResultHighlightEscapingBehaviorIsPreserved(): void
    {
        $source = $this->testToolJs();

        foreach ([
            'const title = smHighlight(rawTitle, query, titleTerms);',
            'const displayText = rawDisplayText ? smHighlight(rawDisplayText.substring(0, 400), query, descTerms) : \'\';',
            'if (!showHighlighting.checked) return Craft.escapeHtml(text);',
            'return Craft.escapeHtml(text);',
            'SearchManagerHighlighter.highlight(text, query, {',
            "tag: 'mark',",
            '<strong style="color: #111827; font-size: 15px;">${title}</strong>',
            '${displayText}${rawDisplayText.length > 400 ? \'...\' : \'\'}',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testSearchToolUsesAssetBundleAndTwigOnlyPassesConfig(): void
    {
        $twig = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');
        $asset = $this->readPluginFile('src/web/assets/testtool/TestToolAsset.php');
        $assetPackage = $this->readPluginFile('src/web/assets/package.json');
        $rootPackage = $this->readPluginFile('package.json');
        $attributes = $this->readPluginFile('.gitattributes');

        self::assertStringContainsString("view.registerAssetBundle('lindemannrock\\\\searchmanager\\\\web\\\\assets\\\\testtool\\\\TestToolAsset')", $twig);
        self::assertStringContainsString('window.lrSearchManagerTestToolInit({', $twig);
        self::assertStringContainsString('csrfToken: {{ craft.app.request.csrfToken|json_encode|raw }}', $twig);
        self::assertStringContainsString('autocompleteMinLength: {{ settings.autocompleteMinLength ?? 2 }}', $twig);
        self::assertStringContainsString('indexSiteIds: {{ indexSiteIds|json_encode|raw }}', $twig);
        self::assertStringNotContainsString("document.addEventListener('DOMContentLoaded'", $twig);
        self::assertStringNotContainsString('function displaySearchResults', $twig);
        self::assertStringNotContainsString('function safeUrlAttribute', $twig);

        self::assertStringContainsString('namespace lindemannrock\\searchmanager\\web\\assets\\testtool;', $asset);
        self::assertStringContainsString('class TestToolAsset extends AssetBundle', $asset);
        self::assertStringContainsString('SearchHighlighterAsset::class', $asset);
        self::assertStringContainsString("'test-tool.css'", $asset);
        self::assertStringContainsString("'test-tool.js'", $asset);

        self::assertStringContainsString('"build:testtool": "mkdir -p testtool/dist && terser testtool/src/test-tool.js -o testtool/dist/test-tool.js -c -m && cp testtool/src/test-tool.css testtool/dist/test-tool.css"', $assetPackage);
        self::assertStringContainsString('"build:testtool": "cd src/web/assets && npm run build:testtool"', $rootPackage);
        self::assertStringContainsString('src/web/assets/testtool/src/ export-ignore', $attributes);
    }

    public function testInitializerRunsAfterDomContentLoadedHasAlreadyFired(): void
    {
        $source = $this->testToolJs();

        self::assertStringContainsString('function init() {', $source);
        self::assertStringContainsString("if (document.readyState === 'loading') {", $source);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', init, { once: true });", $source);
        self::assertStringContainsString('} else {', $source);
        self::assertStringContainsString('init();', $source);
    }

    private function testToolJs(): string
    {
        return $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
