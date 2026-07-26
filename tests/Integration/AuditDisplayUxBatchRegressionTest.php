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
final class AuditDisplayUxBatchRegressionTest extends TestCase
{
    public function testExpectedCountsUseEligibleElementUnitsWithoutTransformingSplitContent(): void
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

        $candidate = SearchIndexQueryHelper::buildSiteQueries($splitIndex)[$siteId]->one();
        if (!$candidate instanceof Entry) {
            self::markTestSkipped('A live entry is required for count-unit coverage.');
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
        self::assertSame(1, $splitIndex->getExpectedCount());

        $expectedCountBody = $this->methodBody(
            $this->readPluginSource('src/models/SearchIndex.php'),
            'getExpectedCount',
            'public',
        );
        self::assertStringNotContainsString('->each(', $expectedCountBody);
        self::assertStringNotContainsString('->transform(', $expectedCountBody);
        self::assertStringNotContainsString('SplitSectionDocumentHelper', $expectedCountBody);

        $rebuildSource = $this->readPluginSource('src/jobs/RebuildIndexJob.php');
        self::assertStringContainsString(
            '$totalIndexedDocuments += $batchResult[\'acceptedDocumentCount\']',
            $rebuildSource,
        );
        self::assertStringContainsString('$index->updateStats($totalIndexedDocuments)', $rebuildSource);
    }

    public function testSplitHealthDetectsMissingParentsButIgnoresSectionShapeDrift(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $splitIndex = new SearchIndex([
            'handle' => '__sm_audit_425_split',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'transformerClass' => AutoTransformer::class,
            'splitSections' => true,
            'documentCount' => 5,
        ]);
        $backend = $this->installStubBackend();

        $expected = $splitIndex->getExpectedCount();
        if ($expected === 0) {
            self::markTestSkipped('At least one eligible entry is required for split-health coverage.');
        }

        $backend->distinctParentCounts[$splitIndex->handle . ':' . $siteId] = $expected - 1;
        self::assertSame($expected - 1, $splitIndex->getComparisonCount(), 'A missing parent must make split coverage stale.');

        $backend->distinctParentCounts[$splitIndex->handle . ':' . $siteId] = $expected;
        self::assertSame(
            $splitIndex->getExpectedCount(),
            $splitIndex->getComparisonCount(),
            'Changed section counts must not make an otherwise-present parent stale.',
        );
        self::assertSame(5, $splitIndex->documentCount, 'Split documentCount must remain the true backend-document total.');

        $pageIndex = new SearchIndex([
            'handle' => '__sm_audit_425_page',
            'elementType' => Entry::class,
            'siteId' => [$siteId],
            'splitSections' => false,
            'documentCount' => 7,
        ]);
        self::assertSame(7, $pageIndex->getComparisonCount(), 'Page-mode comparison must remain document-based.');
    }

    public function testEveryShippedBackendUsesItsBoundedDistinctParentPrimitive(): void
    {
        $mysql = $this->readPluginSource('src/search/storage/MySqlStorage.php');
        $pgsql = $this->readPluginSource('src/search/storage/PostgreSqlStorage.php');
        $redis = $this->readPluginSource('src/search/storage/RedisStorage.php');
        $file = $this->readPluginSource('src/search/storage/FileStorage.php');
        $algolia = $this->readPluginSource('src/backends/AlgoliaBackend.php');
        $meilisearch = $this->readPluginSource('src/backends/MeilisearchBackend.php');
        $typesense = $this->readPluginSource('src/backends/TypesenseBackend.php');

        self::assertStringContainsString('COUNT(DISTINCT [[elementId]])', $mysql);
        self::assertStringContainsString('COUNT(DISTINCT [[elementId]])', $pgsql);
        self::assertStringContainsString("zCard(\$this->keyPrefix . 'elemindex:' . \$siteId)", $redis);
        self::assertStringContainsString("\$manifest = \$this->readManifest('counting distinct File index parents')", $file);
        self::assertStringNotContainsString("glob(\$this->basePath . '/elements/' . \$siteId . '_*.dat')", $file);

        self::assertStringContainsString("\$settingsUpdate['attributeForDistinct'] = 'elementId'", $algolia);
        self::assertStringContainsString("\$params['distinct'] = 1", $algolia);
        self::assertStringContainsString("'exhaustiveNbHits' => true", $algolia);

        self::assertStringContainsString("\$searchParams['distinct'] = 'elementId'", $meilisearch);
        self::assertStringContainsString("'hitsPerPage' => 0", $meilisearch);
        self::assertStringContainsString('$documentCount > $maxTotalHits', $meilisearch);

        self::assertStringContainsString("'group_by' => 'elementId'", $typesense);
        self::assertStringContainsString("'group_max_candidates' => \$documentCount", $typesense);
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
        $templateRoot = dirname(__DIR__, 2) . '/src/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateRoot));
        $unsafeOccurrences = [];
        $javascriptTemplates = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (!preg_match('/{%\s*js\b|<script\b/i', $source)) {
                continue;
            }

            $relativePath = 'src/templates/' . substr($file->getPathname(), strlen($templateRoot) + 1);
            $javascriptTemplates[] = $relativePath;
            foreach ([
                '/{%\s*js\b[^%]*%}(.*?){%\s*endjs\s*%}/si',
                '/<script\b[^>]*>(.*?)<\/script>/si',
            ] as $contextPattern) {
                preg_match_all($contextPattern, $source, $contexts, PREG_OFFSET_CAPTURE);
                foreach ($contexts[1] ?? [] as [$context, $contextOffset]) {
                    preg_match_all('/{{.*?}}/s', $context, $outputs, PREG_OFFSET_CAPTURE);
                    foreach ($outputs[0] ?? [] as [$output, $outputOffset]) {
                        if (!preg_match('/\|\s*json_encode\s*\|\s*raw\b/', $output)) {
                            $line = substr_count(substr($source, 0, $contextOffset + $outputOffset), "\n") + 1;
                            $unsafeOccurrences[] = $relativePath . ':' . $line . ' ' . trim($output);
                        }
                    }
                }
            }
        }

        sort($javascriptTemplates);
        self::assertNotEmpty($javascriptTemplates);
        self::assertSame([], $unsafeOccurrences);

        $hostileValue = "Can't close \"the string\"\n</script><script>alert(1)</script>";
        $encoded = Craft::$app->getView()->renderString(
            '{{ value|json_encode|raw }}',
            ['value' => $hostileValue],
        );
        self::assertSame($hostileValue, json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('</script>', $encoded);
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
