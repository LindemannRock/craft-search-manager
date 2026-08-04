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
 * Guards audit Batch 6 performance fixes.
 *
 * @since 5.53.0
 */
final class SearchIndexLookupReuseTest extends TestCase
{
    public function testTokenizeQueryTermsCachesSearchIndexLookupByHandle(): void
    {
        $source = $this->readPluginSource('src/services/IndexedSnippetService.php');
        $body = $this->methodBody($source, 'tokenizeQueryTerms');

        self::assertStringContainsString('private array $tokenizeIndexLookupCache = []', $source);
        self::assertStringContainsString('array_key_exists($indexHandle, $this->tokenizeIndexLookupCache)', $body);
        self::assertStringContainsString('$this->tokenizeIndexLookupCache[$indexHandle] = SearchIndex::findByHandle($indexHandle);', $body);
        self::assertSame(1, substr_count($body, 'SearchIndex::findByHandle('));
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
