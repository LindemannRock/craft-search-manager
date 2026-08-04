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
 * Focused regression coverage for audit Batch 7.
 *
 * @since 5.53.0
 */
final class AutocompleteFailureLoggingTest extends TestCase
{
    public function testAutocompleteStorageFailureLoggingOmitsTraceStrings(): void
    {
        // Phase C (#383/#384) replaced getAllTerms with the shared-core
        // buildTokenSuggestions; the batch-7 invariant (graceful degrade, no
        // trace strings in the failure log) carries over to it.
        $body = $this->methodBody($this->readPluginFile('src/services/AutocompleteService.php'), 'buildTokenSuggestions');

        self::assertStringContainsString("'exception' => get_class(\$e)", $body);
        self::assertStringNotContainsString('getTraceAsString()', $body);
        self::assertStringNotContainsString("'trace'", $body);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'private'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
