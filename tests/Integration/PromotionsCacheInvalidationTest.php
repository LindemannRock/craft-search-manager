<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.47.0
 */
#[CoversClass(PromotionsController::class)]
#[CoversClass(PromotionService::class)]
class PromotionsCacheInvalidationTest extends TestCase
{
    public function testPromotionMutationsClearAllSearchCaches(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/PromotionsController.php');
        $service = file_get_contents(dirname(__DIR__, 2) . '/src/services/PromotionService.php');

        $this->assertIsString($controller);
        $this->assertIsString($service);
        $this->assertStringNotContainsString('clearSearchCache(', $controller);
        $this->assertStringNotContainsString('clearAllSearchCache()', $controller);
        $this->assertSame(2, substr_count($service, 'clearAllSearchCache()'));
    }
}
