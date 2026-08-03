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
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use lindemannrock\searchmanager\helpers\SearchIndexQueryHelper;
use craft\elements\User;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\Highlighter;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;

/**
 * Regression coverage for the display/UX audit batch (#414, #417, #418,
 * #420, #422, and #424).
 *
 * @since 5.54.0
 */
final class PromotionListHydrationTest extends TestCase
{
    public function testPromotionListingPreloadsTargetsUsingTheirStoredElementTypes(): void
    {
        $targets = [
            Asset::find()->status(null)->one(),
            Category::find()->status(null)->one(),
            User::find()->status(null)->one(),
        ];
        foreach ($targets as $target) {
            if (!$target instanceof ElementInterface) {
                self::markTestSkipped('Asset, category, and user fixtures are required for promotion-list coverage.');
            }
        }

        $promotions = [];
        foreach ($targets as $offset => $target) {
            $promotion = new Promotion();
            $promotion->id = 2147483100 + $offset;
            $promotion->elementId = (int)$target->id;
            $promotion->elementType = get_class($target);
            $promotion->siteId = $target instanceof User ? null : (int)$target->siteId;
            $promotions[] = $promotion;
        }

        $method = new \ReflectionMethod(PromotionsController::class, 'preloadPromotionElements');
        $method->setAccessible(true);
        $resolved = $method->invoke(new PromotionsController('promotions', SearchManager::$plugin), $promotions);

        foreach ($promotions as $offset => $promotion) {
            $element = $resolved[(int)$promotion->id] ?? null;
            self::assertInstanceOf(get_class($targets[$offset]), $element);
            self::assertSame((int)$targets[$offset]->id, (int)$element->id);
        }

        $controllerSource = $this->readPluginSource('src/controllers/PromotionsController.php');
        self::assertStringNotContainsString('getElementById(', $this->methodBody($controllerSource, 'preloadPromotionElements'));
        self::assertStringContainsString('->id(array_keys(', $this->methodBody($controllerSource, 'preloadPromotionElements'));
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
