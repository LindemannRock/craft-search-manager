<?php

namespace lindemannrock\searchmanager\search\storage;

use craft\helpers\App;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * RedisStorage
 *
 * Redis-based storage implementation using hashes, sets, and sorted sets.
 * Uses Redis pipelining for batch operations to optimize performance.
 *
 * Key patterns:
 * - sm:idx:{index}:doc:{siteId}:{elementId} → HASH {term: freq, _length: N}
 * - sm:idx:{index}:term:{term}:{siteId} → HASH {docId: frequency}
 * - sm:idx:{index}:title:{siteId}:{elementId} → SET {terms}
 * - sm:idx:{index}:ngram:{siteId}:{ngram} → SET {terms}
 * - sm:idx:{index}:ngramcount:{siteId}:{term} → STRING (count)
 * - sm:idx:{index}:meta:{siteId}:{key} → STRING (value)
 *
 * @since 5.0.0
 */
class RedisStorage implements StorageInterface
{
    use LoggingTrait;

    /**
     * @var string Index handle
     */
    private string $indexHandle;

    /**
     * @var \Redis Redis connection
     */
    private $redis;

    /**
     * @var string Key prefix
     */
    private string $keyPrefix;

    /**
     * Constructor
     *
     * @param string $indexHandle Index handle
     * @param array $config Redis configuration
     * @throws \Exception If Redis is not available
     */
    public function __construct(string $indexHandle, array $config = [])
    {
        $this->setLoggingHandle('search-manager');
        $this->indexHandle = $indexHandle;
        $this->keyPrefix = 'sm:idx:' . $indexHandle . ':';

        // Initialize Redis connection
        $this->initializeRedis($config);

        $this->logDebug('Initialized RedisStorage', [
            'index' => $this->indexHandle,
            'prefix' => $this->keyPrefix,
        ]);
    }

