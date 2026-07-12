<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\elements\Entry;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(QueryRuleService::class)]
final class QueryRuleBoostBatchTest extends TestCase
{
    public function testElementBoostsStillReorderRepeatedHitsWithoutElementLoads(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $entry] = $pair;
        $siteId = (int)$entry->siteId;
        $service = $this->serviceWithBoosts([
            'sections' => [],
            'categories' => [],
            'elements' => [(int)$entry->id => 5.0],
        ]);

        $results = $service->applyBoosts([
            ['id' => 2147483000, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 10.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 3.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 1.0],
        ], 'query', $index->handle, $siteId);

        self::assertSame((int)$entry->id, $results[0]['id']);
        self::assertSame(15.0, $results[0]['score']);
        self::assertTrue($results[0]['boosted']);
        self::assertSame(10.0, $results[1]['score']);
        self::assertSame(5.0, $results[2]['score']);
    }

    public function testSectionBoostsUseIndexedSectionHandleAndPreserveScoreChanges(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $entry] = $pair;
        self::assertInstanceOf(Entry::class, $entry);

        $siteId = (int)$entry->siteId;
        $sectionHandle = $entry->getSection()->handle;
        $service = $this->serviceWithBoosts([
            'sections' => [$sectionHandle => 2.0],
            'categories' => [],
            'elements' => [],
        ]);

        $results = $service->applyBoosts([
            ['id' => 2147483000, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 10.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'entrySectionHandle' => $sectionHandle, 'score' => 6.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'entrySectionHandle' => $sectionHandle, 'score' => 1.0],
        ], 'query', $index->handle, $siteId);

        self::assertSame((int)$entry->id, $results[0]['id']);
        self::assertSame(12.0, $results[0]['score']);
        self::assertTrue($results[0]['boosted']);
        self::assertSame(10.0, $results[1]['score']);
        self::assertSame(2.0, $results[2]['score']);
    }

    public function testSectionAndCategoryBoostPathsUseIndexedHitMetadataOnly(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/QueryRuleService.php');
        $this->assertIsString($source);

        self::assertStringContainsString("\$sectionHandle = \$result['entrySectionHandle'] ?? null;", $source);
        self::assertStringContainsString("\$categoryIds = \$result['_categoryIds'] ?? null;", $source);
        self::assertStringContainsString('Skipping section boost for results missing indexed entrySectionHandle metadata', $source);
        self::assertStringContainsString('Skipping category boost for results missing indexed _categoryIds metadata', $source);
        self::assertStringNotContainsString('preloadBoostElements', $source);
        self::assertStringNotContainsString('preloadCategoryBoostMatches', $source);
        self::assertStringNotContainsString('Table::RELATIONS', $source);
        self::assertStringNotContainsString('getElementById', $source);
        self::assertStringNotContainsString('::find()', $source);
    }

    public function testLegacyCategoryHandleBoostsDoNotResolveLiveTargets(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/QueryRuleService.php');
        $this->assertIsString($source);

        preg_match(
            '/public function getBoostMultipliers\(.*?^    \}/ms',
            $source,
            $matches,
        );
        $this->assertNotEmpty($matches, 'getBoostMultipliers source should be found');

        self::assertStringContainsString('Skipping category boost rule without indexed categoryId target', $matches[0]);
        self::assertStringNotContainsString('resolveCategoryHandleBoostIds', $source);
        self::assertStringNotContainsString('Category::find()', $source);
        self::assertStringNotContainsString('->slug(array_keys($handles))', $source);
        self::assertStringNotContainsString('->id(array_keys($ids))', $source);
    }

    public function testCategoryBoostAppliesToDirectCategoryHits(): void
    {
        $categoryId = 2147482999;
        $siteId = 1;
        $service = $this->serviceWithBoosts([
            'sections' => [],
            'categories' => [$categoryId => 4.0],
            'elements' => [],
        ]);

        $results = $service->applyBoosts([
            [
                'id' => $categoryId,
                'siteId' => $siteId,
                'type' => 'category',
                'score' => 2.0,
            ],
            [
                'id' => 2147483000,
                'siteId' => $siteId,
                'type' => 'category',
                'score' => 7.0,
            ],
        ], 'query', null, $siteId);

        self::assertSame($categoryId, $results[0]['id']);
        self::assertSame(8.0, $results[0]['score']);
        self::assertTrue($results[0]['boosted']);
    }

    public function testBoostAttributionIsAbsentByDefault(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $entry] = $pair;
        $rule = $this->queryRule(501, QueryRule::ACTION_BOOST_ELEMENT, [
            'elementId' => (int)$entry->id,
            'elementType' => Entry::class,
            'multiplier' => 3.0,
        ]);

        $results = (new QueryRuleService())->applyBoosts([
            ['id' => (int)$entry->id, 'siteId' => (int)$entry->siteId, '_index' => $index->handle, 'score' => 2.0],
        ], 'query', $index->handle, (int)$entry->siteId, [$rule]);

        self::assertTrue($results[0]['boosted']);
        self::assertArrayNotHasKey('_queryRuleDebug', $results[0]);
    }

    public function testBoostElementAttributionIncludesRuleAndTarget(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $entry] = $pair;
        $rule = $this->queryRule(502, QueryRule::ACTION_BOOST_ELEMENT, [
            'elementId' => (int)$entry->id,
            'elementType' => Entry::class,
            'multiplier' => 3.0,
        ]);

        $results = (new QueryRuleService())->applyBoosts([
            ['id' => (int)$entry->id, 'siteId' => (int)$entry->siteId, '_index' => $index->handle, 'score' => 2.0],
        ], 'query', $index->handle, (int)$entry->siteId, [$rule], true);

        self::assertSame(6.0, $results[0]['score']);
        self::assertSame([
            [
                'ruleId' => 502,
                'actionType' => QueryRule::ACTION_BOOST_ELEMENT,
                'multiplier' => 3.0,
                'elementId' => (int)$entry->id,
                'elementType' => Entry::class,
            ],
        ], $results[0]['_queryRuleDebug']['boosts'] ?? null);
    }

    public function testBoostSectionAttributionIncludesSectionHandle(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        $this->assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [$index, $entry] = $pair;
        self::assertInstanceOf(Entry::class, $entry);
        $sectionHandle = $entry->getSection()->handle;
        $rule = $this->queryRule(503, QueryRule::ACTION_BOOST_SECTION, [
            'sectionHandle' => $sectionHandle,
            'multiplier' => 2.0,
        ]);

        $results = (new QueryRuleService())->applyBoosts([
            ['id' => (int)$entry->id, 'siteId' => (int)$entry->siteId, '_index' => $index->handle, 'entrySectionHandle' => $sectionHandle, 'score' => 3.0],
        ], 'query', $index->handle, (int)$entry->siteId, [$rule], true);

        self::assertSame(6.0, $results[0]['score']);
        self::assertSame($sectionHandle, $results[0]['_queryRuleDebug']['boosts'][0]['sectionHandle'] ?? null);
        self::assertSame(503, $results[0]['_queryRuleDebug']['boosts'][0]['ruleId'] ?? null);
        self::assertSame(QueryRule::ACTION_BOOST_SECTION, $results[0]['_queryRuleDebug']['boosts'][0]['actionType'] ?? null);
    }

    public function testDirectCategoryBoostAttributionIncludesCategoryTarget(): void
    {
        $categoryId = 2147482998;

        $rule = $this->queryRule(504, QueryRule::ACTION_BOOST_CATEGORY, [
            'categoryId' => $categoryId,
            'multiplier' => 4.0,
        ]);

        $results = (new QueryRuleService())->applyBoosts([
            ['id' => $categoryId, 'siteId' => 1, 'type' => 'category', 'score' => 2.0],
        ], 'query', null, 1, [$rule], true);

        self::assertSame(8.0, $results[0]['score']);
        self::assertSame(504, $results[0]['_queryRuleDebug']['boosts'][0]['ruleId'] ?? null);
        self::assertSame(QueryRule::ACTION_BOOST_CATEGORY, $results[0]['_queryRuleDebug']['boosts'][0]['actionType'] ?? null);
        self::assertSame($categoryId, $results[0]['_queryRuleDebug']['boosts'][0]['categoryId'] ?? null);
        self::assertNull($results[0]['_queryRuleDebug']['boosts'][0]['categoryHandle'] ?? null);
    }

    public function testRelatedCategoryBoostAttributionIncludesCategoryTarget(): void
    {
        $categoryId = 2147482997;

        $rule = $this->queryRule(505, QueryRule::ACTION_BOOST_CATEGORY, [
            'categoryId' => $categoryId,
            'categoryHandle' => 'indexed-category',
            'multiplier' => 2.0,
        ]);

        $results = (new QueryRuleService())->applyBoosts([
            ['id' => 2147482996, 'siteId' => 1, 'type' => 'entry', '_categoryIds' => [$categoryId], 'score' => 3.0],
        ], 'query', null, 1, [$rule], true);

        self::assertSame(6.0, $results[0]['score']);
        self::assertSame(505, $results[0]['_queryRuleDebug']['boosts'][0]['ruleId'] ?? null);
        self::assertSame(QueryRule::ACTION_BOOST_CATEGORY, $results[0]['_queryRuleDebug']['boosts'][0]['actionType'] ?? null);
        self::assertSame($categoryId, $results[0]['_queryRuleDebug']['boosts'][0]['categoryId'] ?? null);
        self::assertSame('indexed-category', $results[0]['_queryRuleDebug']['boosts'][0]['categoryHandle'] ?? null);
    }

    /**
     * @param array{sections: array<string, float>, categories: array<int, float>, elements: array<int, float>} $boosts
     */
    private function serviceWithBoosts(array $boosts): QueryRuleService
    {
        return new class($boosts) extends QueryRuleService {
            public function __construct(private array $testBoosts)
            {
                parent::__construct();
            }

            public function getBoostMultipliers(
                string $query,
                ?string $indexHandle = null,
                ?int $siteId = null,
                ?array $matchedRules = null,
            ): array {
                return $this->testBoosts;
            }
        };
    }

    /**
     * @param array<string, mixed> $actionValue
     */
    private function queryRule(int $id, string $actionType, array $actionValue): QueryRule
    {
        $rule = new QueryRule();
        $rule->id = $id;
        $rule->name = 'Boost attribution';
        $rule->matchValue = 'query';
        $rule->actionType = $actionType;
        $rule->actionValue = $actionValue;

        return $rule;
    }
}
