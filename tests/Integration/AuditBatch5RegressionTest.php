<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class AuditBatch5RegressionTest extends TestCase
{
    public function testModelFromRowDatesAreParsedAsUtc(): void
    {
        foreach ([
            'src/models/SearchIndex.php',
            'src/models/ConfiguredBackend.php',
            'src/models/QueryRule.php',
            'src/models/Promotion.php',
        ] as $file) {
            $source = $this->readPluginSource($file);

            self::assertStringContainsString("new \\DateTimeZone('UTC')", $source, $file . ' should parse row dates as UTC.');
            self::assertStringNotContainsString("new \\DateTime(\$row['dateCreated'])", $source, $file);
            self::assertStringNotContainsString("new \\DateTime(\$row['dateUpdated'])", $source, $file);
        }
    }

    public function testPromotionElementIdZeroIsInvalid(): void
    {
        $promotion = new Promotion();
        $promotion->elementId = 0;

        $promotion->validateElement('elementId');

        self::assertTrue($promotion->hasErrors('elementId'));
    }

    public function testAnalyticsRefererInstallColumnAllowsLongUrls(): void
    {
        $source = $this->readPluginSource('src/migrations/Install.php');

        self::assertStringContainsString("'referer' => \$this->string(2048)->null(),", $source);
    }

    public function testTransformerTableHasNoRuntimeWritePathYet(): void
    {
        foreach ($this->runtimePhpSources() as $file => $source) {
            self::assertStringNotContainsString('searchmanager_transformers', $source, $file);
        }
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @return iterable<string, string>
     */
    private function runtimePhpSources(): iterable
    {
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src');
        $iterator = new \RecursiveIteratorIterator($directory);

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/migrations/')) {
                continue;
            }

            $source = file_get_contents($path);
            self::assertIsString($source);

            yield $path => $source;
        }
    }
}
