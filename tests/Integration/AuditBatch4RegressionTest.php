<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class AuditBatch4RegressionTest extends TestCase
{
    public function testApiKeysConsoleUnknownIndexHandlesUsesFindAllOnce(): void
    {
        $source = $this->readPluginSource('src/console/controllers/ApiKeysController.php');
        $body = $this->methodBody($source, 'unknownIndexHandles');

        self::assertStringContainsString('SearchIndex::findAll()', $body);
        self::assertStringNotContainsString('SearchIndex::findByHandle(', $body);
    }

    public function testBatchSyncCountRefreshUsesFindAllOnce(): void
    {
        $source = $this->readPluginSource('src/jobs/BatchSyncJob.php');
        $body = $this->methodBody($source, 'refreshSyncedIndexCounts');

        self::assertStringContainsString('SearchIndex::findAll()', $body);
        self::assertStringNotContainsString('SearchIndex::findByHandle(', $body);
    }

    public function testRebuildAllIndicesPassesPreloadedIndexIntoSingleRebuild(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $singleBody = $this->methodBody($source, 'rebuildSingleIndex');
        $allBody = $this->methodBody($source, 'rebuildAllIndices');

        self::assertStringContainsString('?SearchIndex $preloadedIndex = null', $source);
        self::assertStringContainsString('$index = $preloadedIndex ?? SearchIndex::findByHandle($indexHandle);', $singleBody);
        self::assertStringContainsString('$this->rebuildSingleIndex($queue, $index->handle, $index);', $allBody);
    }

    public function testExpectedCountSkipUrlPathDoesNotLoadAllElements(): void
    {
        $source = $this->readPluginSource('src/models/SearchIndex.php');
        $body = $this->methodBody($source, 'getExpectedCount', 'public');

        self::assertStringContainsString('Expected count result (skip URL non-entry)', $body);
        self::assertStringContainsString("->andWhere(['not', ['elements_sites.uri' => null]])", $body);
        self::assertStringContainsString("->andWhere(['<>', 'elements_sites.uri', ''])", $body);
        self::assertStringNotContainsString('foreach ($query->all() as $element)', $body);
    }

    public function testEntryTransformerOnlyFetchesFeaturedImageOnce(): void
    {
        $source = $this->readPluginSource('src/transformers/EntryTransformer.php');
        $body = $this->methodBody($source, 'transform', 'public');

        self::assertSame(1, substr_count($body, '->one()'));
        self::assertStringContainsString('$image = $featuredImage?->one();', $body);
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
