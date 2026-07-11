<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for audit #171 and #172.
 *
 * @since 5.53.0
 */
final class AuditPass30RegressionTest extends TestCase
{
    public function testApplyPromotionsUsesSuppliedMatchesWithoutRefetching(): void
    {
        $stub = $this->installStubBackend();
        $stub->documentsByElementId['test-index:42:null'] = [
            'id' => 42,
            'elementId' => 42,
            'title' => 'Indexed promotion title',
            'url' => '/indexed-promotion',
            'type' => 'entry',
        ];

        $promotion = new Promotion();
        $promotion->id = 123;
        $promotion->query = 'audit-pass-30';
        $promotion->elementId = 42;
        $promotion->position = 1;

        $service = new class extends PromotionService {
            public int $fetches = 0;

            public function getPromotedElements(string $query, string $indexHandle, ?int $siteId = null): array
            {
                $this->fetches++;
                return [];
            }
        };

        $results = $service->applyPromotions([99], 'audit-pass-30', 'test-index', null, [$promotion]);

        self::assertSame(0, $service->fetches);
        self::assertSame(42, $results[0]['id']);
        self::assertSame('Indexed promotion title', $results[0]['title']);
        self::assertSame('/indexed-promotion', $results[0]['url']);
        self::assertTrue($results[0]['promoted']);
        self::assertSame(1, $results[0]['position']);
        self::assertSame([99], array_slice($results, 1));
    }

    public function testApplyPromotionsFetchesPromotedDocumentsFromBackend(): void
    {
        $stub = $this->installStubBackend();
        $stub->documentsByElementId['test-index:42:7'] = [
            'id' => 42,
            'elementId' => 42,
            'siteId' => 7,
            'title' => 'Indexed site title',
            'url' => '/site/indexed',
            'type' => 'entry',
        ];

        $promotion = new Promotion();
        $promotion->id = 124;
        $promotion->query = 'audit-pass-30';
        $promotion->elementId = 42;
        $promotion->position = 1;

        $results = (new PromotionService())->applyPromotions(
            [['id' => 99, 'siteId' => 7, 'score' => 1.0]],
            'audit-pass-30',
            'test-index',
            7,
            [$promotion],
        );

        self::assertSame('Indexed site title', $results[0]['title']);
        self::assertSame('/site/indexed', $results[0]['url']);
        self::assertSame([
            [
                'method' => 'getDocumentsByElementIds',
                'indexName' => 'test-index',
                'items' => [[
                    'elementIds' => [42],
                    'siteId' => 7,
                ]],
            ],
        ], $stub->callsFor('getDocumentsByElementIds'));
    }

    public function testApplyPromotionsSkipsMissingIndexedDocuments(): void
    {
        $this->installStubBackend();

        $promotion = new Promotion();
        $promotion->id = 125;
        $promotion->query = 'audit-pass-30';
        $promotion->elementId = 42;
        $promotion->position = 1;

        $results = (new PromotionService())->applyPromotions(
            [['id' => 99, 'siteId' => 7, 'score' => 1.0]],
            'audit-pass-30',
            'test-index',
            7,
            [$promotion],
        );

        self::assertSame([['id' => 99, 'siteId' => 7, 'score' => 1.0]], $results);
    }

    public function testPromotedHitsCarryInternalElementTypeAndPresenterSuppressesIt(): void
    {
        $promotionSource = $this->methodSource(
            $this->readPluginFile('src/services/PromotionService.php'),
            'public function applyPromotions',
        );
        $liveComparisonSource = $this->readPluginFile('src/services/LiveComparisonService.php');
        $presenterSource = $this->readPluginFile('src/helpers/SearchHitPresenter.php');

        self::assertStringContainsString("\$promotedItem['_elementType'] = \$promotion->elementType;", $promotionSource);
        self::assertStringContainsString('$explicitElementClass = is_string($hit[\'_elementType\'] ?? null) ? $hit[\'_elementType\'] : null;', $liveComparisonSource);
        self::assertStringContainsString('$elementClass = $explicitElementClass ?:', $liveComparisonSource);
        self::assertStringContainsString('$hit[\'description\'],', $presenterSource);
        self::assertStringContainsString('$hit[\'highlights\'],', $presenterSource);
        self::assertStringContainsString('$hit[\'_elementType\'],', $presenterSource);
        self::assertStringContainsString('$hit[\'_bodyClean\'],', $presenterSource);
        self::assertStringContainsString('$hit[\'_contentClean\'],', $presenterSource);
    }

