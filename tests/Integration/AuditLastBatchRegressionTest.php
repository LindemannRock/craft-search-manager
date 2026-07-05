<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression coverage for audit #221, #224, #239, and #240.
 */
final class AuditLastBatchRegressionTest extends TestCase
{
    public function testAutocompleteResultDedupKeepsFirstResultPerSiteElementAndType(): void
    {
        $results = [
            ['siteId' => 1, 'id' => 10, 'type' => 'entry', 'text' => 'First'],
            ['siteId' => 1, 'id' => 10, 'type' => 'entry', 'text' => 'Duplicate'],
            ['siteId' => 2, 'id' => 10, 'type' => 'entry', 'text' => 'Other Site'],
            ['siteId' => 1, 'id' => 10, 'type' => 'asset', 'text' => 'Other Type'],
        ];

        self::assertSame(
            [$results[0], $results[2], $results[3]],
            $this->callApiDedupe($results),
        );
        self::assertSame(
            [$results[0], $results[2], $results[3]],
            $this->callGqlDedupe($results),
        );
    }

    public function testAutocompleteServicePreservesSuggestionSiteId(): void
    {
        $source = $this->readPluginFile('src/services/AutocompleteService.php');

        self::assertStringContainsString("'siteId' => isset(\$suggestion['siteId']) ? (int)\$suggestion['siteId'] : null", $source);
    }

    public function testAutoTransformerDoesNotSingularizeUsIsOrOsWords(): void
    {
        $transformer = new AutoTransformer();
        $method = new \ReflectionMethod($transformer, 'singularize');
        $method->setAccessible(true);

        self::assertSame('news', $method->invoke($transformer, 'news'));
        self::assertSame('status', $method->invoke($transformer, 'status'));
        self::assertSame('analysis', $method->invoke($transformer, 'analysis'));
        self::assertSame('logos', $method->invoke($transformer, 'logos'));
        self::assertSame('product', $method->invoke($transformer, 'products'));
    }

    public function testAutoTransformerUsesSubclassAwareFieldTypeChecks(): void
    {
        $source = $this->readPluginFile('src/transformers/AutoTransformer.php');

        self::assertStringContainsString('is_a($field, \'craft\\ckeditor\\Field\')', $source);
        self::assertStringContainsString('is_a($field, \'craft\\redactor\\Field\')', $source);
        self::assertStringNotContainsString('$fieldClass === \'craft\\ckeditor\\Field\'', $source);
        self::assertStringNotContainsString('$fieldClass === \'craft\\redactor\\Field\'', $source);
    }

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
        $body = $this->methodBody($source, 'scheduleBatchJob');

        self::assertStringContainsString('use lindemannrock\\searchmanager\\jobs\\BatchSyncJob;', $source);
        self::assertStringContainsString('push(new BatchSyncJob())', $body);
        self::assertStringNotContainsString('IndexBatchJob', $body);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function callApiDedupe(array $results): array
    {
        $controller = (new \ReflectionClass(ApiController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ApiController::class, 'dedupeAutocompleteResults');
        $method->setAccessible(true);

        return $method->invoke($controller, $results);
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function callGqlDedupe(array $results): array
    {
        $method = new \ReflectionMethod(SearchResolver::class, 'dedupeAutocompleteResults');
        $method->setAccessible(true);

        return $method->invoke(null, $results);
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
            '/public function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
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
