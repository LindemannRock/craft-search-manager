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

/**
 * Regression coverage for audit #221, #224, #239, and #240.
 */
final class AutocompletePresentationTest extends TestCase
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
            AutocompleteResponseHelper::results([$results], 10),
        );
    }

    public function testAutocompleteServicePreservesSuggestionSiteId(): void
    {
        $source = $this->readPluginFile('src/services/AutocompleteService.php');

        self::assertStringContainsString("'siteId' => isset(\$suggestion['siteId']) ? (int)\$suggestion['siteId'] : null", $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
