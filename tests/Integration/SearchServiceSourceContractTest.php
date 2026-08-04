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
 * Focused regression coverage for audit Batch 7.
 *
 * @since 5.53.0
 */
final class SearchServiceSourceContractTest extends TestCase
{
    public function testSearchWithParsedQueryDoesNotAccumulateUnusedDocIds(): void
    {
        $body = $this->methodBody($this->readPluginFile('src/search/SearchEngine.php'), 'searchWithParsedQuery', 'public');

        self::assertStringNotContainsString('$allDocIds', $body);
        self::assertStringNotContainsString('array_unique(array_merge', $body);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
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
