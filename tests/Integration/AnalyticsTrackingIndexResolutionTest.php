<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\services\analytics\AnalyticsTrackingService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(AnalyticsTrackingService::class)]
final class AnalyticsTrackingIndexResolutionTest extends TestCase
{
    public function testCommaIndexAnalyticsBranchUsesFindAllMap(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/services/analytics/AnalyticsTrackingService.php';
        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        preg_match('/elseif \(str_contains\(\$indexHandle, \',\'\)\) \{(?<branch>.*?)\} else \{/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $branch = $matches['branch'];
        $this->assertStringContainsString('SearchIndex::findAll()', $branch);
        $this->assertStringNotContainsString('findByHandle(', $branch);
    }
}
