<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\helpers\AutocompleteResponseHelper;
use lindemannrock\searchmanager\tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression coverage for audit #221, #224, #239, and #240.
 */
final class PendingQueueLifecycleTest extends TestCase
{
    public function testRemovedIndexBatchJobHasNoInternalReferences(): void
    {
        self::assertFileDoesNotExist($this->pluginPath('src/jobs/IndexBatchJob.php'));

        foreach ($this->pluginFilesToScanForDeadJob() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringNotContainsString(
                'IndexBatchJob',
                $source,
                str_replace($this->pluginPath('') . '/', '', $path) . ' should not reference the removed queue job.',
            );
        }
    }

    public function testPendingSyncSchedulingRoutesThroughBatchSyncJob(): void
    {
        $source = $this->readPluginFile('src/services/sync/PendingSyncRepository.php');
        $normalBody = $this->methodBody($source, 'scheduleBatchJob');
        $eligibilityBody = $this->methodBody($source, 'scheduleNextEligibleBatchJob');
        $pushBody = $this->methodBody($source, 'scheduleBatchJobAt');

        self::assertStringContainsString('use lindemannrock\\searchmanager\\jobs\\BatchSyncJob;', $source);
        self::assertStringContainsString('scheduleBatchJobAt(', $normalBody);
        self::assertStringContainsString('scheduleBatchJobAt(', $eligibilityBody);
        self::assertStringContainsString('push(new BatchSyncJob())', $pushBody);
        self::assertStringNotContainsString('IndexBatchJob', $normalBody . $eligibilityBody . $pushBody);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/(?:public|private) function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }

    /**
     * @return string[]
     */
    private function pluginFilesToScanForDeadJob(): array
    {
        $files = [];
        $roots = [
            'src',
            'docs',
            'tests',
        ];

        foreach ($roots as $root) {
            $path = $this->pluginPath($root);
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $pathname = $file->getPathname();
                if ($pathname === __FILE__) {
                    continue;
                }

                $files[] = $pathname;
            }
        }

        foreach (['README.md', 'composer.json'] as $relativePath) {
            $path = $this->pluginPath($relativePath);
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function pluginPath(string $path): string
    {
        return rtrim(dirname(__DIR__, 2) . '/' . $path, '/');
    }
}
