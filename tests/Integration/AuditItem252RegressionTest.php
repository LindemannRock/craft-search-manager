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
        self::assertStringContainsString('${thumbnail ? `<img src="${thumbnail}" class="sm-test-thumb" alt="">` : \'\'}', $source);
        self::assertStringContainsString('${url ? `<div class="sm-test-url"><a href="${url}" target="_blank">${urlText}</a></div>` : \'\'}', $source);

        self::assertStringNotContainsString('const url = hit.url || \'\';', $source);
        self::assertStringNotContainsString('const thumbnail = hit.thumbnail || null;', $source);
        self::assertStringNotContainsString('<img src="${hit.thumbnail}', $source);
        self::assertStringNotContainsString('<a href="${hit.url}', $source);
        self::assertStringNotContainsString('style=', $source);
        self::assertStringNotContainsString('onmouseover=', $source);
        self::assertStringNotContainsString('onmouseout=', $source);
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
            'const rawType = hit.type || T.entry;',
            'const type = escapeDisplay(rawType);',
            "const isCommerceHit = normalizedType === 'product' || normalizedType === 'variant';",
            "const productType = hit.productType || '';",
            'const contextValue = isCommerceHit ? productType : hit.section;',
            'const contextMeta = contextValue ? `<span class="sm-test-meta-item"><span class="sm-test-meta-label">${formatMetaLabel(contextLabel)}</span> ${escapeDisplay(contextValue)}</span>` : \'\';',
            'const siteName = escapeDisplay(hit.siteName || T.unknown);',
            "const language = escapeDisplay(hit.language || '??');",
            '<a href="${url}" target="_blank">${urlText}</a>',
            '<span class="sm-test-meta-item"><span class="sm-test-meta-label">${formatMetaLabel(\'ID\')}</span> #${objectIdDisplay}</span>',
            '<span class="sm-test-meta-item"><span class="sm-test-meta-label">${formatMetaLabel(T.typeLabel)}</span> ${type}</span>',
            '${contextMeta}',
            '${indexHandle ? `<span class="sm-test-meta-item"><span class="sm-test-meta-label">${formatMetaLabel(T.indexLabel)}</span> <code>${indexHandle}</code></span>` : \'\'}',
            '<span class="sm-test-site-badge"><span class="sm-test-meta-label">${formatMetaLabel(T.siteLabel)}</span> ${siteName} (${language})</span>',
            '${matchedIn ? `<div class="sm-test-match-line"><strong>${T.matchedInLabel}</strong> <code>${matchedIn}</code></div>` : \'\'}',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        foreach ([
            '${url}</a>',
            '${hit.type || T.entry}',
            '+ hit.section',
            'hit.productTypeName',
            'Boolean(hit.productTypeHandle || hit.productType)',
            "isCommerceHit ? hit.section : ''",
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
            '<strong class="sm-test-title">${title}</strong>',
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
