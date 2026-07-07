<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit batch 8 hardening.
 *
 * @since 5.53.0
 */
final class AuditBatch8RegressionTest extends TestCase
{
    public function testQueryRuleRegexRejectsBacktrackingProbeFailures(): void
    {
        $rule = $this->queryRule('(a+)+$');

        self::assertFalse($rule->validate(['matchValue']));
        self::assertNotEmpty($rule->getErrors('matchValue'));
    }

    public function testQueryRuleRegexStillAcceptsNormalAdminPatterns(): void
    {
        $rule = $this->queryRule('^(coffee|tea)\\s+beans?$');

        self::assertTrue($rule->validate(['matchValue']));
        self::assertTrue($rule->matches('Coffee beans'));
        self::assertFalse($rule->matches('coffee grinder'));
    }

    public function testPublicLanguageEntryPointsNormalizeAndDropUnsafeValues(): void
    {
        self::assertSame('en-us', ApiController::normalizePublicLanguage('en_US'));
        self::assertSame('pt-br', SearchResolver::normalizePublicLanguage('pt_BR'));
        self::assertNull(ApiController::normalizePublicLanguage('../../../../tmp/payload'));
        self::assertNull(SearchResolver::normalizePublicLanguage('en.php'));
    }

    public function testGraphqlSchemaExposesLangAliasForSearchAndAutocomplete(): void
    {
        $queries = SearchQuery::getQueries(false);

        self::assertArrayHasKey('lang', $queries['searchManagerSearch']['args']);
        self::assertArrayHasKey('lang', $queries['searchManagerAutocomplete']['args']);
    }

    public function testCsrfFreeTrackingUsesTrustedOriginGuard(): void
    {
        $source = $this->readPluginFileContents('src/controllers/SearchController.php');

        self::assertStringContainsString('$this->enableCsrfValidation = false;', $source);
        self::assertStringContainsString('$this->requireTrustedTrackingOrigin()', $source);
        self::assertStringContainsString('Cross-origin tracking requests are not allowed.', $source);
        self::assertSame(42, SearchController::normalizeTrackingElementId('42'));
        self::assertNull(SearchController::normalizeTrackingElementId('-1'));
        self::assertSame(3, SearchController::normalizeTrackingPosition('3'));
        self::assertNull(SearchController::normalizeTrackingPosition('1001'));
    }

    public function testTrustedTrackingOriginMatchingIsExactOriginOnly(): void
    {
        $allowedOrigins = [
            'https://frontend.example.com/',
            'http://localhost:3000',
        ];

        self::assertSame(
            ['https://frontend.example.com:443', 'http://localhost:3000'],
            SearchController::normalizeTrackingOrigins($allowedOrigins),
        );
        self::assertSame(
            ['https://frontend.example.com:443', 'http://localhost:3000'],
            SearchController::normalizeTrackingOrigins('https://frontend.example.com/, http://localhost:3000'),
        );
        self::assertNull(SearchController::normalizeTrackingOrigin('https://frontend.example.com/path'));
        self::assertNull(SearchController::normalizeTrackingOrigin('*.example.com'));

        self::assertTrue(SearchController::trackingOriginAllowed(
            'https://craft.example.com',
            'https://craft.example.com',
            [],
        ));
        self::assertTrue(SearchController::trackingOriginAllowed(
            'https://frontend.example.com',
            'https://craft.example.com',
            $allowedOrigins,
        ));
        self::assertFalse(SearchController::trackingOriginAllowed(
            'https://evil.example.com',
            'https://craft.example.com',
            $allowedOrigins,
        ));
        self::assertFalse(SearchController::trackingOriginAllowed(
            'https://frontend.example.com:8443',
            'https://craft.example.com',
            $allowedOrigins,
        ));
    }

    public function testAllowedTrackingOriginEmitsCorsAndOptionsPreflight(): void
    {
        $source = $this->readPluginFileContents('src/controllers/SearchController.php');

        self::assertStringContainsString("'Access-Control-Allow-Origin', rtrim(trim(\$origin), '/')", $source);
        self::assertStringContainsString("'Vary', 'Origin'", $source);
        self::assertStringContainsString("'Access-Control-Allow-Methods', 'POST, OPTIONS'", $source);
        self::assertStringContainsString("'Access-Control-Allow-Headers', 'Content-Type, Accept, X-Search-Manager-Key'", $source);
        self::assertStringContainsString("\$request->getMethod()) === 'OPTIONS'", $source);
        self::assertStringContainsString("Craft::\$app->getResponse()->setStatusCode(204);", $source);
        self::assertStringNotContainsString('Access-Control-Allow-Origin\', \'*', $source);
    }

    public function testTrackingAllowedOriginsIsConfigOnlySetting(): void
    {
        $settings = $this->readPluginFileContents('src/models/Settings.php');
        $config = $this->readPluginFileContents('src/config.php');

        self::assertStringContainsString('public array|string $trackingAllowedOrigins = [];', $settings);
        self::assertStringContainsString("'trackingAllowedOrigins',", $settings);
        self::assertStringContainsString("'trackingAllowedOrigins' => App::env('SEARCH_MANAGER_TRACKING_ALLOWED_ORIGINS') ?: []", $config);
    }

    public function testCpSettingsTestToolDoesNotCallPublicTrackingEndpoints(): void
    {
        $publicTrackingEndpoints = [
            'search-manager/search/track-search',
            'search-manager/search/track-click',
            '/actions/search-manager/search/track-search',
            '/actions/search-manager/search/track-click',
        ];

        foreach ([
            'src/templates/settings/test/_partials/search.twig',
            'src/web/assets/testtool/src/test-tool.js',
            'src/web/assets/testtool/dist/test-tool.js',
        ] as $path) {
            $source = $this->readPluginFileContents($path);

            foreach ($publicTrackingEndpoints as $endpoint) {
                self::assertStringNotContainsString(
                    $endpoint,
                    $source,
                    $path . ' must keep CP/internal test searches off the public CSRF-free tracking endpoint.',
                );
            }
        }
    }

    public function testAnalyticsTrendingChangePercentIsNumericBeforeHtmlInsertion(): void
    {
        $source = $this->readPluginFileContents('src/web/assets/analytics/src/analytics.js');

        self::assertStringContainsString('const changePercent = Number(q.changePercent);', $source);
        self::assertStringContainsString('const safeChangePercent = Number.isFinite(changePercent) ? changePercent : 0;', $source);
        self::assertStringContainsString("trendText = '+' + safeChangePercent + '%';", $source);
        self::assertStringContainsString("trendText = '-' + safeChangePercent + '%';", $source);
        self::assertStringContainsString('trendText = Craft.escapeHtml(strings.newLabel);', $source);
    }

    private function queryRule(string $pattern): QueryRule
    {
        $rule = new QueryRule();
        $rule->name = 'Audit batch 8 regex';
        $rule->matchType = QueryRule::MATCH_REGEX;
        $rule->matchValue = $pattern;
        $rule->actionType = QueryRule::ACTION_SYNONYM;
        $rule->actionValue = ['terms' => ['coffee']];

        return $rule;
    }

    private function readPluginFileContents(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
