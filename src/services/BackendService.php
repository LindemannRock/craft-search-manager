<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use craft\helpers\App;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\MySqlBackend;
use lindemannrock\searchmanager\backends\PostgreSqlBackend;
use lindemannrock\searchmanager\backends\RedisBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\events\SearchEvent;
use lindemannrock\searchmanager\helpers\CacheKeyHelper;
use lindemannrock\searchmanager\helpers\QueryNormalizer;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\IndexCountBackendInterface;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Backend Service
 *
 * Manages search backend adapters and provides a unified interface
 *
 * @since 5.0.0
 */
class BackendService extends Component
{
    use LoggingTrait;

    /**
     * Fired before a search executes, after query rules are resolved.
     *
     * Listeners can modify [[SearchEvent::$query]] or [[SearchEvent::$options]]
     * to change what gets searched. Set `$event->handled = true` to skip the
     * search entirely and return [[SearchEvent::$results]] directly.
     *
     * @since 5.39.0
     * @see SearchEvent
     */
    public const EVENT_BEFORE_SEARCH = 'beforeSearch';

    /**
     * Fired after a search completes and promotions are applied.
     *
     * Listeners can modify [[SearchEvent::$results]] to filter, enrich,
     * or reorder hits before they are returned to the caller.
     *
     * @since 5.39.0
     * @see SearchEvent
     */
    public const EVENT_AFTER_SEARCH = 'afterSearch';

    private ?BackendInterface $_activeBackend = null;

    /**
     * @var array<string, list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>>
     */
    private array $lastBatchIndexingFailures = [];

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    /** @inheritdoc */
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
     *
     * @return BackendInterface|null
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
            if (App::isEphemeral()) {
                $this->logWarning('No compatible default backend configured on an ephemeral filesystem');
                return null;
            }

