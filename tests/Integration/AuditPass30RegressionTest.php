<?php
/**
 * LindemannRock Search Manager
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
