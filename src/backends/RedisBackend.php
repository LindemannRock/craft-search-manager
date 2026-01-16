<?php

namespace lindemannrock\searchmanager\backends;

use Craft;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\SearchManager;

/**
 * Redis Backend
 *
 * Search backend using BM25 algorithm with Redis storage
 * Includes fuzzy matching, title boosting, and n-gram based typo tolerance
 * Fast, in-memory search with persistence
 */
class RedisBackend extends BaseBackend
{
    private ?\Redis $_client = null;

    /**
     * @var array<string, SearchEngine> Search engine instances per index
     */
    private array $searchEngines = [];

    /**
     * @var array<string, RedisStorage> Storage instances per index
     */
    private array $storages = [];

    public function getName(): string
    {
        return 'redis';
    }

    /**
     * Get or create SearchEngine instance for an index
     *
     * NOTE: This method expects a RAW index handle (without prefix).
     * It will apply the prefix internally.
     *
     * @param string $indexHandle Raw index handle (e.g., 'all-sites', not 'searchmanager_all-sites')
     * @return SearchEngine
     */
    private function getSearchEngine(string $indexHandle): SearchEngine
    {
        // Apply prefix to get the full index name for storage
        $fullIndexName = $this->getFullIndexName($indexHandle);

        if (!isset($this->searchEngines[$fullIndexName])) {
            $storage = $this->getStorageInternal($fullIndexName);
            $settings = SearchManager::$plugin->getSettings();

            $this->searchEngines[$fullIndexName] = new SearchEngine($storage, $fullIndexName, [
                'k1' => $settings->bm25K1 ?? 1.5,
                'b' => $settings->bm25B ?? 0.75,
                'titleBoost' => $settings->titleBoostFactor ?? 5.0,
                'exactMatchBoost' => $settings->exactMatchBoostFactor ?? 3.0,
                'phraseBoost' => $settings->phraseBoostFactor ?? 4.0,
                'ngramSizes' => explode(',', $settings->ngramSizes ?? '2,3'),
                'similarityThreshold' => $settings->similarityThreshold ?? 0.25,
                'maxFuzzyCandidates' => $settings->maxFuzzyCandidates ?? 100,
            ]);
        }

        return $this->searchEngines[$fullIndexName];
    }

    /**
     * Get or create storage instance (public for autocomplete/other services)
     *
     * NOTE: This method expects a RAW index handle (without prefix).
     * It will apply the prefix internally.
     *
     * @param string $indexHandle Raw index handle (e.g., 'all-sites')
     * @return RedisStorage
     */
    public function getStorage(string $indexHandle): RedisStorage
    {
        // Apply index prefix to get the full index name
        $fullIndexName = $this->getFullIndexName($indexHandle);

        return $this->getStorageInternal($fullIndexName);
    }

    /**
     * Internal method to get storage by full (already-prefixed) index name
     *
     * @param string $fullIndexName Full index name with prefix
     * @return RedisStorage
     */
    private function getStorageInternal(string $fullIndexName): RedisStorage
    {
        if (!isset($this->storages[$fullIndexName])) {
            $backendSettings = $this->getBackendSettings();
            $this->storages[$fullIndexName] = new RedisStorage($fullIndexName, $backendSettings);
        }

        return $this->storages[$fullIndexName];
    }

