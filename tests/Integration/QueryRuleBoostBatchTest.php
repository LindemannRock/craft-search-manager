<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.53.0
 */
#[CoversClass(QueryRuleService::class)]
final class QueryRuleBoostBatchTest extends TestCase
{
    private ?object $originalElements = null;

    protected function tearDown(): void
    {
        if ($this->originalElements !== null) {
            Craft::$app->set('elements', $this->originalElements);
            $this->originalElements = null;
        }

        parent::tearDown();
    }

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

        $counting = $this->installCountingElements();
        $results = $service->applyBoosts([
            ['id' => 2147483000, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 10.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 3.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 1.0],
        ], 'query', $index->handle, $siteId);

        self::assertSame(0, $counting->getByIdCalls, 'element-only boosts do not need element hydration.');
        self::assertSame((int)$entry->id, $results[0]['id']);
        self::assertSame(15.0, $results[0]['score']);
        self::assertTrue($results[0]['boosted']);
        self::assertSame(10.0, $results[1]['score']);
        self::assertSame(5.0, $results[2]['score']);
    }

    public function testSectionBoostsBatchLoadRepeatedHitsAndPreserveScoreChanges(): void
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

        $counting = $this->installCountingElements();
        $results = $service->applyBoosts([
            ['id' => 2147483000, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 10.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 6.0],
            ['id' => (int)$entry->id, 'siteId' => $siteId, '_index' => $index->handle, 'score' => 1.0],
        ], 'query', $index->handle, $siteId);

        self::assertSame(0, $counting->getByIdCalls, 'resolved repeated hits must use batched element queries, not per-hit getElementById().');
        self::assertSame((int)$entry->id, $results[0]['id']);
        self::assertSame(12.0, $results[0]['score']);
        self::assertTrue($results[0]['boosted']);
        self::assertSame(10.0, $results[1]['score']);
        self::assertSame(2.0, $results[2]['score']);
    }

    public function testSectionAndCategoryBoostPathsUseBatches(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/services/QueryRuleService.php');
        $this->assertIsString($source);

        self::assertStringContainsString('preloadBoostElements', $source);
        self::assertStringContainsString('Table::RELATIONS', $source);
        self::assertStringContainsString('preloadCategoryBoosts', $source);
        self::assertStringNotContainsString('getElementById($elementId, null, $siteId)', $source);
        self::assertStringNotContainsString('getFieldValue($field->handle)', $source);
        self::assertStringNotContainsString('->all() as $category', $source);
    }

    private function installCountingElements(): object
    {
        $this->originalElements = Craft::$app->get('elements');

        $counting = new class extends \craft\services\Elements {
            public int $getByIdCalls = 0;

            public function getElementById(
                int $elementId,
                ?string $elementType = null,
                array|string|int|null $siteId = null,
                array $criteria = [],
            ): ?ElementInterface {
                $this->getByIdCalls++;

                return parent::getElementById($elementId, $elementType, $siteId, $criteria);
            }
        };

        Craft::$app->set('elements', $counting);

        return $counting;
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

            public function getBoostMultipliers(string $query, ?string $indexHandle = null, ?int $siteId = null): array
            {
                return $this->testBoosts;
            }
        };
    }
}
