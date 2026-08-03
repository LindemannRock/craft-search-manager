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
final class IndexingResultContractTest extends TestCase
{
    public function testIndexElementNowUsesResultContractsInsteadOfDocumentExistsProbes(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElementNow', 'public');

        self::assertStringContainsString('->indexWithResult($indexHandle, $data)', $body);
        self::assertStringContainsString('->deleteWithResult($index->handle, $element->id, $siteId)', $body);
        self::assertStringNotContainsString('->documentExists(', $body);
    }

    public function testCleanupOnlyDecrementsDocumentCountWhenDeleteResultConfirmsExistingDocument(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElementNow', 'public');

        self::assertStringContainsString('if ($deleteResult[\'existed\'] === true) {', $body);
        self::assertStringContainsString('SearchIndex::decrementDocumentCount($index->handle);', $body);
        self::assertLessThan(
            strpos($body, 'SearchIndex::decrementDocumentCount($index->handle);'),
            strpos($body, 'if ($deleteResult[\'existed\'] === true) {'),
        );
    }

    public function testStubBackendDeleteWithResultReportsExistenceAndDeletesIdempotently(): void
    {
        $backend = new StubBackend();
        $backend->existingDocuments['docs:42:1'] = true;

        $deleted = $backend->deleteWithResult('docs', 42, 1);
        self::assertSame(['success' => true, 'existed' => true], $deleted);
        self::assertFalse($backend->documentExists('docs', 42, 1));

        $missing = $backend->deleteWithResult('docs', 42, 1);
        self::assertSame(['success' => true, 'existed' => false], $missing);
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
