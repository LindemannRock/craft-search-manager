<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\RedisStorage;
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
        $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
        $fuzzy = $options['fuzzy'] ?? $settings->autocompleteFuzzy ?? false;
        $language = $options['language'] ?? null;

        // Auto-detect language from current site if not provided
        if ($language === null) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                $language = substr($site->language, 0, 2);  // en-US → en
            } else {
                $language = 'en';
            }
        }

        // Validate query length
        if (mb_strlen($query) < $minLength) {
            return [];
        }

        // Normalize query
        $query = mb_strtolower(trim($query));

        // Apply index prefix to get full index name (matches how data is stored)
        $indexPrefix = $settings->indexPrefix ?? '';
        $fullIndexHandle = $indexPrefix . $indexHandle;

        $this->logDebug('Generating suggestions', [
            'query' => $query,
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
        $prefixMatches = $this->getPrefixMatches($storage, $query, $siteId, $limit, $language, $fullIndexHandle);
        $suggestions = array_merge($suggestions, $prefixMatches);

        // Method 2: Fuzzy matching (slower, typo-tolerant)
        if ($fuzzy && count($suggestions) < $limit) {
            $fuzzyMatches = $this->getFuzzyMatches($storage, $query, $siteId, $limit - count($suggestions));
            $suggestions = array_merge($suggestions, $fuzzyMatches);
        }

        // Remove duplicates and limit
        $suggestions = array_values(array_unique($suggestions));
        $suggestions = array_slice($suggestions, 0, $limit);

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
     * @param int $siteId Site ID
     * @param int $limit Maximum suggestions
     * @param string|null $language Language filter
     * @param string|null $indexHandle Full index handle (with prefix) to filter by
     * @return array Matching terms
     */
    private function getPrefixMatches($storage, string $query, int $siteId, int $limit, ?string $language = null, ?string $indexHandle = null): array
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
     * @param int $siteId Site ID
     * @param string|null $language Language filter
     * @param string|null $indexHandle Full index handle (with prefix) to filter by
     * @return array Terms with frequencies [term => frequency]
     */
    private function getAllTerms($storage, int $siteId, ?string $language = null, ?string $indexHandle = null): array
    {
        // This is backend-specific and simplified
        // In production, you'd want a dedicated method in StorageInterface

        try {
            if ($storage instanceof MySqlStorage) {
                return $this->getAllTermsFromMysql($siteId, $language, $indexHandle);
            } elseif ($storage instanceof RedisStorage) {
                return $this->getAllTermsFromRedis($storage, $siteId, $language);
            } elseif ($storage instanceof FileStorage) {
                return $this->getAllTermsFromFile($storage, $siteId, $language);
            }
        } catch (\Throwable $e) {
            $this->logError('Failed to get all terms', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get all terms from MySQL
     *
     * @param int $siteId Site ID
     * @param string|null $language Language filter
     * @param string|null $indexHandle Full index handle (with prefix) to filter by
     * @return array Terms with frequencies [term => frequency]
     */
    private function getAllTermsFromMysql(int $siteId, ?string $language = null, ?string $indexHandle = null): array
    {
        try {
            $query = (new \craft\db\Query())
                ->select(['term', 'SUM(frequency) as total_freq'])
                ->from('{{%searchmanager_search_terms}}')
                ->where(['siteId' => $siteId]);

            // Filter by index handle if provided
            if ($indexHandle) {
                $query->andWhere(['indexHandle' => $indexHandle]);
            }

            // Filter by language if provided
            if ($language) {
                $query->andWhere(['language' => $language]);
            }

            $results = $query
                ->groupBy(['term'])
                ->orderBy(['total_freq' => SORT_DESC])
                ->limit(1000) // Limit for performance
                ->all();

            $this->logDebug('Retrieved terms from MySQL', [
                'site_id' => $siteId,
                'index_handle' => $indexHandle,
                'count' => count($results),
            ]);

            $terms = [];
            foreach ($results as $row) {
                $terms[$row['term']] = (int)$row['total_freq'];
            }

            return $terms;
        } catch (\Throwable $e) {
            $this->logError('Failed to get MySQL terms', [
                'error' => $e->getMessage(),
                'site_id' => $siteId,
            ]);
            return [];
        }
    }

    /**
     * Get all terms from Redis
     */
    private function getAllTermsFromRedis($storage, int $siteId, ?string $language = null): array
    {
        try {
            // Use reflection to access private redis property
            $reflection = new \ReflectionClass($storage);
            $redisProperty = $reflection->getProperty('redis');
            $redisProperty->setAccessible(true);
            $redis = $redisProperty->getValue($storage);

            $prefixProperty = $reflection->getProperty('keyPrefix');
            $prefixProperty->setAccessible(true);
            $prefix = $prefixProperty->getValue($storage);

            // Pattern: sm:idx:all-sites:term:TERM:SITE_ID
            $pattern = $prefix . 'term:*:' . $siteId;
            $keys = $redis->keys($pattern);

            $this->logDebug('Redis keys found', [
                'pattern' => $pattern,
                'count' => count($keys),
            ]);

            $terms = [];
            foreach ($keys as $key) {
                // Key format: sm:idx:all-sites:term:TERM:SITE_ID
                // We need to extract TERM (between last two colons)
                $parts = explode(':', $key);

                // Get the term (second to last part)
                if (count($parts) >= 2) {
                    $term = $parts[count($parts) - 2];

                    // Get document count for this term
                    $count = $redis->hLen($key); // Number of documents containing this term
                    $terms[$term] = $count;
                }

                if (count($terms) >= 1000) {
                    break; // Limit for performance
                }
            }

            arsort($terms);

            $this->logDebug('Terms extracted from Redis', [
                'term_count' => count($terms),
                'sample' => array_slice($terms, 0, 5, true),
            ]);

            return $terms;
        } catch (\Throwable $e) {
            $this->logError('Failed to get Redis terms', [
                'error' => $e->getMessage(),
                'site_id' => $siteId,
            ]);
            return [];
        }
    }

    /**
     * Get all terms from File storage
     */
    private function getAllTermsFromFile($storage, int $siteId, ?string $language = null): array
    {
        // Use reflection to access private basePath property
        $reflection = new \ReflectionClass($storage);
        $pathProperty = $reflection->getProperty('basePath');
        $pathProperty->setAccessible(true);
        $basePath = $pathProperty->getValue($storage);

        $termsPath = $basePath . '/terms';

        if (!is_dir($termsPath)) {
            return [];
        }

        $terms = [];
        // File storage uses: term_siteId.dat format (e.g., test_1.dat)
        $files = glob($termsPath . '/*_' . $siteId . '.dat');

        foreach ($files as $file) {
            $basename = basename($file, '.dat');
            // Extract term from filename (test_1 → test)
            $parts = explode('_', $basename);
            array_pop($parts); // Remove site ID
            $term = implode('_', $parts);

            // Read serialized data
            $data = @unserialize(file_get_contents($file));
            $count = is_array($data) ? count($data) : 0;

            if ($count > 0) {
                $terms[$term] = $count;
            }

            if (count($terms) >= 1000) {
                break; // Limit for performance
            }
        }

        arsort($terms);
        return $terms;
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
     * Get storage instance for index from active backend
     *
     * @param string $indexHandle Index handle
     * @return mixed Storage instance or null
     */
    private function getStorage(string $indexHandle)
    {
        $settings = SearchManager::$plugin->getSettings();
        $backendName = $settings->searchBackend;

        // Only support built-in backends (MySQL, PostgreSQL, Redis, File)
        if (!in_array($backendName, ['mysql', 'pgsql', 'redis', 'file'])) {
            $this->logWarning('Autocomplete only supported for MySQL, PostgreSQL, Redis, and File backends', [
                'backend' => $backendName,
            ]);
            return null;
        }

        try {
            // Get the active backend
            $backend = SearchManager::$plugin->backend->getActiveBackend();

            if (!$backend) {
                $this->logError('No active backend available');
                return null;
            }

            // All built-in backends have getStorage() method
            // Type assertion for PHPStan since BackendInterface doesn't define getStorage()
            if (method_exists($backend, 'getStorage')) {
                /** @var \lindemannrock\searchmanager\backends\MySqlBackend|\lindemannrock\searchmanager\backends\PostgreSqlBackend|\lindemannrock\searchmanager\backends\RedisBackend|\lindemannrock\searchmanager\backends\FileBackend $backend */
                $storage = $backend->getStorage($indexHandle);

                $this->logDebug('Retrieved storage from backend', [
                    'backend' => $backendName,
                    'storage' => get_class($storage),
                ]);

                return $storage;
            }

            $this->logError('Backend does not support getStorage()', [
                'backend' => $backendName,
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logError('Failed to get storage from backend', [
                'backend' => $backendName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get storage instance with full index name (prefix already applied)
     *
     * Used when the caller has already applied the index prefix.
     *
     * @param string $fullIndexName Full index name including prefix
     * @return mixed Storage instance or null
     */
    private function getStorageWithFullName(string $fullIndexName)
    {
        $settings = SearchManager::$plugin->getSettings();
        $backendName = $settings->searchBackend;

        // Only support MySQL for now (element suggestions)
        if ($backendName !== 'mysql') {
            $this->logWarning('Element suggestions only supported for MySQL backend', [
                'backend' => $backendName,
            ]);
            return null;
        }

        try {
            // Create storage directly with the full index name
            $storage = new \lindemannrock\searchmanager\search\storage\MySqlStorage($fullIndexName);

            $this->logDebug('Created storage with full index name', [
                'index' => $fullIndexName,
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
}
