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
use craft\elements\User;
use lindemannrock\searchmanager\controllers\PromotionsController;
use lindemannrock\searchmanager\helpers\SplitSectionDocumentHelper;
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
final class AuditDisplayUxBatchRegressionTest extends TestCase
{
    public function testExpectedAndStoredCountsUseDocumentUnitsInBothIndexModes(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $splitIndex = new SearchIndex([
            'handle' => '__sm_audit_414_split',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'transformerClass' => AutoTransformer::class,
            'headingLevels' => [2, 3],
            'splitSections' => true,
        ]);

        $candidate = null;
        $candidateDocuments = [];
        foreach (Entry::find()->siteId($siteId)->status(Entry::STATUS_LIVE)->limit(100)->all() as $entry) {
            $data = SearchManager::$plugin->transformers->transform(
                $entry,
                $splitIndex->handle,
                $splitIndex->transformerClass,
                $splitIndex->headingLevels,
            );
            if ($data === null) {
                continue;
            }

            $documents = SplitSectionDocumentHelper::documentsForIndex($splitIndex, $entry, $data);
            if (count($documents) > 1) {
                $candidate = $entry;
                $candidateDocuments = $documents;
                break;
            }
        }

        if (!$candidate instanceof Entry) {
            self::markTestSkipped('A live entry with split-section headings is required for count-unit coverage.');
        }

        $criteria = static fn($query) => $query->id((int)$candidate->id);
        $splitIndex->criteria = $criteria;

        $pageIndex = new SearchIndex([
            'handle' => '__sm_audit_414_page',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'criteria' => $criteria,
            'transformerClass' => AutoTransformer::class,
            'headingLevels' => [2, 3],
            'splitSections' => false,
        ]);

        self::assertSame(1, $pageIndex->getExpectedCount());
        self::assertSame(count($candidateDocuments), $splitIndex->getExpectedCount());

        $rebuildSource = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        self::assertStringContainsString(
            '$totalIndexedDocuments += $batchResult[\'acceptedDocumentCount\']',
            $rebuildSource,
        );
        self::assertStringContainsString('$index->updateStats($totalIndexedDocuments)', $rebuildSource);
    }

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

    public function testSnippetMinimumUsesCharacterLength(): void
    {
        $text = str_repeat('a', 250) . '東';

        self::assertSame(
            [str_repeat('a', 40) . '...'],
            (new Highlighter(['snippetMaxLength' => 40]))->generateSnippets($text, ['東']),
        );
    }

    public function testBackendCollisionSelectorUsesTheHandleColumnIdentity(): void
    {
        $source = $this->readPluginSource('src/templates/backends/index.twig');

        self::assertStringContainsString("td[data-column=\"handle\"] code", $source);
        self::assertStringNotContainsString("td:nth-child(3) code", $source);
    }

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

    public function testExecutableTwigJavascriptValuesUseJsonEncoding(): void
    {
        $paths = [
            'src/templates/indices/edit.twig',
            'src/templates/indices/view.twig',
            'src/templates/settings/analytics.twig',
            'src/templates/utilities/index.twig',
        ];

        $executableOccurrences = [];
        foreach ($paths as $path) {
            foreach (preg_split('/\R/', $this->readPluginSource($path)) ?: [] as $lineNumber => $line) {
                if (str_contains($line, "|e('js')") && !str_starts_with(trim($line), '//')) {
                    $executableOccurrences[] = $path . ':' . ($lineNumber + 1);
                }
            }
        }

        self::assertSame([], $executableOccurrences);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/private function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
