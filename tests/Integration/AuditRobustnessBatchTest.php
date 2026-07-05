<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * Source-level regressions for audit #218, #219, #220, and #223.
 */
final class AuditRobustnessBatchTest extends TestCase
{
    public function testControllerAndJobErrorBoundariesCatchThrowable(): void
    {
        $analytics = $this->readPluginFile('src/controllers/AnalyticsController.php');
        $geoLookupJob = $this->readPluginFile('src/jobs/GeoLookupJob.php');

        self::assertSame(2, substr_count($analytics, 'catch (\Throwable $e)'));
        self::assertStringNotContainsString('catch (\Exception $e)', $analytics);
        self::assertStringContainsString('catch (\Throwable $e)', $geoLookupJob);
        self::assertStringNotContainsString('catch (\Exception $e)', $geoLookupJob);
    }

    public function testUtilitiesControllerUsesStrictInArrayChecks(): void
    {
        $source = $this->readPluginFile('src/controllers/UtilitiesController.php');

        self::assertStringContainsString('in_array($type, $validTypes, true)', $source);
        self::assertStringContainsString('in_array($indexBackendType, $typesToMatch, true)', $source);
        self::assertStringNotContainsString('in_array($type, $validTypes))', $source);
        self::assertStringNotContainsString('in_array($indexBackendType, $typesToMatch))', $source);
    }

    public function testConsoleControllersHaveFileHeaders(): void
    {
        foreach ([
            'src/console/controllers/IndexController.php',
            'src/console/controllers/MaintenanceController.php',
            'src/console/controllers/SecurityController.php',
        ] as $path) {
            $source = $this->readPluginFile($path);

            self::assertStringStartsWith("<?php\n/**\n * Search Manager plugin for Craft CMS 5.x", $source);
            self::assertStringContainsString('@copyright Copyright (c) 2026 LindemannRock', $source);
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
