<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(AnalyticsController::class)]
final class AnalyticsControllerRequestGateTest extends TestCase
{
    public function testAjaxJsonEndpointsRequireAcceptsJsonBeforePermission(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/controllers/AnalyticsController.php';
        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        foreach ([
            'actionGetData',
            'actionDelete',
            'actionClearAll',
        ] as $method) {
            preg_match(
                '/public function ' . preg_quote($method, '/') . '\(\): Response\s+\{(?<body>.*?)(?:\n    \}|\n    public function )/s',
                $source,
                $matches,
            );
            $body = $matches['body'] ?? '';
            $this->assertNotSame('', $body, $method . ' body should be captured.');

            $acceptsJsonPos = strpos($body, '$this->requireAcceptsJson();');
            $permissionPos = strpos($body, '$this->requirePermission(');

            $this->assertIsInt($acceptsJsonPos, $method . ' must call requireAcceptsJson().');
            $this->assertIsInt($permissionPos, $method . ' must still call requirePermission().');
            $this->assertLessThan($permissionPos, $acceptsJsonPos, $method . ' must gate Accepts JSON before permission.');
        }
    }

    public function testOnlyLiveAnalyticsWiringRemains(): void
    {
        $controllerSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/AnalyticsController.php');
        $this->assertIsString($controllerSource);
        self::assertStringNotContainsString('actionGetChartData', $controllerSource);
        self::assertStringNotContainsString('actionGetRuleAnalytics', $controllerSource);
        self::assertStringNotContainsString('actionGetPromotionAnalytics', $controllerSource);
        self::assertStringNotContainsString('analytics-content', $controllerSource);

        $analyticsIndex = file_get_contents(dirname(__DIR__, 2) . '/src/templates/analytics/index.twig');
        $this->assertIsString($analyticsIndex);
        self::assertStringContainsString('search-manager/analytics/get-data', $analyticsIndex);
        self::assertStringNotContainsString('search-manager/analytics/get-chart-data', $analyticsIndex);

        foreach ([
            'query-rules/edit.twig' => 'search-manager/query-rules/_partials/analytics',
            'promotions/edit.twig' => 'search-manager/promotions/_partials/analytics',
        ] as $template => $partial) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/' . $template);
            $this->assertIsString($source);
            self::assertStringContainsString($partial, $source);
            self::assertStringNotContainsString('analytics-content', $source);
        }
    }
}
