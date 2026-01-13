<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\backends\RedisBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Backend Service
 *
 * Manages search backend adapters and provides a unified interface
 */
class BackendService extends Component
{
    use LoggingTrait;

    private ?BackendInterface $_activeBackend = null;

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // BACKEND MANAGEMENT
    // =========================================================================

    /**
     * Get the active backend
     */
    public function getActiveBackend(): ?BackendInterface
    {
        if ($this->_activeBackend === null) {
            $this->_activeBackend = $this->createBackend();
        }

        return $this->_activeBackend;
    }

    /**
     * Create backend instance based on settings
     * Uses defaultBackendHandle to look up the configured backend
     */
    private function createBackend(): ?BackendInterface
    {
        $settings = SearchManager::$plugin->getSettings();
        $defaultHandle = $settings->defaultBackendHandle;

        $this->logDebug('Creating default backend instance', ['handle' => $defaultHandle]);

        // If no default handle configured, fall back to file backend
        if (!$defaultHandle) {
            $this->logWarning('No defaultBackendHandle configured, falling back to file backend');
            return new FileBackend();
        }

        // Look up the configured backend
        $configuredBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($defaultHandle);

        if ($configuredBackend && $configuredBackend->enabled) {
            $backend = $this->createBackendFromConfig($configuredBackend);
            if ($backend) {
                $this->logDebug('Using configured default backend', [
                    'handle' => $defaultHandle,
                    'backendType' => $configuredBackend->backendType,
                ]);
                return $backend;
            }
        }

        // Fallback: try treating defaultHandle as a backend type directly (backwards compatibility)
        $this->logDebug('Configured backend not found, trying as backend type', ['handle' => $defaultHandle]);

        try {
            $backend = match ($defaultHandle) {
                'algolia' => new AlgoliaBackend(),
                'file' => new FileBackend(),
                'meilisearch' => new MeilisearchBackend(),
                'mysql' => new MySqlBackend(),
                'pgsql' => new PostgreSqlBackend(),
                'redis' => new RedisBackend(),
                'typesense' => new TypesenseBackend(),
                default => null,
            };

            if ($backend && !$backend->isAvailable()) {
                $this->logWarning('Backend is not available', [
                    'backend' => $defaultHandle,
                    'status' => $backend->getStatus(),
                ]);
            }

            return $backend;
        } catch (\Throwable $e) {
            $this->logError('Failed to create backend', [
                'backend' => $defaultHandle,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get specific backend by name
     */
    public function getBackend(string $name): ?BackendInterface
    {
        try {
            return match ($name) {
                'algolia' => new AlgoliaBackend(),
                'file' => new FileBackend(),
                'meilisearch' => new MeilisearchBackend(),
                'mysql' => new MySqlBackend(),
                'pgsql' => new PostgreSqlBackend(),
                'redis' => new RedisBackend(),
                'typesense' => new TypesenseBackend(),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logError('Failed to get backend', [
                'backend' => $name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get all available backends
     */
    public function getAllBackends(): array
    {
        return [
            'algolia' => new AlgoliaBackend(),
            'file' => new FileBackend(),
            'meilisearch' => new MeilisearchBackend(),
            'mysql' => new MySqlBackend(),
            'pgsql' => new PostgreSqlBackend(),
            'redis' => new RedisBackend(),
            'typesense' => new TypesenseBackend(),
        ];
    }

    /**
     * Get backend for a specific index
     *
     * If the index has a configured backend override, uses that backend with its settings.
     * Otherwise falls back to the global default backend.
     *
     * @param string $indexName Index handle
     * @return BackendInterface|null
     */
    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        // Load the index to check for backend override
        $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexName);

        if ($index && $index->hasBackendOverride()) {
            // Load the configured backend by handle
            $configuredBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($index->backend);

            if ($configuredBackend && $configuredBackend->enabled) {
                $backend = $this->createBackendFromConfig($configuredBackend);
                if ($backend) {
                    $this->logDebug('Using configured backend for index', [
                        'index' => $indexName,
                        'configuredBackend' => $configuredBackend->handle,
                        'backendType' => $configuredBackend->backendType,
                    ]);
                    return $backend;
                }
            }

            // Configured backend not found or disabled - log warning and fall back
            $this->logWarning('Configured backend not available, falling back to default', [
                'index' => $indexName,
                'specifiedBackend' => $index->backend,
            ]);
        }

        // Fall back to global default
        return $this->getActiveBackend();
    }

    /**
     * Create a backend instance from a ConfiguredBackend model
     *
     * @param \lindemannrock\searchmanager\models\ConfiguredBackend $configuredBackend
     * @return BackendInterface|null
     */
    public function createBackendFromConfig(\lindemannrock\searchmanager\models\ConfiguredBackend $configuredBackend): ?BackendInterface
    {
        try {
            $backend = $this->getBackend($configuredBackend->backendType);

            if ($backend) {
                // Store the configured backend settings for this instance
                // The backend will use these settings instead of global config
                $backend->setConfiguredSettings($configuredBackend->settings);
            }

            return $backend;
        } catch (\Throwable $e) {
            $this->logError('Failed to create backend from config', [
                'configuredBackend' => $configuredBackend->handle,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get list of available backend options for UI dropdowns
     * Uses configured backends from the database
     *
     * @return array Array of [value => label] pairs
     */
    public function getBackendOptions(): array
    {
        return \lindemannrock\searchmanager\models\ConfiguredBackend::getSelectOptions(true);
    }

    // =========================================================================
    // PROXY METHODS (delegate to active backend)
    // =========================================================================

    public function index(string $indexName, array $data): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for indexing', ['index' => $indexName]);
            return false;
        }

        return $backend->index($indexName, $data);
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for batch indexing', ['index' => $indexName]);
            return false;
        }

        return $backend->batchIndex($indexName, $items);
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for deletion', ['index' => $indexName]);
            return false;
        }

        return $backend->delete($indexName, $elementId, $siteId);
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for document check', ['index' => $indexName]);
            return false;
        }

        return $backend->documentExists($indexName, $elementId, $siteId);
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for search', ['index' => $indexName]);
            return [];
        }

        // Handle "all sites" option - siteId of '*' or null/not set means search all
        // Check raw value BEFORE applying default
        $rawSiteId = $options['siteId'] ?? null;
        $searchAllSites = $rawSiteId === '*' || $rawSiteId === null;

        // Ensure siteId is in options for cache key generation
        if (!isset($options['siteId'])) {
            $options['siteId'] = \Craft::$app->getSites()->getCurrentSite()->id ?? 1;
        }
        $siteId = $searchAllSites ? null : $options['siteId'];
        $settings = SearchManager::$plugin->getSettings();

        // Extract analytics options from search options (API callers can pass these)
        $analyticsOptions = [
            'source' => $options['source'] ?? null,
            'platform' => $options['platform'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
        ];

        // =====================================================================
        // QUERY RULES: Get all matching rules for analytics
        // =====================================================================
        $matchedRules = \lindemannrock\searchmanager\models\QueryRule::findMatching($query, $indexName, $siteId);
        $matchedPromotions = \lindemannrock\searchmanager\models\Promotion::findMatching($query, $indexName, $siteId);

        // =====================================================================
        // QUERY RULES: Check for redirect first
        // =====================================================================
        $redirectUrl = SearchManager::$plugin->queryRules->getRedirectUrl($query, $indexName, $siteId);
        if ($redirectUrl) {
            $this->logDebug('Query rule redirect matched', [
                'query' => $query,
                'redirectUrl' => $redirectUrl,
            ]);

            // Track analytics for redirect (no search performed)
            SearchManager::$plugin->analytics->trackSearch(
                $indexName,
                $query,
                0, // No results
                0, // No execution time
                $backend->getName(),
                $siteId,
                array_merge($analyticsOptions, [
                    'synonymsExpanded' => false,
                    'rulesMatched' => count($matchedRules),
                    'promotionsShown' => 0,
                    'wasRedirected' => true,
                    'matchedRules' => $matchedRules,
                    'matchedPromotions' => [],
                ])
            );

            return [
                'hits' => [],
                'total' => 0,
                'redirect' => $redirectUrl,
            ];
        }

        // =====================================================================
        // QUERY RULES: Expand query with synonyms
        // =====================================================================
        $expandedQueries = SearchManager::$plugin->queryRules->expandWithSynonyms($query, $indexName, $siteId);
        $useSynonyms = count($expandedQueries) > 1;

        if ($useSynonyms) {
            $this->logDebug('Query expanded with synonyms', [
                'original' => $query,
                'expanded' => $expandedQueries,
            ]);
        }

        // 1. Check cache first (if caching enabled)
        // Note: Cache stores RAW backend results. Promotions are applied fresh each time
        // to ensure disabled/expired promotions are immediately excluded.
        if ($settings->enableCache) {
            $cached = $this->_getFromCache($indexName, $query, $options);
            if ($cached !== null) {
                // Apply promotions fresh (not from cache) so disabled promotions are excluded
                if (!empty($cached['hits'])) {
                    $cached['hits'] = SearchManager::$plugin->promotions->applyPromotions(
                        $cached['hits'],
                        $query,
                        $indexName,
                        $siteId
                    );
                }

                // Still track analytics for cached results
                SearchManager::$plugin->analytics->trackSearch(
                    $indexName,
                    $query,
                    $cached['total'] ?? 0,
                    0, // Cache hit = 0ms execution time
                    $backend->getName(), // Don't append "(cached)" - breaks analytics grouping
                    $siteId,
                    array_merge($analyticsOptions, [
                        'synonymsExpanded' => $useSynonyms,
                        'rulesMatched' => count($matchedRules),
                        'promotionsShown' => count($matchedPromotions),
                        'wasRedirected' => false,
                        'matchedRules' => $matchedRules,
                        'matchedPromotions' => $matchedPromotions,
                    ])
                );

                // Add metadata about rules and promotions (even for cached results)
                $cached['meta'] = [
                    'synonymsExpanded' => $useSynonyms,
                    'expandedQueries' => $useSynonyms ? $expandedQueries : [],
                    'rulesMatched' => array_map(function($rule) {
                        return [
                            'id' => $rule->id,
                            'name' => $rule->name,
                            'actionType' => $rule->actionType,
                            'actionValue' => $rule->actionValue,
                        ];
                    }, $matchedRules),
                    'promotionsMatched' => array_map(function($promo) {
                        return [
                            'id' => $promo->id,
                            'elementId' => $promo->elementId,
                            'position' => $promo->position,
                        ];
                    }, $matchedPromotions),
                ];

                return $cached;
            }
        }

        // 2. No cache - perform actual search
        $startTime = microtime(true);

        // If synonyms exist, search for all expanded queries and merge results
        if ($useSynonyms) {
            $results = $this->_searchWithSynonyms($backend, $indexName, $expandedQueries, $options);
        } else {
            $results = $backend->search($indexName, $query, $options);
        }

        // =====================================================================
        // QUERY RULES: Apply score boosts
        // =====================================================================
        if (!empty($results['hits'])) {
            $results['hits'] = SearchManager::$plugin->queryRules->applyBoosts(
                $results['hits'],
                $query,
                $indexName,
                $siteId
            );
        }

        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // 3. Cache RAW results (BEFORE promotions) so promotions can be applied fresh
        // This ensures disabled/expired promotions are immediately excluded
        if ($settings->enableCache) {
            if ($settings->cachePopularQueriesOnly) {
                // Check if query is popular enough
                $searchCount = $this->_getQuerySearchCount($query);
                if ($searchCount >= $settings->popularQueryThreshold) {
                    $this->_saveToCache($indexName, $query, $options, $results);
                } else {
                    $this->logDebug('Query not popular enough to cache', [
                        'query' => $query,
                        'count' => $searchCount,
                        'threshold' => $settings->popularQueryThreshold,
                    ]);
                }
            } else {
                // Cache everything
                $this->_saveToCache($indexName, $query, $options, $results);
            }
        }

        // =====================================================================
        // PROMOTIONS: Apply pinned/promoted results (AFTER caching)
        // Promotions are applied fresh each time, not cached, so disabled
        // promotions are immediately excluded from results.
        // =====================================================================
        if (!empty($results['hits'])) {
            $results['hits'] = SearchManager::$plugin->promotions->applyPromotions(
                $results['hits'],
                $query,
                $indexName,
                $siteId
            );
        }

        // 4. Track analytics
        SearchManager::$plugin->analytics->trackSearch(
            $indexName,
            $query,
            $results['total'] ?? 0,
            $executionTime,
            $backend->getName(),
            $siteId,
            array_merge($analyticsOptions, [
                'synonymsExpanded' => $useSynonyms,
                'rulesMatched' => count($matchedRules),
                'promotionsShown' => count($matchedPromotions),
                'wasRedirected' => false,
                'matchedRules' => $matchedRules,
                'matchedPromotions' => $matchedPromotions,
            ])
        );

        // 5. Add metadata about rules and promotions applied
        $results['meta'] = [
            'synonymsExpanded' => $useSynonyms,
            'expandedQueries' => $useSynonyms ? $expandedQueries : [],
            'rulesMatched' => array_map(function($rule) {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'actionType' => $rule->actionType,
                    'actionValue' => $rule->actionValue,
                ];
            }, $matchedRules),
            'promotionsMatched' => array_map(function($promo) {
                return [
                    'id' => $promo->id,
                    'elementId' => $promo->elementId,
                    'position' => $promo->position,
                ];
            }, $matchedPromotions),
        ];

        return $results;
    }

    /**
     * Search with synonym expansion - merges results from multiple queries
     *
     * @param BackendInterface $backend
     * @param string $indexName
     * @param array $queries Array of query strings (original + synonyms)
     * @param array $options
     * @return array Merged search results
     */
    private function _searchWithSynonyms(BackendInterface $backend, string $indexName, array $queries, array $options): array
    {
        $allHits = [];
        $seenElementIds = [];
        $total = 0;

        foreach ($queries as $searchQuery) {
            $queryResults = $backend->search($indexName, $searchQuery, $options);

            if (!empty($queryResults['hits'])) {
                foreach ($queryResults['hits'] as $hit) {
                    $elementId = $hit['objectID'] ?? $hit['elementId'] ?? null;

                    // Avoid duplicates - keep highest score
                    if ($elementId && !isset($seenElementIds[$elementId])) {
                        $seenElementIds[$elementId] = true;
                        $allHits[] = $hit;
                    } elseif ($elementId && isset($seenElementIds[$elementId])) {
                        // Find existing hit and update score if higher
                        foreach ($allHits as &$existingHit) {
                            if (($existingHit['objectID'] ?? $existingHit['elementId'] ?? null) === $elementId) {
                                $existingHit['score'] = max($existingHit['score'] ?? 0, $hit['score'] ?? 0);
                                break;
                            }
                        }
                        unset($existingHit);
                    }
                }
            }
        }

        // Sort by score (descending)
        usort($allHits, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        // Apply limit
        $limit = $options['limit'] ?? 50;
        if ($limit > 0 && count($allHits) > $limit) {
            $allHits = array_slice($allHits, 0, $limit);
        }

        return [
            'hits' => $allHits,
            'total' => count($allHits),
        ];
    }

    public function clearIndex(string $indexName): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for clearing index', ['index' => $indexName]);
            return false;
        }

        return $backend->clearIndex($indexName);
    }

    /**
     * Search across multiple indices and merge results
     *
     * @param array $indexNames Array of index names to search
     * @param string $query Search query
     * @param array $options Search options
     * @return array Merged search results with index metadata
     */
    public function searchMultiple(array $indexNames, string $query, array $options = []): array
    {
        $backend = $this->getActiveBackend();
        if (!$backend) {
            $this->logError('No active backend available for multi-index search');
            return ['hits' => [], 'total' => 0, 'indices' => []];
        }

        $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id;

        // Check for redirects first across all indexes (including global rules)
        // Global rules (indexHandle = null) apply to all indexes
        $redirectUrl = SearchManager::$plugin->queryRules->getRedirectUrl($query, null, $siteId);
        if (!$redirectUrl) {
            // Check each specific index for redirect rules
            foreach ($indexNames as $indexName) {
                $redirectUrl = SearchManager::$plugin->queryRules->getRedirectUrl($query, $indexName, $siteId);
                if ($redirectUrl) {
                    break;
                }
            }
        }

        if ($redirectUrl) {
            $this->logDebug('Multi-index search redirect matched', [
                'query' => $query,
                'redirectUrl' => $redirectUrl,
                'indices' => $indexNames,
            ]);

            // Track analytics for redirect
            SearchManager::$plugin->analytics->trackSearch(
                implode(',', $indexNames),
                $query,
                0,
                0,
                $backend->getName(),
                $siteId,
                [
                    'synonymsExpanded' => false,
                    'rulesMatched' => 1,
                    'promotionsShown' => 0,
                    'wasRedirected' => true,
                    'matchedRules' => [],
                    'matchedPromotions' => [],
                ]
            );

            return [
                'hits' => [],
                'total' => 0,
                'indices' => array_fill_keys($indexNames, 0),
                'redirect' => $redirectUrl,
            ];
        }

        $allHits = [];
        $totalCount = 0;
        $indicesSearched = [];
        $meta = [
            'synonymsExpanded' => false,
            'expandedQueries' => [],
            'rulesMatched' => [],
            'promotionsMatched' => [],
        ];

        foreach ($indexNames as $indexName) {
            $indexResults = $this->search($indexName, $query, $options);

            // Tag each hit with its source index
            if (!empty($indexResults['hits'])) {
                foreach ($indexResults['hits'] as &$hit) {
                    $hit['_index'] = $indexName;
                }
                unset($hit);
                $allHits = array_merge($allHits, $indexResults['hits']);
            }

            $totalCount += $indexResults['total'] ?? 0;
            $indicesSearched[$indexName] = $indexResults['total'] ?? 0;

            // Merge metadata from each index (deduplicate by ID)
            if (!empty($indexResults['meta'])) {
                $indexMeta = $indexResults['meta'];
                if (!empty($indexMeta['synonymsExpanded'])) {
                    $meta['synonymsExpanded'] = true;
                    $meta['expandedQueries'] = array_unique(array_merge(
                        $meta['expandedQueries'],
                        $indexMeta['expandedQueries'] ?? []
                    ));
                }
                if (!empty($indexMeta['rulesMatched'])) {
                    foreach ($indexMeta['rulesMatched'] as $rule) {
                        $ruleId = $rule['id'] ?? $rule['name'] ?? null;
                        if ($ruleId && !isset($meta['rulesMatched'][$ruleId])) {
                            $meta['rulesMatched'][$ruleId] = $rule;
                        }
                    }
                }
                if (!empty($indexMeta['promotionsMatched'])) {
                    foreach ($indexMeta['promotionsMatched'] as $promo) {
                        $promoId = $promo['id'] ?? $promo['elementId'] ?? null;
                        if ($promoId && !isset($meta['promotionsMatched'][$promoId])) {
                            $meta['promotionsMatched'][$promoId] = $promo;
                        }
                    }
                }
            }
        }

        // Sort merged hits by score (descending)
        usort($allHits, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        // Apply limit if specified
        if (!empty($options['limit'])) {
            $allHits = array_slice($allHits, 0, (int)$options['limit']);
        }

        return [
            'hits' => $allHits,
            'total' => $totalCount,
            'indices' => $indicesSearched,
            'meta' => [
                'synonymsExpanded' => $meta['synonymsExpanded'],
                'expandedQueries' => $meta['expandedQueries'],
                'rulesMatched' => array_values($meta['rulesMatched']),
                'promotionsMatched' => array_values($meta['promotionsMatched']),
            ],
        ];
    }

    // =========================================================================
    // SEARCH CACHE METHODS
    // =========================================================================

    /**
     * Generate cache key for search query
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return string
     */
    private function _generateCacheKey(string $indexName, string $query, array $options): string
    {
        // Include everything that affects the search results
        $keyData = [
            'index' => $indexName,
            'query' => $query,
            'options' => $options, // Future-proof: any new options automatically included
        ];

        return md5(json_encode($keyData));
    }

    /**
     * Get search results from cache
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return array|null
     */
    private function _getFromCache(string $indexName, string $query, array $options): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $cacheKey = $this->_generateCacheKey($indexName, $query, $options);
        $fullCacheKey = 'searchmanager:search:' . $cacheKey;

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cached = Craft::$app->cache->get($fullCacheKey);
            if ($cached !== false) {
                $this->logDebug('Cache hit (Redis)', ['cacheKey' => $cacheKey, 'query' => $query]);
                return $cached;
            }
            return null;
        }

        // Use file-based cache (default)
        $cachePath = $this->_getCachePath($indexName);
        $cacheFile = $cachePath . $cacheKey . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is expired
        $mtime = filemtime($cacheFile);
        if (time() - $mtime > $settings->cacheDuration) {
            @unlink($cacheFile);
            $this->logDebug('Cache expired and deleted', ['cacheKey' => $cacheKey]);
            return null;
        }

        $data = file_get_contents($cacheFile);
        $this->logDebug('Cache hit (File)', ['cacheKey' => $cacheKey, 'query' => $query]);
        return unserialize($data);
    }

    /**
     * Save search results to cache
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @param array $results
     * @return void
     */
    private function _saveToCache(string $indexName, string $query, array $options, array $results): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $cacheKey = $this->_generateCacheKey($indexName, $query, $options);
        $fullCacheKey = 'searchmanager:search:' . $cacheKey;

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = Craft::$app->cache;
            $cache->set($fullCacheKey, $results, $settings->cacheDuration);

            // Track key in set for selective deletion
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;
                $redis->executeCommand('SADD', ['searchmanager-search-keys', $fullCacheKey]);
            }

            $this->logDebug('Results cached (Redis)', ['cacheKey' => $cacheKey, 'query' => $query]);
            return;
        }

        // Use file-based cache (default)
        $cachePath = $this->_getCachePath($indexName);

        // Create directory if it doesn't exist
        if (!is_dir($cachePath)) {
            \craft\helpers\FileHelper::createDirectory($cachePath);
        }

        $cacheFile = $cachePath . $cacheKey . '.cache';
        file_put_contents($cacheFile, serialize($results));
        $this->logDebug('Results cached (File)', ['cacheKey' => $cacheKey, 'query' => $query]);
    }

