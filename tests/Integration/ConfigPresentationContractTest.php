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
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit Batch 7.
 *
 * @since 5.53.0
 */
final class ConfigPresentationContractTest extends TestCase
{
    public function testRawConfigDisplayFormatsActualClassAndGuardsMissingClasses(): void
    {
        $method = new \ReflectionMethod(SearchIndex::class, 'formatClassConfigValue');
        $method->setAccessible(true);

        self::assertSame('\\craft\\elements\\Entry::class', $method->invoke(null, \craft\elements\Entry::class));
        self::assertSame(
            '\'lindemannrock\\\\missing\\\\Element\'',
            $method->invoke(null, 'lindemannrock\\missing\\Element'),
        );
    }

    public function testRawConfigDisplayEscapesQuotedStringValuesThroughSharedFormatter(): void
    {
        $originalConfigCache = $this->configCache();

        try {
            $name = "Editor's \\ Picks";
            $transformer = "Vendor\\Editor'sTransformer";
            $language = "en'custom";
            $this->withConfigFileIndices([
                'quoted-config-index' => [
                    'name' => $name,
                    'elementType' => \craft\elements\Entry::class,
                    'transformer' => $transformer,
                    'language' => $language,
                    'enabled' => true,
                ],
            ]);

            $index = new SearchIndex();
            $index->handle = 'quoted-config-index';
            $index->source = 'config';
            $display = $index->getRawConfigDisplay();

            self::assertNotNull($display);
            self::assertStringContainsString("'name' => " . var_export($name, true), $display);
            self::assertStringContainsString("'transformer' => " . var_export($transformer, true), $display);
            self::assertStringContainsString("'language' => " . var_export($language, true), $display);
        } finally {
            $this->setConfigCache($originalConfigCache);
            SearchIndex::clearCache();
        }
    }

    public function testSimilarityThresholdInstallAndTemplateDefaultsUseCanonicalRuntimeValue(): void
    {
        $install = $this->readPluginFile('src/migrations/Install.php');
        $template = $this->readPluginFile('src/templates/settings/search.twig');
        $termResolver = $this->readPluginFile('src/search/TermResolver.php');

        self::assertStringContainsString("'similarityThreshold' => \$this->decimal(3, 2)->notNull()->defaultValue(0.25)", $install);
        self::assertStringContainsString("'similarityThreshold' => 0.25", $install);
        self::assertStringContainsString('value: settings.similarityThreshold ?? 0.25', $template);
        self::assertStringContainsString('Default: 0.25 (typo-tolerant). Lower = more typo tolerance but more false positives; higher = stricter matching.', $template);
        self::assertStringNotContainsString('Default: 0.50 (balanced)', $template);
        self::assertStringContainsString('Base fuzzy threshold (default 0.25)', $termResolver);
        self::assertStringContainsString("\$config['similarityThreshold'] ?? 0.25", $termResolver);
        self::assertStringNotContainsString("\$config['similarityThreshold'] ?? 0.50", $termResolver);
    }

    public function testFuzzyControlGuidanceMatchesRuntimeDefaultsAndPrecisionPolicy(): void
    {
        $template = $this->readPluginFile('src/templates/settings/search.twig');
        $settings = new \lindemannrock\searchmanager\models\Settings();

        self::assertTrue($settings->enableFuzzy);
        self::assertSame('2,3', $settings->ngramSizes);
        self::assertSame(0.25, $settings->similarityThreshold);
        self::assertSame(100, $settings->maxFuzzyCandidates);

        self::assertStringContainsString('Every selected size contributes to one combined score.', $template);
        self::assertStringContainsString('Recommended: 2 + 3.', $template);
        self::assertStringContainsString('"arae" → "area"', $template);
        self::assertStringContainsString('cannot override the similarity threshold or typo budget', $template);
        self::assertStringContainsString('Rebuild affected indices after changing this setting.', $template);
        self::assertStringContainsString("{% include 'lindemannrock-base/_components/info-box' with {", $template);
        self::assertStringContainsString("message: 'Recommended: 2 + 3.", $template);
        self::assertStringNotContainsString("tip: 'Recommended: 2 + 3.", $template);
        self::assertStringNotContainsString('"tst" can find "test"', $template);
        self::assertStringNotContainsString('More selections = better accuracy', $template);
    }

    public function testSearchSettingsTemplateCompilesAndTranslatedGuidanceIsComplete(): void
    {
        $twig = Craft::$app->getView()->getTwig();
        self::assertNotNull($twig->load('search-manager/settings/search'));

        $html = Craft::$app->getView()->renderString(
            "{{ 'Recommended: 2 + 3. 2-grams tolerate short terms and common typing errors; 3-grams balance recall and precision. Adding 4-grams increases precision but can reject short transpositions such as \"arae\" → \"area\". Rebuild affected indices after changing this setting.'|t('search-manager') }}",
        );

        self::assertStringContainsString('Recommended: 2 + 3.', $html);
        self::assertStringContainsString('"arae" → "area"', $html);
        self::assertStringNotContainsString('"tst" can find "test"', $html);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}
