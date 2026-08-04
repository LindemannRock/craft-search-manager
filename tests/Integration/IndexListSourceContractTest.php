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

/**
 * Regression coverage for audit findings #429 and #431-#437.
 *
 * @since 5.54.0
 */
final class IndexListSourceContractTest extends TestCase
{
    public function testIndicesListingReadsExpectedCountOncePerRow(): void
    {
        $template = $this->readPluginFile('src/templates/indices/index.twig');

        self::assertSame(1, substr_count($template, 'item.expectedCount'));
        self::assertStringContainsString('{% set expected = item.expectedCount %}', $template);
        self::assertLessThan(
            strpos($template, '{# Craft (expected count) #}'),
            strpos($template, '{% set expected = item.expectedCount %}'),
        );
    }

    private function readPluginFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
