<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\AnalyticsController;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Focused regressions for audit #250 and #251.
 *
 * @since 5.53.0
 */
#[CoversClass(AnalyticsController::class)]
final class ControlPanelRenderingSecurityTest extends TestCase
{
    public function testBackendIndexEntriesAreFormattedBeforeInnerHtmlInsertion(): void
    {
        foreach ([
            'src/templates/settings/test/_partials/backend.twig' => 'idx.entries',
            'src/templates/backends/_partials/diagnostics.twig' => 'index.entries',
        ] as $path => $entriesExpression) {
            $source = $this->readPluginFile($path);

            self::assertStringContainsString('function formatEntries(entries)', $source);
            self::assertStringContainsString("return '—';", $source);
            self::assertStringContainsString('return Number(entries).toLocaleString();', $source);
            self::assertStringContainsString('return Craft.escapeHtml(String(entries));', $source);
            self::assertStringContainsString('const entries = formatEntries(' . $entriesExpression . ');', $source);
            self::assertStringNotContainsString($entriesExpression . '.toLocaleString()', $source);
        }
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        if ($contents === false) {
            self::fail('Unable to read plugin file: ' . $path);
        }

        return $contents;
    }
}