            $this->logWarning('No defaultBackendHandle configured, falling back to file backend');
            return new FileBackend();
        }

        // Look up the configured backend
        $configuredBackend = ConfiguredBackend::findByHandle($defaultHandle);

        if ($configuredBackend && $configuredBackend->enabled) {
            if ($this->isEphemeralFileBackend($configuredBackend)) {
                $this->logWarning('Configured default backend is unavailable on an ephemeral filesystem', [
                    'handle' => $defaultHandle,
                ]);
                return null;
            }

            $backend = $this->createBackendFromConfig($configuredBackend);
            if ($backend) {
                $this->logDebug('Using configured default backend', [
                    'handle' => $defaultHandle,
                    'backendType' => $configuredBackend->backendType,
                ]);
                return $backend;
            }
        }

        $this->logWarning('Configured default backend not found or disabled', ['handle' => $defaultHandle]);

        return null;
    }

    /**
     * Get specific backend by name
     *
     * @param string $name
     * @return BackendInterface|null
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
     *
     * @return BackendInterface[]
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
     * @since 5.28.0
     */
    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        // Load the index to check for backend override
        $index = \lindemannrock\searchmanager\models\SearchIndex::findByHandle($indexName);

        if ($index && $index->hasBackendOverride()) {
            // Load the configured backend by handle
            $configuredBackend = ConfiguredBackend::findByHandle($index->backend);

            if ($configuredBackend && $configuredBackend->enabled) {
                if ($this->isEphemeralFileBackend($configuredBackend)) {
                    $this->logWarning('Configured index backend is unavailable on an ephemeral filesystem', [
                        'index' => $indexName,
                        'specifiedBackend' => $index->backend,
                    ]);
                    return null;
                }

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
     * @since 5.28.0
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

    private function isEphemeralFileBackend(ConfiguredBackend $backend): bool
    {
        return App::isEphemeral() && $backend->backendType === 'file';
    }

    /**
     * Get list of available backend options for UI dropdowns
     * Uses configured backends from the database
     *
     * @return array Array of [value => label] pairs
     * @since 5.28.0
     */
    public function getBackendOptions(): array
    {
        return \lindemannrock\searchmanager\models\ConfiguredBackend::getSelectOptions(true);
    }

    // =========================================================================
    // PROXY METHODS (delegate to active backend)
    // =========================================================================

    /**
     * Index a document
     *
     * @param string $indexName Logical index handle
     * @param array $data
     * @return bool
     */
    public function index(string $indexName, array $data): bool
    {
        return $this->indexWithResult($indexName, $data)['success'];
    }

    /**
     * Index a document and return backend existence metadata.
     *
     * @param string $indexName Logical index handle
     * @param array $data
     * @return array{success: bool, wasCreated: bool|null}
     * @since 5.53.0
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for indexing', ['index' => $indexName]);
            return [
                'success' => false,
                'wasCreated' => null,
            ];
        }

        return $backend->indexWithResult($indexName, $data);
    }

    /**
     * Batch index multiple documents
     *
     * @param string $indexName
     * @param array $items
     * @return bool
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for batch indexing', ['index' => $indexName]);
            $this->lastBatchIndexingFailures[$indexName] = [];
            return false;
        }

        $result = $backend->batchIndex($indexName, $items);
        $this->lastBatchIndexingFailures[$indexName] = $backend instanceof BaseBackend
            ? $backend->getLastIndexingFailures()
            : [];

        return $result;
    }

    /**
     * @return list<array{backendId: string|null, elementId: int|null, title: string|null, error: string}>
     * @since 5.53.0
     */
    public function getLastIndexingFailures(string $indexName): array
    {
        if (array_key_exists($indexName, $this->lastBatchIndexingFailures)) {
            return $this->lastBatchIndexingFailures[$indexName];
        }

        $backend = $this->getBackendForIndex($indexName);
        if (!$backend instanceof BaseBackend) {
            return [];
        }

        return $backend->getLastIndexingFailures();
    }

    /**
     * Batch delete multiple documents
     *
     * @param string $indexName
     * @param array $items
     * @return bool
     * @since 5.46.0
     */
    public function batchDelete(string $indexName, array $items): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for batch deletion', ['index' => $indexName]);
            return false;
        }

        return $backend->batchDelete($indexName, $items);
    }

    /**
     * Delete stale section documents for a parent element after the replacement
     * section set has been indexed.
     *
     * @param string[] $keepBackendIds
     * @since 5.53.0
     */
    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for orphan deletion', ['index' => $indexName]);
            return false;
        }

        return $backend->deleteOrphanDocuments($indexName, $elementId, $siteId, $keepBackendIds);
    }

    /**
     * Delete a document from the index
     *
     * @param string $indexName
     * @param int $elementId
     * @param int|null $siteId
     * @return bool
     */
    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return $this->deleteWithResult($indexName, $elementId, $siteId)['success'];
    }

    /**
     * Delete a document and return backend existence metadata.
     *
     * @param string $indexName
     * @param int $elementId
     * @param int|null $siteId
     * @return array{success: bool, existed: bool|null}
     * @since 5.53.0
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for deletion', ['index' => $indexName]);
            return [
                'success' => false,
                'existed' => null,
            ];
        }

        return $backend->deleteWithResult($indexName, $elementId, $siteId);
    }

    /**
     * Check if a document exists in the index
     *
     * @param string $indexName
     * @param int $elementId
     * @param int|null $siteId
     * @return bool
     */
    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for document check', ['index' => $indexName]);
            return false;
        }

        return $backend->documentExists($indexName, $elementId, $siteId);
    }

    /**
     * Fetch indexed documents for element IDs from an index.
     *
     * @param array<int, int> $elementIds
     * @return array<int, array<string, mixed>>
     * @since 5.53.0
     */
    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for document fetch', ['index' => $indexName]);
            return [];
        }

        return $backend->getDocumentsByElementIds($indexName, $elementIds, $siteId);
    }

    /**
     * Return the authoritative backend document count when supported.
     *
     * @since 5.54.0
     */
    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend instanceof IndexCountBackendInterface) {
            return null;
        }

        return $backend->getDocumentCount($indexName, $siteId);
    }

    /**
     * Return the authoritative distinct parent-element count when supported.
     *
     * @since 5.54.0
     */
    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int
    {
        $backend = $this->getBackendForIndex($indexName);
        if (!$backend instanceof IndexCountBackendInterface) {
            return null;
        }

        return $backend->getDistinctParentCount($indexName, $siteId);
    }

    /**
     * Search an index
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return array
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        if (!SearchManager::$plugin->dependencies->areIndexDependenciesAvailable($indexName, true)) {
            return [];
        }

        $backend = $this->getBackendForIndex($indexName);
        if (!$backend) {
            $this->logError('No backend available for search', ['index' => $indexName]);
            return [];
        }

        $this->normalizeSearchLanguageOption($options);

        $options['siteId'] = SearchSiteScopeHelper::normalize($options['siteId'] ?? null);
        $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId']);
        $settings = SearchManager::$plugin->getSettings();
        $includeQueryRuleDebug = (bool)($options['includeQueryRuleDebug'] ?? false);

        // Check if analytics should be skipped (used by cache warming, internal operations)
        $skipAnalytics = $options['skipAnalytics'] ?? false;

        // Session ID for grouping multi-index analytics rows (passed by searchMultiple)
        $sessionId = $options['sessionId'] ?? null;

        // Extract analytics options from search options (API callers can pass these)
        $analyticsOptions = $this->analyticsOptionsFromSearchOptions($options);

        // =====================================================================
        // QUERY RULES: Get all matching rules for analytics
        // =====================================================================
        $matchedRules = SearchManager::$plugin->queryRules->getMatchingRules($query, $indexName, $siteId);
        $matchedPromotions = \lindemannrock\searchmanager\models\Promotion::findMatching($query, $indexName, $siteId);

        // =====================================================================
        // QUERY RULES: Check for redirect first
        // =====================================================================
        $redirectUrl = SearchManager::$plugin->queryRules->getRedirectUrl($query, $indexName, $siteId, $matchedRules);
        if ($redirectUrl) {
            $this->logDebug('Query rule redirect matched', [
                'query' => $query,
                'redirectUrl' => $redirectUrl,
            ]);

            // Track analytics for redirect (no search performed)
            if (!$skipAnalytics) {
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
                    ]),
                    $sessionId,
                );
            }

            return [
                'hits' => [],
                'total' => 0,
                'redirect' => $redirectUrl,
            ];
        }

        // =====================================================================
        // QUERY RULES: Expand query with synonyms
        // =====================================================================
        $expandedQueries = SearchManager::$plugin->queryRules->expandWithSynonyms($query, $indexName, $siteId, $matchedRules);
        $useSynonyms = count($expandedQueries) > 1;

        if ($useSynonyms) {
            $this->logDebug('Query expanded with synonyms', [
                'original' => $query,
                'expanded' => $expandedQueries,
            ]);
        }

        // =====================================================================
        // EVENT: Before search — allows query/options modification or short-circuit
        // =====================================================================
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $beforeEvent = new SearchEvent([
                'indexName' => $indexName,
                'query' => $query,
                'options' => $options,
                'backend' => $backend->getName(),
            ]);
            $this->trigger(self::EVENT_BEFORE_SEARCH, $beforeEvent);

            // Allow listeners to modify query and options
            $query = $beforeEvent->query;
            $options = $beforeEvent->options;
            $options['siteId'] = SearchSiteScopeHelper::normalize($options['siteId'] ?? null);
            $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId']);

            // Allow short-circuit: listener sets handled=true and provides results
            if ($beforeEvent->handled) {
                return $beforeEvent->results ?: ['hits' => [], 'total' => 0];
            }
        }

        // 1. Check cache first (if caching enabled)
        // Note: Cache stores RAW backend results. Promotions are applied fresh each time
        // to ensure disabled/expired promotions are immediately excluded.
        if ($settings->enableCache && !$includeQueryRuleDebug) {
            $cached = $this->_getFromCache($indexName, $query, $options);
            if ($cached !== null) {
                // Apply promotions fresh (not from cache) so disabled promotions are excluded
                $promotionOutcome = SearchManager::$plugin->promotions->applyPromotionsWithOutcome(
                    $cached['hits'] ?? [],
                    $query,
                    $indexName,
                    $siteId,
                    $matchedPromotions,
                    fn(array $hits): array => $this->filterHitsByType($hits, $options['type'] ?? null),
                );
                $cached['hits'] = $promotionOutcome['hits'];
                $presentedPromotions = $promotionOutcome['presentedPromotions'];

                // Still track analytics for cached results
                if (!$skipAnalytics) {
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
                            'promotionsShown' => count($presentedPromotions),
                            'wasRedirected' => false,
                            'matchedRules' => $matchedRules,
                            'matchedPromotions' => $presentedPromotions,
                        ]),
                        $sessionId,
                    );
                }

                // Add metadata about rules and promotions (even for cached results)
                $cached['meta'] = [
                    'cached' => true,
                    'took' => 0,
                    'cacheEnabled' => true,
                    'cacheDriver' => $this->_getCacheDriver(),
                    'backend' => $backend->getName(),
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
                    }, $presentedPromotions),
                ];

                // Cache stores RAW backend results, so the local-engine debug
                // rides along and stays accurate for the cached hit set.
                $this->_mergeSearchDebugIntoMeta($cached);

                // Fire after-search event for cached results too
                if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
                    $afterEvent = new SearchEvent([
                        'indexName' => $indexName,
                        'query' => $query,
                        'options' => $options,
                        'results' => $cached,
                        'executionTime' => 0,
                        'backend' => $backend->getName(),
                    ]);
                    $this->trigger(self::EVENT_AFTER_SEARCH, $afterEvent);

                    $cached = $afterEvent->results;
                }

                return $cached;
            }
        }

        // 2. No cache - perform actual search
        $startTime = microtime(true);
        $backendFailed = false;
        $backendOptions = $options;
        if ($backend instanceof AbstractSearchEngineBackend) {
            $backendOptions['_localSearchFailureReporter'] = static function() use (&$backendFailed): void {
                $backendFailed = true;
            };
        }

        // If synonyms exist, search for all expanded queries and merge results
        if ($useSynonyms) {
            $results = $this->_searchWithSynonyms($backend, $indexName, $expandedQueries, $backendOptions);
        } else {
            $results = $backend->search($indexName, $query, $backendOptions);
        }

        // =====================================================================
        // QUERY RULES: Apply score boosts
        // =====================================================================
        if (!empty($results['hits'])) {
            $results['hits'] = SearchManager::$plugin->queryRules->applyBoosts(
                $results['hits'],
                $query,
                $indexName,
                $siteId,
                $matchedRules,
                $includeQueryRuleDebug,
            );
        }

        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        $backendFailed = $backendFailed || (bool)($results['_failed'] ?? false);

        // 3. Cache RAW results (BEFORE promotions) so promotions can be applied fresh
        // This ensures disabled/expired promotions are immediately excluded
        if ($settings->enableCache && !$backendFailed && !$includeQueryRuleDebug) {
            $this->_saveToCache($indexName, $query, $options, $results);
        }

        unset($results['_failed']);

        // =====================================================================
        // PROMOTIONS: Apply pinned/promoted results (AFTER caching)
        // Promotions are applied fresh each time, not cached, so disabled
        // promotions are immediately excluded from results.
        // =====================================================================
        $promotionOutcome = SearchManager::$plugin->promotions->applyPromotionsWithOutcome(
            $results['hits'] ?? [],
            $query,
            $indexName,
            $siteId,
            $matchedPromotions,
            fn(array $hits): array => $this->filterHitsByType($hits, $options['type'] ?? null),
        );
        $results['hits'] = $promotionOutcome['hits'];
        $presentedPromotions = $promotionOutcome['presentedPromotions'];

        // 4. Track analytics
        if (!$skipAnalytics) {
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
                    'promotionsShown' => count($presentedPromotions),
                    'wasRedirected' => false,
                    'matchedRules' => $matchedRules,
                    'matchedPromotions' => $presentedPromotions,
                ]),
                $sessionId,
            );
        }

        // 5. Add metadata about rules and promotions applied
        // Note: This meta is internal — consumers must gate exposure:
        // - Public REST, GraphQL, and Twig boundaries require an explicit
        //   debug request plus server-controlled debug access.
        // - Raw hit presentation never grants debug-data authority.
        $results['meta'] = [
            'cached' => false,
            'took' => round($executionTime, 2),
            'cacheEnabled' => $settings->enableCache,
            'cacheDriver' => $this->_getCacheDriver(),
            'backend' => $backend->getName(),
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
            }, $presentedPromotions),
        ];

        $this->_mergeSearchDebugIntoMeta($results);

        // =====================================================================
        // EVENT: After search — allows result filtering, enrichment, reordering
        // =====================================================================
        if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $afterEvent = new SearchEvent([
                'indexName' => $indexName,
                'query' => $query,
                'options' => $options,
                'results' => $results,
                'executionTime' => $executionTime,
                'backend' => $backend->getName(),
            ]);
            $this->trigger(self::EVENT_AFTER_SEARCH, $afterEvent);

            $results = $afterEvent->results;
        }

        return $results;
    }

    /**
     * Move the local engine's coherence debug (#383/#384) — per-token resolved
     * terms and the relax-on-zero flag — from the backend result into meta.
     * External backends don't emit it; their meta stays unchanged.
     *
     * @param array<string, mixed> $results Backend results (meta already built)
     */
    private function _mergeSearchDebugIntoMeta(array &$results): void
    {
        $searchDebug = $results['searchDebug'] ?? null;
        unset($results['searchDebug']);

        if (is_array($searchDebug) && isset($results['meta'])) {
            $results['meta']['relaxedMatching'] = !empty($searchDebug['relaxedMatching']);
            $results['meta']['resolvedTerms'] = $searchDebug['resolvedTerms'] ?? [];
        }
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
        // Safety cap — prevent excessive backend calls from too many synonym expansions
        $maxQueries = 10;
        if (count($queries) > $maxQueries) {
            $this->logWarning('Synonym expansion exceeded limit, capping at {max} queries (had {count}). Consider reducing synonyms per rule.', [
                'max' => $maxQueries,
                'count' => count($queries),
                'dropped' => array_slice($queries, $maxQueries),
            ]);
            $queries = array_slice($queries, 0, $maxQueries);
        }

        $allHits = [];
        $hitIndexesByElementId = [];
        $searchDebug = null;
        $backendFailed = false;
        $childOptions = $this->aggregateChildPaginationOptions($options, 50);

        foreach ($queries as $searchQuery) {
            $queryResults = $backend->search($indexName, $searchQuery, $childOptions);
            $backendFailed = $backendFailed || (bool)($queryResults['_failed'] ?? false);

            // Merge local-engine debug across the expanded queries: relax is
            // sticky, per-token resolutions union (first query wins per token).
            if (isset($queryResults['searchDebug']) && is_array($queryResults['searchDebug'])) {
                $searchDebug ??= ['relaxedMatching' => false, 'resolvedTerms' => []];
                $searchDebug['relaxedMatching'] = $searchDebug['relaxedMatching']
                    || !empty($queryResults['searchDebug']['relaxedMatching']);
                $searchDebug['resolvedTerms'] += $queryResults['searchDebug']['resolvedTerms'] ?? [];
            }

            if (!empty($queryResults['hits'])) {
                foreach ($queryResults['hits'] as $hit) {
                    $elementId = SearchHitIdentityHelper::elementId($hit);

                    if ($elementId === null) {
                        continue;
                    }

                    $identity = isset($hit['siteId'])
                        ? SearchHitIdentityHelper::pageDocumentId($elementId, $hit['siteId'])
                        : (string)$elementId;

                    // Avoid duplicates - keep highest score
                    if (!isset($hitIndexesByElementId[$identity])) {
                        $hitIndexesByElementId[$identity] = count($allHits);
                        $allHits[] = $hit;
                        continue;
                    }

                    $existingIndex = $hitIndexesByElementId[$identity];
                    $allHits[$existingIndex]['score'] = max($allHits[$existingIndex]['score'] ?? 0, $hit['score'] ?? 0);
                }
            }
        }

        // Sort by score (descending)
        usort($allHits, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $total = count($allHits);

        if ($limit > 0) {
            $allHits = array_slice($allHits, $offset, $limit);
        } elseif ($offset > 0) {
            $allHits = array_slice($allHits, $offset);
        }

        $merged = [
            'hits' => $allHits,
            'total' => $total,
        ];

        if ($searchDebug !== null) {
            $merged['searchDebug'] = $searchDebug;
        }
        if ($backendFailed) {
            $merged['_failed'] = true;
        }

        return $merged;
    }

    /**
     * Clear all documents from an index
     *
     * @param string $indexName
     * @return bool
     */
    public function clearIndex(string $indexName): bool
    {
        $target = SearchManager::$plugin->dependencies->getStrictBackendTarget(
            $indexName,
            DependencyService::ACTION_CLEAR_DATA,
        );
        if ($target === null) {
            $capability = SearchManager::$plugin->dependencies->getIndexActionCapability(
                $indexName,
                DependencyService::ACTION_CLEAR_DATA,
            );
            $this->logWarning('Index clear denied by structural capability', [
                'index' => $indexName,
                'reasonCode' => $capability['reasonCode'],
            ]);
            return false;
        }

        return $target['backend']->clearIndex($indexName);
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
        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue($indexNames);
        $indexNames = array_values(array_filter(
            $indexNames,
            static fn(string $handle): bool => !($catalogue[$handle]['exists'] ?? true)
                || (
                    !($catalogue[$handle]['configError'] ?? true)
                    && (bool)($catalogue[$handle]['dependencyAvailability']['available'] ?? false)
                ),
        ));
        if ($indexNames === []) {
            return ['hits' => [], 'total' => 0, 'indices' => []];
        }

        $backend = $this->getActiveBackend();
        if (!$backend) {
            $this->logError('No active backend available for multi-index search');
            return ['hits' => [], 'total' => 0, 'indices' => []];
        }

        $this->normalizeSearchLanguageOption($options);

        // Match search(): if siteId is omitted, search all sites.
        $options['siteId'] = SearchSiteScopeHelper::normalize($options['siteId'] ?? null);
        $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId']);
        $skipAnalytics = (bool)($options['skipAnalytics'] ?? false);

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

        // Generate session ID for multi-index analytics tracking
        $sessionId = \craft\helpers\StringHelper::UUID();

        if ($redirectUrl) {
            $this->logDebug('Multi-index search redirect matched', [
                'query' => $query,
                'redirectUrl' => $redirectUrl,
                'indices' => $indexNames,
            ]);

            // Track analytics per index with shared session ID
            if (!$skipAnalytics) {
                $analyticsOptions = $this->analyticsOptionsFromSearchOptions($options);
                foreach ($indexNames as $indexName) {
                    SearchManager::$plugin->analytics->trackSearch(
                        $indexName,
                        $query,
                        0,
                        0,
                        $backend->getName(),
                        $siteId,
                        array_merge($analyticsOptions, [
                            'synonymsExpanded' => false,
                            'rulesMatched' => 1,
                            'promotionsShown' => 0,
                            'wasRedirected' => true,
                            'matchedRules' => [],
                            'matchedPromotions' => [],
                        ]),
                        $sessionId,
                    );
                }
            }

            return [
                'hits' => [],
                'total' => 0,
                'indices' => array_fill_keys($indexNames, 0),
                'redirect' => $redirectUrl,
            ];
        }

        $startTime = microtime(true);
        $settings = SearchManager::$plugin->getSettings();

        $allHits = [];
        $totalCount = 0;
        $indicesSearched = [];
        $meta = [
            'synonymsExpanded' => false,
            'expandedQueries' => [],
            'rulesMatched' => [],
            'promotionsMatched' => [],
            'cached' => true, // Will be set to false if any index isn't cached
            'relaxedMatching' => false,
            'resolvedTerms' => [],
        ];

        $limit = (int) ($options['limit'] ?? 0);
        $offset = (int) ($options['offset'] ?? 0);

        foreach ($indexNames as $indexName) {
            $indexOptions = $this->aggregateChildPaginationOptions($options, 0);
            $indexOptions['sessionId'] = $sessionId;

            $indexResults = $this->search($indexName, $query, $indexOptions);

            // Track cache status (if any index is not cached, mark as not cached)
            if (!empty($indexResults['meta']) && !$indexResults['meta']['cached']) {
                $meta['cached'] = false;
            }

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
                if (!empty($indexMeta['relaxedMatching'])) {
                    $meta['relaxedMatching'] = true;
                }
                if (!empty($indexMeta['resolvedTerms'])) {
                    // First index wins per token — indices resolve against the
                    // same query, so per-token differences are corpus-local.
                    $meta['resolvedTerms'] += $indexMeta['resolvedTerms'];
                }
            }
        }

        // Sort merged hits by score (descending)
        usort($allHits, function($a, $b) {
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });

        if ($limit > 0) {
            $allHits = array_slice($allHits, $offset, $limit);
        } elseif ($offset > 0) {
            $allHits = array_slice($allHits, $offset);
        }

        $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        return [
            'hits' => $allHits,
            'total' => $totalCount,
            'indices' => $indicesSearched,
            'meta' => [
                'cached' => $meta['cached'],
                'took' => round($executionTime, 2),
                'cacheEnabled' => $settings->enableCache,
                'cacheDriver' => $this->_getCacheDriver(),
                'backend' => $backend->getName(),
                'synonymsExpanded' => $meta['synonymsExpanded'],
                'expandedQueries' => $meta['expandedQueries'],
                'rulesMatched' => array_values($meta['rulesMatched']),
                'promotionsMatched' => array_values($meta['promotionsMatched']),
                'relaxedMatching' => $meta['relaxedMatching'],
                'resolvedTerms' => $meta['resolvedTerms'],
            ],
        ];
    }

    /**
     * Give an aggregate child enough candidates while retaining pagination
     * authority at the owning aggregate.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function aggregateChildPaginationOptions(array $options, int $defaultLimit): array
    {
        $limit = (int)($options['limit'] ?? $defaultLimit);
        $offset = (int)($options['offset'] ?? 0);

        $options['limit'] = $limit > 0 ? $limit + $offset : 0;
        $options['offset'] = 0;
        $options['page'] = 0;

        return $options;
    }

    /**
     * Normalize or drop public language options before reaching any backend.
     *
     * @param array<string, mixed> $options
     */
    private function normalizeSearchLanguageOption(array &$options): void
    {
        if (!isset($options['language'])) {
            return;
        }

        $language = is_string($options['language'])
            ? LanguageNormalizer::normalizeOrNull($options['language'])
            : null;

        if ($language === null) {
            unset($options['language']);
            return;
        }

        $options['language'] = $language;
    }

    // =========================================================================
    // SEARCH CACHE METHODS
    // =========================================================================

    /**
     * Keep late-stage additions, such as promotions, inside the requested type
     * boundary.
     *
     * @param array<int, array<string, mixed>> $hits
     * @return array<int, array<string, mixed>>
     */
    private function filterHitsByType(array $hits, mixed $typeFilter): array
    {
        if ($typeFilter === null || $typeFilter === '') {
            return $hits;
        }

        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', (string) $typeFilter);
        $allowedTypes = array_values(array_filter(array_map('trim', $allowedTypes), static fn(string $value): bool => $value !== ''));

        if ($allowedTypes === []) {
            return $hits;
        }

        return array_values(array_filter(
            $hits,
            static fn(array $hit): bool => in_array((string) ($hit['type'] ?? ''), $allowedTypes, true),
        ));
    }

    /**
     * Get cache driver type for debug info
     *
     * @return string Cache driver name (file, redis, memcached, etc.)
     */
    private function _getCacheDriver(): string
    {
        return (new CacheStorageService())->getDriverLabel();
    }

    /**
     * Generate cache key for search query
     *
     * @param string $indexName
     * @param string $query
     * @param array $options
     * @return string|null
     */
    private function _generateCacheKey(string $indexName, string $query, array $options): ?string
    {
        // Normalize query to improve cache hit rate
        $normalizedQuery = QueryNormalizer::forCacheIdentity($query);

        // Remove analytics-only options that don't affect results
        $cacheOptions = $options;
        foreach (['source', 'sourceDefault', 'platform', 'appVersion', 'skipAnalytics', 'sessionId', 'apiKeyId', 'apiKeyPrefix', 'apiKeyType'] as $key) {
            unset($cacheOptions[$key]);
        }
        if (array_key_exists('siteId', $cacheOptions)) {
            $cacheOptions['siteId'] = SearchSiteScopeHelper::normalize($cacheOptions['siteId']);
        }

        // Include everything that affects the search results
        $keyData = [
            'index' => $indexName,
            'query' => $normalizedQuery,
            'edition' => SearchManager::$plugin->getEditionHandle(),
            'options' => $cacheOptions, // Future-proof: any new options automatically included
        ];

        return CacheKeyHelper::generate($keyData);
    }

    /**
     * Extract analytics-only metadata without resolving or reconstructing it.
     */
    private function analyticsOptionsFromSearchOptions(array $options): array
    {
        return [
            'source' => $options['source'] ?? null,
            'sourceDefault' => $options['sourceDefault'] ?? null,
            'platform' => $options['platform'] ?? null,
            'appVersion' => $options['appVersion'] ?? null,
            // API key attribution (slice 5) — set by ApiController for keyed
            // requests, absent for anonymous traffic.
            'apiKeyId' => $options['apiKeyId'] ?? null,
            'apiKeyPrefix' => $options['apiKeyPrefix'] ?? null,
            'apiKeyType' => $options['apiKeyType'] ?? null,
        ];
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
        $fullIndexName = $settings->getFullIndexName($indexName);
        $cacheKey = $this->_generateCacheKey($fullIndexName, $query, $options);
        if ($cacheKey === null) {
            return null;
        }

        $cached = (new CacheStorageService())->read(
            'search',
            $fullIndexName,
            $cacheKey,
            (int)$settings->cacheDuration,
        );
        if (!$cached->isHit() || !is_array($cached->value)) {
            return null;
        }

        $this->logDebug('Search cache hit', ['cacheKey' => $cacheKey, 'query' => $query]);
        return $cached->value;
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
        $fullIndexName = $settings->getFullIndexName($indexName);
        $cacheKey = $this->_generateCacheKey($fullIndexName, $query, $options);
        if ($cacheKey === null) {
            return;
        }

        (new CacheStorageService())->write(
            'search',
            $fullIndexName,
            $cacheKey,
            $results,
            (int)$settings->cacheDuration,
        );
    }

    /**
     * Clear search cache for a specific index
     *
     * @param string $indexName Index handle
     * @return void
     */
    public function clearSearchCache(string $indexName): void
    {
        $fullIndexName = SearchManager::$plugin->getSettings()->getFullIndexName($indexName);

        if (!(new CacheStorageService())->invalidateScope('search', $fullIndexName)) {
            throw new \RuntimeException('Search cache invalidation failed.');
        }
        $this->logInfo('Cleared search cache for index', ['index' => $indexName]);
    }

    /**
     * Clear all search cache
     *
     * @return void
     */
    public function clearAllSearchCache(): void
    {
        if (!(new CacheStorageService())->invalidateFamily('search')) {
            throw new \RuntimeException('Search cache invalidation failed.');
        }
        $this->logInfo('Cleared all search cache');
    }
}
