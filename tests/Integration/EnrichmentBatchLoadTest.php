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
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Pins the EnrichmentService element-hydration batching fix.
 *
 * `enrichResults()` previously called Craft::$app->elements->getElementById()
 * inside the raw-hit loop — one element load per hit, worst on enrich=1 API /
 * widget searches at hitsPerPage=100. Elements are now batch-loaded up front,
 * grouped by element type and site.
 *
 * Verified against live data: resolved hits must load via the batched element
 * query (zero per-hit getElementById calls) with output preserved, and hits
 * whose index/type can't be resolved must still fall back to getElementById.
 *
 * @since 5.47.0
 */
final class EnrichmentBatchLoadTest extends TestCase
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

    /**
     * Find an enabled Entry index that has at least $min live entries on its
     * first site, or null if the install has none.
     *
     * @return array{0: SearchIndex, 1: Entry[]}|null
     */
    private function findLiveEntries(int $min = 1, int $limit = 3): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== Entry::class) {
                continue;
            }
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }
            $entries = Entry::find()->siteId($siteId)->status('live')->limit($limit)->all();
            if (count($entries) >= $min) {
                return [$index, $entries];
            }
        }

        return null;
    }

    /**
     * @return array{0: SearchIndex, 1: Category[]}|null
     */
    private function findEnabledCategories(int $min = 1, int $limit = 3): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== Category::class) {
                continue;
            }
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }
            $categories = Category::find()->siteId($siteId)->status(Element::STATUS_ENABLED)->limit($limit)->all();
            if (count($categories) >= $min) {
                return [$index, $categories];
            }
        }

        return null;
    }

    /**
     * @return array{0: SearchIndex, 1: Asset[]}|null
     */
    private function findEnabledAssets(int $min = 1, int $limit = 3): ?array
    {
        foreach (SearchIndex::findAll() as $index) {
            if (!$index->enabled || $index->elementType !== Asset::class) {
                continue;
            }
            $siteIds = $index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds();
            $siteId = (int) ($siteIds[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }
            $assets = Asset::find()->siteId($siteId)->status(Element::STATUS_ENABLED)->limit($limit)->all();
            if (count($assets) >= $min) {
                return [$index, $assets];
            }
        }

        return null;
    }

    /**
     * Swap a counting Elements service so the test can assert how many per-hit
     * getElementById() calls enrichResults makes. Restored in tearDown.
     */
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

    public function testResolvedHitsLoadInBatchWithoutPerHitGetElementById(): void
    {
        $found = $this->findLiveEntries(1, 3);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [$index, $entries] = $found;
        $siteId = (int) $entries[0]->siteId;

        // Mix explicit _index with the indexHandles[0] fallback: every hit
        // resolves the same handle, which must be resolved once via the cached
        // map rather than per hit (no findByHandle N+1).
        $hits = [];
        foreach ($entries as $i => $entry) {
            $hit = ['id' => $entry->id, 'siteId' => $siteId];
            if ($i % 2 === 0) {
                $hit['_index'] = $index->handle;
            }
            $hits[] = $hit;
        }

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults($hits, '', [$index->handle], ['siteId' => $siteId]);

        // Output preserved: one enriched result per hit, in order, correct data.
        $this->assertCount(count($entries), $results);
        foreach ($entries as $i => $entry) {
            $this->assertSame((int) $entry->id, $results[$i]['id']);
            // enrichResults falls back to 'Untitled' when the element has no title.
            $this->assertSame($entry->title ?? 'Untitled', $results[$i]['title']);
        }

        // Batched: resolved hits never touch getElementById (the removed N+1) —
        // and crucially the call count does not grow with the number of hits.
        $this->assertSame(0, $counting->getByIdCalls, 'resolved hits load via the batched element query, not per-hit getElementById');
    }

    public function testExplicitHitElementTypeLoadsInBatchBeforeIndexFallback(): void
    {
        $found = $this->findLiveEntries(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [, $entries] = $found;
        $entry = $entries[0];
        $siteId = (int) $entry->siteId;

        $hits = [[
            'id' => $entry->id,
            'siteId' => $siteId,
            '_index' => '__sm_no_such_index',
            '_elementType' => Entry::class,
        ]];

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults($hits, '', [], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame((int) $entry->id, $results[0]['id']);
        $this->assertSame(0, $counting->getByIdCalls, 'explicit hit element type should load through the batched query before index fallback');
    }

    public function testCategoryHitsUseSharedAvailabilityAndAreNotDropped(): void
    {
        $found = $this->findEnabledCategories(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Category index with an enabled category available.');
        }
        [$index, $categories] = $found;
        $category = $categories[0];
        $siteId = (int)$category->siteId;

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults([[
            'id' => (int)$category->id,
            'siteId' => $siteId,
            '_index' => $index->handle,
            'type' => 'category',
            'elementType' => 'category',
            'url' => $category->url,
        ]], 'one', [$index->handle], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame((int)$category->id, $results[0]['id']);
        $this->assertSame('category', $results[0]['type']);
        $this->assertSame($category->getGroup()->name, $results[0]['group'] ?? null);
        $this->assertSame($category->getGroup()->handle, $results[0]['groupHandle'] ?? null);
        $this->assertArrayNotHasKey('section', $results[0]);
        $this->assertSame(0, $counting->getByIdCalls);
    }

    public function testAssetHitsUseSharedAvailabilityAndAreNotDropped(): void
    {
        $found = $this->findEnabledAssets(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Asset index with an enabled asset available.');
        }
        [$index, $assets] = $found;
        $asset = $assets[0];
        $siteId = (int)$asset->siteId;

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults([[
            'id' => (int)$asset->id,
            'siteId' => $siteId,
            '_index' => $index->handle,
            'type' => 'asset',
            'elementType' => 'asset',
            'url' => $asset->url,
        ]], 'cheese', [$index->handle], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame((int)$asset->id, $results[0]['id']);
        $this->assertSame('asset', $results[0]['type']);
        $this->assertSame($asset->getVolume()->name, $results[0]['volume'] ?? null);
        $this->assertSame($asset->getVolume()->handle, $results[0]['volumeHandle'] ?? null);
        $this->assertArrayNotHasKey('section', $results[0]);
        $this->assertSame(0, $counting->getByIdCalls);
    }

    public function testUserEnrichmentFallsBackWhenHitTitleIsEmpty(): void
    {
        $user = User::find()->status(User::STATUS_ACTIVE)->one();
        if (!$user instanceof User) {
            $this->markTestSkipped('No active user available for enrichment title fallback coverage.');
        }

        $expectedTitle = $user->fullName ?: ($user->username ?: ($user->email ?: '#' . $user->id));
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;

        $results = SearchManager::$plugin->enrichment->enrichResults([[
            'id' => (int)$user->id,
            'siteId' => $siteId,
            '_elementType' => User::class,
            'type' => 'user',
            'title' => '',
        ]], '', [], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame($expectedTitle, $results[0]['title']);
        $this->assertArrayNotHasKey('section', $results[0]);
    }

    public function testQueryRuleDebugOnlyPassesThroughWhenExplicitlyEnabled(): void
    {
        $found = $this->findLiveEntries(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [$index, $entries] = $found;
        $entry = $entries[0];
        $siteId = (int) $entry->siteId;

        $hit = [
            'id' => (int)$entry->id,
            'siteId' => $siteId,
            '_index' => $index->handle,
            '_queryRuleDebug' => ['boosts' => [['ruleId' => 123]]],
        ];

        $withoutDebug = SearchManager::$plugin->enrichment->enrichResults([$hit], '', [$index->handle], ['siteId' => $siteId]);
        $withDebug = SearchManager::$plugin->enrichment->enrichResults([$hit], '', [$index->handle], [
            'siteId' => $siteId,
            'includeQueryRuleDebug' => true,
        ]);

        $this->assertArrayNotHasKey('_queryRuleDebug', $withoutDebug[0]);
        $this->assertSame(['boosts' => [['ruleId' => 123]]], $withDebug[0]['_queryRuleDebug'] ?? null);
    }

    public function testContentBagOnlyMatchReturnsNullSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            'title' => 'Eco Shirt',
            'content' => 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0',
            'matchedTerms' => [
                'title' => [],
                'content' => ['organic'],
            ],
        ], 'organic');

        self::assertNull($result['snippet']);
    }

    public function testFieldMatchReturnsPlainSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            '_fields' => [
                'description' => 'This category has one clear paragraph for testing.',
            ],
            'matchedTerms' => [
                'title' => [],
                'content' => ['one'],
            ],
        ], 'one');

        self::assertSame('This category has one clear paragraph for testing.', $result['snippet']);
        self::assertArrayNotHasKey('highlights', $result);
    }

    public function testTitleOnlyMatchWithoutFieldContentReturnsNullSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            'title' => 'Eco Shirt',
            '_fields' => [
                'description' => 'Soft cotton for everyday wear.',
            ],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ], 'eco');

        self::assertNull($result['snippet']);
    }

    public function testBodyOnlyMatchReturnsPlainSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            '_bodyClean' => 'Run Composer install before rebuilding the docs index.',
            'matchedTerms' => [
                'title' => [],
                'content' => ['composer'],
            ],
        ], 'composer');

        self::assertSame('Run Composer install before rebuilding the docs index.', $result['snippet']);
        self::assertArrayNotHasKey('highlights', $result);
    }

    public function testRawContentBagIsNeverUsedForSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            'content' => 'Quickstart quickstart setup composer deployment',
            'excerpt' => 'Quickstart quickstart setup composer deployment',
            'matchedTerms' => [
                'title' => [],
                'content' => ['composer'],
            ],
        ], 'composer');

        self::assertNull($result['snippet']);
    }

    public function testTitleMatchWithEligibleFieldContainingQueryUsesThatField(): void
    {
        $result = $this->invokeFieldSnippet([
            'title' => 'Eco Shirt',
            '_fields' => [
                'marketingCopy' => 'This eco shirt uses breathable organic cotton.',
            ],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ], 'eco');

        self::assertSame('This eco shirt uses breathable organic cotton.', $result['snippet']);
        self::assertArrayNotHasKey('highlights', $result);
    }

    public function testMultipleFieldsContainingQueryStillChooseBestSnippet(): void
    {
        $result = $this->invokeFieldSnippet([
            '_fields' => [
                'description' => 'The one summary explains the search result.',
                'body' => 'Another body paragraph with one useful detail for visitors.',
            ],
            'matchedTerms' => [
                'title' => [],
                'content' => ['one'],
            ],
        ], 'one');

        self::assertSame('The one summary explains the search result.', $result['snippet']);
        self::assertArrayNotHasKey('highlights', $result);
        self::assertStringNotContainsString('<mark', (string)$result['snippet']);
    }

    public function testSnippetNeverSourcesLiveElementFieldsOrNoiseFields(): void
    {
        $field = new PlainText([
            'handle' => 'description',
            'name' => 'Description',
            'searchable' => true,
        ]);
        $element = SearchManagerEnrichmentTestElement::withFields([$field], [
            'description' => 'Live element field contains organic but must not be read.',
        ]);

        $result = $this->invokeFieldSnippet([
            '_fields' => [
                'title' => 'Organic title field must stay noisy.',
                'slug' => 'organic-shirt',
                'sku' => 'ORGANIC-001',
            ],
            'matchedTerms' => [
                'title' => [],
                'content' => ['organic'],
            ],
        ], 'organic', $element);

        self::assertNull($result['snippet']);
    }

    public function testServerSnippetReturnsPlainTextWithoutHighlightMarkup(): void
    {
        $result = $this->invokeFieldSnippet([
            '_fields' => [
                'description' => '<script>alert(1)</script> one <b>bold</b> value',
            ],
            'matchedTerms' => [
                'title' => [],
                'content' => ['one'],
            ],
        ], 'one');

        self::assertSame('one bold value', $result['snippet']);
        self::assertStringNotContainsString('<mark', (string)$result['snippet']);
        self::assertStringNotContainsString('<script>', (string)$result['snippet']);
        self::assertStringNotContainsString('<b>', (string)$result['snippet']);
    }

    /**
     * @return array{snippet: string|null}
     */
    private function invokeFieldSnippet(
        array $hit,
        string $query,
        ?ElementInterface $element = null,
    ): array {
        $debug = [];
        unset($element);

        return SearchManager::$plugin->indexedSnippets->prepareHitSnippets(
            $hit,
            $query,
            'products-en',
            [
                'snippetMode' => 'balanced',
                'snippetLength' => 150,
                'showCodeSnippets' => false,
                'parseMarkdownSnippets' => false,
            ],
            $debug,
        );
    }

    public function testUnresolvedIndexFallsBackToGetElementById(): void
    {
        $found = $this->findLiveEntries(1, 1);
        if ($found === null) {
            $this->markTestSkipped('No enabled Entry index with a live entry available.');
        }
        [, $entries] = $found;
        $entry = $entries[0];
        $siteId = (int) $entry->siteId;

        // Unresolvable index + no indexHandles → element type unknown → the
        // defensive per-element getElementById() fallback must still load it.
        $hits = [['id' => $entry->id, 'siteId' => $siteId, '_index' => '__sm_no_such_index']];

        $counting = $this->installCountingElements();
        $results = SearchManager::$plugin->enrichment->enrichResults($hits, '', [], ['siteId' => $siteId]);

        $this->assertCount(1, $results);
        $this->assertSame((int) $entry->id, $results[0]['id']);
        $this->assertGreaterThanOrEqual(1, $counting->getByIdCalls, 'unresolved hits still load via the getElementById fallback');
    }
}

class SearchManagerEnrichmentTestElement extends Element
{
    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

    public static function displayName(): string
    {
        return 'Search Manager Enrichment Test Element';
    }

    /**
     * @param Field[] $fields
     * @param array<string, mixed> $values
     */
    public static function withFields(array $fields, array $values): self
    {
        $element = new self();
        $element->testFieldLayout = self::fieldLayout($fields);
        $element->testFieldValues = $values;

        return $element;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->testFieldLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $this->testFieldValues[$fieldHandle] ?? null;
    }

    /**
     * @param Field[] $fields
     */
    protected static function fieldLayout(array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => self::class]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements(array_map(
            static fn(Field $field): CustomField => new CustomField($field),
            $fields,
        ));

        $layout->setTabs([$tab]);

        return $layout;
    }
}

final class SearchManagerEnrichmentTestVariant extends SearchManagerEnrichmentTestElement
{
    private ?ElementInterface $product = null;

    public static function withProduct(ElementInterface $product): self
    {
        $variant = new self();
        $variant->product = $product;

        return $variant;
    }

    public function getProduct(): ?ElementInterface
    {
        return $this->product;
    }
}
