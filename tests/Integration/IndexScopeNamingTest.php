<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for the public search-scope naming contract.
 */
final class IndexScopeNamingTest extends TestCase
{
    public function testResolveRequestedIndicesAcceptsSingleOrCommaSeparatedIndicesOnly(): void
    {
        $handle = $this->firstEnabledIndexHandle();
        if ($handle === null) {
            $this->markTestSkipped('No enabled search index available.');
        }

        self::assertSame([[$handle], true, false], SearchIndex::resolveRequestedIndices($handle));

        [$handles, $provided, $exceededMax] = SearchIndex::resolveRequestedIndices($handle . ',__missing_index__');

        self::assertTrue($provided);
        self::assertFalse($exceededMax);
        self::assertSame([$handle], $handles);
    }

    public function testRemovedIndexSearchScopeIsNotAcceptedByHelperOrRestApi(): void
    {
        $method = new \ReflectionMethod(SearchIndex::class, 'resolveRequestedIndices');

        self::assertCount(2, $method->getParameters());
        self::assertSame('indicesParam', $method->getParameters()[0]->getName());
        self::assertSame('maxCount', $method->getParameters()[1]->getName());

        $apiController = $this->readPluginFile('src/controllers/ApiController.php');

        self::assertStringNotContainsString("getParam('index'", $apiController);
        self::assertStringNotContainsString('getParam("index"', $apiController);
        self::assertStringContainsString("getParam('indexHandles'", $apiController);
    }

    public function testGraphqlSearchScopeExposesOnlyIndexHandlesArgument(): void
    {
        $queries = SearchQuery::getQueries(false);

        self::assertArrayNotHasKey('index', $queries['searchManagerSearch']['args']);
        self::assertArrayHasKey('indexHandles', $queries['searchManagerSearch']['args']);
        self::assertArrayNotHasKey('index', $queries['searchManagerAutocomplete']['args']);
        self::assertArrayHasKey('indexHandles', $queries['searchManagerAutocomplete']['args']);

        $resolver = $this->readPluginFile('src/gql/resolvers/SearchResolver.php');

        self::assertStringNotContainsString("\$arguments['index']", $resolver);
        self::assertStringContainsString("\$arguments['indexHandles']", $resolver);
    }

    public function testWidgetConfigDoesNotDerivePublicIndexAlias(): void
    {
        $configParser = $this->readPluginFile('src/web/assets/searchwidget/src/core/ConfigParser.js');
        $widgetBase = $this->readPluginFile('src/web/assets/searchwidget/src/core/SearchWidgetBase.js');

        self::assertStringNotContainsString('@property {string} index', $configParser);
        self::assertStringNotContainsString('index: indices[0]', $configParser);
        self::assertDoesNotMatchRegularExpression('/\\bthis\\.config\\.index\\b/', $widgetBase);
        self::assertStringContainsString('getSearchScopeKey(this.config)', $widgetBase);
        self::assertStringContainsString('item.dataset.sourceIndex', $widgetBase);
    }

    private function firstEnabledIndexHandle(): ?string
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled) {
                return $index->handle;
            }
        }

        return null;
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}