    /**
     * Initialize Redis connection
     *
     * @param array $config Redis configuration
     * @return void
     * @throws \Exception
     */
    private function initializeRedis(array $config): void
    {
        if (!class_exists('\Redis')) {
            throw new \Exception('Redis extension is not installed');
        }

        $this->redis = new \Redis();

        // Resolve environment variables (strip $ prefix if present)
        $host = $this->resolveEnvVar($config['host'] ?? null, '127.0.0.1');
        $port = (int)$this->resolveEnvVar($config['port'] ?? null, 6379);
        $password = $this->resolveEnvVar($config['password'] ?? null, null);
        $database = (int)$this->resolveEnvVar($config['database'] ?? null, 0);

        try {
            $connected = $this->redis->connect($host, $port);

            if (!$connected) {
                $this->logError('Failed to connect to Redis', [
                    'host' => $host,
                    'port' => $port,
                    'port_type' => gettype($port),
                ]);
                throw new \Exception("Failed to connect to Redis at {$host}:{$port}");
            }
        } catch (\Throwable $e) {
            $this->logError('Redis connection error', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        if ($password) {
            $this->redis->auth($password);
        }

        $this->redis->select($database);
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        $key = $this->getDocKey($siteId, $elementId);

        // Prepare data with _length and _language
        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;

        // Use HMSET to store all term frequencies at once
        $this->redis->hMSet($key, $data);

        $this->logDebug('Stored document', [
            'site_id' => $siteId,
            'element_id' => $elementId,
            'language' => $language,
            'term_count' => count($termFreqs),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTerms(int $siteId, int $elementId): array
    {
        $key = $this->getDocKey($siteId, $elementId);
        $data = $this->redis->hGetAll($key);

        if (!$data) {
            return [];
        }

        // Remove _length from terms
        unset($data['_length']);

        // Convert to integers
        return array_map('intval', $data);
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(int $siteId, int $elementId): void
    {
        // Delete document data
        $docKey = $this->getDocKey($siteId, $elementId);
        $this->redis->del($docKey);

        // Delete title terms
        $titleKey = $this->getTitleKey($siteId, $elementId);
        $this->redis->del($titleKey);

        // Delete element metadata
        $this->deleteElement($siteId, $elementId);

        $this->logDebug('Deleted document, title terms, and element', [
            'site_id' => $siteId,
            'element_id' => $elementId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLength(int $siteId, int $elementId): int
    {
        $key = $this->getDocKey($siteId, $elementId);
        $length = $this->redis->hGet($key, '_length');

        return $length ? (int)$length : 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLanguage(int $siteId, int $elementId): string
    {
        $key = $this->getDocKey($siteId, $elementId);
        $language = $this->redis->hGet($key, '_language');

        return $language ?: 'en';
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatch(array $docIds): array
    {
        $lengths = [];

        // Use pipeline for batch operation
        $this->redis->multi(\Redis::PIPELINE);

        $keys = [];
        foreach ($docIds as $siteId => $elementIds) {
            foreach ($elementIds as $elementId) {
                $key = $this->getDocKey($siteId, $elementId);
                $keys[] = ['siteId' => $siteId, 'elementId' => $elementId];
                $this->redis->hGet($key, '_length');
            }
        }

        $results = $this->redis->exec();

        // Map results back to docIds
        foreach ($results as $index => $length) {
            if ($length !== false) {
                $docId = $keys[$index]['siteId'] . ':' . $keys[$index]['elementId'];
                $lengths[$docId] = (int)$length;
            }
        }

        return $lengths;
    }

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeTermDocument(string $term, int $siteId, int $elementId, int $frequency, string $language = 'en'): void
    {
        $key = $this->getTermKey($term, $siteId);
        $docId = $siteId . ':' . $elementId;
        // Note: Redis storage uses siteId for language context, language param not stored separately

        $this->redis->hSet($key, $docId, $frequency);
    }

    /**
     * @inheritdoc
     */
    public function getTermDocuments(string $term, int $siteId): array
    {
        $key = $this->getTermKey($term, $siteId);
        $data = $this->redis->hGetAll($key);

        if (!$data) {
            return [];
        }

        // Convert to integers
        return array_map('intval', $data);
    }

    /**
     * @inheritdoc
     */
    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $key = $this->getTermKey($term, $siteId);
        $docId = $siteId . ':' . $elementId;

        $this->redis->hDel($key, $docId);
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        if (empty($titleTerms)) {
            return;
        }

        $key = $this->getTitleKey($siteId, $elementId);

        // Use SADD to add all terms to set
        $this->redis->sAddArray($key, $titleTerms);

        $this->logDebug('Stored title terms', [
            'site_id' => $siteId,
            'element_id' => $elementId,
            'term_count' => count($titleTerms),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getTitleTerms(int $siteId, int $elementId): array
    {
        $key = $this->getTitleKey($siteId, $elementId);
        $terms = $this->redis->sMembers($key);

        return $terms ?: [];
    }

    /**
     * @inheritdoc
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $key = $this->getTitleKey($siteId, $elementId);
        $this->redis->del($key);
    }

    // =========================================================================
    // ELEMENT OPERATIONS (for rich autocomplete suggestions)
    // =========================================================================

    /**
     * Store element metadata for autocomplete suggestions
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @param string $title Full title for display
     * @param string $elementType Element type (product, category, etc.)
     * @return void
     */
    public function storeElement(int $siteId, int $elementId, string $title, string $elementType): void
    {
        $key = $this->getElementKey($siteId, $elementId);

        // Normalize searchText for prefix matching (lowercase)
        $searchText = mb_strtolower(trim($title));

        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
        ];

        $this->redis->hMSet($key, $data);

        // Also add to a sorted set for prefix searching
        $indexKey = $this->keyPrefix . 'elemindex:' . $siteId;
        $this->redis->zAdd($indexKey, 0, $elementId . ':' . $searchText);

        $this->logDebug('Stored element for suggestions', [
            'site_id' => $siteId,
            'element_id' => $elementId,
            'type' => $elementType,
        ]);
    }

    /**
     * Delete element metadata
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return void
     */
    public function deleteElement(int $siteId, int $elementId): void
    {
        // Get element data first to remove from index
        $key = $this->getElementKey($siteId, $elementId);
        $data = $this->redis->hGetAll($key);

        if (!empty($data['searchText'])) {
            // Remove from sorted set index
            $indexKey = $this->keyPrefix . 'elemindex:' . $siteId;
            $this->redis->zRem($indexKey, $elementId . ':' . $data['searchText']);
        }

        // Delete element hash
        $this->redis->del($key);
    }

    /**
     * Get element info for a list of element IDs
     *
     * @param int $siteId Site ID
     * @param array $elementIds Array of element IDs
     * @return array Map of elementId => ['title' => ..., 'elementType' => ...]
     */
    public function getElementsByIds(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        // Use pipeline to batch fetch element data
        $this->redis->multi(\Redis::PIPELINE);

        foreach ($elementIds as $elementId) {
            $key = $this->getElementKey($siteId, (int)$elementId);
            $this->redis->hGetAll($key);
        }

        $elementsData = $this->redis->exec();

        $result = [];
        foreach ($elementIds as $index => $elementId) {
            $data = $elementsData[$index] ?? null;
            if (!empty($data)) {
                $result[(int)$elementId] = [
                    'title' => $data['title'] ?? '',
                    'elementType' => $data['elementType'] ?? 'entry',
                ];
            }
        }

        return $result;
    }

    /**
     * Get element suggestions by prefix
     *
     * @param string $query Search query (prefix)
     * @param int $siteId Site ID
     * @param int $limit Maximum results
     * @param string|null $elementType Filter by element type (null = all types)
     * @return array Array of suggestions [{title, elementType, elementId}, ...]
     */
    public function getElementSuggestions(string $query, int $siteId, int $limit = 10, ?string $elementType = null): array
    {
        $searchText = mb_strtolower(trim($query));
        $indexKey = $this->keyPrefix . 'elemindex:' . $siteId;

        // Use ZRANGEBYLEX for prefix matching
        $min = '[' . $searchText;
        $max = '[' . $searchText . "\xff";

        // Get more results to account for type filtering
        $fetchLimit = $elementType ? $limit * 3 : $limit;
        $matches = $this->redis->zRangeByLex($indexKey, $min, $max, 0, $fetchLimit);

        if (empty($matches)) {
            return [];
        }

        $results = [];

        // Use pipeline to batch fetch element data
        $this->redis->multi(\Redis::PIPELINE);
        $elementIds = [];

        foreach ($matches as $match) {
            // Extract elementId from "elementId:searchText"
            $parts = explode(':', $match, 2);
            $elemId = (int)$parts[0];
            $elementIds[] = $elemId;

            $key = $this->getElementKey($siteId, $elemId);
            $this->redis->hGetAll($key);
        }

        $elementsData = $this->redis->exec();

        // Build results
        foreach ($elementsData as $index => $data) {
            if (empty($data)) {
                continue;
            }

            // Apply type filter if specified
            if ($elementType !== null && ($data['elementType'] ?? '') !== $elementType) {
                continue;
            }

            $results[] = [
                'title' => $data['title'] ?? '',
                'elementType' => $data['elementType'] ?? 'entry',
                'elementId' => $elementIds[$index],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    // =========================================================================
    // N-GRAM OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        if (empty($ngrams)) {
            return;
        }

        // Use pipeline for batch operations
        $this->redis->multi(\Redis::PIPELINE);

        // Store each n-gram -> term mapping
        foreach ($ngrams as $ngram) {
            $ngramKey = $this->getNgramKey($siteId, $ngram);
            $this->redis->sAdd($ngramKey, $term);
        }

        // Store n-gram count for this term
        $countKey = $this->getNgramCountKey($siteId, $term);
        $this->redis->set($countKey, count($ngrams));

        $this->redis->exec();

        $this->logDebug('Stored n-grams', [
            'term' => $term,
            'site_id' => $siteId,
            'ngram_count' => count($ngrams),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function termHasNgrams(string $term, int $siteId): bool
    {
        $countKey = $this->getNgramCountKey($siteId, $term);
        $result = $this->redis->exists($countKey);
        return is_int($result) && $result > 0;
    }

    /**
     * @inheritdoc
     */
    public function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold, int $limit = 100): array
    {
        if (empty($ngrams)) {
            return [];
        }

        $searchNgramCount = count($ngrams);

        // Get all terms that have any of the search n-grams
        $candidateTerms = [];

        foreach ($ngrams as $ngram) {
            $ngramKey = $this->getNgramKey($siteId, $ngram);
            $terms = $this->redis->sMembers($ngramKey);

            foreach ($terms as $term) {
                if (!isset($candidateTerms[$term])) {
                    $candidateTerms[$term] = 0;
                }
                $candidateTerms[$term]++; // Count intersections
            }
        }

        // Calculate Jaccard similarity for each candidate
        $similarities = [];

        // Get n-gram counts in batch
        $this->redis->multi(\Redis::PIPELINE);
        $termsList = array_keys($candidateTerms);

        foreach ($termsList as $term) {
            $countKey = $this->getNgramCountKey($siteId, $term);
            $this->redis->get($countKey);
        }

        $ngramCounts = $this->redis->exec();

        // Calculate similarities
        foreach ($termsList as $index => $term) {
            $intersection = $candidateTerms[$term];
            $termNgramCount = (int)$ngramCounts[$index];

            if ($termNgramCount === 0) {
                continue;
            }

            $union = $searchNgramCount + $termNgramCount - $intersection;
            $similarity = $union > 0 ? $intersection / $union : 0.0;

            if ($similarity >= $threshold) {
                $similarities[$term] = $similarity;
            }
        }

        // Sort by similarity (highest first)
        arsort($similarities);

        // Apply limit
        return array_slice($similarities, 0, $limit, true);
    }

    /**
     * @inheritdoc
     */
    public function getTermsByPrefix(string $prefix, int $siteId): array
    {
        if (empty($prefix)) {
            return [];
        }

        // Use SCAN to find all term keys matching the prefix pattern
        // Redis key format: {indexHandle}:term:{term}:site{siteId}
        $pattern = $this->indexHandle . ':term:' . $prefix . '*:site' . $siteId;

        $matchingTerms = [];
        $iterator = null;

        // SCAN returns keys in batches to avoid blocking
        do {
            $keys = $this->redis->scan($iterator, $pattern, 100);

            if ($keys !== false) {
                foreach ($keys as $key) {
                    // Extract term from key: {indexHandle}:term:{TERM}:site{siteId}
                    if (preg_match('/^' . preg_quote($this->indexHandle, '/') . ':term:(.+?):site' . $siteId . '$/', $key, $matches)) {
                        $matchingTerms[] = $matches[1];
                    }
                }
            }
        } while ($iterator > 0);

        return array_unique($matchingTerms);
    }

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTotalDocCount(int $siteId): int
    {
        $key = $this->getMetaKey($siteId, 'doc_count');
        $value = $this->redis->get($key);

        return $value ? (int)$value : 0;
    }

    /**
     * @inheritdoc
     */
    public function getTotalLength(int $siteId): int
    {
        $key = $this->getMetaKey($siteId, 'total_length');
        $value = $this->redis->get($key);

        return $value ? (int)$value : 1; // Minimum 1 to avoid division by zero
    }

    /**
     * @inheritdoc
     */
    public function getAverageDocLength(int $siteId): float
    {
        $totalDocs = $this->getTotalDocCount($siteId);
        $totalLength = $this->getTotalLength($siteId);

        if ($totalDocs === 0) {
            return 1.0;
        }

        return $totalLength / $totalDocs;
    }

    /**
     * @inheritdoc
     */
    public function updateMetadata(int $siteId, int $docLength, bool $isAddition): void
    {
        $docCountKey = $this->getMetaKey($siteId, 'doc_count');
        $lengthKey = $this->getMetaKey($siteId, 'total_length');

        // Use INCRBY for atomic increments
        $docCountChange = $isAddition ? 1 : -1;
        $lengthChange = $isAddition ? $docLength : -$docLength;

        $this->redis->incrBy($docCountKey, $docCountChange);
        $this->redis->incrBy($lengthKey, $lengthChange);

        // Ensure values don't go negative
        if ($this->redis->get($docCountKey) < 0) {
            $this->redis->set($docCountKey, 0);
        }
        if ($this->redis->get($lengthKey) < 1) {
            $this->redis->set($lengthKey, 1);
        }
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function clearSite(int $siteId): void
    {
        // Get all keys for this site
        $patterns = [
            $this->keyPrefix . 'doc:' . $siteId . ':*',
            $this->keyPrefix . 'term:*:' . $siteId,
            $this->keyPrefix . 'title:' . $siteId . ':*',
            $this->keyPrefix . 'ngram:' . $siteId . ':*',
            $this->keyPrefix . 'ngramcount:' . $siteId . ':*',
            $this->keyPrefix . 'meta:' . $siteId . ':*',
            $this->keyPrefix . 'elem:' . $siteId . ':*',
            $this->keyPrefix . 'elemindex:' . $siteId,
        ];

        foreach ($patterns as $pattern) {
            $keys = $this->redis->keys($pattern);

            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        }

        $this->logInfo('Cleared site data', [
            'index' => $this->indexHandle,
            'site_id' => $siteId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function clearAll(): void
    {
        $pattern = $this->keyPrefix . '*';
        $keys = $this->redis->keys($pattern);

        if (!empty($keys)) {
            $this->redis->del($keys);
        }

        $this->logInfo('Cleared all data', [
            'index' => $this->indexHandle,
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get document key
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string Redis key
     */
    private function getDocKey(int $siteId, int $elementId): string
    {
        return $this->keyPrefix . 'doc:' . $siteId . ':' . $elementId;
    }

    /**
     * Get term key
     *
     * @param string $term Term
     * @param int $siteId Site ID
     * @return string Redis key
     */
    private function getTermKey(string $term, int $siteId): string
    {
        return $this->keyPrefix . 'term:' . $term . ':' . $siteId;
    }

    /**
     * Get title key
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string Redis key
     */
    private function getTitleKey(int $siteId, int $elementId): string
    {
        return $this->keyPrefix . 'title:' . $siteId . ':' . $elementId;
    }

    /**
     * Get element key (for autocomplete suggestions)
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string Redis key
     */
    private function getElementKey(int $siteId, int $elementId): string
    {
        return $this->keyPrefix . 'elem:' . $siteId . ':' . $elementId;
    }

    /**
     * Get n-gram key
     *
     * @param int $siteId Site ID
     * @param string $ngram N-gram
     * @return string Redis key
     */
    private function getNgramKey(int $siteId, string $ngram): string
    {
        return $this->keyPrefix . 'ngram:' . $siteId . ':' . $ngram;
    }

    /**
     * Get n-gram count key
     *
     * @param int $siteId Site ID
     * @param string $term Term
     * @return string Redis key
     */
    private function getNgramCountKey(int $siteId, string $term): string
    {
        return $this->keyPrefix . 'ngramcount:' . $siteId . ':' . $term;
    }

    /**
     * Get metadata key
     *
     * @param int $siteId Site ID
     * @param string $key Metadata key
     * @return string Redis key
     */
    private function getMetaKey(int $siteId, string $key): string
    {
        return $this->keyPrefix . 'meta:' . $siteId . ':' . $key;
    }

    /**
     * Resolve environment variable
     * Strips $ prefix if present and calls App::env()
     *
     * @param mixed $value Config value (e.g., "$REDIS_HOST" or "REDIS_HOST" or "redis")
     * @param mixed $default Default value if env var not found
     * @return mixed Resolved value
     */
    private function resolveEnvVar($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }

        // If it's a string starting with $, it's an env var reference
        if (is_string($value) && str_starts_with($value, '$')) {
            $envVarName = ltrim($value, '$');
            $resolved = App::env($envVarName);

            $this->logDebug('Resolved env var', [
                'original' => $value,
                'envVarName' => $envVarName,
                'resolved' => $resolved,
                'default' => $default,
            ]);

            return $resolved ?? $default;
        }

        // Otherwise try to resolve as env var or return as-is
        $resolved = App::env($value);
        return $resolved ?? $default;
    }
}
