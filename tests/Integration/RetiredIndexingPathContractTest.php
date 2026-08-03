<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\Stubs\StubBackend;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Guards audit Batch 6 performance fixes.
 *
 * @since 5.53.0
 */
final class RetiredIndexingPathContractTest extends TestCase
{
    public function testIndexingServiceHasNoLegacyRemoveElementPath(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');

        self::assertStringNotContainsString('public function removeElement(', $source);
        self::assertStringNotContainsString('Remove an element from all matching indices', $source);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }
}
