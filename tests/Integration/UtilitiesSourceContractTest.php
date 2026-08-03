<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source-level regressions for audit #218, #219, #220, and #223.
 */
final class UtilitiesSourceContractTest extends TestCase
{
    public function testUtilitiesControllerUsesStrictInArrayChecks(): void
    {
        $controller = $this->readPluginFile('src/controllers/UtilitiesController.php');
        $service = $this->readPluginFile('src/services/StorageMaintenanceService.php');

        self::assertStringContainsString('in_array($type, $validTypes, true)', $controller);
        self::assertStringContainsString('in_array($backendType, $types, true)', $service);
        self::assertStringNotContainsString('in_array($type, $validTypes))', $controller);
        self::assertStringNotContainsString('in_array($backendType, $types))', $service);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
