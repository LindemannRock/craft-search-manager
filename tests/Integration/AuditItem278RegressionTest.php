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
 * @since 5.53.0
 */
final class AuditItem278RegressionTest extends TestCase
{
    public function testSingleIndexRebuildProgressReachesCompleteAfterWork(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $body = $this->methodBody($source, 'rebuildSingleIndex');

        self::assertStringContainsString('$batchCount = count($batches);', $body);
        self::assertStringContainsString('($batchIndex + 1) / $batchCount', $body);
        self::assertStringContainsString('$this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);', $body);
        self::assertStringNotContainsString('$siteIndex / count($sitesToIndex)', $body);
        self::assertStringNotContainsString('$batchIndex / count($batches)', $body);
    }

    public function testSingleIndexRebuildProgressHandlesNoSitesAndEmptyBatches(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $body = $this->methodBody($source, 'rebuildSingleIndex');

        self::assertStringContainsString('if (empty($sitesToIndex))', $body);
        self::assertStringContainsString('$this->setRebuildProgress($queue, 1.0, $progressStart, $progressEnd);', $body);
        self::assertStringContainsString('if ($batchCount === 0)', $body);
        self::assertStringContainsString('($siteIndex + 1) / count($sitesToIndex)', $body);
    }

    public function testAllIndicesRebuildProgressUsesEnabledCountAndReachesComplete(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $body = $this->methodBody($source, 'rebuildAllIndices');

        self::assertStringContainsString('array_filter(', $body);
        self::assertStringContainsString('static fn(SearchIndex $index): bool => $index->enabled', $body);
        self::assertStringContainsString('$indexCount = count($indices);', $body);
        self::assertStringContainsString('if ($indexCount === 0)', $body);
        self::assertStringContainsString('$this->setProgress($queue, 1.0);', $body);
        self::assertStringContainsString('$i / $indexCount', $body);
        self::assertStringContainsString('($i + 1) / $indexCount', $body);
        self::assertStringNotContainsString('$this->setProgress($queue, $i / count($indices));', $body);
    }

    public function testNestedProgressHelperBoundsProgressToRange(): void
    {
        $source = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        $body = $this->methodBody($source, 'setRebuildProgress');

        self::assertStringContainsString('$start = max(0.0, min(1.0, $start));', $body);
        self::assertStringContainsString('$end = max($start, min(1.0, $end));', $body);
        self::assertStringContainsString('$progress = max(0.0, min(1.0, $progress));', $body);
        self::assertStringContainsString('$this->setProgress($queue, $start + (($end - $start) * $progress));', $body);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
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
