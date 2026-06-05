<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.47.0
 */
#[CoversClass(PromotionsController::class)]
class PromotionsCacheInvalidationTest extends TestCase
{
    public function testPromotionMutationsClearAllSearchCaches(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/controllers/PromotionsController.php';
        $source = file_get_contents($sourceFile);

        $this->assertIsString($source);
        $this->assertStringNotContainsString('clearSearchCache(', $source);
        $this->assertSame(5, substr_count($source, 'clearAllSearchCache()'));
    }
}
