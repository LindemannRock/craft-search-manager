<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\elements\Entry;
use lindemannrock\searchmanager\helpers\SearchIndexQueryHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;

/**
 * Regression coverage for the display/UX audit batch (#414, #417, #418,
 * #420, #422, and #424).
 *
 * @since 5.54.0
 */
final class IndexHealthPresentationTest extends TestCase
{
    public function testExpectedCountsUseEligibleElementUnitsWithoutTransformingSplitContent(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $splitIndex = new SearchIndex([
            'handle' => '__sm_audit_414_split',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'transformerClass' => AutoTransformer::class,
            'headingLevels' => [2, 3],
            'splitSections' => true,
        ]);

        $candidate = SearchIndexQueryHelper::buildSiteQueries($splitIndex)[$siteId]->one();
        if (!$candidate instanceof Entry) {
            self::markTestSkipped('A live entry is required for count-unit coverage.');
        }

        $criteria = static fn($query) => $query->id((int)$candidate->id);
        $splitIndex->criteria = $criteria;

        $pageIndex = new SearchIndex([
            'handle' => '__sm_audit_414_page',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'criteria' => $criteria,
            'transformerClass' => AutoTransformer::class,
            'headingLevels' => [2, 3],
            'splitSections' => false,
        ]);

        self::assertSame(1, $pageIndex->getExpectedCount());
        self::assertSame(1, $splitIndex->getExpectedCount());

        $expectedCountBody = $this->methodBody(
            $this->readPluginSource('src/models/SearchIndex.php'),
            'getExpectedCount',
            'public',
        );
        self::assertStringNotContainsString('->each(', $expectedCountBody);
        self::assertStringNotContainsString('->transform(', $expectedCountBody);
        self::assertStringNotContainsString('SplitSectionDocumentHelper', $expectedCountBody);

        $rebuildSource = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        self::assertStringContainsString(
            '$totalIndexedDocuments += $batchResult[\'acceptedDocumentCount\']',
            $rebuildSource,
        );
        self::assertStringContainsString('$index->updateStats($totalIndexedDocuments)', $rebuildSource);
    }

    public function testSplitHealthDetectsMissingParentsButIgnoresSectionShapeDrift(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $splitIndex = new SearchIndex([
            'handle' => '__sm_audit_425_split',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'transformerClass' => AutoTransformer::class,
            'splitSections' => true,
            'documentCount' => 5,
        ]);
        $backend = $this->installStubBackend();

        $expected = $splitIndex->getExpectedCount();
        if ($expected === 0) {
            self::markTestSkipped('At least one eligible entry is required for split-health coverage.');
        }

        $backend->distinctParentCounts[$splitIndex->handle . ':' . $siteId] = $expected - 1;
        self::assertSame($expected - 1, $splitIndex->getComparisonCount(), 'A missing parent must make split coverage stale.');

        $backend->distinctParentCounts[$splitIndex->handle . ':' . $siteId] = $expected;
        self::assertSame(
            $splitIndex->getExpectedCount(),
            $splitIndex->getComparisonCount(),
            'Changed section counts must not make an otherwise-present parent stale.',
        );
        self::assertSame(5, $splitIndex->documentCount, 'Split documentCount must remain the true backend-document total.');

        $pageIndex = new SearchIndex([
            'handle' => '__sm_audit_425_page',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'splitSections' => false,
            'documentCount' => 7,
        ]);
        self::assertSame(7, $pageIndex->getComparisonCount(), 'Page-mode comparison must remain document-based.');
    }

    public function testEveryShippedBackendUsesItsBoundedDistinctParentPrimitive(): void
    {
        $mysql = $this->readPluginSource('src/search/storage/MySqlStorage.php');
        $pgsql = $this->readPluginSource('src/search/storage/PostgreSqlStorage.php');
        $redis = $this->readPluginSource('src/search/storage/RedisStorage.php');
        $file = $this->readPluginSource('src/search/storage/FileStorage.php');
        $algolia = $this->readPluginSource('src/backends/AlgoliaBackend.php');
        $meilisearch = $this->readPluginSource('src/backends/MeilisearchBackend.php');
        $typesense = $this->readPluginSource('src/backends/TypesenseBackend.php');

        self::assertStringContainsString('COUNT(DISTINCT [[elementId]])', $mysql);
        self::assertStringContainsString('COUNT(DISTINCT [[elementId]])', $pgsql);
        self::assertStringContainsString("zCard(\$this->keyPrefix . 'elemindex:' . \$siteId)", $redis);
        self::assertStringContainsString("\$manifest = \$this->readManifest('counting distinct File index parents')", $file);
        self::assertStringNotContainsString("glob(\$this->basePath . '/elements/' . \$siteId . '_*.dat')", $file);

        self::assertStringContainsString("\$settingsUpdate['attributeForDistinct'] = 'elementId'", $algolia);
        self::assertStringContainsString("\$params['distinct'] = 1", $algolia);
        self::assertStringContainsString("'exhaustiveNbHits' => true", $algolia);

        self::assertStringContainsString("\$searchParams['distinct'] = 'elementId'", $meilisearch);
        self::assertStringContainsString("'hitsPerPage' => 0", $meilisearch);
        self::assertStringContainsString('$documentCount > $maxTotalHits', $meilisearch);

        self::assertStringContainsString("'group_by' => 'elementId'", $typesense);
        self::assertStringContainsString("'group_max_candidates' => \$documentCount", $typesense);
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
