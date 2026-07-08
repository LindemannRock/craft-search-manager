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
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\TargetElementTypeHelper;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks Commerce Product/Variant target support for promotions and query rules.
 *
 * @since 5.53.0
 */
#[CoversClass(TargetElementTypeHelper::class)]
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

    private function readPluginFile(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