    public function isAvailable(): bool
    {
        if (!extension_loaded('redis')) {
            return false;
        }

        try {
            $client = $this->getClient();
            $client->ping();
            return true;
        } catch (\Throwable $e) {
            $this->logError('Redis connection failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getStatus(): array
    {
        $settings = $this->getBackendSettings();

        return [
            'name' => 'Redis',
            'enabled' => $this->isEnabledInConfig(),
            'configured' => !empty($settings['host']) || Craft::$app->cache instanceof \yii\redis\Cache,
            'available' => $this->isAvailable(),
            'extension' => extension_loaded('redis'),
        ];
    }

    public function index(string $indexName, array $data): bool
    {
        try {
            // Pass raw handle - getSearchEngine applies prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

            // Extract title and content
            $title = $data['title'] ?? '';
            $content = implode(' ', [
                $data['content'] ?? '',
                $data['excerpt'] ?? '',
                $data['body'] ?? '',
            ]);

            // Get site ID and element ID
            $siteId = $data['siteId'] ?? 1;
            $elementId = $data['objectID'] ?? $data['id'];

            // Get element type: from data, or derive from index name
            $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);

            // Use SearchEngine to index
            $success = $engine->indexDocument($siteId, $elementId, $title, $content);

            if ($success) {
                // Store element metadata for rich autocomplete suggestions
                $storage->storeElement($siteId, $elementId, $title, $elementType);

                $this->logDebug('Document indexed with SearchEngine', [
                    'index' => $indexName,
                    'element_id' => $elementId,
                    'element_type' => $elementType,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in Redis', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Derive element type from index name or data
     *
     * @param string $indexName Index name
     * @param array $data Element data
     * @return string Element type (product, category, etc.)
     */
    private function deriveElementType(string $indexName, array $data): string
    {
        // Check for common patterns in index name
        $indexLower = strtolower($indexName);

        if (str_contains($indexLower, 'product')) {
            return 'product';
        }

        if (str_contains($indexLower, 'categor')) {
            return 'category';
        }

        if (str_contains($indexLower, 'article') || str_contains($indexLower, 'blog') || str_contains($indexLower, 'post')) {
            return 'article';
        }

        if (str_contains($indexLower, 'page')) {
            return 'page';
        }

        // Fallback: check Craft element class if available
        if (isset($data['_elementType'])) {
            $elementClass = $data['_elementType'];
            if (str_contains($elementClass, 'Category')) {
                return 'category';
            }
            if (str_contains($elementClass, 'Entry')) {
                return 'entry';
            }
            if (str_contains($elementClass, 'Asset')) {
                return 'asset';
            }
        }

        return 'entry'; // Default fallback
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        try {
            // Pass raw handle - getSearchEngine applies prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

            foreach ($items as $data) {
                // Extract title and content
                $title = $data['title'] ?? '';
                $content = implode(' ', [
                    $data['content'] ?? '',
                    $data['excerpt'] ?? '',
                    $data['body'] ?? '',
                ]);

                // Get site ID and element ID
                $siteId = $data['siteId'] ?? 1;
                $elementId = $data['objectID'] ?? $data['id'];

                // Get element type: from data, or derive from index name
                $elementType = $data['elementType'] ?? $this->deriveElementType($indexName, $data);

                // Use SearchEngine to index
                $engine->indexDocument($siteId, $elementId, $title, $content);

                // Store element metadata for rich autocomplete suggestions
                $storage->storeElement($siteId, $elementId, $title, $elementType);
            }

            $this->logInfo('Batch indexed in Redis', [
                'index' => $indexName,
                'count' => count($items),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to batch index in Redis', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            // Pass raw handle - getSearchEngine applies prefix internally
            $engine = $this->getSearchEngine($indexName);

            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $indexName,
                    'element_id' => $elementId,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to delete from Redis', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        try {
            // Pass raw handle - getSearchEngine/getStorage apply prefix internally
            $engine = $this->getSearchEngine($indexName);
            $storage = $this->getStorage($indexName);

            // Get site ID from options - check raw value first for "all sites" detection
            $rawSiteId = $options['siteId'] ?? null;
            $limit = $options['limit'] ?? 0;
            $typeFilter = $options['type'] ?? null;

            // Handle "all sites" search (siteId = '*' or null/not set)
            $searchAllSites = $rawSiteId === '*' || $rawSiteId === null;
            $siteIdOption = $rawSiteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;

            if ($searchAllSites) {
                // Search across all sites and combine results
                // For all-sites search, show each site version separately (no deduplication)
                $allResults = [];
                $allSites = Craft::$app->getSites()->getAllSites();

                foreach ($allSites as $site) {
                    $siteResults = $engine->search($query, $site->id, 0);
                    foreach ($siteResults as $elementId => $score) {
                        // Use composite key to keep results from all sites (no deduplication)
                        $compositeKey = $site->id . ':' . $elementId;
                        $allResults[$compositeKey] = [
                            'elementId' => $elementId,
                            'score' => $score,
                            'siteId' => $site->id,
                        ];
                    }
                }

                $hits = [];
                foreach ($allResults as $compositeKey => $data) {
                    $elementId = $data['elementId'];
                    $elementInfo = $storage->getElementsByIds($data['siteId'], [$elementId]);
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    if ($typeFilter !== null) {
                        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', $typeFilter);
                        if (!in_array($elementType, $allowedTypes, true)) {
                            continue;
                        }
                    }

                    $hits[] = [
                        'objectID' => $elementId,
                        'id' => $elementId,
                        'score' => $data['score'],
                        'type' => $elementType,
                        'siteId' => $data['siteId'],
                    ];
                }

                usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
                if ($limit > 0) {
                    $hits = array_slice($hits, 0, $limit);
                }
            } else {
                $siteId = (int)$siteIdOption;
                $results = $engine->search($query, $siteId, $limit);
                $elementIds = array_keys($results);
                $elementInfo = $storage->getElementsByIds($siteId, $elementIds);

                $hits = [];
                foreach ($results as $elementId => $score) {
                    $info = $elementInfo[$elementId] ?? null;
                    $elementType = $info['elementType'] ?? 'entry';

                    if ($typeFilter !== null) {
                        $allowedTypes = is_array($typeFilter) ? $typeFilter : explode(',', $typeFilter);
                        if (!in_array($elementType, $allowedTypes, true)) {
                            continue;
                        }
                    }

                    $hits[] = [
                        'objectID' => $elementId,
                        'id' => $elementId,
                        'score' => $score,
                        'type' => $elementType,
                    ];
                }
            }

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $indexName,
                'query' => $query,
                'result_count' => count($hits),
                'type_filter' => $typeFilter,
                'all_sites' => $searchAllSites,
            ]);

            return ['hits' => $hits, 'total' => count($hits)];
        } catch (\Throwable $e) {
            $this->logError('Redis search failed', ['error' => $e->getMessage()]);
            return ['hits' => [], 'total' => 0];
        }
    }

    public function clearIndex(string $indexName): bool
    {
        try {
            // Pass raw handle - getStorage applies prefix internally
            $storage = $this->getStorage($indexName);

            // Clear all data for this index
            $storage->clearAll();

            // Also clear cached engine and storage instances
            $fullIndexName = $this->getFullIndexName($indexName);
            unset($this->searchEngines[$fullIndexName]);
            unset($this->storages[$fullIndexName]);

            $this->logInfo('Cleared Redis index with SearchEngine', [
                'index' => $indexName,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Redis index', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        try {
            // Pass raw handle - getStorage applies prefix internally
            $storage = $this->getStorage($indexName);
            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

            // Check if document has any terms indexed (means it exists)
            $terms = $storage->getDocumentTerms($siteId, $elementId);

            return !empty($terms);
        } catch (\Throwable $e) {
            $this->logError('Failed to check document existence', [
                'index' => $indexName,
                'elementId' => $elementId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function getClient(): \Redis
    {
        if ($this->_client === null) {
            $settings = $this->getBackendSettings();

            // If no host configured, use Craft's Redis cache settings
            if (empty($settings['host']) && Craft::$app->cache instanceof \yii\redis\Cache) {
                $this->logInfo('Using Craft Redis cache settings for Search Manager');

                $redisConnection = Craft::$app->cache->redis;
                $this->_client = new \Redis();

                $craftHost = $redisConnection->hostname ?? 'localhost';
                $craftPort = $redisConnection->port ?? 6379;
                $craftPassword = $redisConnection->password ?? null;
                $craftDatabase = $redisConnection->database ?? 0;

                $this->logInfo('Connecting to Craft Redis', [
                    'host' => $craftHost,
                    'port' => $craftPort,
                    'database' => $craftDatabase,
                ]);

                $this->_client->connect($craftHost, $craftPort, 2.0);

                if (!empty($craftPassword)) {
                    $this->_client->auth($craftPassword);
                }

                $this->_client->select((int)$craftDatabase);

                return $this->_client;
            }

            // Otherwise create dedicated connection
            $this->_client = new \Redis();

            // Resolve environment variables (no defaults - must be explicit)
            $host = $this->resolveEnvVar($settings['host'] ?? null, null);
            $port = (int)$this->resolveEnvVar($settings['port'] ?? null, null);
            $timeout = (float)$this->resolveEnvVar($settings['timeout'] ?? null, 2.0);

            if (!$host || !$port) {
                throw new \Exception('Redis host and port must be configured');
            }

            $this->logInfo('Connecting to Redis', [
                'raw_host' => $settings['host'] ?? 'null',
                'resolved_host' => $host,
                'raw_port' => $settings['port'] ?? 'null',
                'resolved_port' => $port,
            ]);

            $this->_client->connect($host, $port, $timeout);

            // Authenticate if password provided
            $password = $this->resolveEnvVar($settings['password'] ?? null, null);
            if (!empty($password)) {
                $this->_client->auth($password);
            }

            // Select database (required, must be explicit even if 0)
            $database = $this->resolveEnvVar($settings['database'] ?? null, null);
            if ($database === null || $database === '') {
                throw new \Exception('Redis database must be configured (use 0 for default database)');
            }
            $this->_client->select((int)$database);
        }

        return $this->_client;
    }
}
