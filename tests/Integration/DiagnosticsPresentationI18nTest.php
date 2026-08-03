<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins audit Pass 35 fixes #193-#195.
 */
final class DiagnosticsPresentationI18nTest extends TestCase
{
    public function testDiagnosticsUnknownFallbackIsTranslatedForInlineJs(): void
    {
        $source = $this->readPluginFile('src/templates/backends/_partials/diagnostics.twig');

        self::assertStringContainsString('const backendId = {{ (backend.id ?: backend.handle)|json_encode|raw }};', $source);
        self::assertStringNotContainsString("const backendId = '{{ backend.id ?: backend.handle }}';", $source);
        self::assertStringContainsString("unknown: {{ 'Unknown'|t('search-manager')|json_encode|raw }},", $source);
        self::assertStringContainsString('const name = index.name || index.uid || labels.unknown;', $source);
        self::assertStringNotContainsString("index.name || index.uid || 'Unknown'", $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
