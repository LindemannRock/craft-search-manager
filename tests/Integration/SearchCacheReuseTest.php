<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Local regression coverage for the backend search cache as used by public
 * API / widget searches (`/actions/search-manager/api/search` → BackendService).
 *
 * Anonymous API responses don't expose cache meta, so this asserts cache
 * create/reuse directly at the BackendService layer — exactly what
 * ApiController::actionSearch() calls. The widget and the direct API hit the
 * same method with the same cache-affecting options, so proving it here proves
 * it for both.
 *
 * Each test uses a nonsense marker query (matches no content, no query rule /
 * promotion / synonym) and `skipAnalytics` so it isolates pure cache behaviour
 * without writing analytics rows. Caching is forced on and popular-only off for
 * the duration; both are restored in tearDown.
 *
 * @since 5.47.0
 */
final class SearchCacheReuseTest extends TestCase
{
    private bool $originalEnableCache = true;
    private bool $originalPopularOnly = false;
    private ?string $indexHandle = null;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = $settings->enableCache;
        $this->originalPopularOnly = $settings->cachePopularQueriesOnly;
        $settings->enableCache = true;
        $settings->cachePopularQueriesOnly = false;

        // A real enabled index with a working backend, via the shared helper.
        $pair = $this->findWorkingIndexAndElement();
        $this->indexHandle = $pair !== null ? $pair[0]->handle : $this->firstEnabledIndexHandle();

        if ($this->indexHandle !== null) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = $this->originalEnableCache;
        $settings->cachePopularQueriesOnly = $this->originalPopularOnly;

        if ($this->indexHandle !== null) {
            SearchManager::$plugin->backend->clearAllSearchCache();
        }

        parent::tearDown();
    }

    private function firstEnabledIndexHandle(): ?string
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->enabled && SearchManager::$plugin->backend->getBackendForIndex($index->handle) !== null) {
                return $index->handle;
            }
        }

        return null;
    }

    private function requireIndex(): string
    {
        if ($this->indexHandle === null) {
            $this->markTestSkipped('No enabled index with a working backend available.');
        }

        // Sanity: caching must actually be enabled (no config override forcing it off).
        if (!SearchManager::$plugin->getSettings()->enableCache) {
            $this->markTestSkipped('enableCache is overridden off (config), cannot test cache behaviour.');
        }

        return $this->indexHandle;
    }

    private function markerQuery(): string
    {
        // Deterministic within a test, unique across runs; matches no real
        // content/rule/promotion so only cache behaviour is exercised.
        return '__smcachetest_' . StringHelper::UUID();
    }

    private function search(string $handle, string $query, array $options): array
    {
        // Every call skips analytics so the test never writes analytics rows.
        return SearchManager::$plugin->backend->search($handle, $query, $options + ['skipAnalytics' => true]);
    }

    // 1. Cache is written on first search and reused on the identical repeat.
    public function testIdenticalSearchCreatesThenReusesCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();
        $options = ['limit' => 10];

        $first = $this->search($handle, $query, $options);
        $this->assertFalse($first['meta']['cached'], 'first search must be a cache miss (and write the cache)');

        $second = $this->search($handle, $query, $options);
        $this->assertTrue($second['meta']['cached'], 'identical repeat search must hit the cache');
        $this->assertSame(0, $second['meta']['took'], 'cache hit reports took=0');
        $this->assertSame($first['total'], $second['total'], 'total unchanged on cache hit');
        $this->assertEquals($first['hits'], $second['hits'], 'hits unchanged on cache hit');
    }

    // 2a. Analytics / attribution-only options must NOT fragment the cache.
    public function testAnalyticsOnlyOptionsDoNotFragmentCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        $first = $this->search($handle, $query, [
            'limit' => 10,
            'source' => 'widget',
            'sessionId' => 'session-A',
        ]);
        $this->assertFalse($first['meta']['cached']);

        // Same result-affecting option (limit), different analytics/attribution
        // options → must reuse the same cache entry.
        $second = $this->search($handle, $query, [
            'limit' => 10,
            'source' => 'api',
            'sessionId' => 'session-B',
            'platform' => 'iOS 17.2',
            'appVersion' => '2.1.0',
            'apiKeyId' => 7,
            'apiKeyPrefix' => 'sm_pub_abcd1234',
            'apiKeyType' => 'public',
        ]);
        $this->assertTrue($second['meta']['cached'], 'analytics/attribution-only options must not fragment the cache');
    }

    // 2b. Result-affecting options MUST fragment the cache.
    public function testResultAffectingOptionsFragmentCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        $first = $this->search($handle, $query, ['limit' => 10]);
        $this->assertFalse($first['meta']['cached']);

        $differentLimit = $this->search($handle, $query, ['limit' => 25]);
        $this->assertFalse($differentLimit['meta']['cached'], 'changing limit must fragment the cache');

        $differentType = $this->search($handle, $query, ['limit' => 10, 'type' => 'entry']);
        $this->assertFalse($differentType['meta']['cached'], 'changing type must fragment the cache');
    }

    // 3. Widget-style option shape (hitsPerPage=100 enrich search) creates + reuses cache.
    public function testWidgetStyleSearchCreatesAndReusesCache(): void
    {
        $handle = $this->requireIndex();
        $query = $this->markerQuery();

        // The backend option shape ApiController::actionSearch() builds for a
        // widget enrich search. Snippet/enrich options (snippetMode, snippetLength,
        // parseMarkdownSnippets, enrich, ...) are consumed by EnrichmentService and
        // never reach backend->search(), so they cannot affect the cache key.
        $widgetOptions = [
            'limit' => 100,
            'offset' => 0,
            'page' => 0,
            'type' => null,
            'source' => 'frontend-widget',
        ];

        $first = $this->search($handle, $query, $widgetOptions);
        $this->assertFalse($first['meta']['cached'], 'first widget-style search must be a cache miss');

        $second = $this->search($handle, $query, $widgetOptions);
        $this->assertTrue($second['meta']['cached'], 'repeated widget-style search must hit the cache');
        $this->assertSame(0, $second['meta']['took']);
    }

    // 4. Widget equivalence: the full attribution/analytics set the widget +
    //    ApiController attach must not fragment the cache.
    public function testWidgetAttributionOptionsDoNotFragmentCache(): void
    {
        // SearchService.js::performSearch() always sends skipAnalytics=1 and an
        // optional X-Search-Manager-Key; ApiController::actionSearch() folds those
        // plus source/platform/appVersion/sessionId/key attribution into the
        // analytics options, which BackendService::_generateCacheKey() excludes
        // (source, platform, appVersion, skipAnalytics, sessionId, apiKeyId,
        // apiKeyPrefix, apiKeyType). Result-affecting options (limit) held equal.
        $handle = $this->requireIndex();
        $query = $this->markerQuery();
        $base = ['limit' => 100];

        $first = $this->search($handle, $query, $base);
        $this->assertFalse($first['meta']['cached']);

        $withAttribution = $this->search($handle, $query, $base + [
            'source' => 'frontend-widget',
            'platform' => 'Android 14',
            'appVersion' => '3.0.1',
            'sessionId' => StringHelper::UUID(),
            'apiKeyId' => 42,
            'apiKeyPrefix' => 'sm_pub_ffff0000',
            'apiKeyType' => 'public',
        ]);
        $this->assertTrue($withAttribution['meta']['cached'], 'widget attribution/analytics options must not fragment the cache');
    }
}
