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
final class AuditBatch6PerformanceTest extends TestCase
{
    public function testIndexElementNowUsesResultContractsInsteadOfDocumentExistsProbes(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElementNow', 'public');

        self::assertStringContainsString('->indexWithResult($indexHandle, $data)', $body);
        self::assertStringContainsString('->deleteWithResult($index->handle, $element->id, $siteId)', $body);
        self::assertStringNotContainsString('->documentExists(', $body);
    }

    public function testSplitIndexElementNowUpsertsBeforeDeletingOrphanSectionDocuments(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'indexElementNow', 'public');

        self::assertStringContainsString('if ($index?->usesSplitSections()) {', $body);
        self::assertStringContainsString('->batchIndex($indexHandle, $documents)', $body);
        self::assertStringContainsString('->deleteOrphanDocuments(', $body);
        self::assertLessThan(
            strpos($body, '->deleteOrphanDocuments('),
            strpos($body, '->batchIndex($indexHandle, $documents)'),
            'split indexing must upsert the new section set before deleting orphan backend IDs.',
        );
    }

    public function testRemoveElementUsesDeleteResultContractInsteadOfDocumentExistsProbe(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'removeElement', 'public');

        self::assertStringContainsString('->deleteWithResult($index->handle, $element->id, $siteId)', $body);
        self::assertStringContainsString('->deleteOrphanDocuments($index->handle, (int)$element->id, $siteId, [])', $body);
        self::assertStringNotContainsString('->documentExists(', $body);
        self::assertStringNotContainsString('->delete($index->handle, $element->id, $siteId)', $body);
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

    public function testRemoveElementOnlyDecrementsDocumentCountWhenDeleteResultConfirmsExistingDocument(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'removeElement', 'public');

        self::assertStringContainsString('if ($deleteResult[\'existed\'] !== true) {', $body);
        self::assertStringContainsString('SearchIndex::decrementDocumentCount($index->handle);', $body);
        self::assertLessThan(
            strpos($body, 'SearchIndex::decrementDocumentCount($index->handle);'),
            strpos($body, 'if ($deleteResult[\'existed\'] !== true) {'),
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

    public function testTokenizeQueryTermsCachesSearchIndexLookupByHandle(): void
    {
        $source = $this->readPluginSource('src/services/IndexedSnippetService.php');
        $body = $this->methodBody($source, 'tokenizeQueryTerms');

        self::assertStringContainsString('private array $tokenizeIndexLookupCache = []', $source);
        self::assertStringContainsString('array_key_exists($indexHandle, $this->tokenizeIndexLookupCache)', $body);
        self::assertStringContainsString('$this->tokenizeIndexLookupCache[$indexHandle] = SearchIndex::findByHandle($indexHandle);', $body);
        self::assertSame(1, substr_count($body, 'SearchIndex::findByHandle('));
    }

    public function testBatchIndexWrapsTransformCallsInBatchTransformerReuse(): void
    {
        $source = $this->readPluginSource('src/services/IndexingService.php');
        $body = $this->methodBody($source, 'batchIndex', 'public');

        self::assertStringContainsString('->withTransformerReuse(function()', $body);
        self::assertStringContainsString('->transform(', $body);
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
