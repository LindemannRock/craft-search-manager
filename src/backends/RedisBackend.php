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
     * @param string $indexHandle Index handle
     * @return SearchEngine
     */
    private function getSearchEngine(string $indexHandle): SearchEngine
    {
        if (!isset($this->searchEngines[$indexHandle])) {
            $storage = $this->getStorage($indexHandle);
            $settings = SearchManager::$plugin->getSettings();

            $this->searchEngines[$indexHandle] = new SearchEngine($storage, $indexHandle, [
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

        return $this->searchEngines[$indexHandle];
    }

    /**
     * Get or create storage instance (public for autocomplete/other services)
     *
     * @param string $indexHandle Index handle
     * @return RedisStorage
     */
    public function getStorage(string $indexHandle): RedisStorage
    {
        if (!isset($this->storages[$indexHandle])) {
            $backendSettings = $this->getBackendSettings();
            $this->storages[$indexHandle] = new RedisStorage($indexHandle, $backendSettings);
        }

        return $this->storages[$indexHandle];
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
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

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

            // Use SearchEngine to index
            $success = $engine->indexDocument($siteId, $elementId, $title, $content);

            if ($success) {
                $this->logDebug('Document indexed with SearchEngine', [
                    'index' => $fullIndexName,
                    'element_id' => $elementId,
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            $this->logError('Failed to index in Redis', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        try {
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

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

                // Use SearchEngine to index
                $engine->indexDocument($siteId, $elementId, $title, $content);
            }

            $this->logInfo('Batch indexed in Redis', [
                'index' => $fullIndexName,
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
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $success = $engine->deleteDocument($siteId, $elementId);

            if ($success) {
                $this->logDebug('Document deleted with SearchEngine', [
                    'index' => $fullIndexName,
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
            $fullIndexName = $this->getFullIndexName($indexName);
            $engine = $this->getSearchEngine($fullIndexName);

            // Get site ID from options or use current site
            $siteId = $options['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id ?? 1;
            $limit = $options['limit'] ?? 0;

            // Use SearchEngine to search
            $results = $engine->search($query, $siteId, $limit);

            // Convert to backend format
            $hits = [];
            foreach ($results as $elementId => $score) {
                $hits[] = [
                    'objectID' => $elementId,
                    'id' => $elementId,
                    'score' => $score,
                ];
            }

            $this->logDebug('Search completed with SearchEngine', [
                'index' => $fullIndexName,
                'query' => $query,
                'result_count' => count($hits),
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
            $fullIndexName = $this->getFullIndexName($indexName);
            $backendSettings = $this->getBackendSettings();
            $storage = new RedisStorage($fullIndexName, $backendSettings);

            // Clear all data for this index
            $storage->clearAll();

            $this->logInfo('Cleared Redis index with SearchEngine', [
                'index' => $fullIndexName,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logError('Failed to clear Redis index', ['error' => $e->getMessage()]);
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
