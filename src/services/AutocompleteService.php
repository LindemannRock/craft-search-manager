<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Autocomplete Service
 *
 * Provides search-as-you-type suggestions based on indexed terms.
 *
 * Features:
 * - Prefix matching (query: "te" → "test", "testing", "technical")
 * - Fuzzy suggestions (typo-tolerant)
 * - Popular terms (sorted by frequency)
 * - Configurable limits
 *
 * @since 5.0.0
 */
class AutocompleteService extends Component
{
    use LoggingTrait;

    private static bool $redisFallbackLogged = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    /**
     * Get autocomplete suggestions for a query
     *
     * @param string $query Partial search query
     * @param string $indexHandle Index to search
     * @param array $options Suggestion options
     * @return array Array of suggestion strings
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $startTime = microtime(true);

        // Get options with defaults from settings
        $settings = SearchManager::$plugin->getSettings();
        $minLength = $options['minLength'] ?? $settings->autocompleteMinLength ?? 2;
        $limit = $options['limit'] ?? $settings->autocompleteLimit ?? 10;
        $fuzzy = $options['fuzzy'] ?? $settings->autocompleteFuzzy ?? false;
        $language = $options['language'] ?? null;

        // Check if siteId was explicitly provided (for all-sites indices, it won't be)
        $siteIdProvided = isset($options['siteId']) && $options['siteId'] !== null;
        $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

        // Auto-detect language only when a specific site is targeted
        if ($language === null) {
            if ($siteIdProvided) {
                if ($this->containsArabicScript($query)) {
                    // Query contains Arabic script, use Arabic language
                    $language = 'ar';
                } else {
                    // Detect from site
                    $site = Craft::$app->getSites()->getSiteById($siteId);
                    if ($site) {
                        $language = substr($site->language, 0, 2);  // en-US → en
                    } else {
                        $language = 'en';
                    }
                }
            }
        }

        // Validate query length
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        // Normalize query
        $normalizedQuery = $this->normalizeQuery($query);

        // Apply index prefix to get full index name (matches how data is stored)
        $indexPrefix = $settings->indexPrefix ?? '';
        $fullIndexHandle = $indexPrefix . $indexHandle;

        // Check cache first
        $this->logDebug('Autocomplete cache check', [
            'enableAutocompleteCache' => $settings->enableAutocompleteCache,
            'cacheStorageMethod' => $settings->cacheStorageMethod,
            'autocompleteCacheDuration' => $settings->autocompleteCacheDuration,
        ]);

        if ($settings->enableAutocompleteCache) {
            $cacheKey = $this->generateCacheKey('suggest', $fullIndexHandle, $normalizedQuery, $siteIdProvided ? $siteId : null, $language);
            $cached = $this->getFromCache($cacheKey, $fullIndexHandle);
            if ($cached !== null) {
                $this->logDebug('Autocomplete cache hit', [
                    'query' => $normalizedQuery,
                    'index' => $fullIndexHandle,
                ]);
                return $cached;
            }
            $this->logDebug('Autocomplete cache miss', [
                'cacheKey' => $cacheKey,
                'index' => $fullIndexHandle,
            ]);
        }

        $this->logDebug('Generating suggestions', [
            'query' => $normalizedQuery,
            'index' => $fullIndexHandle,
            'language' => $language,
            'fuzzy' => $fuzzy,
        ]);

        // Get storage using DRY approach via BackendService
        $storage = $this->getStorageForIndex($indexHandle);

        // If no storage (external backend like Meilisearch/Algolia/Typesense),
        // try to use the backend's native autocomplete
        if (!$storage) {
            $suggestions = $this->getBackendAutocomplete($indexHandle, $normalizedQuery, [
                'limit' => $limit,
                'siteId' => $siteIdProvided ? $siteId : null,
            ]);

            // Cache and return
            if ($settings->enableAutocompleteCache && !empty($suggestions)) {
                $this->saveToCache($cacheKey, $suggestions, $fullIndexHandle);
            }

            return $suggestions;
        }

        $suggestions = [];

        // Method 1: Prefix matching (fast, exact)
        // For all-sites indices (siteId not provided), skip siteId filter
        $prefixMatches = $this->getPrefixMatches($storage, $normalizedQuery, $siteIdProvided ? $siteId : null, $limit, $language, $fullIndexHandle);
        $suggestions = array_merge($suggestions, $prefixMatches);

        // Method 2: Fuzzy matching (slower, typo-tolerant)
        if ($fuzzy && count($suggestions) < $limit) {
            $fuzzyMatches = $this->getFuzzyMatches($storage, $normalizedQuery, $siteIdProvided ? $siteId : null, $limit - count($suggestions));
            $suggestions = array_merge($suggestions, $fuzzyMatches);
        }

        // Remove duplicates and limit
        $suggestions = array_values(array_unique($suggestions));
        $suggestions = array_slice($suggestions, 0, $limit);

        // Save to cache
        if ($settings->enableAutocompleteCache) {
            $this->saveToCache($cacheKey, $suggestions, $fullIndexHandle);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logInfo('Suggestions generated', [
            'query' => $query,
            'count' => count($suggestions),
            'duration_ms' => $duration,
        ]);

        return $suggestions;
    }

    /**
     * Get prefix-matched suggestions
     *
     * @param mixed $storage Storage instance
     * @param string $query Query prefix
     * @param int|null $siteId Site ID (null for all-sites indices)
     * @param int $limit Maximum suggestions
     * @param string|null $language Language filter
     * @param string|null $indexHandle Full index handle (with prefix) to filter by
     * @return array Matching terms
     */
    private function getPrefixMatches($storage, string $query, ?int $siteId, int $limit, ?string $language = null, ?string $indexHandle = null): array
    {
        $matches = [];

        // Get all terms from storage (filtered by language and index)
        $allTerms = $this->getAllTerms($storage, $siteId, $language, $indexHandle);

        $this->logDebug('getPrefixMatches: Retrieved terms from storage', [
            'termCount' => count($allTerms),
            'query' => $query,
            'siteId' => $siteId,
            'language' => $language,
            'indexHandle' => $indexHandle,
            'sampleTerms' => array_slice(array_keys($allTerms), 0, 10),
        ]);

        foreach ($allTerms as $term => $frequency) {
            if (str_starts_with($term, $query)) {
                $matches[$term] = $frequency;
            }

            if (count($matches) >= $limit * 2) {
                break; // Collect more than needed for sorting
            }
        }

        $this->logDebug('getPrefixMatches: Found matches', [
            'matchCount' => count($matches),
            'matches' => array_keys($matches),
        ]);

        // Sort by frequency (most common first)
        arsort($matches);

        return array_keys(array_slice($matches, 0, $limit, true));
    }

