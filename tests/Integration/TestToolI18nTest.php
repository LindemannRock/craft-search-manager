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
        // Audit #177: the inline JS result UI must be fed by a translated strings object.
        $source = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');

        // The strings object exists and routes representative strings through |t().
        self::assertStringContainsString('const T = {', $source);
        foreach ([
            "promoted: {{ 'Promoted'|t('search-manager')|json_encode|raw }}",
            "boosted: {{ 'Boosted'|t('search-manager')|json_encode|raw }}",
            "noResults: {{ 'No results found for \"{query}\"'|t('search-manager')|json_encode|raw }}",
            "promotionsNote: {{ 'Note: Promotions only appear in search results on sites where the element is live (green).'|t('search-manager')|json_encode|raw }}",
            "backendLabel: {{ 'Backend:'|t('search-manager')|json_encode|raw }}",
            "redirectToElement: {{ 'Redirect to {link}'|t('search-manager')|json_encode|raw }}",
            "foundResultsSingular: {{ 'Found {count} result'|t('search-manager')|json_encode|raw }}",
            "foundResultsPlural: {{ 'Found {count} results'|t('search-manager')|json_encode|raw }}",
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        // Render sites reference the strings object, not raw literals.
        foreach ([
            "T.noResults.replace('{query}', Craft.escapeHtml(query))",
            'T.actionLabels[r.actionType] || r.actionType',
            'hit.title || T.untitled',
            'data.error || T.unknownError',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testResultBadgesUseCssUppercaseNotAllCapsKeys(): void
    {
        // The Promoted/Boosted/Disabled result-card pills are inline-styled badges on
        // the test page (not the base badge component). Their uppercase is a CSS concern
        // (text-transform), so the translation value stays normal-case and Disabled is
        // reused — never a forked all-caps key (meaningless for AR/JA, awkward elsewhere).
        $twig = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');

        self::assertStringContainsString('text-transform: uppercase;">${T.promoted}</span>', $twig);
        self::assertStringContainsString('text-transform: uppercase;">${T.boosted}</span>', $twig);
        self::assertStringContainsString('text-transform: uppercase;">${T.disabled}</span>', $twig);
        self::assertStringNotContainsString('disabledBadge', $twig);

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
        $source = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');

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

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
