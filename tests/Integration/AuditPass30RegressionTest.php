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
        self::assertSame([42, 99], $results);
    }

    public function testApplyPromotionsResolvesSiteBeforeFetchingPromotedElementMetadata(): void
    {
        $source = $this->methodSource(
            $this->readPluginFile('src/services/PromotionService.php'),
            'public function applyPromotions',
        );

        self::assertStringContainsString('$siteIdsByElementId = $resultsAreArrays', $source);
        self::assertStringContainsString('$promotionSiteId = $this->resolvePromotionSiteId($promotion, $siteId, $siteIdsByElementId);', $source);
        self::assertStringContainsString('->siteId((int)$elementSiteId)', $source);
        self::assertStringNotContainsString('->siteId($siteId)', $source);
        self::assertStringNotContainsString('$elements += $found;', $source);
    }

    public function testPromotedEntryMetadataUsesEntryTypeAndSeparateSectionLabel(): void
    {
        $source = $this->readPluginFile('src/services/PromotionService.php');

        self::assertStringContainsString("\$documentType = \$this->resolveElementType(\$element);", $source);
        self::assertStringContainsString("'type' => \$documentType,", $source);
        self::assertStringContainsString("'elementType' => \$documentType,", $source);
        self::assertStringContainsString("'section' => \$this->resolveElementSection(\$element),", $source);
        self::assertStringContainsString('], $this->resolveEntryMetadata($element), $this->resolveCommerceMetadata($element));', $source);
        self::assertStringContainsString("return 'entry';", $source);
        self::assertStringContainsString('return $element->getSection()?->name;', $source);
        self::assertStringContainsString("'sectionHandle' => \$section?->handle,", $source);
        self::assertStringContainsString("'sectionType' => \$section?->type,", $source);
        self::assertStringNotContainsString('return $element->getSection()?->handle;', $source);
    }

    public function testPromotedCommerceMetadataUsesSearchDocumentTypeAndProductType(): void
    {
        $source = $this->readPluginFile('src/services/PromotionService.php');

        self::assertStringContainsString('is_a($element, CommerceElementTypeHelper::productElementType())', $source);
        self::assertStringContainsString("return 'product';", $source);
        self::assertStringContainsString('is_a($element, CommerceElementTypeHelper::variantElementType())', $source);
        self::assertStringContainsString("return 'variant';", $source);
        self::assertStringContainsString("'productType' => \$productTypeName,", $source);
        self::assertStringContainsString("'productTypeName' => \$productTypeName,", $source);
        self::assertStringContainsString("'productTypeHandle' => \$productTypeHandle,", $source);
        self::assertStringContainsString("'section' => \$productTypeName,", $source);
    }

    public function testPromotedHitsCarryInternalElementTypeAndPresenterSuppressesIt(): void
    {
        $promotionSource = $this->methodSource(
            $this->readPluginFile('src/services/PromotionService.php'),
            'public function applyPromotions',
        );
        $enrichmentSource = $this->readPluginFile('src/services/EnrichmentService.php');
        $presenterSource = $this->readPluginFile('src/helpers/SearchHitPresenter.php');

        self::assertStringContainsString("'_elementType' => \$elementType,", $promotionSource);
        self::assertStringContainsString('$explicitElementClass = is_string($hit[\'_elementType\'] ?? null) ? $hit[\'_elementType\'] : null;', $enrichmentSource);
        self::assertStringContainsString('$elementClass = $explicitElementClass ?:', $enrichmentSource);
        self::assertStringContainsString('unset($hit[\'_elementType\']);', $presenterSource);
    }

    public function testGlobalPromotionsInsertOneDeterministicPromotedHit(): void
    {
        $source = $this->methodSource(
            $this->readPluginFile('src/services/PromotionService.php'),
            'private function resolvePromotionSiteId',
        );

        self::assertStringContainsString('if ($promotion->siteId !== null)', $source);
        self::assertStringContainsString('if ($requestedSiteId !== null)', $source);
        self::assertStringContainsString('return Craft::$app->getSites()->getPrimarySite()->id ?? null;', $source);
        self::assertStringNotContainsString('getAllSites()', $source);
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
