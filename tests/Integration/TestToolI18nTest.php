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
 * Pins translation of CP template strings that bypassed the translation work:
 * deleted-site fallbacks (audits #175/#176) and the search-test/diagnostic tool
 * inline JS string cluster (audit #177).
 */
final class TestToolI18nTest extends TestCase
{
    public function testDeletedSiteFallbacksAreTranslated(): void
    {
        // Audit #175: promotions / query-rules index site column.
        foreach ([
            'src/templates/promotions/index.twig',
            'src/templates/query-rules/index.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);
            self::assertStringContainsString("site ? site.name : 'Unknown'|t('search-manager')", $source);
            self::assertStringNotContainsString("site ? site.name : 'Unknown' }}", $source);
        }

        // Audit #176: pending-syncs index + row site column.
        foreach ([
            'src/templates/pending-syncs/index.twig',
            'src/templates/pending-syncs/_row.twig',
        ] as $path) {
            $source = $this->readPluginFile($path);
            self::assertStringContainsString("'Site #{id}'|t('search-manager', {id: item.siteId})", $source);
            self::assertStringNotContainsString("('Site #' ~ item.siteId)", $source);
        }
    }

    public function testSearchTestToolUsesTranslatedStringsObject(): void
    {
        // Audit #177: the asset-rendered result UI must be fed by a translated strings object.
        $twig = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');
        $js = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        // The config strings object exists and routes representative strings through |t().
        self::assertStringContainsString('translations: {', $twig);
        foreach ([
            "promoted: {{ 'Promoted'|t('search-manager')|json_encode|raw }}",
            "boosted: {{ 'Boosted'|t('search-manager')|json_encode|raw }}",
            "noResults: {{ 'No results found for \"{query}\"'|t('search-manager')|json_encode|raw }}",
            "promotionsNote: {{ 'Note: Promotions only appear in search results on sites where the element is live (green).'|t('search-manager')|json_encode|raw }}",
            "backendLabel: {{ 'Backend:'|t('search-manager')|json_encode|raw }}",
            "redirectToElement: {{ 'Redirect to {link}'|t('search-manager')|json_encode|raw }}",
            "redirectRuleMatched: {{ 'Redirect rule matched'|t('search-manager')|json_encode|raw }}",
            "productionRedirectNotice: {{ 'Production search would redirect this query.'|t('search-manager')|json_encode|raw }}",
            "ruleLabel: {{ 'Rule:'|t('search-manager')|json_encode|raw }}",
            "targetLabel: {{ 'Target:'|t('search-manager')|json_encode|raw }}",
            "urlLabel: {{ 'URL:'|t('search-manager')|json_encode|raw }}",
            "sectionTypeLabel: {{ 'Section Type:'|t('search-manager')|json_encode|raw }}",
            "anchorLabel: {{ 'Anchor:'|t('search-manager')|json_encode|raw }}",
            "breadcrumbLabel: {{ 'Breadcrumb'|t('search-manager')|json_encode|raw }}",
            "levelLabel: {{ 'Level'|t('search-manager')|json_encode|raw }}",
            "folderPathLabel: {{ 'Folder Path'|t('search-manager')|json_encode|raw }}",
            "hitLabel: {{ 'Hit'|t('search-manager')|json_encode|raw }}",
            "yesLabel: {{ 'Yes'|t('search-manager')|json_encode|raw }}",
            "noLabel: {{ 'No'|t('search-manager')|json_encode|raw }}",
            "foundResultsSingular: {{ 'Found {count} result'|t('search-manager')|json_encode|raw }}",
            "foundResultsPlural: {{ 'Found {count} results'|t('search-manager')|json_encode|raw }}",
        ] as $needle) {
            self::assertStringContainsString($needle, $twig);
        }
        self::assertStringNotContainsString("filter: {{ 'Filter'|t('search-manager')|json_encode|raw }}", $twig);

        // Render sites reference the strings object, not raw literals.
        foreach ([
            "T.noResults.replace('{query}', Craft.escapeHtml(query))",
            'T.actionLabels[r.actionType] || Craft.escapeHtml(r.actionType)',
            'const rawTitle = hasSectionHit ? (hit.sectionTitle || hit.title || T.untitled) : (hit.title || T.untitled);',
            'renderMetaPill(T.sectionTypeLabel, hit.sectionType)',
            'renderMetaPill(T.anchorLabel, hit.sectionAnchor)',
            'data.error || T.unknownError',
            'formatMetaLabel(T.breadcrumbLabel)',
            'formatMetaLabel(T.folderPathLabel)',
        ] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
    }

    public function testQueryRuleEditUiDoesNotOfferFilterResultsAction(): void
    {
        $source = $this->readPluginFile('src/templates/query-rules/edit.twig');

        foreach ([
            'Filter Results',
            'value="filter"',
            'id="action-filter"',
            'filterField',
            'filterValue',
            'Filter results to only show entries matching a specific field value.',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }

        foreach ([
            '<option value="synonym"',
            '<option value="boost_section"',
            '<option value="boost_category"',
            '<option value="boost_element"',
            '<option value="redirect"',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testQueryRuleEditUiUsesActionSpecificBoostMultiplierFields(): void
    {
        $source = $this->readPluginFile('src/templates/query-rules/edit.twig');

        self::assertStringContainsString("name: 'boostSectionMultiplier'", $source);
        self::assertStringContainsString("name: 'boostCategoryMultiplier'", $source);
        self::assertStringContainsString("name: 'boostElementMultiplier'", $source);
        self::assertStringNotContainsString("name: 'boostMultiplier'", $source);
    }

    public function testResultBadgesUseCssUppercaseNotAllCapsKeys(): void
    {
        // The Promoted/Boosted/Disabled test page pills are local badges, not
        // the base badge component. Their uppercase is a CSS concern
        // (text-transform), so the translation value stays normal-case and Disabled is
        // reused — never a forked all-caps key (meaningless for AR/JA, awkward elsewhere).
        $js = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');
        $css = $this->readPluginFile('src/web/assets/testtool/src/test-tool.css');

        self::assertStringContainsString('<span class="sm-test-status sm-test-status--promoted">${T.promoted}</span>', $js);
        self::assertStringContainsString('<span class="sm-test-status sm-test-status--boosted">${T.boosted}</span>', $js);
        self::assertStringContainsString('<span class="sm-test-disabled-badge">${T.disabled}</span>', $js);
        self::assertStringContainsString('text-transform: uppercase;', $css);
        self::assertStringNotContainsString('disabledBadge', $js);

        // The all-caps keys must not exist in the translation files; normal-case ones do.
        $en = require dirname(__DIR__, 2) . '/src/translations/en/search-manager.php';
        foreach (['PROMOTED', 'BOOSTED', 'DISABLED'] as $allCaps) {
            self::assertArrayNotHasKey($allCaps, $en);
        }
        self::assertArrayHasKey('Promoted', $en);
        self::assertArrayHasKey('Boosted', $en);
        self::assertArrayHasKey('Disabled', $en);
    }

    public function testSearchTestToolRawStringClusterIsGone(): void
    {
        // Audit #177: the raw English literals (and parenthetical plurals) must be gone
        // from the render sites. The English text now lives only inside the |t() calls.
        $source = $this->readPluginFile('src/templates/settings/test/_partials/search.twig')
            . "\n"
            . $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        foreach ([
            'promotion(s)',
            'rule(s)',
            'result${data.total !== 1 ? \'s\' : \'\'}',
            'const actionLabels = {',
            "hit.title || 'Untitled'",
            ">PROMOTED</span>",
            ">BOOSTED</span>",
            ">DISABLED</span>",
            '|| \'Unknown error\')',
            "'filter': 'orange'",
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testBackendDiagnosticToolStringsAreTranslated(): void
    {
        // Audit #177: backend.twig remaining raw strings (Yes/No/Unknown).
        $source = $this->readPluginFile('src/templates/settings/test/_partials/backend.twig');

        self::assertStringContainsString("&#10003; {{ 'Yes'|t('search-manager') }}", $source);
        self::assertStringContainsString("&#10007; {{ 'No'|t('search-manager') }}", $source);
        self::assertStringContainsString("idx.uid || {{ 'Unknown'|t('search-manager')|json_encode|raw }}", $source);

        self::assertStringNotContainsString('&#10003; Yes</span>', $source);
        self::assertStringNotContainsString("idx.uid || 'Unknown'", $source);
    }

    public function testSearchTestToolEscapesDynamicInnerHtmlValues(): void
    {
        // Audits #202/#203: autocomplete terms must not be embedded in inline
        // handlers, and admin-configured values rendered through innerHTML stay
        // escaped before reaching the DOM.
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        self::assertStringContainsString('let autocompleteTerms = [];', $source);
        self::assertStringContainsString("autocompleteDropdown.addEventListener('click'", $source);
        self::assertStringContainsString('data-autocomplete-index="${index}"', $source);
        self::assertStringContainsString('testQueryInput.value = term;', $source);
        self::assertStringNotContainsString('onclick="document.getElementById(\'testQuery\').value=\'${term}\'', $source);

        foreach ([
            '${Craft.escapeHtml(s)}</span>',
            '<article class="sm-test-diagnostic-card${!p.enabled ? \' sm-test-row-disabled\' : \'\'}">',
            '<code>${Craft.escapeHtml(p.matchType)}</code>',
            'p.siteStatuses.filter(s => s.isLive).map(s => `',
            '${Craft.escapeHtml(s.siteName)}</span>',
            '<span class="sm-test-site-list-items">',
            'function renderSafeLinkOrText(url, label)',
            'const safeUrl = safeUrlAttribute(url);',
            'return safeUrl ? `<a href="${safeUrl}" target="_blank">${display}</a>` : display;',
            'data.synonyms.map(s => `<code>${Craft.escapeHtml(s)}</code>`).join(\', \')',
            'const actionLabel = T.actionLabels[r.actionType] || Craft.escapeHtml(r.actionType);',
            'function renderStatusLabel(label, colorClass)',
            '<span class="status-label ${color}">',
            '<span class="status ${color}"></span>',
            '<span class="status-label-text">${Craft.escapeHtml(label)}</span>',
            '<article class="sm-test-diagnostic-card">',
            '<span><code>${Craft.escapeHtml(r.matchType)}</code>: <code>${Craft.escapeHtml(r.matchValue)}</code></span>',
            'if (index === -1) return Craft.escapeHtml(text);',
            "Craft.escapeHtml(text.substring(0, index)) + '<strong>' + Craft.escapeHtml(text.substring(index, index + query.length))",
            'resultsContent.innerHTML = `<p class="sm-test-error">${Craft.escapeHtml(error.message)}</p>`;',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        $backend = $this->readPluginFile('src/templates/settings/test/_partials/backend.twig');
        self::assertStringContainsString("Craft.escapeHtml(response.data.error || {{ 'Failed'|t('search-manager')|json_encode|raw }})", $backend);
    }

    public function testRedirectNoticeExplainsProductionRedirectWithoutShowingFullPanel(): void
    {
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        self::assertStringContainsString('const shouldFetchQueryRules = showQueryRules.checked || (searchData && searchData.redirect);', $source);
        self::assertStringContainsString('if (queryRulesData && showQueryRules.checked) {', $source);
        self::assertStringContainsString('displayQueryRules(queryRulesData, query, searchData);', $source);
        self::assertStringContainsString('displaySearchResults(searchData, query, queryRulesData);', $source);
        self::assertStringContainsString('function renderRedirectNotice(searchData, queryRulesData, isCompact)', $source);
        self::assertStringContainsString('if (!redirectUrl) {', $source);
        self::assertStringContainsString("return '';", $source);
        self::assertStringContainsString('const hasRedirect = Boolean(data.redirect || (queryRulesData && queryRulesData.redirect));', $source);
        self::assertStringContainsString('resultsTitle.innerHTML = hasRedirect ? T.redirectRuleMatched', $source);
        self::assertStringContainsString('} else if (!hasRedirect) {', $source);
        self::assertStringContainsString('html += renderRedirectNotice(data, queryRulesData, true);', $source);
        self::assertStringContainsString('const redirectHtml = renderRedirectNotice(null, data, false);', $source);
        self::assertStringContainsString("isCompact ? 'sm-test-main-redirect-notice' : 'sm-test-redirect-box'", $source);
        self::assertStringContainsString('T.productionRedirectNotice', $source);
        self::assertStringContainsString('T.redirectRuleMatched', $source);

        self::assertStringNotContainsString('showQueryRules.checked ? postJson(urls.testQueryRules', $source);
        self::assertStringNotContainsString('<a href="${redirectUrl}" target="_blank">${redirectUrl}</a>', $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
