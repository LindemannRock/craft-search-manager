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
 * Regression coverage for audit batch 8 hardening.
 *
 * @since 5.53.0
 */
final class WidgetRequestScopeContractTest extends TestCase
{
    public function testWidgetIndexLimitConfigContractIsFailClosed(): void
    {
        $config = $this->readPluginFileContents('src/config.php');

        self::assertStringContainsString('Max 5 explicit indices per search (requests with >5 are rejected)', $config);
        self::assertStringNotContainsString('search.indexHandles arrays with >5 items are truncated', $config);
    }

    private function readPluginFileContents(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
