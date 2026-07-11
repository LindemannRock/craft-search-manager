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
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\helpers\TargetElementTypeHelper;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks Commerce Product/Variant target support for promotions and query rules.
 *
 * @since 5.53.0
 */
#[CoversClass(TargetElementTypeHelper::class)]
#[CoversClass(SearchElementAvailabilityHelper::class)]
#[CoversClass(Promotion::class)]
#[CoversClass(QueryRule::class)]
final class CommerceTargetElementTypesTest extends TestCase
{
    public function testTargetOptionsKeepExistingCoreTypesInOrder(): void
    {
        $typeKeys = TargetElementTypeHelper::typeKeys();

        self::assertSame(Entry::class, $typeKeys['entry'] ?? null);
        self::assertSame(Asset::class, $typeKeys['asset'] ?? null);
        self::assertSame(Category::class, $typeKeys['category'] ?? null);
        self::assertSame(User::class, $typeKeys['user'] ?? null);
        self::assertSame(['entry', 'asset', 'category', 'user'], array_slice(array_keys($typeKeys), 0, 4));
    }

    public function testCommerceTargetsFollowCommerceHelperAvailability(): void
    {
        $typeKeys = TargetElementTypeHelper::typeKeys();
        $options = TargetElementTypeHelper::options();
        $optionValues = array_column($options, 'value');

        foreach (CommerceElementTypeHelper::elementTypes() as $elementType) {
            $expectedKey = $elementType === CommerceElementTypeHelper::PRODUCT_ELEMENT_TYPE ? 'product' : 'variant';

            if (in_array($elementType, CommerceElementTypeHelper::availableElementTypes(), true)) {
                self::assertSame($elementType, $typeKeys[$expectedKey] ?? null);
                self::assertContains($expectedKey, $optionValues);
                continue;
            }

            self::assertArrayNotHasKey($expectedKey, $typeKeys);
            self::assertNotContains($expectedKey, $optionValues);
        }
    }

    public function testPromotionValidationAcceptsSupportedCommerceTargets(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            self::assertTrue(TargetElementTypeHelper::isSupportedElementType($elementType));
        }

