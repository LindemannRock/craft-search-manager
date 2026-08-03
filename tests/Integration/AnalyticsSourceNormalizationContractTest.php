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
final class AnalyticsSourceNormalizationContractTest extends TestCase
{
    public function testSearchControllerUsesSharedTrackingSourceNormalizer(): void
    {
        $source = $this->readPluginFile('src/controllers/SearchController.php');

        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $source);
        self::assertStringContainsString("\$sourceDefault = TrackingMetadataHelper::widgetSourceDefault(\$parameters['widgetType']);", $source);
        self::assertStringNotContainsString('frontend-widget', $source);
        self::assertStringNotContainsString('substr($source, 0, 64)', $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
