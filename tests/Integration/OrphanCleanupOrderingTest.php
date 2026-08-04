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
final class OrphanCleanupOrderingTest extends TestCase
{
    public function testSplitIndexElementNowUpsertsBeforeDeletingOrphanSectionDocuments(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElementNow', 'public');

        self::assertStringContainsString('$usesSplitSections = $index?->usesSplitSections() === true;', $body);
        self::assertStringContainsString('if ($usesSplitSections) {', $body);
        self::assertStringContainsString('->batchIndex($indexHandle, $documents)', $body);
        self::assertStringContainsString('->deleteOrphanDocuments(', $body);
        self::assertStringContainsString('$index->refreshDocumentCount();', $body);
        self::assertStringNotContainsString('getExpectedCount()', $body);
        self::assertLessThan(
            strpos($body, '->deleteOrphanDocuments('),
            strpos($body, '->batchIndex($indexHandle, $documents)'),
            'split indexing must upsert the new section set before deleting orphan backend IDs.',
        );
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
