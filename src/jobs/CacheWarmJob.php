<?php

namespace lindemannrock\searchmanager\jobs;

use Craft;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\SearchManager;

/**
 * Cache Warm Job
 *
 * Warms the search and autocomplete caches after an index rebuild
 * by pre-running popular queries from analytics.
 */
class CacheWarmJob extends BaseJob
{
    use LoggingTrait;

    /**
     * @var string The index handle to warm cache for
     */
    public string $indexHandle = '';

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    public function execute($queue): void
    {
        $settings = SearchManager::$plugin->getSettings();

        if (!$settings->enableCacheWarming) {
            $this->logDebug('Cache warming disabled, skipping', [
                'index' => $this->indexHandle,
            ]);
            return;
        }

        // Check if caching is enabled at all
        if (!$settings->enableCache && !$settings->enableAutocompleteCache) {
            $this->logDebug('All caching disabled, skipping cache warming', [
                'index' => $this->indexHandle,
            ]);
            return;
        }

        $queryCount = $settings->cacheWarmingQueryCount;

        $this->logInfo('Starting cache warming', [
            'index' => $this->indexHandle,
            'queryCount' => $queryCount,
        ]);

        // Get popular queries from analytics for this index
        $popularQueries = $this->getPopularQueries($this->indexHandle, $queryCount);

        if (empty($popularQueries)) {
            $this->logInfo('No popular queries found for cache warming', [
                'index' => $this->indexHandle,
            ]);
            return;
        }

        $warmedSearchCount = 0;
        $warmedAutocompleteCount = 0;
        $total = count($popularQueries);

        foreach ($popularQueries as $i => $queryData) {
            $query = $queryData['query'];
            $siteId = $queryData['siteId'];

            $this->setProgress($queue, $i / $total, Craft::t('search-manager', 'Warming cache: {query}', [
                'query' => mb_substr($query, 0, 30) . (mb_strlen($query) > 30 ? '...' : ''),
            ]));

            // Warm search cache
            if ($settings->enableCache) {
                try {
                    SearchManager::$plugin->backend->search($this->indexHandle, $query, [
                        'siteId' => $siteId,
                        'limit' => 20,
                        'skipAnalytics' => true, // Don't track warming as real searches
                    ]);
                    $warmedSearchCount++;
                } catch (\Throwable $e) {
                    $this->logWarning('Failed to warm search cache for query', [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Warm autocomplete cache with query prefixes
            if ($settings->enableAutocompleteCache && mb_strlen($query) >= $settings->autocompleteMinLength) {
                try {
                    // Warm with common prefixes (2, 3, 4 chars and full query)
                    $prefixes = $this->getQueryPrefixes($query, $settings->autocompleteMinLength);
                    foreach ($prefixes as $prefix) {
                        SearchManager::$plugin->autocomplete->suggest(
                            $prefix,
                            $this->indexHandle,
                            ['siteId' => $siteId]
                        );
                    }
                    $warmedAutocompleteCount++;
                } catch (\Throwable $e) {
                    $this->logWarning('Failed to warm autocomplete cache for query', [
                        'query' => $query,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logInfo('Cache warming completed', [
            'index' => $this->indexHandle,
            'queriesProcessed' => $total,
            'searchCacheWarmed' => $warmedSearchCount,
            'autocompleteCacheWarmed' => $warmedAutocompleteCount,
        ]);
    }

    /**
     * Get popular queries from analytics for the given index
     */
    private function getPopularQueries(string $indexHandle, int $limit): array
    {
        // Query the analytics table for popular queries
        $results = (new \craft\db\Query())
            ->select(['query', 'siteId', 'COUNT(*) as searchCount'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['indexHandle' => $indexHandle])
            ->andWhere(['not', ['query' => null]])
            ->andWhere(['not', ['query' => '']])
            ->groupBy(['query', 'siteId'])
            ->orderBy(['searchCount' => SORT_DESC])
            ->limit($limit)
            ->all();

        return $results;
    }

    /**
     * Get prefixes for autocomplete warming
     */
    private function getQueryPrefixes(string $query, int $minLength): array
    {
        $prefixes = [];
        $length = mb_strlen($query);

        // Add prefixes of increasing length
        for ($i = $minLength; $i <= min($length, 5); $i++) {
            $prefixes[] = mb_substr($query, 0, $i);
        }

        // Always include the full query if longer than 5 chars
        if ($length > 5) {
            $prefixes[] = $query;
        }

        return array_unique($prefixes);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('search-manager', 'Warming cache for index: {index}', [
            'index' => $this->indexHandle,
        ]);
    }
}
