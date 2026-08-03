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
final class StopWordConfigurationTest extends TestCase
{
    public function testStopWordSettingsListEveryShippedFileWithItsActualCount(): void
    {
        $source = $this->readPluginSource('src/templates/settings/language.twig');
        $expectedOrder = ['en', 'pt', 'it', 'es', 'fr', 'no', 'nl', 'sv', 'de', 'da', 'ja', 'ar'];
        $languageKeys = ['Portuguese', 'Italian', 'Norwegian', 'Dutch', 'Swedish', 'Danish', 'Japanese'];

        foreach ($expectedOrder as $code) {
            $words = require dirname(__DIR__, 2) . "/src/search/stopwords/{$code}.php";
            self::assertStringContainsString(
                "{code: '{$code}', name:",
                $source,
            );
            self::assertStringContainsString(
                "file: '{$code}.php', count: " . count($words),
                $source,
            );
        }

        $positions = array_map(
            static fn(string $code): int => (int)strpos($source, "{code: '{$code}', name:"),
            $expectedOrder,
        );
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);

        foreach (['en', 'de', 'fr', 'nl', 'es', 'ar', 'it', 'pt', 'ja', 'sv', 'da', 'no'] as $locale) {
            $translations = require dirname(__DIR__, 2) . "/src/translations/{$locale}/search-manager.php";
            foreach ($languageKeys as $languageKey) {
                self::assertArrayHasKey($languageKey, $translations, "Missing {$languageKey} in {$locale}");
                self::assertNotSame('', $translations[$languageKey], "Empty {$languageKey} in {$locale}");
            }
        }
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }
}
