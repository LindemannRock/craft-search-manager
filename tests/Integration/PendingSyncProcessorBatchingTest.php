<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\services\sync\PendingSyncProcessor;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins pending-sync element preloading for audit #153.
 */
#[CoversClass(PendingSyncProcessor::class)]
final class PendingSyncProcessorBatchingTest extends TestCase
{
    public function testProcessIndexRowsUsesBatchedElementPreloadForUpserts(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/sync/PendingSyncProcessor.php');
        $this->assertIsString($source);

        $processBody = $this->methodBody($source, 'processIndexRows');
        self::assertStringContainsString('$elementsByKey = $elementTypeAvailable ? $this->preloadElements($index, $rows) : [];', $processBody);
        self::assertStringContainsString('$elementsByKey[$this->elementCacheKey($siteId, $elementId)] ?? null', $processBody);
        self::assertStringNotContainsString('loadElement(', $processBody);
        self::assertStringNotContainsString('->one()', $processBody);

        $preloadBody = $this->methodBody($source, 'preloadElements');
        self::assertStringContainsString('->id(array_keys($idSet))', $preloadBody);
        self::assertStringContainsString('->siteId((int)$siteId)', $preloadBody);
        self::assertStringContainsString('->status(null)', $preloadBody);
        self::assertStringContainsString('foreach ($query->all() as $element)', $preloadBody);
        self::assertStringNotContainsString('->one()', $preloadBody);
    }

    public function testSuccessfulUpsertAndDeletePathsClearSearchCachesOncePerIndex(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/sync/PendingSyncProcessor.php');
        $this->assertIsString($source);

        $processBody = $this->methodBody($source, 'processIndexRows');

        self::assertStringContainsString('$synced && SearchManager::$plugin->getSettings()->clearCacheOnSave', $processBody);
        self::assertSame(1, substr_count($processBody, 'SearchManager::$plugin->backend->clearSearchCache($indexHandle);'));
        self::assertSame(1, substr_count($processBody, 'SearchManager::$plugin->autocomplete->clearCache($indexHandle);'));
        self::assertGreaterThan(
            strpos($processBody, 'SearchManager::$plugin->backend->batchDelete($indexHandle, $deleteItems)'),
            strpos($processBody, 'SearchManager::$plugin->backend->clearSearchCache($indexHandle);'),
            'cache clear must happen after both batchIndex and batchDelete branches finish.'
        );
    }

    public function testSplitSyncUpsertsBeforeDeletingOrphanSectionDocuments(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/sync/PendingSyncProcessor.php');
        $this->assertIsString($source);

        $processBody = $this->methodBody($source, 'processIndexRows');
        self::assertStringContainsString('$docParents = [];', $processBody);
        self::assertStringContainsString('SearchManager::$plugin->backend->batchIndex($indexHandle, $docs)', $processBody);
        self::assertStringContainsString('SearchManager::$plugin->backend->deleteOrphanDocuments(', $processBody);
        self::assertLessThan(
            strpos($processBody, 'SearchManager::$plugin->backend->deleteOrphanDocuments('),
            strpos($processBody, 'SearchManager::$plugin->backend->batchIndex($indexHandle, $docs)'),
            'pending sync must upsert section documents before deleting stale section IDs.',
        );

        $deleteBody = $this->methodBody($source, 'deleteSplitParents');
        self::assertStringContainsString('deleteOrphanDocuments(', $deleteBody);
        self::assertStringContainsString('$indexHandle,', $deleteBody);
        self::assertStringContainsString('[]', $deleteBody);
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/private function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
