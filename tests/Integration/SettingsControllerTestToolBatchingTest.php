<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins CP test-tool element preloading for audit #132/#133.
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerTestToolBatchingTest extends TestCase
{
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
}
