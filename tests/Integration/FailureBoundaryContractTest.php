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
final class FailureBoundaryContractTest extends TestCase
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

    public function testAnalyticsFailureBoundariesCatchThrowable(): void
    {
        $tracking = $this->readPluginFile('src/services/analytics/AnalyticsTrackingService.php');
        $export = $this->readPluginFile('src/services/analytics/AnalyticsExportService.php');
        $breakdown = $this->readPluginFile('src/services/analytics/AnalyticsBreakdownService.php');

        self::assertSame(3, substr_count($tracking, 'catch (\Throwable $e)'));
        self::assertStringNotContainsString('catch (\Exception $e)', $tracking);
        self::assertStringContainsString(
            'public function deleteAnalytic(int $id, int|array|null $siteId = null): bool',
            $export,
        );
        self::assertStringContainsString('catch (\Throwable $e)', $export);
        self::assertStringNotContainsString('catch (\Exception $e)', $export);
        self::assertStringContainsString('public function getLocationFromIp(string $ip): ?array', $breakdown);
        self::assertStringContainsString('catch (\Throwable $e)', $breakdown);
        self::assertStringNotContainsString('catch (\Exception $e)', $breakdown);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
