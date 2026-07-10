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

    public function testTitleMatchDoesNotUseFlattenedExcerptOrContentAsDescription(): void
    {
        $hit = [
            'title' => 'Eco Shirt',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0 SHIRT-36E05A-1 eco-shirt-9-e006 Eco Shirt Lorem ipsum dolor sit amet',
            'content' => 'Eco Shirt SHIRT-36E041-0 SHIRT-36E05A-1 eco-shirt-9-e006 Eco Shirt Lorem ipsum dolor sit amet',
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'eco', $debug);

        self::assertNull($description);
        self::assertSame(['title'], $hit['matchedIn']);
        self::assertSame(['eco'], $hit['matchedTerms']['title']);
        self::assertSame('title', $debug['snippetSource'] ?? null);
        self::assertSame('none', $debug['snippetFrom'] ?? null);
    }

    public function testCommerceProductTitleMatchUsesLiveProductProseDescription(): void
    {
        $field = new PlainText([
            'handle' => 'productDetails',
            'name' => 'Product Details',
            'searchable' => true,
        ]);
        $product = SearchManagerEnrichmentTestElement::withFields([$field], [
            'productDetails' => 'Lorem ipsum dolor sit amet with organic cotton for everyday wear.',
        ]);
        $hit = [
            'title' => 'Eco Shirt',
            'type' => 'product',
            'elementType' => 'product',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0 SHIRT-36E05A-1 eco-shirt-9-e006 Eco Shirt Lorem ipsum dolor sit amet',
            'content' => 'Eco Shirt SHIRT-36E041-0 SHIRT-36E05A-1 eco-shirt-9-e006 Eco Shirt Lorem ipsum dolor sit amet',
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'eco', $debug, $product);

        self::assertSame('Lorem ipsum dolor sit amet with organic cotton for everyday wear.', $description);
        self::assertStringNotContainsString('SHIRT-36E041-0', $description);
        self::assertSame('title', $debug['snippetSource'] ?? null);
        self::assertSame('description', $debug['snippetFrom'] ?? null);
    }

    public function testVariantTitleMatchCanUseParentProductProseDescription(): void
    {
        $field = new PlainText([
            'handle' => 'productStory',
            'name' => 'Product Story',
            'searchable' => true,
        ]);
        $product = SearchManagerEnrichmentTestElement::withFields([$field], [
            'productStory' => 'Parent product prose explains the fabric, fit, and care instructions.',
        ]);
        $variant = SearchManagerEnrichmentTestVariant::withProduct($product);
        $hit = [
            'title' => 'Eco Shirt - Medium',
            'type' => 'variant',
            'elementType' => 'variant',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0 Medium eco-shirt-9-e006',
            'content' => 'Eco Shirt SHIRT-36E041-0 Medium eco-shirt-9-e006',
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'eco', $debug, $variant);

        self::assertSame('Parent product prose explains the fabric, fit, and care instructions.', $description);
        self::assertSame('description', $debug['snippetFrom'] ?? null);
    }

    public function testUserDefinedSearchableProseFieldDoesNotRequireDescriptionHandle(): void
    {
        $field = new PlainText([
            'handle' => 'marketingCopy',
            'name' => 'Marketing Copy',
            'searchable' => true,
        ]);
        $element = SearchManagerEnrichmentTestElement::withFields([$field], [
            'marketingCopy' => 'A breathable everyday layer made from soft organic cotton.',
        ]);
        $hit = [
            'title' => 'Eco Shirt',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006',
            'content' => 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006',
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'eco', $debug, $element);

        self::assertSame('A breathable everyday layer made from soft organic cotton.', $description);
        self::assertSame('description', $debug['snippetFrom'] ?? null);
    }

    public function testAssetTitleMatchUsesSearchableCustomDescriptionInsteadOfFlattenedContent(): void
    {
        $field = new PlainText([
            'handle' => 'description',
            'name' => 'Description',
            'searchable' => true,
        ]);
        $asset = SearchManagerEnrichmentTestElement::withFields([$field], [
            'description' => 'A cheese image for testing',
        ]);
        $hit = [
            'title' => 'Cheese',
            'type' => 'asset',
            'elementType' => 'asset',
            'content' => 'Cheese ALT TEXT FOR CHEESE A cheese image for testing',
            'excerpt' => 'Cheese ALT TEXT FOR CHEESE A cheese image for testing',
            'matchedIn' => ['title', 'content'],
            'matchedTerms' => [
                'title' => ['cheese'],
                'content' => ['cheese'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'cheese', $debug, $asset);

        self::assertSame('A cheese image for testing', $description);
        self::assertStringNotContainsString('ALT TEXT FOR CHEESE', $description);
        self::assertSame('title', $debug['snippetSource'] ?? null);
        self::assertSame('short', $debug['snippetFrom'] ?? null);
    }

    public function testAssetTitleMatchCanUseAltTextAsSafeProse(): void
    {
        $field = new PlainText([
            'handle' => 'alternativeText',
            'name' => 'Alternative Text',
            'searchable' => true,
        ]);
        $asset = SearchManagerEnrichmentTestElement::withFields([$field], [
            'alternativeText' => 'ALT TEXT FOR CHEESE',
        ]);
        $hit = [
            'title' => 'Cheese',
            'type' => 'asset',
            'elementType' => 'asset',
            'content' => 'Cheese ALT TEXT FOR CHEESE',
            'excerpt' => 'Cheese ALT TEXT FOR CHEESE',
            'matchedIn' => ['title', 'content'],
            'matchedTerms' => [
                'title' => ['cheese'],
                'content' => ['cheese'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'cheese', $debug, $asset);

        self::assertSame('ALT TEXT FOR CHEESE', $description);
        self::assertSame('short', $debug['snippetFrom'] ?? null);
    }

    public function testUserTitleMatchUsesCustomDescriptionInsteadOfNativeContentBag(): void
    {
        $field = new PlainText([
            'handle' => 'description',
            'name' => 'Description',
            'searchable' => true,
        ]);
        $user = SearchManagerEnrichmentTestElement::withFields([$field], [
            'description' => 'The admin is a creative technologist',
        ]);
        $hit = [
            'title' => 'Bilal Harry Lindemann',
            'type' => 'user',
            'elementType' => 'user',
            'content' => 'bh@lindemannrock.com Bilal Harry Lindemann Bilal Lindemann bh@lindemannrock.com The admin is a creative technologist',
            'excerpt' => 'bh@lindemannrock.com Bilal Harry Lindemann Bilal Lindemann bh@lindemannrock.com The admin is a creative technologist',
            'matchedIn' => ['title', 'content'],
            'matchedTerms' => [
                'title' => ['bilal'],
                'content' => ['bilal'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'bilal', $debug, $user);

        self::assertSame('The admin is a creative technologist', $description);
        self::assertStringNotContainsString('bh@lindemannrock.com', $description);
        self::assertSame('title', $debug['snippetSource'] ?? null);
        self::assertSame('description', $debug['snippetFrom'] ?? null);
    }

    public function testCategoryHitDescriptionRemainsSafeProseCandidate(): void
    {
        $hit = [
            'title' => 'One',
            'type' => 'category',
            'elementType' => 'category',
            '_fields' => [
                'description' => 'This is one to test for categories',
            ],
            'content' => 'One This is one to test for categories',
            'excerpt' => 'One This is one to test for categories',
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['one'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'one', $debug);

        self::assertSame('This is one to test for categories', $description);
        self::assertSame('short', $debug['snippetFrom'] ?? null);
    }

    public function testTitleAndContentMatchWithBodyOnlyTermStillUsesContextualContentSnippet(): void
    {
        $hit = [
            'title' => 'Cheese',
            'content' => 'Cheese A cheese image for testing with a detailed dairy counter note.',
            'matchedIn' => ['title', 'content'],
            'matchedTerms' => [
                'title' => ['cheese'],
                'content' => ['image'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'image', $debug);

        self::assertIsString($description);
        self::assertStringContainsString('cheese image for testing', $description);
        self::assertSame('content', $debug['snippetSource'] ?? null);
        self::assertSame('content', $debug['snippetFrom'] ?? null);
    }

    public function testSourceDocContentMatchStillUsesContextualContentSnippet(): void
    {
        $hit = [
            'title' => 'Quickstart',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'description' => 'Install and configure Search Manager.',
            'content' => 'Quickstart Install and configure Search Manager. Use the indexing command after deployment.',
            'matchedIn' => ['content'],
            'matchedTerms' => [
                'title' => [],
                'content' => ['deployment'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'deployment', $debug);

        self::assertIsString($description);
        self::assertStringContainsString('deployment', $description);
        self::assertSame('content', $debug['snippetSource'] ?? null);
        self::assertSame('content', $debug['snippetFrom'] ?? null);
    }

    public function testContentMatchStillUsesContextualContentSnippet(): void
    {
        $hit = [
            'title' => 'Eco Shirt',
            'excerpt' => 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006 Eco Shirt',
            'content' => 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006 Eco Shirt Lorem ipsum dolor sit amet with organic cotton.',
            'matchedIn' => ['content'],
            'matchedTerms' => [
                'title' => [],
                'content' => ['organic'],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'organic', $debug);

        self::assertIsString($description);
        self::assertStringContainsString('organic cotton', $description);
        self::assertSame('content', $debug['snippetSource'] ?? null);
        self::assertSame('content', $debug['snippetFrom'] ?? null);
        self::assertSame(mb_strlen($hit['content']), $debug['fullContentLength'] ?? null);
    }

    public function testEquivalentHitDescriptionIsSuppressedWhenNoSafeProseExists(): void
    {
        $flattened = 'Eco Shirt SHIRT-36E041-0 eco-shirt-9-e006 Eco Shirt';
        $hit = [
            'description' => $flattened,
            'excerpt' => $flattened,
            'content' => $flattened,
            'matchedIn' => ['title'],
            'matchedTerms' => [
                'title' => ['eco'],
                'content' => [],
            ],
        ];
        $debug = [];

        $description = $this->invokeDescription($hit, 'eco', $debug);

        self::assertNull($description);
        self::assertSame('none', $debug['snippetFrom'] ?? null);
    }

    /**
     * @param array<string, mixed> $debug
     */
    private function invokeDescription(array $hit, string $query, array &$debug, ?ElementInterface $element = null): ?string
    {
        $service = SearchManager::$plugin->enrichment;
        $reflection = new \ReflectionMethod($service, 'getDescription');
        $reflection->setAccessible(true);
        $matchedTerms = is_array($hit['matchedTerms'] ?? null) ? $hit['matchedTerms'] : null;
        $args = [
            $hit,
            $element,
            $query,
            $matchedTerms,
            'products-en',
            'balanced',
            150,
            false,
            false,
            &$debug,
        ];

        return $reflection->invokeArgs($service, $args);
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
