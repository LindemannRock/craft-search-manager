<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.47.0
 */
#[CoversClass(SearchManager::class)]
#[CoversClass(PluginHelper::class)]
class RedisCacheSafeguardTest extends TestCase
{
    public function testDirectRedisCommandSitesUseRedisSafeguardHelper(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $sourceFiles = [
            $pluginRoot . '/src/controllers/UtilitiesController.php',
            $pluginRoot . '/src/services/AutocompleteService.php',
            $pluginRoot . '/src/services/BackendService.php',
        ];

        foreach ($sourceFiles as $sourceFile) {
            $source = file_get_contents($sourceFile);
            $this->assertIsString($source);
            $this->assertStringContainsString('executeCommand(', $source);
            $this->assertStringContainsString('PluginHelper::getRedisCacheOrLog', $source);
            $this->assertDoesNotMatchRegularExpression(
                '/instanceof\s+\\\\yii\\\\redis\\\\Cache(?:(?!PluginHelper::getRedisCacheOrLog).){0,500}executeCommand\(/s',
                $source,
                $sourceFile . ' must not guard direct Redis commands with a bare instanceof check.',
            );
        }
    }

    public function testAutocompleteCacheReadPathUsesRedisSafeguardHelper(): void
    {
        $sourceFile = dirname(__DIR__, 2) . '/src/services/AutocompleteService.php';
        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        preg_match('/private function getFromCache\(.*?private function saveToCache/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $getFromCacheSource = $matches[0];
        $this->assertStringContainsString('PluginHelper::getRedisCacheOrLog', $getFromCacheSource);
        $this->assertStringNotContainsString('instanceof \\yii\\redis\\Cache', $getFromCacheSource);
    }
}
