<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins CP test-tool element preloading for audit #132/#133.
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerTestToolBatchingTest extends TestCase
{
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function tearDown(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }

        parent::tearDown();
    }

    public function testCleanupAnalyticsRequiresJsonAcceptAfterPostGate(): void
    {
        $body = $this->controllerMethodBody('actionCleanupAnalytics');

        $permissionPos = strpos($body, '$this->requirePermission(\'searchManager:manageSettings\');');
        $postPos = strpos($body, '$this->requirePostRequest();');
        $acceptsJsonPos = strpos($body, '$this->requireAcceptsJson();');

        self::assertIsInt($permissionPos, 'actionCleanupAnalytics must keep the manageSettings permission gate.');
        self::assertIsInt($postPos, 'actionCleanupAnalytics must keep the POST gate.');
        self::assertIsInt($acceptsJsonPos, 'actionCleanupAnalytics must require JSON accept headers.');
        self::assertLessThan($postPos, $permissionPos, 'Permission gate should remain before POST validation.');
        self::assertLessThan($acceptsJsonPos, $postPos, 'POST validation should remain before JSON accept validation.');
        self::assertStringContainsString('return $this->asJson([', $body);
    }

    public function testPromotionTestToolDoesNotQueryOneElementPerPromotionSitePair(): void
    {
        $body = $this->controllerMethodBody('actionTestPromotions');

        self::assertStringNotContainsString('->one()', $body);
        self::assertStringNotContainsString('$promotion->getElement()', $body);
        self::assertStringContainsString('preloadTestPromotionElements($matchingPromotions)', $body);
        self::assertStringContainsString('preloadTestPromotionLiveElements($matchingPromotions, $promotionElements, $sites)', $body);
        self::assertStringContainsString("'elementTypeLabel' => \$this->promotionElementTypeLabel(\$promotion->elementType, \$element),", $body);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($elementQuery, $elementClass)->all()', $this->readPluginFile('src/controllers/SettingsController.php'));
    }

    public function testPromotionTestToolDisplaysTargetElementTypeLabel(): void
    {
        $controllerSource = $this->readPluginFile('src/controllers/SettingsController.php');
        $assetSource = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        self::assertStringContainsString('TargetElementTypeHelper::translatedLabels()', $controllerSource);
        self::assertStringContainsString('p.elementTypeLabel', $assetSource);
        self::assertStringContainsString('${T.typeLabel} ${Craft.escapeHtml(p.elementTypeLabel)}', $assetSource);
        self::assertStringContainsString('p.siteIndependent ? \'\' : `<div class="sm-test-diagnostic-field sm-test-diagnostic-field--wide">', $assetSource);
    }

    public function testQueryRuleTestToolDoesNotLoadRedirectElementsOneByOne(): void
    {
        $body = $this->controllerMethodBody('actionTestQueryRules');

        self::assertStringNotContainsString('getElementById(', $body);
        self::assertStringContainsString('preloadTestQueryRuleRedirectElements($matchingRules)', $body);
        self::assertStringContainsString('$redirectElements[$this->elementCacheKey(', $body);
        self::assertStringContainsString("'targetElementId' => \$targetElementId,", $body);
        self::assertStringContainsString("'targetElementType' => \$targetElementType,", $body);
        self::assertStringContainsString("'targetSectionHandle' => \$targetSectionHandle,", $body);
        self::assertStringContainsString("'targetCategoryId' => \$targetCategoryId,", $body);
        self::assertStringContainsString("'targetCategoryHandle' => \$targetCategoryHandle,", $body);
    }

    public function testTestToolComparesMatchedDiagnosticsAgainstRenderedResults(): void
    {
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        self::assertStringContainsString('function resultElementIds(searchData, predicate)', $source);
        self::assertStringContainsString('displayPromotions(promotionsData, query, searchData);', $source);
        self::assertStringContainsString('displayQueryRules(queryRulesData, query, searchData);', $source);
        self::assertStringContainsString('const renderedPromotionIds = resultElementIds(searchData, hit => hit.promoted === true);', $source);
        self::assertStringContainsString('renderedPromotionIds.has(Number(p.elementId)) ? T.yesLabel : T.noLabel', $source);
        self::assertStringContainsString('function countAppliedBoostRule(rule, searchData)', $source);
        self::assertStringContainsString("const isBoostRule = ['boost_section', 'boost_category', 'boost_element'].includes(r.actionType);", $source);
        self::assertStringContainsString("const debug = hit._queryRuleDebug && typeof hit._queryRuleDebug === 'object' ? hit._queryRuleDebug : null;", $source);
        self::assertStringContainsString('const boosts = debug && Array.isArray(debug.boosts) ? debug.boosts : [];', $source);
        self::assertStringContainsString('boosts.some(boost => Number(boost.ruleId) === ruleId)', $source);
        self::assertStringContainsString('const appliedCount = isBoostRule ? countAppliedBoostRule(r, searchData) : null;', $source);
        self::assertStringContainsString("renderStatusLabel(appliedCount > 0 ? T.yesLabel : T.noLabel, appliedCount > 0 ? 'green' : 'red')", $source);
        self::assertStringContainsString('includeQueryRuleDebug: showQueryRules.checked,', $source);
        self::assertStringNotContainsString('const boostedCount = boostedElementIds.size;', $source);
        self::assertStringNotContainsString('renderStatusLabel(String(appliedCount)', $source);
    }

    public function testTestToolIndexedUrlFilterIsTopLevelFeatureToggle(): void
    {
        $template = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');
        $assetSource = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        self::assertStringContainsString('id="resultsRequireUrl"', $template);
        self::assertStringContainsString('id="includeDebugMeta"', $template);
        self::assertStringNotContainsString('id="liveComparisonOptions"', $template);
        self::assertStringNotContainsString('id="hideWithoutLiveUrl"', $template);
        self::assertStringNotContainsString('sm-test-toggle-card--primary', $template);
        self::assertStringContainsString("resultsRequireUrl: document.getElementById('resultsRequireUrl').checked,", $assetSource);
        self::assertStringNotContainsString('hideWithoutLiveUrl', $assetSource);
    }

    public function testSearchTestToolPassesBackendRedirectToAssetRenderer(): void
    {
        $body = $this->controllerMethodBody('actionTestSearch');

        self::assertStringContainsString("'redirect' => \$results['redirect'] ?? null,", $body);
        self::assertStringContainsString("\$includeQueryRuleDebug = (bool)Craft::\$app->getRequest()->getBodyParam('includeQueryRuleDebug', false);", $body);
        self::assertStringContainsString("\$searchOptions['includeQueryRuleDebug'] = true;", $body);
        self::assertStringContainsString('], $includeQueryRuleDebug);', $body);
        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $body);
        self::assertStringContainsString('SearchManager::$plugin->liveComparison->compareHits', $body);
        self::assertStringNotContainsString('SearchHitPresenter::present($hit, $includeQueryRuleDebug)', $body);
    }

    public function testSearchTestToolReturnsTriStateCacheStatus(): void
    {
        $stub = $this->installStubBackend();

        $miss = $this->postTestSearch($stub, [
            'hits' => [],
            'total' => 0,
            'meta' => ['cached' => false, 'cacheDriver' => 'file'],
        ], false);
        self::assertSame('miss', $miss['cacheStatus'] ?? null);
        self::assertArrayNotHasKey('cacheHit', $miss);

        $hit = $this->postTestSearch($stub, [
            'hits' => [],
            'total' => 0,
            'meta' => ['cached' => true, 'cacheDriver' => 'file'],
        ], false);
        self::assertSame('hit', $hit['cacheStatus'] ?? null);
        self::assertArrayNotHasKey('cacheHit', $hit);

        $bypassed = $this->postTestSearch($stub, [
            'hits' => [],
            'total' => 0,
            'meta' => ['cached' => false, 'cacheDriver' => 'file'],
        ], true);
        self::assertSame('bypassed', $bypassed['cacheStatus'] ?? null);
        self::assertArrayNotHasKey('cacheHit', $bypassed);

        $searchCalls = $stub->callsFor('search');
        self::assertFalse($searchCalls[0]['items'][0]['options']['includeQueryRuleDebug'] ?? false);
        self::assertSame(true, $searchCalls[2]['items'][0]['options']['includeQueryRuleDebug'] ?? null);
    }

    private function controllerMethodBody(string $method): string
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/controllers/SettingsController.php';
        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        preg_match(
            '/public function ' . preg_quote($method, '/') . '\(\): Response\s+\{(?<body>.*?)(?:\n    \}|\n    public function )/s',
            $source,
            $matches,
        );

        $body = $matches['body'] ?? '';
        self::assertNotSame('', $body, $method . ' body should be captured.');

        return $body;
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($contents);

        return $contents;
    }

    /**
     * @param array<string, mixed> $searchResponse
     * @return array<string, mixed>
     */
    private function postTestSearch(StubBackend $stub, array $searchResponse, bool $includeQueryRuleDebug): array
    {
        $this->withPostJson([
            'query' => '__cache_status_test__',
            'indexHandle' => '__missing_index__',
            'includeQueryRuleDebug' => $includeQueryRuleDebug,
        ]);
        $this->withSettingsManagerPermissions();

        $stub->searchResponse = $searchResponse;
        Craft::$app->getResponse()->data = null;

        $controller = new SettingsController('settings', SearchManager::$plugin);
        $response = $controller->actionTestSearch();
        $data = $response->data;

        self::assertIsArray($data);
        self::assertSame(true, $data['success'] ?? null);

        return $data;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function withSettingsManagerPermissions(): void
    {
        $user = $this->createTestUser('__sm_cache_status_user_', [
            'admin' => true,
        ]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);
    }
}
