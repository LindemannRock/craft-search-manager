<?php
/**
 * LindemannRock Search Manager
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
            'indexing' => ['autoIndex', 'queueEnabled', 'replaceNativeSearch', 'batchSize', 'lastIndexedDebounceSeconds', 'syncBatchSize', 'batchFlushInterval', 'pendingMaxAge', 'batchMaxAttempts', 'indexPrefix'],
            'analytics' => ['enableAnalytics', 'enableGeoDetection', 'geoProvider', 'geoApiKey', 'anonymizeIpAddress', 'analyticsRetention'],
            'search' => ['bm25K1', 'bm25B', 'titleBoostFactor', 'exactMatchBoostFactor', 'phraseBoostFactor', 'similarityThreshold', 'maxFuzzyCandidates', 'ngramSizes'],
            'language' => ['defaultLanguage', 'enableStopWords'],
            'highlighting' => ['enableHighlighting', 'highlightTag', 'highlightClass', 'snippetLength', 'maxSnippets', 'enableAutocomplete', 'autocompleteMinLength', 'autocompleteLimit', 'autocompleteFuzzy'],
            'cache' => ['cacheStorageMethod', 'enableCache', 'cacheDuration', 'cachePopularQueriesOnly', 'popularQueryThreshold', 'enableAutocompleteCache', 'autocompleteCacheDuration', 'clearCacheOnSave', 'statusSyncInterval', 'enableCacheWarming', 'cacheWarmingQueryCount', 'cacheDeviceDetection', 'deviceDetectionCacheDuration'],
            'interface' => ['itemsPerPage', 'timeFormat', 'monthFormat', 'dateOrder', 'dateSeparator', 'showSeconds', 'defaultDateRange', 'exportsCsv', 'exportsJson', 'exportsExcel'],
        ];

        foreach ($expected as $section => $attributes) {
            self::assertSame($attributes, $method->invoke($controller, $section), "Unexpected {$section} settings scope.");
        }
    }
}
