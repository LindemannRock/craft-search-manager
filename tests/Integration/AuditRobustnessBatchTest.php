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

    public function testAnalyticsFailureBoundariesCatchThrowable(): void
    {
        $tracking = $this->readPluginFile('src/services/analytics/AnalyticsTrackingService.php');
        $export = $this->readPluginFile('src/services/analytics/AnalyticsExportService.php');
        $breakdown = $this->readPluginFile('src/services/analytics/AnalyticsBreakdownService.php');

        self::assertSame(3, substr_count($tracking, 'catch (\Throwable $e)'));
        self::assertStringNotContainsString('catch (\Exception $e)', $tracking);
        self::assertStringContainsString('public function deleteAnalytic(int $id): bool', $export);
        self::assertStringContainsString('catch (\Throwable $e)', $export);
        self::assertStringNotContainsString('catch (\Exception $e)', $export);
        self::assertStringContainsString('public function getLocationFromIp(string $ip): ?array', $breakdown);
        self::assertStringContainsString('catch (\Throwable $e)', $breakdown);
        self::assertStringNotContainsString('catch (\Exception $e)', $breakdown);
    }

    public function testUtilitiesControllerUsesStrictInArrayChecks(): void
    {
        $source = $this->readPluginFile('src/controllers/UtilitiesController.php');

        self::assertStringContainsString('in_array($type, $validTypes, true)', $source);
        self::assertStringContainsString('in_array($indexBackendType, $typesToMatch, true)', $source);
        self::assertStringNotContainsString('in_array($type, $validTypes))', $source);
        self::assertStringNotContainsString('in_array($indexBackendType, $typesToMatch))', $source);
    }

    public function testSourceAndTestFilesHaveStandardFileHeaders(): void
    {
        foreach ($this->headerEligiblePhpFiles() as $path) {
            if ($path === 'src/config.php') {
                continue;
            }

            $source = $this->readPluginFile($path);

            self::assertMatchesRegularExpression(
                '/^<\?php\n\/\*\*\n \* Search Manager plugin for Craft CMS 5\.x\n \*\n \* @link      https:\/\/lindemannrock\.com\n \* @copyright Copyright \(c\) \d{4}(?:-\d{4})? LindemannRock\n \*\/\n\n/',
                $source,
                $path . ' must start with the standard Search Manager file header.',
            );
            self::assertFalse(
                str_starts_with($source, "<?php\n/**\n * LindemannRock Search Manager\n"),
                $path . ' must not use the old LindemannRock Search Manager file header.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function headerEligiblePhpFiles(): array
    {
        return array_merge(
            $this->phpFilesInDirectory('src'),
            $this->phpFilesInDirectory('tests'),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesInDirectory(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);

            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($files);

        return $files;
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
