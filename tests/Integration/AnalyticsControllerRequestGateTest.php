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
            'actionGetChartData',
            'actionGetRuleAnalytics',
            'actionGetPromotionAnalytics',
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
}
