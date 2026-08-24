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
use craft\errors\MissingComponentException;
use craft\web\Request;
use craft\web\Response;
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
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function tearDown(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        parent::tearDown();
    }

    public function testSettingsSectionsMatchRenderedFormScopes(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_PRO);
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

    public function testIndexingSettingsPreserveTheBatchSyncAndIndexNamingLayout(): void
    {
        $indexing = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/indexing.twig');
        self::assertIsString($indexing);

        self::assertStringContainsString(
            "instructions: 'Number of elements processed at a time during a full index rebuild.'|t('search-manager')",
            $indexing,
        );
        self::assertStringContainsString(
            "instructions: 'Maximum number of pending element changes processed in each sync chunk.'|t('search-manager')",
            $indexing,
        );
        self::assertStringContainsString("<h3>{{ 'Batch Sync'|t('search-manager') }}</h3>", $indexing);
        self::assertMatchesRegularExpression(
            "/<hr>\\s*<h3>{{ 'Index Naming'\\|t\\('search-manager'\\) }}<\\/h3>\\s*{{ forms\\.textField\\(\\{\\s*label: 'Index Prefix'\\|t\\('search-manager'\\),/",
            $indexing,
        );
        self::assertStringContainsString("name: 'settings[lastIndexedDebounceSeconds]'", $indexing);
        self::assertDoesNotMatchRegularExpression('/{%\\s*if\\s+(?:not\\s+)?settings\\.autoIndex/', $indexing);
    }

    public function testAutocompleteSectionPostPersistsAllAutocompleteSettings(): void
    {
        $user = $this->createTestUser('__sm_autocomplete_settings_user_', ['admin' => true]);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageSettings']);
        $this->actingAs($user);
        $this->withPost([
            'section' => 'autocomplete',
            'settings' => [
                'enableAutocomplete' => false,
                'autocompleteMinLength' => 4,
                'autocompleteLimit' => 37,
            ],
        ]);

        try {
            (new SettingsController('settings', SearchManager::$plugin))->actionSave();
        } catch (MissingComponentException $e) {
            // The integration harness boots Craft as a console app, so the
            // success notice cannot access a web session after persistence.
            self::assertSame('Session does not exist in a console request.', $e->getMessage());
        }

        $settings = Settings::loadFromDatabase();
        self::assertFalse($settings->enableAutocomplete);
        self::assertSame(4, $settings->autocompleteMinLength);
        self::assertSame(37, $settings->autocompleteLimit);
    }

    public function testCacheWarmingSettingsAreExcludedFromStandardSaves(): void
    {
        $this->forcePluginEdition(SearchManager::EDITION_STANDARD);
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $attributes = $method->invoke($controller, 'cache');

        self::assertContains('enableCache', $attributes);
        self::assertContains('statusSyncInterval', $attributes);
        self::assertContains('cacheDeviceDetection', $attributes);
        self::assertNotContains('enableCacheWarming', $attributes);
        self::assertNotContains('cacheWarmingQueryCount', $attributes);
    }

    public function testCacheWarmingFieldsAreHiddenWithoutAnInlineUpgradePrompt(): void
    {
        $cache = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/cache.twig');
        self::assertIsString($cache);

        self::assertStringContainsString('{% set isPro = craft.searchManager.plugin.isPro() %}', $cache);
        self::assertStringContainsString('{% if isPro %}', $cache);
        self::assertStringNotContainsString("featureName: 'Cache Warming'", $cache);
        self::assertStringNotContainsString("'lindemannrock-base/_partials/edition-upgrade-prompt'", $cache);
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

    /**
     * @param array<string, mixed> $params
     */
    private function withPost(array $params): void
    {
        $this->originalRequest = Craft::$app->getRequest();
        $this->originalResponse = Craft::$app->getResponse();
        $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
    }
}
