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
            'search' => ['replaceNativeSearch', 'bm25K1', 'bm25B', 'titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor', 'similarityThreshold', 'maxFuzzyCandidates', 'ngramSizes'],
            'language' => ['defaultLanguage', 'enableStopWords'],
            'highlighting' => ['highlightResultsEnabled', 'highlightTag', 'highlightClass', 'snippetMaxLength', 'maxSnippets', 'enableAutocomplete', 'autocompleteMinLength', 'autocompleteLimit', 'autocompleteFuzzy'],
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

    public function testReplaceNativeSearchTemplateBlockMovedFromIndexingToTopOfSearch(): void
    {
        $indexing = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/indexing.twig');
        $search = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/search.twig');
        self::assertIsString($indexing);
        self::assertIsString($search);

        self::assertStringNotContainsString("name: 'settings[replaceNativeSearch]'", $indexing);
        self::assertStringNotContainsString('Native Search Coverage', $indexing);
        self::assertStringNotContainsString('nativeSearchHasLocalBackend', $indexing);

        $nativeHeadingPosition = strpos($search, "class=\"first\">{{ 'Native Search Replacement'|t('search-manager') }}</h2>");
        $replaceTogglePosition = strpos($search, "name: 'settings[replaceNativeSearch]'");
        $coveragePosition = strpos($search, 'Native Search Coverage');
        $bm25Position = strpos($search, '<h2>{{ "BM25 Ranking Algorithm"|t(\'search-manager\') }}</h2>');

        self::assertIsInt($nativeHeadingPosition);
        self::assertIsInt($replaceTogglePosition);
        self::assertIsInt($coveragePosition);
        self::assertIsInt($bm25Position);
        self::assertLessThan($replaceTogglePosition, $nativeHeadingPosition);
        self::assertLessThan($coveragePosition, $replaceTogglePosition);
        self::assertLessThan($bm25Position, $coveragePosition);
        self::assertStringContainsString('nativeSearchHasLocalBackend', $search);
        self::assertStringContainsString('Replace Native Search requires a local backend', $search);
        self::assertStringContainsString('<th scope="col" class="lr-text-end">{{ \'Actions\'|t(\'search-manager\') }}</th>', $search);
        self::assertStringContainsString('<td class="lr-text-end">', $search);
        self::assertStringContainsString('<div class="buttons right">', $search);
        self::assertStringContainsString('class="btn native-search-create-catch-all"', $search);
        self::assertStringNotContainsString('class="btn small native-search-create-catch-all"', $search);
        self::assertStringContainsString('class="modal fitted native-search-catch-all-confirm"', $search);
        self::assertStringContainsString('max-width: 42rem;', $search);
        self::assertStringNotContainsString('<h2 class="first">{{ "BM25 Ranking Algorithm"', $search);
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