    /**
     * Get fuzzy-matched suggestions (typo-tolerant)
     *
     * @param mixed $storage Storage instance
     * @param string $query Query string
     * @param int $siteId Site ID
     * @param int $limit Maximum suggestions
     * @return array Matching terms
     */
    private function getFuzzyMatches($storage, string $query, ?int $siteId, int $limit): array
    {
        $settings = SearchManager::$plugin->getSettings();

        // Use existing FuzzyMatcher logic
        $ngramGenerator = new \lindemannrock\searchmanager\search\NgramGenerator(
            explode(',', $settings->ngramSizes ?? '2,3')
        );

        $fuzzyMatcher = new \lindemannrock\searchmanager\search\FuzzyMatcher(
            $ngramGenerator,
            $settings->similarityThreshold ?? 0.25,
            $limit
        );

        if ($siteId !== null) {
            return $fuzzyMatcher->findMatches($query, $storage, $siteId);
        }

        $matches = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteMatches = $fuzzyMatcher->findMatches($query, $storage, (int)$site->id);
            foreach ($siteMatches as $term) {
                if (!in_array($term, $matches, true)) {
                    $matches[] = $term;
                }
                if (count($matches) >= $limit) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * Get all indexed terms for autocomplete
     *
     * @param mixed $storage Storage instance
     * @param int|null $siteId Site ID (null for all-sites indices)
     * @param string|null $language Language filter
     * @param string|null $indexHandle Full index handle (with prefix) to filter by
     * @return array Terms with frequencies [term => frequency]
     */
    private function getAllTerms($storage, ?int $siteId, ?string $language = null, ?string $indexHandle = null): array
    {
        $this->logDebug('getAllTerms: Starting', [
            'storageClass' => $storage ? get_class($storage) : 'null',
            'isStorageInterface' => $storage instanceof StorageInterface,
            'siteId' => $siteId,
            'language' => $language,
            'indexHandle' => $indexHandle,
        ]);

        try {
            // Use the StorageInterface method
            if ($storage instanceof StorageInterface) {
                $terms = $storage->getTermsForAutocomplete($siteId, $language, 1000);
                $this->logDebug('getAllTerms: Got terms from storage', [
                    'termCount' => count($terms),
                ]);
                return $terms;
            }
            $this->logWarning('getAllTerms: Storage is not StorageInterface');
        } catch (\Throwable $e) {
            $this->logError('Failed to get all terms', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return [];
    }

    /**
     * Get element-based autocomplete suggestions with type info
     *
     * Returns rich suggestions with element titles and types for display,
     * perfect for showing icons (📦 for products, 🏷️ for categories).
     *
     * @param string $query Partial search query
     * @param string $indexHandle Index to search
     * @param array $options Suggestion options
     * @return array Array of suggestion objects [{text, type, id}, ...]
     */
    public function suggestElements(string $query, string $indexHandle, array $options = []): array
    {
        $startTime = microtime(true);

        // Get options with defaults from settings
        $settings = SearchManager::$plugin->getSettings();
        $minLength = $options['minLength'] ?? $settings->autocompleteMinLength ?? 2;
        $limit = $options['limit'] ?? $settings->autocompleteLimit ?? 10;
        $siteId = $options['siteId'] ?? \Craft::$app->getSites()->getCurrentSite()->id ?? 1;
        $elementType = $options['type'] ?? null; // Optional filter by type

        // Validate query length
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        // Apply index prefix to get full index name (matches how data is stored)
        $indexPrefix = $settings->indexPrefix ?? '';
        $fullIndexHandle = $indexPrefix . $indexHandle;

        $this->logDebug('Generating element suggestions', [
            'query' => $query,
            'index' => $fullIndexHandle,
            'type_filter' => $elementType,
        ]);

        // Get storage using DRY approach via BackendService
        $storage = $this->getStorageForIndex($indexHandle);
        if (!$storage) {
            return [];
        }

        // Check if storage supports element suggestions (MySqlStorage does)
        if (!method_exists($storage, 'getElementSuggestions')) {
            $this->logWarning('Storage does not support element suggestions, falling back to term suggestions');
            // Fallback to term-based suggestions
            $terms = $this->suggest($query, $indexHandle, $options);
            return array_map(fn($term) => ['text' => $term, 'type' => 'term', 'id' => null], $terms);
        }

        // Get element suggestions from storage
        $suggestions = $storage->getElementSuggestions($query, $siteId, $limit, $elementType);

        // Format response
        $results = [];
        foreach ($suggestions as $suggestion) {
            $results[] = [
                'text' => $suggestion['title'],
                'type' => $suggestion['elementType'],
                'id' => (int)$suggestion['elementId'],
            ];
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->logInfo('Element suggestions generated', [
            'query' => $query,
            'count' => count($results),
            'duration_ms' => $duration,
        ]);

        return $results;
    }

    /**
     * Get storage instance for an index
     *
     * Uses BackendService::getBackendForIndex() to get the properly configured
     * backend, then retrieves storage from it. This is the DRY approach - all
     * backend/storage creation logic is centralized in BackendService.
     *
     * @param string $indexHandle Raw index handle (without prefix)
     * @return StorageInterface|null Storage instance or null
     */
    private function getStorageForIndex(string $indexHandle): ?StorageInterface
    {
        try {
            // Use BackendService to get the properly configured backend for this index
            // This handles all the ConfiguredBackend lookup and settings injection
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);

            if (!$backend) {
                $this->logError('No backend available for index', ['index' => $indexHandle]);
                return null;
            }

            // Check if backend supports storage (internal backends like MySQL, Redis, File)
            if (!method_exists($backend, 'getStorage')) {
                $this->logWarning('Backend does not support direct storage access', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return null;
            }

            $storage = $backend->getStorage($indexHandle);

            $this->logDebug('Got storage from backend', [
                'index' => $indexHandle,
                'backend' => $backend->getName(),
            ]);

            return $storage;
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage for index', [
                'index' => $indexHandle,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get autocomplete suggestions from the backend directly
     *
     * Used for external backends (Meilisearch, Algolia, Typesense) that don't
     * use the storage abstraction but have native autocomplete/search support.
     *
     * @param string $indexHandle Raw index handle (without prefix)
     * @param string $query Partial search query
     * @param array $options Options like limit, siteId
     * @return array Array of suggestion strings
     */
    private function getBackendAutocomplete(string $indexHandle, string $query, array $options = []): array
    {
        try {
            // Get the backend for this index
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);

            if (!$backend) {
                $this->logError('No backend available for index', ['index' => $indexHandle]);
                return [];
            }

            // Check if backend supports autocomplete
            if (!method_exists($backend, 'autocomplete') || !method_exists($backend, 'supportsAutocomplete')) {
                $this->logWarning('Backend does not support autocomplete', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return [];
            }

            if (!$backend->supportsAutocomplete()) {
                $this->logWarning('Backend autocomplete not enabled', [
                    'index' => $indexHandle,
                    'backend' => $backend->getName(),
                ]);
                return [];
            }

            $suggestions = $backend->autocomplete($indexHandle, $query, $options);

            $this->logDebug('Got autocomplete from backend', [
                'index' => $indexHandle,
                'backend' => $backend->getName(),
                'suggestions' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            $this->logError('Failed to get backend autocomplete', [
                'index' => $indexHandle,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if a string contains Arabic script characters
     *
     * @param string $text Text to check
     * @return bool True if contains Arabic script
     */
    private function containsArabicScript(string $text): bool
    {
        // Arabic Unicode range: \x{0600}-\x{06FF} (Arabic)
        // Also includes: \x{0750}-\x{077F} (Arabic Supplement)
        // And: \x{08A0}-\x{08FF} (Arabic Extended-A)
        return (bool)preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text);
    }

    // =========================================================================
    // CACHE METHODS
    // =========================================================================

    /**
     * Normalize query for caching (lowercase, trim, collapse whitespace)
     */
    private function normalizeQuery(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));
    }

    /**
     * Generate cache key for autocomplete
     */
    private function generateCacheKey(string $type, string $indexHandle, string $query, ?int $siteId, ?string $language): string
    {
        $keyData = [
            'type' => $type,
            'index' => $indexHandle,
            'query' => $query,
            'siteId' => $siteId,
            'language' => $language,
        ];

        return md5(json_encode($keyData));
    }

    /**
     * Get from autocomplete cache
     */
    private function getFromCache(string $cacheKey, ?string $indexHandle = null): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . $cacheKey;

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = \Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                $cached = $cache->get($fullCacheKey);
                if ($cached !== false) {
                    return $cached;
                }
                return null;
            }

            if (!self::$redisFallbackLogged) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file cache', [
                    'cacheClass' => get_class($cache),
                ]);
                self::$redisFallbackLogged = true;
            }
        }

        // Use file-based cache (default)
        $cachePath = $this->getCachePath($indexHandle);
        $cacheFile = $cachePath . $cacheKey . '.cache';

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is expired
        $mtime = filemtime($cacheFile);
        if (time() - $mtime > $settings->autocompleteCacheDuration) {
            @unlink($cacheFile);
            return null;
        }

        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        // Use JSON instead of unserialize to prevent object injection attacks
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Invalid JSON (possibly old serialized format) - delete and return miss
            @unlink($cacheFile);
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Save to autocomplete cache
     */
    private function saveToCache(string $cacheKey, array $data, ?string $indexHandle = null): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . $cacheKey;

        $this->logDebug('Saving to autocomplete cache', [
            'cacheKey' => $cacheKey,
            'storageMethod' => $settings->cacheStorageMethod,
            'duration' => $settings->autocompleteCacheDuration,
            'dataCount' => count($data),
        ]);

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cache = \Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                try {
                    $cache->set($fullCacheKey, $data, $settings->autocompleteCacheDuration);

                    // Track key in set for selective deletion
                    $redis = $cache->redis;
                    $redis->executeCommand('SADD', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete'), $fullCacheKey]);

                    $this->logDebug('Saved to Redis autocomplete cache', ['key' => $fullCacheKey]);
                } catch (\Throwable $e) {
                    $this->logError('Failed to save to Redis autocomplete cache', [
                        'key' => $fullCacheKey,
                        'error' => $e->getMessage(),
                    ]);
                }

                return;
            }

            if (!self::$redisFallbackLogged) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file cache', [
                    'cacheClass' => get_class($cache),
                ]);
                self::$redisFallbackLogged = true;
            }
        }

        // Use file-based cache (default)
        try {
            $cachePath = $this->getCachePath($indexHandle);

            // Create directory if it doesn't exist
            if (!is_dir($cachePath)) {
                \craft\helpers\FileHelper::createDirectory($cachePath);
                $this->logDebug('Created autocomplete cache directory', ['path' => $cachePath]);
            }

            $cacheFile = $cachePath . $cacheKey . '.cache';
            // Use JSON instead of serialize to prevent object injection attacks on read
            $result = file_put_contents($cacheFile, json_encode($data, JSON_THROW_ON_ERROR));

            if ($result === false) {
                $this->logError('Failed to write autocomplete cache file', ['file' => $cacheFile]);
            } else {
                $this->logDebug('Saved to file autocomplete cache', [
                    'file' => $cacheFile,
                    'bytes' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to save to file autocomplete cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache path for autocomplete
     */
    private function getCachePath(?string $indexHandle = null): string
    {
        $base = PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
        return $indexHandle ? $base . $indexHandle . '/' : $base;
    }

    /**
     * Clear autocomplete cache for an index
     */
    public function clearCache(?string $indexHandle = null): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullIndexHandle = null;
        if ($indexHandle !== null) {
            $indexPrefix = $settings->indexPrefix ?? '';
            $fullIndexHandle = $indexPrefix . $indexHandle;
        }

        if ($settings->cacheStorageMethod === 'redis') {
            $cache = \Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;
                $keys = $redis->executeCommand('SMEMBERS', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete')]);

                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        // If indexHandle specified, only delete keys for that index
                        if ($fullIndexHandle === null || str_contains($key, $fullIndexHandle)) {
                            $cache->delete($key);
                            $redis->executeCommand('SREM', [PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete'), $key]);
                        }
                    }
                }

                $this->logInfo('Cleared autocomplete cache (Redis)', ['index' => $indexHandle]);
                return;
            }

            if (!self::$redisFallbackLogged) {
                $this->logWarning('Redis cache selected but Craft cache is not Redis; falling back to file cache', [
                    'index' => $indexHandle,
                    'cacheClass' => get_class($cache),
                ]);
                self::$redisFallbackLogged = true;
            }
        }

        // File-based cache
        $cachePath = $this->getCachePath($fullIndexHandle);

        if (is_dir($cachePath)) {
            \craft\helpers\FileHelper::clearDirectory($cachePath);
        }

        $this->logInfo('Cleared autocomplete cache (file)', ['index' => $indexHandle]);
    }
}
