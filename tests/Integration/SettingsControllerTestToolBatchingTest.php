<?php
/**
 * LindemannRock Search Manager
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
    }

    public function testQueryRuleTestToolDoesNotLoadRedirectElementsOneByOne(): void
    {
        $body = $this->controllerMethodBody('actionTestQueryRules');

        self::assertStringNotContainsString('getElementById(', $body);
        self::assertStringContainsString('preloadTestQueryRuleRedirectElements($matchingRules)', $body);
        self::assertStringContainsString('$redirectElements[$this->elementCacheKey(', $body);
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
}
