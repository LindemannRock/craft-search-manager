<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class AuditItem287RegressionTest extends TestCase
{
    public function testRebuildIndexJobUsesEntryOnlySkipHelper(): void
    {
        $body = $this->methodBody($this->readPluginSource('src/jobs/RebuildIndexJob.php'), 'rebuildSingleIndex');

        self::assertStringContainsString('$index->shouldSkipElementWithoutUrl($element)', $body);
        self::assertStringNotContainsString('$index->skipEntriesWithoutUrl && $element->url === null', $body);
    }

    public function testIndexingServiceUsesEntryOnlySkipHelper(): void
    {
        $body = $this->methodBody(
            $this->readPluginSource('src/services/IndexingService.php'),
            'indexElementNow',
            'public',
        );

        self::assertStringContainsString('$index->shouldSkipElementWithoutUrl($element)', $body);
        self::assertStringNotContainsString('$index->skipEntriesWithoutUrl && $element->url === null', $body);
    }

    public function testPendingSyncProcessorUsesEntryOnlySkipHelper(): void
    {
        $body = $this->methodBody(
            $this->readPluginSource('src/services/sync/PendingSyncProcessor.php'),
            'processIndexRows',
        );

        self::assertStringContainsString('$index->shouldSkipElementWithoutUrl($element)', $body);
        self::assertStringNotContainsString('$index->skipEntriesWithoutUrl && $element->url === null', $body);
    }

    public function testSearchIndexExpectedCountOnlyAppliesUriFilterForEntries(): void
    {
        $body = $this->methodBody(
            $this->readPluginSource('src/models/SearchIndex.php'),
            'getExpectedCount',
            'public',
        );

        self::assertStringContainsString('if ($this->skipEntriesWithoutUrl && $elementType === Entry::class)', $body);
        self::assertStringContainsString("->andWhere(['not', ['elements_sites.uri' => null]])", $body);
        self::assertStringContainsString("->andWhere(['<>', 'elements_sites.uri', ''])", $body);
        self::assertStringNotContainsString('if ($this->skipEntriesWithoutUrl) {', $body);
    }

    public function testSkipHelperIsExplicitlyEntryOnly(): void
    {
        $body = $this->methodBody(
            $this->readPluginSource('src/models/SearchIndex.php'),
            'shouldSkipElementWithoutUrl',
            'public',
        );

        self::assertStringContainsString('$this->skipEntriesWithoutUrl', $body);
        self::assertStringContainsString('$this->elementType === Entry::class', $body);
        self::assertStringContainsString('$element instanceof Entry', $body);
        self::assertStringContainsString('$element->url === null', $body);
    }

    public function testNonEntryIndexWithSkipEnabledIsNotSkippedByHelper(): void
    {
        $index = new SearchIndex([
            'elementType' => 'lindemannrock\\shortlinkmanager\\elements\\ShortLink',
            'skipEntriesWithoutUrl' => true,
        ]);
        $element = $this->createMock(ElementInterface::class);

        self::assertFalse($index->shouldSkipElementWithoutUrl($element));
    }

    public function testEntryIndexWithSkipEnabledStillSkipsNullUrlEntry(): void
    {
        $index = new SearchIndex([
            'elementType' => Entry::class,
            'skipEntriesWithoutUrl' => true,
        ]);
        $entry = new Entry();

        self::assertNull($entry->url);
        self::assertTrue($index->shouldSkipElementWithoutUrl($entry));
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
