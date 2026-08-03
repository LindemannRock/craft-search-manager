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
final class TransformerReuseTest extends TestCase
{
    public function testBatchIndexWrapsTransformCallsInBatchTransformerReuse(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'batchIndex', 'public');

        self::assertStringContainsString('->withTransformerReuse(function()', $body);
        self::assertStringContainsString('->transformWithResult(', $body);
    }

    public function testTransformerReuseCacheIsScopedByClassElementTypeAndHeadingLevels(): void
    {
        $source = $this->readPluginSource('src/services/TransformerService.php');
        $cacheKeyBody = $this->methodBody($source, 'transformerCacheKey');
        $reuseBody = $this->methodBody($source, 'withTransformerReuse', 'public');

        self::assertStringContainsString('$transformerClass', $cacheKeyBody);
        self::assertStringContainsString('get_class($element)', $cacheKeyBody);
        self::assertStringContainsString('json_encode($levels)', $cacheKeyBody);
        self::assertStringContainsString('$previousCache = $this->transformerReuseCache;', $reuseBody);
        self::assertStringContainsString('$this->transformerReuseCache = $previousCache;', $reuseBody);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
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