        foreach (CommerceElementTypeHelper::elementTypes() as $elementType) {
            if (!in_array($elementType, CommerceElementTypeHelper::availableElementTypes(), true)) {
                self::assertFalse(TargetElementTypeHelper::isSupportedElementType($elementType));
            }
        }
    }

    public function testPromotionSaveLoadAcceptsCommerceTargetRowsWhenElementsExist(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $element = $elementType::find()
                ->status(null)
                ->one();

            if ($element === null) {
                continue;
            }

            $promotion = new Promotion();
            $promotion->title = 'Commerce target regression';
            $promotion->query = 'commerce target regression';
            $promotion->matchType = 'exact';
            $promotion->elementId = (int)$element->id;
            $promotion->elementType = $elementType;
            $promotion->position = 1;

            self::assertTrue($promotion->save(), implode('; ', $promotion->getFirstErrors()));
            $loaded = Promotion::findById((int)$promotion->id);
            self::assertNotNull($loaded);
            self::assertSame($elementType, $loaded->elementType);
            self::assertSame((int)$element->id, $loaded->elementId);

            $promotion->delete();
        }

        self::assertTrue(true);
    }

    public function testPromotionInjectionCarriesCommerceTargetElementTypeContext(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $element = $this->findLiveCommerceElement($elementType);
            if ($element === null) {
                continue;
            }

            $promotion = new Promotion();
            $promotion->id = 987000 + (int)$element->id;
            $promotion->title = 'Commerce target runtime';
            $promotion->query = 'commerce runtime';
            $promotion->matchType = 'exact';
            $promotion->elementId = (int)$element->id;
            $promotion->elementType = $elementType;
            $promotion->position = 1;
            $promotion->siteId = (int)$element->siteId;

            $results = (new PromotionService())->applyPromotions(
                [['id' => 2147483000, 'siteId' => (int)$element->siteId, 'score' => 1.0]],
                'commerce runtime',
                'products',
                (int)$element->siteId,
                [$promotion],
            );

            self::assertIsArray($results[0]);
            self::assertSame((int)$element->id, $results[0]['elementId']);
            self::assertSame($elementType, $results[0]['_elementType']);
            self::assertSame($elementType === CommerceElementTypeHelper::productElementType() ? 'product' : 'variant', $results[0]['type']);
            self::assertArrayNotHasKey('_elementType', SearchHitPresenter::present($results[0]));
            $results[0]['_queryRuleDebug'] = ['boosts' => [['ruleId' => 123]]];
            self::assertArrayNotHasKey('_queryRuleDebug', SearchHitPresenter::present($results[0]));
            self::assertArrayHasKey('_queryRuleDebug', SearchHitPresenter::present($results[0], true));
        }

        self::assertTrue(true);
    }

    public function testVariantPromotionMatchesWhenVariantEnabledAndProductLive(): void
    {
        if (!CommerceElementTypeHelper::variantElementTypeAvailable()) {
            self::assertTrue(true);
            return;
        }

        $variant = $this->findPromotionLiveCommerceElement(CommerceElementTypeHelper::variantElementType());
        if ($variant === null) {
            self::assertTrue(true);
            return;
        }

        $query = 'variant promotion live regression ' . bin2hex(random_bytes(4));
        $promotion = new Promotion();
        $promotion->title = 'Variant promotion live regression';
        $promotion->query = $query;
        $promotion->matchType = 'exact';
        $promotion->indexHandle = 'commerce-variants';
        $promotion->elementId = (int)$variant->id;
        $promotion->elementType = CommerceElementTypeHelper::variantElementType();
        $promotion->position = 1;
        $promotion->siteId = (int)$variant->siteId;

        self::assertTrue($promotion->save(), implode('; ', $promotion->getFirstErrors()));

        try {
            $matches = Promotion::findMatching($query, 'commerce-variants', (int)$variant->siteId);

            self::assertCount(1, $matches);
            self::assertSame((int)$promotion->id, (int)$matches[0]->id);
        } finally {
            $promotion->delete();
        }
    }

    public function testPromotedCommerceTargetsHydrateFromExplicitElementTypeContext(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $element = $this->findLiveCommerceElement($elementType);
            if ($element === null) {
                continue;
            }

            $results = SearchManager::$plugin->liveComparison->compareHits(
                [[
                    'id' => (int)$element->id,
                    'siteId' => (int)$element->siteId,
                    '_index' => '__sm_wrong_or_missing_index',
                    '_elementType' => $elementType,
                    'promoted' => true,
                    'type' => $elementType === CommerceElementTypeHelper::productElementType() ? 'product' : 'variant',
                ]],
                [],
                ['siteId' => (int)$element->siteId],
            );

            self::assertCount(1, $results);
            self::assertSame((int)$element->id, $results[0]['id']);
            self::assertTrue($results[0]['promoted']);
            self::assertSame($elementType === CommerceElementTypeHelper::productElementType() ? 'product' : 'variant', $results[0]['type']);
            self::assertSame(true, $results[0]['_liveComparison']['elementFound'] ?? null);
            self::assertSame($elementType === CommerceElementTypeHelper::productElementType() ? 'product' : 'variant', $results[0]['_liveComparison']['type'] ?? null);
        }

        self::assertTrue(true);
    }

    public function testActiveUserPromotionMatchesSiteIndependently(): void
    {
        $user = User::find()->status(User::STATUS_ACTIVE)->one();
        if (!$user instanceof User) {
            self::markTestSkipped('No active user available for user promotion coverage.');
        }

        $query = 'active user promotion regression ' . bin2hex(random_bytes(4));
        $promotion = new Promotion();
        $promotion->title = 'Active user promotion regression';
        $promotion->query = $query;
        $promotion->matchType = 'exact';
        $promotion->indexHandle = 'users';
        $promotion->elementId = (int)$user->id;
        $promotion->elementType = User::class;
        $promotion->position = 1;

        self::assertTrue($promotion->save(), implode('; ', $promotion->getFirstErrors()));

        try {
            $matches = Promotion::findMatching($query, 'users', (int)Craft::$app->getSites()->getPrimarySite()->id);

            self::assertCount(1, $matches);
            self::assertSame((int)$promotion->id, (int)$matches[0]->id);
        } finally {
            $promotion->delete();
        }
    }

    public function testActiveUserPromotionInjectsPromotedHit(): void
    {
        $user = User::find()->status(User::STATUS_ACTIVE)->one();
        if (!$user instanceof User) {
            self::markTestSkipped('No active user available for user promotion insertion coverage.');
        }

        $promotion = new Promotion();
        $promotion->id = 2147483001;
        $promotion->title = 'Active user promotion insertion regression';
        $promotion->query = 'user insertion';
        $promotion->matchType = 'exact';
        $promotion->elementId = (int)$user->id;
        $promotion->elementType = User::class;
        $promotion->position = 1;

        $results = SearchManager::$plugin->promotions->applyPromotions(
            [['id' => 2147483000, 'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id, 'score' => 1.0]],
            'user insertion',
            'users',
            (int)Craft::$app->getSites()->getPrimarySite()->id,
            [$promotion],
        );

        self::assertSame((int)$user->id, $results[0]['id']);
        self::assertSame('user', $results[0]['type']);
        self::assertTrue($results[0]['promoted']);
        self::assertNotSame('', $results[0]['title']);
        self::assertArrayNotHasKey('section', $results[0]);
    }

    public function testAssetPromotionInjectsVolumeMetadataWithoutSection(): void
    {
        $asset = Asset::find()->status(Element::STATUS_ENABLED)->one();
        if (!$asset instanceof Asset) {
            self::markTestSkipped('No asset available for asset promotion insertion coverage.');
        }

        $promotion = new Promotion();
        $promotion->id = 2147483002;
        $promotion->title = 'Asset promotion insertion regression';
        $promotion->query = 'asset insertion';
        $promotion->matchType = 'exact';
        $promotion->elementId = (int)$asset->id;
        $promotion->elementType = Asset::class;
        $promotion->position = 1;
        $promotion->siteId = (int)$asset->siteId;

        $results = SearchManager::$plugin->promotions->applyPromotions(
            [['id' => 2147483000, 'siteId' => (int)$asset->siteId, 'score' => 1.0]],
            'asset insertion',
            'assets',
            (int)$asset->siteId,
            [$promotion],
        );

        self::assertSame((int)$asset->id, $results[0]['id']);
        self::assertSame('asset', $results[0]['type']);
        self::assertSame($asset->getVolume()->name, $results[0]['volume'] ?? null);
        self::assertSame($asset->getVolume()->handle, $results[0]['volumeHandle'] ?? null);
        self::assertArrayNotHasKey('section', $results[0]);
    }

    public function testCategoryPromotionInjectsGroupMetadataWithoutSection(): void
    {
        $category = Category::find()->status(Element::STATUS_ENABLED)->one();
        if (!$category instanceof Category) {
            self::markTestSkipped('No category available for category promotion insertion coverage.');
        }

        $promotion = new Promotion();
        $promotion->id = 2147483003;
        $promotion->title = 'Category promotion insertion regression';
        $promotion->query = 'category insertion';
        $promotion->matchType = 'exact';
        $promotion->elementId = (int)$category->id;
        $promotion->elementType = Category::class;
        $promotion->position = 1;
        $promotion->siteId = (int)$category->siteId;

        $results = SearchManager::$plugin->promotions->applyPromotions(
            [['id' => 2147483000, 'siteId' => (int)$category->siteId, 'score' => 1.0]],
            'category insertion',
            'categories',
            (int)$category->siteId,
            [$promotion],
        );

        self::assertSame((int)$category->id, $results[0]['id']);
        self::assertSame('category', $results[0]['type']);
        self::assertSame($category->getGroup()->name, $results[0]['group'] ?? null);
        self::assertSame($category->getGroup()->handle, $results[0]['groupHandle'] ?? null);
        self::assertArrayNotHasKey('section', $results[0]);
    }

    public function testQueryRuleBoostElementAcceptsProductAndVariantTargetTypes(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $rule = new QueryRule();
            $rule->name = 'Commerce boost';
            $rule->matchValue = 'product';
            $rule->actionType = QueryRule::ACTION_BOOST_ELEMENT;
            $rule->actionValue = [
                'elementId' => 123,
                'elementType' => $elementType,
                'multiplier' => 2.0,
            ];

            self::assertTrue($rule->validate(['actionValue']), implode('; ', $rule->getErrors('actionValue')));
        }
    }

    public function testQueryRuleBoostElementAppliesToCommerceProductAndVariantHitsAtRuntime(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $element = $this->findLiveCommerceElement($elementType);
            if ($element === null) {
                continue;
            }

            $service = new class((int)$element->id) extends QueryRuleService {
                public function __construct(private int $boostedElementId)
                {
                    parent::__construct();
                }

                public function getBoostMultipliers(
                    string $query,
                    ?string $indexHandle = null,
                    ?int $siteId = null,
                    ?array $matchedRules = null,
                ): array {
                    return [
                        'sections' => [],
                        'categories' => [],
                        'elements' => [$this->boostedElementId => 4.0],
                    ];
                }
            };

            $results = $service->applyBoosts([
                [
                    'id' => (int)$element->id,
                    'siteId' => (int)$element->siteId,
                    '_elementType' => $elementType,
                    'score' => 2.0,
                ],
                [
                    'id' => 2147483000,
                    'siteId' => (int)$element->siteId,
                    'score' => 6.0,
                ],
            ], 'commerce runtime', 'products', (int)$element->siteId);

            self::assertSame((int)$element->id, $results[0]['id']);
            self::assertSame(8.0, $results[0]['score']);
            self::assertTrue($results[0]['boosted']);
        }

        self::assertTrue(true);
    }

    public function testQueryRuleRedirectAcceptsProductAndVariantTargetTypes(): void
    {
        foreach (CommerceElementTypeHelper::availableElementTypes() as $elementType) {
            $rule = new QueryRule();
            $rule->name = 'Commerce redirect';
            $rule->matchValue = 'product';
            $rule->actionType = QueryRule::ACTION_REDIRECT;
            $rule->actionValue = [
                'elementId' => 123,
                'elementType' => $elementType,
            ];

            self::assertTrue($rule->validate(['actionValue']), implode('; ', $rule->getErrors('actionValue')));
        }
    }

    public function testExistingEntryAssetCategoryUserValidationRemainsSupported(): void
    {
        foreach ([Entry::class, Asset::class, Category::class, User::class] as $elementType) {
            self::assertTrue(TargetElementTypeHelper::isSupportedElementType($elementType));

            $rule = new QueryRule();
            $rule->name = 'Core target';
            $rule->matchValue = 'target';
            $rule->actionType = QueryRule::ACTION_REDIRECT;
            $rule->actionValue = [
                'elementId' => 123,
                'elementType' => $elementType,
            ];

            self::assertTrue($rule->validate(['actionValue']));
        }
    }

    public function testCpTemplatesUseSharedTargetOptions(): void
    {
        $promotions = $this->readPluginFile('src/templates/promotions/edit.twig');
        $queryRules = $this->readPluginFile('src/templates/query-rules/edit.twig');

        self::assertStringContainsString('targetTypeOptions', $promotions);
        self::assertStringContainsString('targetTypeOptions', $queryRules);
        self::assertStringNotContainsString("elementType: 'craft\\\\elements\\\\Entry'", $promotions);
        self::assertStringNotContainsString("elementType: 'craft\\\\elements\\\\Entry'", $queryRules);
        self::assertStringNotContainsString("elementTypeMap = [", $this->readPluginFile('src/controllers/QueryRulesController.php'));
    }

    public function testSourceGuardKeepsCommerceAvailabilityInCommerceHelper(): void
    {
        $paths = [
            'src/controllers/PromotionsController.php',
            'src/controllers/QueryRulesController.php',
            'src/templates/promotions/edit.twig',
            'src/templates/query-rules/edit.twig',
            'src/helpers/TargetElementTypeHelper.php',
        ];

        foreach ($paths as $path) {
            $source = $this->readPluginFile($path);

            self::assertStringNotContainsString("isPluginEnabled('commerce')", $source, $path);
            self::assertStringNotContainsString('class_exists(', $source, $path);
            if ($path !== 'src/helpers/TargetElementTypeHelper.php') {
                self::assertStringNotContainsString('CommerceElementTypeHelper', $source, $path);
            }
        }

        $helperSource = $this->readPluginFile('src/helpers/CommerceElementTypeHelper.php');
        self::assertStringContainsString("PluginHelper::isPluginEnabled(self::COMMERCE_PLUGIN_HANDLE)", $helperSource);
        self::assertStringContainsString('class_exists(self::PRODUCT_ELEMENT_TYPE)', $helperSource);
        self::assertStringContainsString('class_exists(self::VARIANT_ELEMENT_TYPE)', $helperSource);
    }

    public function testPromotionLiveLookupUsesVariantProductStatus(): void
    {
        $helperSource = $this->readPluginFile('src/helpers/SearchElementAvailabilityHelper.php');
        $promotionSource = $this->readPluginFile('src/models/Promotion.php');
        $settingsSource = $this->readPluginFile('src/controllers/SettingsController.php');
        $promotionServiceSource = $this->readPluginFile('src/services/PromotionService.php');
        $liveComparisonSource = $this->readPluginFile('src/services/LiveComparisonService.php');
        $indexingSource = $this->readPluginFile('src/services/IndexingService.php');

        self::assertStringContainsString('CommerceElementTypeHelper::variantElementType()', $helperSource);
        self::assertStringContainsString('Element::STATUS_ENABLED', $helperSource);
        self::assertStringContainsString('->productStatus(self::liveStatusFor($elementClass))', $helperSource);
        self::assertStringContainsString('Entry::STATUS_LIVE', $helperSource);
        self::assertStringContainsString('User::STATUS_ACTIVE', $helperSource);
        self::assertStringContainsString('isSiteIndependent($elementClass)', $promotionSource);
        self::assertStringContainsString('isSiteIndependent($elementClass)', $settingsSource);
        self::assertStringContainsString("'siteIndependent' =>", $settingsSource);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($elementQuery, $elementClass)->all()', $promotionSource);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($elementQuery, $elementClass)->all()', $settingsSource);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($elementQuery, $elementClass)->all()', $promotionServiceSource);
        self::assertStringContainsString('SearchElementAvailabilityHelper::applyToQuery($query, $elementClass)->all()', $liveComparisonSource);
        self::assertStringContainsString('return SearchElementAvailabilityHelper::isSearchable($element);', $indexingSource);
        self::assertStringNotContainsString('PromotionLiveElementQueryHelper', $promotionSource . $settingsSource . $promotionServiceSource . $liveComparisonSource);
    }

    private function readPluginFile(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    private function findLiveCommerceElement(string $elementType): ?\craft\base\ElementInterface
    {
        $siteIds = Craft::$app->getSites()->getAllSiteIds();

        foreach ($siteIds as $siteId) {
            $element = $elementType::find()
                ->siteId((int)$siteId)
                ->status('live')
                ->one();

            if ($element instanceof \craft\base\ElementInterface) {
                return $element;
            }
        }

        return null;
    }

    private function findPromotionLiveCommerceElement(string $elementType): ?\craft\base\ElementInterface
    {
        $siteIds = Craft::$app->getSites()->getAllSiteIds();

        foreach ($siteIds as $siteId) {
            $query = $elementType::find()
                ->siteId((int)$siteId)
                ->limit(1);
            $element = SearchElementAvailabilityHelper::applyToQuery($query, $elementType)->one();

            if ($element instanceof \craft\base\ElementInterface) {
                return $element;
            }
        }

        return null;
    }
}
