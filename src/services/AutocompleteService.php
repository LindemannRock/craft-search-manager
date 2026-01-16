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

        // Auto-detect language from query script if not provided and no specific site
        if ($language === null) {
            if (!$siteIdProvided && $this->containsArabicScript($query)) {
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
            $cached = $this->getFromCache($cacheKey);
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

        // Get storage for the current backend
        $storage = $this->getStorage($indexHandle);
        if (!$storage) {
            return [];
        }

        $suggestions = [];

        // Method 1: Prefix matching (fast, exact)
        // For all-sites indices (siteId not provided), skip siteId filter
        $prefixMatches = $this->getPrefixMatches($storage, $normalizedQuery, $siteIdProvided ? $siteId : null, $limit, $language, $fullIndexHandle);
        $suggestions = array_merge($suggestions, $prefixMatches);

        // Method 2: Fuzzy matching (slower, typo-tolerant)
        if ($fuzzy && count($suggestions) < $limit) {
            $fuzzyMatches = $this->getFuzzyMatches($storage, $normalizedQuery, $siteId, $limit - count($suggestions));
            $suggestions = array_merge($suggestions, $fuzzyMatches);
        }

        // Remove duplicates and limit
        $suggestions = array_values(array_unique($suggestions));
        $suggestions = array_slice($suggestions, 0, $limit);

        // Save to cache
        if ($settings->enableAutocompleteCache) {
            $this->saveToCache($cacheKey, $suggestions);
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

        foreach ($allTerms as $term => $frequency) {
            if (str_starts_with($term, $query)) {
                $matches[$term] = $frequency;
            }

            if (count($matches) >= $limit * 2) {
                break; // Collect more than needed for sorting
            }
        }

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
    private function getFuzzyMatches($storage, string $query, int $siteId, int $limit): array
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

        return $fuzzyMatcher->findMatches($query, $storage, $siteId);
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
        try {
            // Use the StorageInterface method
            if ($storage instanceof StorageInterface) {
                return $storage->getTermsForAutocomplete($siteId, $language, 1000);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to get all terms', [
                'error' => $e->getMessage(),
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

        // Get storage for the current backend (pass full index name)
        $storage = $this->getStorageWithFullName($fullIndexHandle);
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
     * Get storage instance for index's configured backend
     *
     * @param string $indexHandle Index handle
     * @return mixed Storage instance or null
     */
    private function getStorage(string $indexHandle)
    {
        try {
            // Get the backend for this specific index (respects per-index backend overrides)
            $backend = SearchManager::$plugin->backend->getBackendForIndex($indexHandle);

            if (!$backend) {
                $this->logError('No backend available for index', ['index' => $indexHandle]);
                return null;
            }

            $backendName = $backend->getName();

            // Only support built-in backends (MySQL, PostgreSQL, Redis, File)
            if (!in_array($backendName, ['mysql', 'pgsql', 'redis', 'file'])) {
                $this->logWarning('Autocomplete only supported for MySQL, PostgreSQL, Redis, and File backends', [
                    'backend' => $backendName,
                    'index' => $indexHandle,
                ]);
                return null;
            }

            // All built-in backends have getStorage() method
            // Type assertion for PHPStan since BackendInterface doesn't define getStorage()
            if (method_exists($backend, 'getStorage')) {
                /** @var \lindemannrock\searchmanager\backends\MySqlBackend|\lindemannrock\searchmanager\backends\PostgreSqlBackend|\lindemannrock\searchmanager\backends\RedisBackend|\lindemannrock\searchmanager\backends\FileBackend $backend */
                $storage = $backend->getStorage($indexHandle);

                $this->logDebug('Retrieved storage from backend for index', [
                    'backend' => $backendName,
                    'index' => $indexHandle,
                    'storage' => get_class($storage),
                ]);

                return $storage;
            }

            $this->logError('Backend does not support getStorage()', [
                'backend' => $backendName,
                'index' => $indexHandle,
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage from backend', [
                'index' => $indexHandle,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get storage instance with full index name (prefix already applied)
     *
     * Used when the caller has already applied the index prefix.
     * Supports MySQL, Redis, and File backends (not PostgreSQL - no element suggestions support).
     *
     * @param string $fullIndexName Full index name including prefix
     * @return mixed Storage instance or null
     */
    private function getStorageWithFullName(string $fullIndexName)
    {
        $backendName = $this->getDefaultBackendType();

        // Support MySQL, PostgreSQL, Redis, and File for element suggestions
        // Note: PostgreSQL uses MySqlStorage which has getElementSuggestions
        if (!in_array($backendName, ['mysql', 'pgsql', 'redis', 'file'])) {
            $this->logWarning('Element suggestions only supported for MySQL, PostgreSQL, Redis, and File backends', [
                'backend' => $backendName,
            ]);
            return null;
        }

        try {
            if ($backendName === 'mysql' || $backendName === 'pgsql') {
                // Create MySQL storage directly with the full index name
                // PostgreSQL also uses MySqlStorage (same SQL structure)
                $storage = new \lindemannrock\searchmanager\search\storage\MySqlStorage($fullIndexName);
            } elseif ($backendName === 'redis') {
                // Create Redis storage directly with the full index name
                $backend = SearchManager::$plugin->backend->getBackend('redis');
                $backendSettings = [];
                if ($backend && method_exists($backend, 'getBackendSettings')) {
                    // Use reflection to access protected method
                    $reflection = new \ReflectionMethod($backend, 'getBackendSettings');
                    $reflection->setAccessible(true);
                    $backendSettings = $reflection->invoke($backend);
                }
                $storage = new \lindemannrock\searchmanager\search\storage\RedisStorage($fullIndexName, $backendSettings);
            } else {
                // Create File storage directly with the full index name
                $storage = new \lindemannrock\searchmanager\search\storage\FileStorage($fullIndexName);
            }

            $this->logDebug('Created storage with full index name', [
                'index' => $fullIndexName,
                'backend' => $backendName,
            ]);

            return $storage;
        } catch (\Throwable $e) {
            $this->logError('Failed to create storage', [
                'index' => $fullIndexName,
                'error' => $e->getMessage(),
            ]);
            return null;
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

    /**
     * Get the default backend type from configured backends
     */
    private function getDefaultBackendType(): string
    {
        $settings = SearchManager::$plugin->getSettings();
        $defaultHandle = $settings->defaultBackendHandle;

        if (!$defaultHandle) {
            return 'file'; // Fallback to file if no default configured
        }

        $configuredBackend = \lindemannrock\searchmanager\models\ConfiguredBackend::findByHandle($defaultHandle);
        if ($configuredBackend) {
            return $configuredBackend->backendType;
        }

        // Fallback: might be a backend type directly for backwards compatibility
        return $defaultHandle;
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
    private function getFromCache(string $cacheKey): ?array
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = 'searchmanager:autocomplete:' . $cacheKey;

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            $cached = \Craft::$app->cache->get($fullCacheKey);
            if ($cached !== false) {
                return $cached;
            }
            return null;
        }

        // Use file-based cache (default)
        $cachePath = $this->getCachePath();
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

        $data = @unserialize($content);
        return is_array($data) ? $data : null;
    }

    /**
     * Save to autocomplete cache
     */
    private function saveToCache(string $cacheKey, array $data): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $fullCacheKey = 'searchmanager:autocomplete:' . $cacheKey;

        $this->logDebug('Saving to autocomplete cache', [
            'cacheKey' => $cacheKey,
            'storageMethod' => $settings->cacheStorageMethod,
            'duration' => $settings->autocompleteCacheDuration,
            'dataCount' => count($data),
        ]);

        // Use Redis/database cache if configured
        if ($settings->cacheStorageMethod === 'redis') {
            try {
                $cache = \Craft::$app->cache;
                $cache->set($fullCacheKey, $data, $settings->autocompleteCacheDuration);

                // Track key in set for selective deletion
                if ($cache instanceof \yii\redis\Cache) {
                    $redis = $cache->redis;
                    $redis->executeCommand('SADD', ['searchmanager-autocomplete-keys', $fullCacheKey]);
                }

                $this->logDebug('Saved to Redis autocomplete cache', ['key' => $fullCacheKey]);
            } catch (\Throwable $e) {
                $this->logError('Failed to save to Redis autocomplete cache', [
                    'key' => $fullCacheKey,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        // Use file-based cache (default)
        try {
            $cachePath = $this->getCachePath();

            // Create directory if it doesn't exist
            if (!is_dir($cachePath)) {
                \craft\helpers\FileHelper::createDirectory($cachePath);
                $this->logDebug('Created autocomplete cache directory', ['path' => $cachePath]);
            }

            $cacheFile = $cachePath . $cacheKey . '.cache';
            $result = file_put_contents($cacheFile, serialize($data));

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
    private function getCachePath(): string
    {
        return PluginHelper::getCachePath(SearchManager::$plugin, 'autocomplete');
    }

    /**
     * Clear autocomplete cache for an index
     */
    public function clearCache(?string $indexHandle = null): void
    {
        $settings = SearchManager::$plugin->getSettings();

        if ($settings->cacheStorageMethod === 'redis') {
            $cache = \Craft::$app->cache;
            if ($cache instanceof \yii\redis\Cache) {
                $redis = $cache->redis;
                $keys = $redis->executeCommand('SMEMBERS', ['searchmanager-autocomplete-keys']);

                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        // If indexHandle specified, only delete keys for that index
                        if ($indexHandle === null || str_contains($key, $indexHandle)) {
                            $cache->delete($key);
                            $redis->executeCommand('SREM', ['searchmanager-autocomplete-keys', $key]);
                        }
                    }
                }
            }

            $this->logInfo('Cleared autocomplete cache (Redis)', ['index' => $indexHandle]);
            return;
        }

        // File-based cache
        $cachePath = $this->getCachePath();

        if (is_dir($cachePath)) {
            $files = glob($cachePath . '*.cache');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        $this->logInfo('Cleared autocomplete cache (file)', ['index' => $indexHandle]);
    }
}
