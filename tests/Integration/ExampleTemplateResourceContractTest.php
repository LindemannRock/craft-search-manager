<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\Support\TestProjectBoundary;
use PHPUnit\Framework\TestCase;

/**
 * Pins the packaged example templates and their public documentation contract.
 *
 * @since 5.54.0
 */
final class ExampleTemplateResourceContractTest extends TestCase
{
    /** @var list<string> */
    private const TEMPLATE_FILES = [
        'search-manager-search-playground.twig',
        'search-manager-native-comparison.twig',
        'search-manager-widget-playground.twig',
    ];

    /** @var list<string> */
    private const LEGACY_TEMPLATE_FILES = [
        'test-search.twig',
        'test-search-datastar.twig',
        'test-search-widget.twig',
    ];

    public function testPackagedTemplatesMirrorTheProjectDevelopmentCopies(): void
    {
        $packageDirectory = $this->packageRoot() . '/resources/example-templates';
        $projectDirectory = TestProjectBoundary::resolve()->templatesRoot;

        foreach (self::TEMPLATE_FILES as $filename) {
            $packaged = $packageDirectory . '/' . $filename;
            $project = $projectDirectory . '/' . $filename;

            self::assertFileExists($packaged);
            self::assertFileExists($project);
            self::assertSame(file_get_contents($project), file_get_contents($packaged), $filename);
        }

        foreach (self::LEGACY_TEMPLATE_FILES as $filename) {
            self::assertFileDoesNotExist($projectDirectory . '/' . $filename);
            self::assertFileDoesNotExist($packageDirectory . '/' . $filename);
        }
    }

    public function testTemplatesAndDocumentationPreserveDistinctTwigAndWidgetScopeContracts(): void
    {
        $sources = [];
        foreach (self::TEMPLATE_FILES as $filename) {
            $sources[$filename] = $this->resource($filename);
            self::assertStringContainsString(
                'craft.searchManager.getAvailableIndices()',
                $sources[$filename],
                $filename,
            );
            self::assertStringContainsString('noindex,nofollow', $sources[$filename], $filename);
        }

        self::assertStringContainsString(
            "datastar.get('search-manager-search-playground.twig')",
            $sources['search-manager-search-playground.twig'],
        );
        self::assertStringContainsString(
            "datastar.get('search-manager-native-comparison.twig')",
            $sources['search-manager-native-comparison.twig'],
        );
        self::assertStringContainsString(
            '? craft.searchManager.searchMultiple(indexHandles, requestQuery, searchOptions)',
            $sources['search-manager-search-playground.twig'],
        );
        self::assertStringContainsString(
            "widgetScope == '__all' ? [] : (widgetScope ? [widgetScope] : [])",
            $sources['search-manager-widget-playground.twig'],
        );
        self::assertStringNotContainsString(
            "widgetScope == '__all' ? indexHandles",
            $sources['search-manager-widget-playground.twig'],
        );
        self::assertStringContainsString(
            "include 'search-manager/_widget/search-modal'",
            $sources['search-manager-widget-playground.twig'],
        );
        self::assertStringContainsString(
            ".site('*').search(query)",
            $sources['search-manager-native-comparison.twig'],
        );

        $combined = implode("\n", $sources);
        foreach (self::LEGACY_TEMPLATE_FILES as $filename) {
            self::assertStringNotContainsString($filename, $combined, $filename);
        }

        $twigScopeContract = 'In **All indices** mode, the page passes the current ordered available-index handle list to `craft.searchManager.searchMultiple()`. This is an internal Twig call, so public API-key scope does not apply.';
        foreach ([
            $this->packageRoot() . '/docs/resources/example-templates.md',
            $this->packageRoot() . '/resources/example-templates/README.md',
        ] as $path) {
            $documentation = file_get_contents($path);
            self::assertIsString($documentation);
            self::assertStringContainsString($twigScopeContract, $documentation, $path);
        }

        $packagedReadme = file_get_contents($this->packageRoot() . '/resources/example-templates/README.md');
        self::assertIsString($packagedReadme);
        self::assertStringContainsString(
            'For **All indices**, the template intentionally passes `indexHandles: []`. The empty array preserves Search Manager\'s server-owned enabled/API-key scope',
            $packagedReadme,
        );
    }

    public function testCustomerArchiveIncludesEveryExampleTemplateResource(): void
    {
        $expected = [
            'resources/example-templates/README.md',
            ...array_map(
                static fn(string $filename): string => 'resources/example-templates/' . $filename,
                self::TEMPLATE_FILES,
            ),
        ];

        foreach ($expected as $path) {
            self::assertFileExists($this->packageRoot() . '/' . $path, $path);
        }

        $pipes = [];
        $process = proc_open(
            ['git', '-c', 'safe.directory=' . $this->packageRoot(), 'check-attr', 'export-ignore', '--', ...$expected],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->packageRoot(),
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($error) ? $error : '');
        self::assertIsString($output);

        foreach ($expected as $path) {
            self::assertStringContainsString("{$path}: export-ignore: unspecified", $output, $path);
        }
    }

    public function testPublicNavigationAndReadmeLinksResolveWithoutRemovedPlaceholders(): void
    {
        $packageRoot = $this->packageRoot();
        $docsRoot = $packageRoot . '/docs';
        $sidebarSource = file_get_contents($docsRoot . '/.sidebar.json');
        self::assertIsString($sidebarSource);
        $sidebar = json_decode($sidebarSource, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($sidebar);

        $resources = null;
        foreach ($sidebar as $section) {
            if (($section['title'] ?? null) === 'Resources') {
                $resources = $section['children'] ?? null;
                break;
            }
        }
        self::assertIsArray($resources);
        self::assertContains('resources/postman', $resources);
        self::assertContains('resources/example-templates', $resources);
        foreach ($resources as $page) {
            self::assertFileExists($docsRoot . '/' . $page . '.md', (string)$page);
        }

        $readme = file_get_contents($packageRoot . '/README.md');
        self::assertIsString($readme);
        foreach (['resources/postman/README.md', 'resources/example-templates/README.md'] as $path) {
            self::assertStringContainsString("]({$path})", $readme, $path);
            self::assertFileExists($packageRoot . '/' . $path, $path);
        }

        $removedImages = [
            'testing-tools-search-test.webp',
            'testing-tools-backend-diagnostics.webp',
        ];
        $publicSource = $readme;
        foreach ($this->markdownFiles($docsRoot) as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $publicSource .= "\n" . $source;
        }
        foreach ($removedImages as $filename) {
            self::assertFileDoesNotExist($docsRoot . '/images/' . $filename);
            self::assertStringNotContainsString($filename, $publicSource, $filename);
        }
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function resource(string $filename): string
    {
        $source = file_get_contents($this->packageRoot() . '/resources/example-templates/' . $filename);
        self::assertIsString($source);

        return $source;
    }

    /** @return list<string> */
    private function markdownFiles(string $root): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }
}
