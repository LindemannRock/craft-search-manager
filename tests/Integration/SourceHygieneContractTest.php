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
 * Source-structure contracts for conclusively removed assignments.
 *
 * @since 5.54.0
 */
final class SourceHygieneContractTest extends TestCase
{
    public function testConclusiveAssignmentsStayRemoved(): void
    {
        $pendingSyncs = $this->readPluginFile('src/templates/pending-syncs/index.twig');
        self::assertStringNotContainsString('{% set claimedAtTs =', $pendingSyncs);

        $backends = $this->readPluginFile('src/templates/backends/index.twig');
        self::assertStringNotContainsString('{% set isConfig =', $backends);

        $widgets = $this->readPluginFile('src/templates/widgets/index.twig');
        self::assertStringNotContainsString('{% set isConfig =', $widgets);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
