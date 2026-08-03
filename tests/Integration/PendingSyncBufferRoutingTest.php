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
final class PendingSyncBufferRoutingTest extends TestCase
{
    public function testIndexElementQueuePathUsesPendingSyncBuffer(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElement', 'public');

        self::assertStringContainsString('use lindemannrock\\searchmanager\\services\\sync\\PendingSyncRepository;', $source);
        self::assertStringContainsString('$queued = SearchManager::$plugin->pendingSyncs->queueForElement', $body);
        self::assertStringContainsString('->queueForElement($element, PendingSyncRepository::OP_UPSERT)', $body);
        self::assertStringNotContainsString('IndexElementJob', $source);
        self::assertStringNotContainsString('Craft::$app->getQueue()->push', $body);
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