    public function testPromotionServiceShapesHitsFromIndexedDocumentsOnly(): void
    {
        $source = $this->readPluginFile('src/services/PromotionService.php');

        self::assertStringContainsString('getDocumentsByElementIds($indexHandle, $elementIds, $siteId)', $source);
        self::assertStringContainsString('Skipping promotion because target document is not indexed', $source);
        self::assertStringNotContainsString('getElementById', $source);
        self::assertStringNotContainsString('SearchElementAvailabilityHelper', $source);
        self::assertStringNotContainsString('::find()', $source);
    }

    public function testBackendSearchThreadsAlreadyMatchedPromotionsIntoApplicationPath(): void
    {
        $source = $this->readPluginFile('src/services/BackendService.php');

        self::assertStringContainsString('$matchedPromotions = \lindemannrock\searchmanager\models\Promotion::findMatching', $source);
        preg_match_all('/applyPromotions\([^)]+\$matchedPromotions,/s', $source, $matches);
        self::assertCount(2, $matches[0]);
    }

    public function testActionTestQueryRulesUsesTranslatedActionDescriptionPath(): void
    {
        $source = $this->methodSource(
            $this->readPluginFile('src/controllers/SettingsController.php'),
            'public function actionTestQueryRules',
        );

        self::assertStringContainsString('$effectDescription = $rule->getActionDescription();', $source);
        self::assertStringContainsString('$effectDescription = $rule->getActionDescription($redirect);', $source);
        self::assertStringContainsString("Craft::t('search-manager', 'Untitled')", $source);
        self::assertStringNotContainsString('Expands to:', $source);
        self::assertStringNotContainsString('Boost section "', $source);
        self::assertStringNotContainsString('Boost category by ', $source);
        self::assertStringNotContainsString('Boost element #', $source);
        self::assertStringNotContainsString('Redirect to element (not found)', $source);
        self::assertStringNotContainsString('Redirect to: ', $source);
    }

    public function testQueryRuleActionDescriptionCanReuseResolvedRedirectUrl(): void
    {
        $source = $this->methodSource(
            $this->readPluginFile('src/models/QueryRule.php'),
            'public function getActionDescription',
        );

        self::assertStringContainsString('public function getActionDescription(?string $redirectUrl = null): string', $source);
        self::assertStringContainsString("'url' => \$redirectUrl ?? \$this->getRedirectUrl()", $source);
    }

    public function testSearchTimeHitShapingPathsDoNotUseLiveElementLookups(): void
    {
        $promotionServiceSource = $this->readPluginFile('src/services/PromotionService.php');
        $queryRuleServiceSource = $this->readPluginFile('src/services/QueryRuleService.php');
        $promotionMatchingSource = $this->methodSource(
            $this->readPluginFile('src/models/Promotion.php'),
            'public static function findMatching',
        );
        $redirectSource = $this->methodSource(
            $this->readPluginFile('src/models/QueryRule.php'),
            'public function getRedirectUrl',
        );

        foreach ([
            'PromotionService' => $promotionServiceSource,
            'QueryRuleService' => $queryRuleServiceSource,
            'Promotion::findMatching' => $promotionMatchingSource,
        ] as $label => $source) {
            self::assertStringNotContainsString('getElementById', $source, $label);
            self::assertDoesNotMatchRegularExpression('/(?:Element|Entry|Category)::find\s*\(/', $source, $label);
        }

        self::assertStringContainsString('single search-time live lookup exception', $redirectSource);
        self::assertStringContainsString('getElementById', $redirectSource);
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            self::fail('Unable to read plugin file: ' . $path);
        }

        return $contents;
    }

    private function methodSource(string $source, string $needle): string
    {
        $start = strpos($source, $needle);

        if ($start === false) {
            self::fail('Unable to find source snippet: ' . $needle);
        }

        $next = strpos($source, "\n    /**", $start + strlen($needle));

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }
}