    /**
     * Get cache path for an index
     *
     * @param string $indexName
     * @return string
     */
    private function _getCachePath(string $indexName): string
    {
        return Craft::$app->path->getRuntimePath() . '/search-manager/cache/search/' . $indexName . '/';
    }

    /**
     * Clear search cache for a specific index
     *
     * @param string $indexName Index handle
     * @return void
     */
    public function clearSearchCache(string $indexName): void
    {
        $settings = SearchManager::$plugin->getSettings();

        if ($settings->cacheStorageMethod === 'redis') {
            // Clear Redis cache for specific index
            $cache = Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;

                // Get all search cache keys from tracking set
                $allKeys = $redis->executeCommand('SMEMBERS', ['searchmanager-search-keys']) ?: [];

                // Filter keys for this specific index (keys contain index name)
                foreach ($allKeys as $key) {
                    if (strpos($key, 'searchmanager:search:') === 0) {
                        // Delete individual key
                        $cache->delete($key);
                        // Remove from tracking set
                        $redis->executeCommand('SREM', ['searchmanager-search-keys', $key]);
                    }
                }
            }

            $this->logInfo('Cleared search cache for index (Redis)', ['index' => $indexName]);
        } else {
            // Clear file cache
            $cachePath = $this->_getCachePath($indexName);

            if (is_dir($cachePath)) {
                \craft\helpers\FileHelper::clearDirectory($cachePath);
                $this->logInfo('Cleared search cache for index (File)', ['index' => $indexName]);
            }
        }
    }

    /**
     * Clear all search cache
     *
     * @return void
     */
    public function clearAllSearchCache(): void
    {
        $settings = SearchManager::$plugin->getSettings();

        if ($settings->cacheStorageMethod === 'redis') {
            // Clear Redis cache
            $cache = Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;

                // Get all search cache keys from tracking set
                $keys = $redis->executeCommand('SMEMBERS', ['searchmanager-search-keys']) ?: [];

                // Delete all search cache keys
                foreach ($keys as $key) {
                    $cache->delete($key);
                }

                // Clear the tracking set
                $redis->executeCommand('DEL', ['searchmanager-search-keys']);
            }

            $this->logInfo('Cleared all search cache (Redis)');
        } else {
            // Clear file cache
            $cachePath = Craft::$app->path->getRuntimePath() . '/search-manager/cache/search/';

            if (is_dir($cachePath)) {
                \craft\helpers\FileHelper::clearDirectory($cachePath);
                $this->logInfo('Cleared all search cache (File)');
            }
        }
    }

    /**
     * Get search count for a query from analytics
     *
     * @param string $query
     * @return int
     */
    private function _getQuerySearchCount(string $query): int
    {
        try {
            return (int)(new \craft\db\Query())
                ->from('{{%searchmanager_analytics}}')
                ->where(['query' => $query])
                ->count();
        } catch (\Throwable $e) {
            $this->logError('Failed to get query search count', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
