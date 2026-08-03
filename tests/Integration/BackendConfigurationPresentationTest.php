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
final class BackendConfigurationPresentationTest extends TestCase
{
    public function testBackendCollisionSelectorUsesTheHandleColumnIdentity(): void
    {
        $source = $this->readPluginSource('src/templates/backends/index.twig');

        self::assertStringContainsString("td[data-column=\"handle\"] code", $source);
        self::assertStringNotContainsString("td:nth-child(3) code", $source);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }
}
