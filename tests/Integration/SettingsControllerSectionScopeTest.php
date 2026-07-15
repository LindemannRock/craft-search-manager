<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.47.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerSectionScopeTest extends TestCase
{
    public function testSettingsSectionsMatchRenderedFormScopes(): void
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $expected = [
            'general' => ['pluginName', 'defaultBackendHandle', 'defaultWidgetHandle', 'requireApiKey', 'logLevel'],
            'indexing' => ['autoIndex', 'batchSize', 'lastIndexedDebounceSeconds', 'syncBatchSize', 'batchFlushInterval', 'pendingMaxAge', 'batchMaxAttempts', 'indexPrefix'],
            'analytics' => ['enableAnalytics', 'enableGeoDetection', 'geoProvider', 'geoApiKey', 'anonymizeIpAddress', 'analyticsRetention'],
            'search' => ['replaceNativeSearch', 'bm25K1', 'bm25B', 'titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor', 'enableFuzzy', 'similarityThreshold', 'maxFuzzyCandidates', 'ngramSizes'],
            'language' => ['defaultLanguage', 'enableStopWords'],
            'autocomplete' => ['enableAutocomplete', 'autocompleteMinLength', 'autocompleteLimit'],
            'highlighting' => ['highlightResultsEnabled', 'highlightTag', 'highlightClass'],
            'snippets' => ['snippetMaxLength', 'maxSnippets'],
            'cache' => ['cacheStorageMethod', 'enableCache', 'cacheDuration', 'enableAutocompleteCache', 'autocompleteCacheDuration', 'clearCacheOnSave', 'statusSyncInterval', 'enableCacheWarming', 'cacheWarmingQueryCount', 'cacheDeviceDetection', 'deviceDetectionCacheDuration'],
            'interface' => ['itemsPerPage', 'timeFormat', 'monthFormat', 'dateOrder', 'dateSeparator', 'showSeconds', 'defaultDateRange', 'exportsCsv', 'exportsJson', 'exportsExcel'],
        ];

        foreach ($expected as $section => $attributes) {
            self::assertSame($attributes, $method->invoke($controller, $section), "Unexpected {$section} settings scope.");
        }
    }

    public function testReplaceNativeSearchLivesOnSearchSettingsSectionOnly(): void
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        self::assertContains('replaceNativeSearch', $method->invoke($controller, 'search'));
        self::assertNotContains('replaceNativeSearch', $method->invoke($controller, 'indexing'));
    }

    public function testSnippetTemplateHelperSettingsLiveOnSnippetsSectionOnly(): void
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        self::assertSame(['snippetMaxLength', 'maxSnippets'], $method->invoke($controller, 'snippets'));
        self::assertNotContains('snippetMaxLength', $method->invoke($controller, 'highlighting'));
        self::assertNotContains('maxSnippets', $method->invoke($controller, 'highlighting'));
    }

    public function testSnippetTemplateHelperSettingsPersistOnlyThroughSnippetsSection(): void
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $settings = Settings::loadFromDatabase();
        $settings->snippetMaxLength = 321;
        $settings->maxSnippets = 4;

        self::assertTrue($settings->saveToDatabase($method->invoke($controller, 'snippets')), print_r($settings->getErrors(), true));

        $reloaded = Settings::loadFromDatabase();
        self::assertSame(321, $reloaded->snippetMaxLength);
        self::assertSame(4, $reloaded->maxSnippets);

        $reloaded->snippetMaxLength = 777;
        $reloaded->maxSnippets = 9;
        $reloaded->highlightClass = 'section-scope-check';

        self::assertTrue($reloaded->saveToDatabase($method->invoke($controller, 'highlighting')), print_r($reloaded->getErrors(), true));

        $afterHighlightingSave = Settings::loadFromDatabase();
        self::assertSame(321, $afterHighlightingSave->snippetMaxLength);
        self::assertSame(4, $afterHighlightingSave->maxSnippets);
        self::assertSame('section-scope-check', $afterHighlightingSave->highlightClass);
    }

    public function testSnippetTemplateHelperSettingsMovedFromHighlightingToSnippets(): void
    {
        $highlighting = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/highlighting.twig');
        $snippets = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/snippets.twig');
        $settingsLayout = file_get_contents(dirname(__DIR__, 2) . '/src/templates/_layouts/settings.twig');
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/src/SearchManager.php');
        self::assertIsString($highlighting);
        self::assertIsString($snippets);
        self::assertIsString($settingsLayout);
        self::assertIsString($plugin);

        self::assertStringNotContainsString('<h3>{{ "Snippets"|t(\'search-manager\') }}</h3>', $highlighting);
        self::assertStringNotContainsString("name: 'settings[snippetMaxLength]'", $highlighting);
        self::assertStringNotContainsString("name: 'settings[maxSnippets]'", $highlighting);
        self::assertStringContainsString("{{ hiddenInput('section', 'snippets') }}", $snippets);
        self::assertStringContainsString('These control the craft.searchManager.snippets() template helper. Search-result snippets (widgets, API) are configured per widget or request.', $snippets);
        self::assertStringContainsString("name: 'settings[snippetMaxLength]'", $snippets);
        self::assertStringContainsString("name: 'settings[maxSnippets]'", $snippets);

        $highlightingNavPosition = strpos($settingsLayout, "url('search-manager/settings/highlighting')");
        $snippetsNavPosition = strpos($settingsLayout, "url('search-manager/settings/snippets')");
        self::assertIsInt($highlightingNavPosition);
        self::assertIsInt($snippetsNavPosition);
        self::assertLessThan($snippetsNavPosition, $highlightingNavPosition);

        $highlightingRoutePosition = strpos($plugin, "'search-manager/settings/highlighting' => 'search-manager/settings/highlighting'");
        $snippetsRoutePosition = strpos($plugin, "'search-manager/settings/snippets' => 'search-manager/settings/snippets'");
        self::assertIsInt($highlightingRoutePosition);
        self::assertIsInt($snippetsRoutePosition);
        self::assertLessThan($snippetsRoutePosition, $highlightingRoutePosition);
    }

    public function testReplaceNativeSearchTemplateBlockLivesAtBottomOfSearchAfterFuzzy(): void
    {
        $indexing = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/indexing.twig');
        $search = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/search.twig');
        self::assertIsString($indexing);
        self::assertIsString($search);

        self::assertStringNotContainsString("name: 'settings[replaceNativeSearch]'", $indexing);
        self::assertStringNotContainsString('Native Search Coverage', $indexing);
        self::assertStringNotContainsString('nativeSearchHasLocalBackend', $indexing);

        // The page leads with the ranking pipeline (BM25 → Boosts → Fuzzy);
        // Native Search Replacement is an opt-in integration and sits last.
        $nativeHeadingPosition = strpos($search, "<h2>{{ 'Native Search Replacement'|t('search-manager') }}</h2>");
        $replaceTogglePosition = strpos($search, "name: 'settings[replaceNativeSearch]'");
        $coveragePosition = strpos($search, 'Native Search Coverage');
        $bm25Position = strpos($search, '<h2 class="first">{{ "BM25 Ranking Algorithm"|t(\'search-manager\') }}</h2>');
        $fuzzyPosition = strpos($search, '{{ "Fuzzy Matching"|t(\'search-manager\') }}');

        self::assertIsInt($nativeHeadingPosition);
        self::assertIsInt($replaceTogglePosition);
        self::assertIsInt($coveragePosition);
        self::assertIsInt($bm25Position);
        self::assertIsInt($fuzzyPosition);
        self::assertLessThan($fuzzyPosition, $bm25Position);
        self::assertLessThan($nativeHeadingPosition, $fuzzyPosition);
        self::assertLessThan($replaceTogglePosition, $nativeHeadingPosition);
        self::assertLessThan($coveragePosition, $replaceTogglePosition);
        self::assertStringContainsString('nativeSearchHasLocalBackend', $search);
        self::assertStringContainsString('Replace Native Search requires a local backend', $search);
        self::assertStringContainsString('<th scope="col" class="lr-text-end">{{ \'Actions\'|t(\'search-manager\') }}</th>', $search);
        self::assertStringContainsString('<td class="lr-text-end">', $search);
        self::assertStringContainsString('<div class="buttons right">', $search);
        self::assertStringContainsString('class="btn native-search-create-catch-all"', $search);
        self::assertStringNotContainsString('class="btn small native-search-create-catch-all"', $search);
        self::assertStringContainsString('class="modal fitted native-search-catch-all-confirm"', $search);
        self::assertStringContainsString('max-width: 42rem;', $search);
        self::assertStringNotContainsString("<h2 class=\"first\">{{ 'Native Search Replacement'", $search);
    }

    public function testSetupCompleteInfoBoxUsesConfiguredPluginName(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/setup.twig');
        self::assertIsString($source);

        self::assertStringContainsString('{% set searchFullNameHtml = searchHelper.fullName|e %}', $source);
        self::assertStringContainsString('searchFullNameHtml: searchFullNameHtml,', $source);
        self::assertStringContainsString("'{pluginName} is ready to track search analytics.'|t('search-manager', {", $source);
        self::assertStringContainsString('pluginName: searchFullNameHtml', $source);
        self::assertStringNotContainsString("'Search Manager is ready to track search analytics.'|t('search-manager')", $source);
    }
}
