<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

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
 * - sm:idx:{index}:compoundidx:{scope}:{language}:{shard}:rank → ZSET {normalizedSuggestion: totalFrequency}
 * - sm:idx:{index}:compoundidx:{scope}:{language}:{shard}:display:{hash} → HASH {suggestion: frequency}
 * - sm:idx:{index}:meta:{siteId}:{key} → STRING (value)
 *
 * @since 5.0.0
 */
class RedisStorage implements DocumentKeyStorageInterface, ElementSuggestionStorageInterface
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

        $this->logDebug('RedisStorage initializeRedis received config', [
            'configKeys' => array_keys($config),
            'rawHost' => $config['host'] ?? 'NOT IN CONFIG',
            'configCount' => count($config),
        ]);

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

    public function supportsDocumentKeys(): bool
    {
        return true;
    }

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

        // Remove special keys from terms
        unset($data['_length'], $data['_language']);

        // Convert to integers
        return array_map('intval', $data);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTermsBatch(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $elementIds)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($ids as $elementId) {
            $this->redis->hGetAll($this->getDocKey($siteId, $elementId));
        }
        $results = $this->redis->exec();

        $byElement = [];
        foreach ($ids as $index => $elementId) {
            $terms = $results[$index] ?? [];
            if (empty($terms)) {
                continue;
            }

            unset($terms['_length'], $terms['_language']);
            $byElement[$elementId] = array_map('intval', $terms);
        }

        return $byElement;
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(int $siteId, int $elementId): void
    {
        $documentKeys = $this->getDocumentKeysByParent($siteId, $elementId);
        if ($documentKeys !== []) {
            foreach ($documentKeys as $documentKey) {
                $this->deleteDocumentByKey($siteId, $documentKey);
            }

            return;
        }

        // Delete document data
        $docKey = $this->getDocKey($siteId, $elementId);
        $this->redis->del($docKey);

        // Delete title terms
        $titleKey = $this->getTitleKey($siteId, $elementId);
        $this->redis->del($titleKey);

        // Delete element metadata
        $this->deleteElement($siteId, $elementId);
        $this->deleteCompoundSuggestions($siteId, $elementId);

        $this->logDebug('Deleted document, title terms, element, and compounds', [
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
    public function getDocumentLanguagesBatch(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $elementIds)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($ids as $elementId) {
            $this->redis->hGet($this->getDocKey($siteId, $elementId), '_language');
        }
        $results = $this->redis->exec();

        $byElement = [];
        foreach ($ids as $index => $elementId) {
            $byElement[$elementId] = ($results[$index] ?? null) ?: 'en';
        }

        return $byElement;
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

    public function storeDocumentByKey(int $siteId, int $elementId, string $documentKey, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeDocument($siteId, $elementId, $termFreqs, $docLength, $language);
            return;
        }

        $key = $this->getDocKeyByDocumentKey($siteId, $documentKey);

        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;
        $data['_elementId'] = $elementId;
        $data['_documentKey'] = $documentKey;

        $this->redis->hMSet($key, $data);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
    }

    public function getDocumentTermsByKey(int $siteId, string $documentKey): array
    {
        $data = $this->redis->hGetAll($this->getDocKeyByDocumentKey($siteId, $documentKey));
        if (!$data) {
            return [];
        }

        unset($data['_length'], $data['_language'], $data['_elementId'], $data['_documentKey']);

        return array_map('intval', $data);
    }

    public function getDocumentTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $keys = array_values(array_unique(array_map('strval', $documentKeys)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($keys as $documentKey) {
            $this->redis->hGetAll($this->getDocKeyByDocumentKey($siteId, $documentKey));
        }
        $results = $this->redis->exec();

        $byDocument = [];
        foreach ($keys as $index => $documentKey) {
            $terms = $results[$index] ?? [];
            if (empty($terms)) {
                continue;
            }

            unset($terms['_length'], $terms['_language'], $terms['_elementId'], $terms['_documentKey']);
            $byDocument[$documentKey] = array_map('intval', $terms);
        }

        return $byDocument;
    }

    public function deleteDocumentByKey(int $siteId, string $documentKey): void
    {
        $data = $this->redis->hGetAll($this->getDocKeyByDocumentKey($siteId, $documentKey));
        $elementId = isset($data['_elementId']) ? (int)$data['_elementId'] : $this->elementIdFromDocumentKey($documentKey);

        $this->deleteCompoundSuggestionsByKey($siteId, $documentKey);

        $this->redis->del([
            $this->getDocKeyByDocumentKey($siteId, $documentKey),
            $this->getTitleKeyByDocumentKey($siteId, $documentKey),
            $this->getDocumentElementKey($siteId, $documentKey),
        ]);

        if ($elementId !== null) {
            $this->removeDocumentKeyForParent($siteId, $elementId, $documentKey);
            if ($this->getDocumentKeysByParent($siteId, $elementId) === []) {
                $this->deleteElement($siteId, $elementId);
            }
        }
    }

    public function getDocumentLengthByKey(int $siteId, string $documentKey): int
    {
        $length = $this->redis->hGet($this->getDocKeyByDocumentKey($siteId, $documentKey), '_length');

        return $length ? (int)$length : 0;
    }

    public function getDocumentLanguagesBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $keys = array_values(array_unique(array_map('strval', $documentKeys)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($keys as $documentKey) {
            $this->redis->hGet($this->getDocKeyByDocumentKey($siteId, $documentKey), '_language');
        }
        $results = $this->redis->exec();

        $byDocument = [];
        foreach ($keys as $index => $documentKey) {
            $byDocument[$documentKey] = ($results[$index] ?? null) ?: 'en';
        }

        return $byDocument;
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
    public function getTermDocumentsBatch(array $terms, int $siteId): array
    {
        if (empty($terms)) {
            return [];
        }

        // Pipeline one hGetAll per term so the candidate set costs a single
        // round-trip instead of one per term.
        $terms = array_values($terms);
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($terms as $term) {
            $this->redis->hGetAll($this->getTermKey($term, $siteId));
        }
        $results = $this->redis->exec();

        $byTerm = [];
        foreach ($terms as $index => $term) {
            $data = $results[$index] ?? [];
            if (!empty($data)) {
                $byTerm[$term] = array_map('intval', $data);
            }
        }

        return $byTerm;
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

    public function storeTermDocumentByKey(string $term, int $siteId, int $elementId, string $documentKey, int $frequency, string $language = 'en'): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeTermDocument($term, $siteId, $elementId, $frequency, $language);
            return;
        }

        $key = $this->getTermKey($term, $siteId);
        $this->redis->hSet($key, $siteId . ':' . $documentKey, $frequency);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
    }

    public function removeTermDocumentByKey(string $term, int $siteId, string $documentKey): void
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            $this->removeTermDocument($term, $siteId, $elementId);
            return;
        }

        $this->redis->hDel($this->getTermKey($term, $siteId), $siteId . ':' . $documentKey);
    }

    /**
     * @inheritdoc
     */
    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000, ?string $prefix = null): array
    {
        $termPattern = $prefix !== null && $prefix !== '' ? $prefix . '*' : '*';

        // Pattern: {prefix}term:TERM:SITE_ID
        // For all-sites indices (siteId = null), match all siteIds with wildcard
        if ($siteId !== null) {
            $pattern = $this->keyPrefix . 'term:' . $termPattern . ':' . $siteId;
        } else {
            // All sites - use wildcard for siteId
            $pattern = $this->keyPrefix . 'term:' . $termPattern . ':*';
        }

        $keys = $this->scanKeys($pattern);

        if (!is_array($keys) || empty($keys)) {
            return [];
        }

        $termKeys = [];
        $termsByKey = [];
        foreach ($keys as $key) {
            // Key format: {prefix}term:TERM:SITE_ID
            $keyRemainder = str_starts_with($key, $this->keyPrefix)
                ? substr($key, strlen($this->keyPrefix))
                : '';
            $parts = explode(':', $keyRemainder, 3);

            if (($parts[0] ?? null) === 'term' && isset($parts[1], $parts[2])) {
                $term = $parts[1];

                if ($prefix !== null && $prefix !== '' && !str_starts_with($term, $prefix)) {
                    continue;
                }

                $termKeys[] = $key;
                $termsByKey[] = $term;
            }
        }

        if (empty($termKeys)) {
            return [];
        }

        $this->redis->multi(\Redis::PIPELINE);
        foreach ($termKeys as $key) {
            $this->redis->hGetAll($key);
        }
        $termDocuments = $this->redis->exec();

        $terms = [];
        foreach ($termDocuments as $index => $documents) {
            if (!is_array($documents)) {
                continue;
            }

            $term = $termsByKey[$index];
            $terms[$term] = ($terms[$term] ?? 0) + array_sum(array_map('intval', $documents));
        }

        arsort($terms);

        return array_slice($terms, 0, $limit, true);
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
    public function getTitleTermsBatch(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        // Pipeline one sMembers per element so the scoring loop pays a single
        // round-trip instead of one per matched document.
        $ids = array_values($elementIds);
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($ids as $elementId) {
            $this->redis->sMembers($this->getTitleKey($siteId, (int)$elementId));
        }
        $results = $this->redis->exec();

        $byElement = [];
        foreach ($ids as $index => $elementId) {
            $terms = $results[$index] ?? [];
            if (!empty($terms)) {
                $byElement[(int)$elementId] = $terms;
            }
        }

        return $byElement;
    }

    /**
     * @inheritdoc
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $key = $this->getTitleKey($siteId, $elementId);
        $this->redis->del($key);
    }

    public function storeTitleTermsByKey(int $siteId, int $elementId, string $documentKey, array $titleTerms): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeTitleTerms($siteId, $elementId, $titleTerms);
            return;
        }

        $key = $this->getTitleKeyByDocumentKey($siteId, $documentKey);
        $this->redis->del($key);

        if (!empty($titleTerms)) {
            $this->redis->sAddArray($key, $titleTerms);
        }

        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
    }

    public function getTitleTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $keys = array_values(array_unique(array_map('strval', $documentKeys)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($keys as $documentKey) {
            $this->redis->sMembers($this->getTitleKeyByDocumentKey($siteId, $documentKey));
        }
        $results = $this->redis->exec();

        $byDocument = [];
        foreach ($keys as $index => $documentKey) {
            $terms = $results[$index] ?? [];
            if (!empty($terms)) {
                $byDocument[$documentKey] = array_values(array_map('strval', $terms));
            }
        }

        return $byDocument;
    }

    public function deleteTitleTermsByKey(int $siteId, string $documentKey): void
    {
        $this->redis->del($this->getTitleKeyByDocumentKey($siteId, $documentKey));
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
     * @param string|null $documentData JSON-encoded transformer output for rich results
     * @return void
     */
    public function storeElement(int $siteId, int $elementId, string $title, string $elementType, ?string $documentData = null): void
    {
        $key = $this->getElementKey($siteId, $elementId);

        // Normalize searchText for prefix matching (lowercase)
        $searchText = mb_strtolower(trim($title));

        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
        ];

        if ($documentData !== null) {
            $data['documentData'] = $documentData;
        }

        $this->redis->hMSet($key, $data);

        // Also add to a sorted set for prefix searching
        $indexKey = $this->keyPrefix . 'elemindex:' . $siteId;
        $this->redis->zAdd($indexKey, 0, $searchText . ':' . $elementId);

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
            $this->redis->zRem($indexKey, $data['searchText'] . ':' . $elementId);
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
     * @return array Map of elementId => ['title' => ..., 'elementType' => ..., 'documentData' => ...]
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
                    'documentData' => !empty($data['documentData']) ? json_decode($data['documentData'], true) : null,
                ];
            }
        }

        return $result;
    }

    public function storeElementByKey(int $siteId, int $elementId, string $documentKey, string $title, string $elementType, ?string $documentData = null): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeElement($siteId, $elementId, $title, $elementType, $documentData);
            return;
        }

        $this->storeElement($siteId, $elementId, $title, $elementType, $documentData);

        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => mb_strtolower(trim($title)),
            'elementId' => $elementId,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];

        if ($documentData !== null) {
            $data['documentData'] = $documentData;
        }

        $this->redis->hMSet($this->getDocumentElementKey($siteId, $documentKey), $data);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
    }

    public function getElementsByDocumentKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $keys = array_values(array_unique(array_map('strval', $documentKeys)));
        $this->redis->multi(\Redis::PIPELINE);
        foreach ($keys as $documentKey) {
            $this->redis->hGetAll($this->getDocumentElementKey($siteId, $documentKey));
        }
        $results = $this->redis->exec();

        $byDocument = [];
        $missingPageKeys = [];
        foreach ($keys as $index => $documentKey) {
            $data = $results[$index] ?? [];
            if (!empty($data)) {
                $byDocument[$documentKey] = [
                    'title' => $data['title'] ?? '',
                    'elementType' => $data['elementType'] ?? 'entry',
                    'documentData' => !empty($data['documentData']) ? json_decode($data['documentData'], true) : null,
                ];
                continue;
            }

            $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
            if ($elementId !== null) {
                $missingPageKeys[$documentKey] = $elementId;
            }
        }

        if ($missingPageKeys !== []) {
            $legacy = $this->getElementsByIds($siteId, array_values($missingPageKeys));
            foreach ($missingPageKeys as $documentKey => $elementId) {
                if (isset($legacy[$elementId])) {
                    $byDocument[$documentKey] = $legacy[$elementId];
                }
            }
        }

        return $byDocument;
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
    public function getElementSuggestions(string $query, ?int $siteId, int $limit = 10, ?string $elementType = null): array
    {
        $searchText = mb_strtolower(trim($query));

        // Determine which site keys to search
        $siteIds = [];
        if ($siteId !== null) {
            $siteIds = [$siteId];
        } else {
            // Search all sites - find all elemindex keys for this index
            $pattern = $this->keyPrefix . 'elemindex:*';
            $keys = $this->scanKeys($pattern);
            foreach ($keys as $key) {
                // Extract siteId from key: prefix:elemindex:siteId
                if (preg_match('/elemindex:(\d+)$/', $key, $matches)) {
                    $siteIds[] = (int)$matches[1];
                }
            }
        }

        if (empty($siteIds)) {
            return [];
        }

        // Use ZRANGEBYLEX for prefix matching
        $min = '[' . $searchText;
        $max = '[' . $searchText . "\xff";

        // Get more results to account for type filtering
        $fetchLimit = $elementType ? $limit * 3 : $limit;

        $allMatches = [];
        foreach ($siteIds as $sid) {
            $indexKey = $this->keyPrefix . 'elemindex:' . $sid;
            $matches = $this->redis->zRangeByLex($indexKey, $min, $max, 0, $fetchLimit);
            foreach ($matches as $match) {
                $allMatches[] = ['siteId' => $sid, 'match' => $match];
            }
        }

        if (empty($allMatches)) {
            return [];
        }

        $results = [];

        // Use pipeline to batch fetch element data
        $this->redis->multi(\Redis::PIPELINE);
        $elementMeta = [];

        foreach ($allMatches as $item) {
            // Extract elementId from "searchText:elementId"; searchText itself may contain colons.
            $separatorPos = strrpos($item['match'], ':');
            if ($separatorPos === false) {
                continue;
            }
            $elemId = (int)substr($item['match'], $separatorPos + 1);
            if ($elemId <= 0) {
                continue;
            }
            $elementMeta[] = ['siteId' => $item['siteId'], 'elementId' => $elemId];

            $key = $this->getElementKey($item['siteId'], $elemId);
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
                'elementId' => $elementMeta[$index]['elementId'],
                'siteId' => $elementMeta[$index]['siteId'],
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

        $this->redis->multi(\Redis::PIPELINE);
        foreach ($ngrams as $ngram) {
            $this->redis->sMembers($this->getNgramKey($siteId, $ngram));
        }
        $termsByNgram = $this->redis->exec();

        foreach ($termsByNgram as $terms) {
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

        // Use SCAN to find term keys matching the same shape as getTermKey().
        // Redis key format: {keyPrefix}term:{term}:{siteId}
        $pattern = $this->keyPrefix . 'term:' . $prefix . '*:' . $siteId;

        $matchingTerms = [];
        foreach ($this->scanKeys($pattern) as $key) {
            if (preg_match('/^' . preg_quote($this->keyPrefix, '/') . 'term:(.+):' . $siteId . '$/', $key, $matches)) {
                $matchingTerms[] = $matches[1];
            }
        }

        return array_unique($matchingTerms);
    }

    /**
     * @inheritdoc
     */
    public function storeCompoundSuggestions(int $siteId, int $elementId, array $suggestions, string $language = 'en'): void
    {
        $oldRows = $this->readCompoundRows($siteId, $elementId);

        if (empty($suggestions)) {
            $this->redis->multi(\Redis::MULTI);
            if (!empty($oldRows)) {
                $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
            }
            $this->redis->del($this->getCompoundKey($siteId, $elementId));
            $this->redis->exec();

            return;
        }

        $rows = [];
        foreach ($suggestions as $suggestion) {
            $rows[] = [
                'suggestion' => (string)$suggestion['suggestion'],
                'normalizedSuggestion' => (string)$suggestion['normalizedSuggestion'],
                'tokenKey' => (string)$suggestion['tokenKey'],
                'frequency' => (int)$suggestion['frequency'],
                'language' => $language,
            ];
        }

        $encoded = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $this->redis->multi(\Redis::MULTI);
            if (!empty($oldRows)) {
                $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
            }
            $this->redis->set($this->getCompoundKey($siteId, $elementId), $encoded);
            $this->applyCompoundAggregateDelta($siteId, $rows, 1);
            $this->redis->exec();
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteCompoundSuggestions(int $siteId, int $elementId): void
    {
        $oldRows = $this->readCompoundRows($siteId, $elementId);
        $this->redis->multi(\Redis::MULTI);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        $this->redis->del($this->getCompoundKey($siteId, $elementId));
        $this->redis->exec();
    }

    public function storeCompoundSuggestionsByKey(int $siteId, int $elementId, string $documentKey, array $suggestions, string $language = 'en'): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeCompoundSuggestions($siteId, $elementId, $suggestions, $language);
            return;
        }

        $oldRows = $this->readCompoundRowsByKey($siteId, $documentKey);

        if (empty($suggestions)) {
            $this->redis->multi(\Redis::MULTI);
            if (!empty($oldRows)) {
                $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
            }
            $this->redis->del($this->getCompoundKeyByDocumentKey($siteId, $documentKey));
            $this->redis->exec();

            return;
        }

        $rows = [];
        foreach ($suggestions as $suggestion) {
            $rows[] = [
                'suggestion' => (string)$suggestion['suggestion'],
                'normalizedSuggestion' => (string)$suggestion['normalizedSuggestion'],
                'tokenKey' => (string)$suggestion['tokenKey'],
                'frequency' => (int)$suggestion['frequency'],
                'language' => $language,
            ];
        }

        $encoded = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== false) {
            $this->redis->multi(\Redis::MULTI);
            if (!empty($oldRows)) {
                $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
            }
            $this->redis->set($this->getCompoundKeyByDocumentKey($siteId, $documentKey), $encoded);
            $this->applyCompoundAggregateDelta($siteId, $rows, 1);
            $this->redis->exec();
            $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
        }
    }

    public function deleteCompoundSuggestionsByKey(int $siteId, string $documentKey): void
    {
        $oldRows = $this->readCompoundRowsByKey($siteId, $documentKey);
        $this->redis->multi(\Redis::MULTI);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        $this->redis->del($this->getCompoundKeyByDocumentKey($siteId, $documentKey));
        $this->redis->exec();
    }

    public function getDocumentKeysByParent(int $siteId, int $elementId): array
    {
        $keys = $this->redis->sMembers($this->getParentKey($siteId, $elementId));
        if (is_array($keys) && $keys !== []) {
            return array_values(array_unique(array_map('strval', $keys)));
        }

        return $this->redis->exists($this->getDocKey($siteId, $elementId)) ? [$this->pageDocumentKey($siteId, $elementId)] : [];
    }

    /**
     * @inheritdoc
     */
    public function getCompoundSuggestionsForAutocomplete(string $normalizedPrefix, ?int $siteId, ?string $language, int $limit = 10): array
    {
        if ($normalizedPrefix === '') {
            return [];
        }

        return $this->getIndexedCompoundSuggestionsForAutocomplete($normalizedPrefix, $siteId, $language, $limit);
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

        $docCountChange = $isAddition ? 1 : -1;
        $lengthChange = $isAddition ? $docLength : -$docLength;

        // Keep the increment and lower-bound clamp in one Redis command. The
        // previous INCRBY -> GET -> SET sequence could overwrite a concurrent
        // increment between GET and SET.
        $script = <<<'LUA'
local doc_count = redis.call('INCRBY', KEYS[1], ARGV[1])
if doc_count < 0 then
    redis.call('SET', KEYS[1], 0)
end

local total_length = redis.call('INCRBY', KEYS[2], ARGV[2])
if total_length < 1 then
    redis.call('SET', KEYS[2], 1)
end

return {redis.call('GET', KEYS[1]), redis.call('GET', KEYS[2])}
LUA;

        $this->redis->eval($script, [$docCountKey, $lengthKey, $docCountChange, $lengthChange], 2);
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function clearSite(int $siteId): void
    {
        $compoundKeys = $this->scanKeys($this->keyPrefix . 'compound:' . $siteId . ':*');
        foreach ($compoundKeys as $key) {
            $encoded = $this->redis->get($key);
            if (!is_string($encoded) || $encoded === '') {
                continue;
            }

            $oldRows = json_decode($encoded, true);
            if (is_array($oldRows)) {
                $this->applyCompoundAggregateDelta($siteId, array_values(array_filter($oldRows, 'is_array')), -1);
            }
        }

        // Get all keys for this site
        $patterns = [
            $this->keyPrefix . 'doc:' . $siteId . ':*',
            $this->keyPrefix . 'term:*:' . $siteId,
            $this->keyPrefix . 'title:' . $siteId . ':*',
            $this->keyPrefix . 'ngram:' . $siteId . ':*',
            $this->keyPrefix . 'ngramcount:' . $siteId . ':*',
            $this->keyPrefix . 'meta:' . $siteId . ':*',
            $this->keyPrefix . 'elem:' . $siteId . ':*',
            $this->keyPrefix . 'docelem:' . $siteId . ':*',
            $this->keyPrefix . 'parent:' . $siteId . ':*',
            $this->keyPrefix . 'elemindex:' . $siteId,
            $this->keyPrefix . 'compoundidx:site' . $siteId . ':*',
        ];

        foreach ($patterns as $pattern) {
            $this->deleteKeysInBatches($this->scanKeys($pattern));
        }
        $this->deleteKeysInBatches($compoundKeys);

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
        $this->deleteKeysInBatches($this->scanKeys($pattern));

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

    private function getDocKeyByDocumentKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getDocKey($siteId, $elementId);
        }

        return $this->keyPrefix . 'doc:' . $siteId . ':key:' . $this->encodeKeySegment($documentKey);
    }

    /**
     * Iterate Redis keys without blocking the server like KEYS does.
     *
     * @return array<int, string>
     */
    private function scanKeys(string $pattern, int $count = 100): array
    {
        $keys = [];
        $iterator = null;

        do {
            $batch = $this->redis->scan($iterator, $pattern, $count);
            if ($batch !== false) {
                foreach ($batch as $key) {
                    $keys[] = (string)$key;
                }
            }
        } while ((int)$iterator > 0);

        return $keys;
    }

    /**
     * @param array<int, string> $keys
     */
    private function deleteKeysInBatches(array $keys, int $batchSize = 500): void
    {
        foreach (array_chunk($keys, $batchSize) as $batch) {
            $this->redis->del($batch);
        }
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

    private function getTitleKeyByDocumentKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getTitleKey($siteId, $elementId);
        }

        return $this->keyPrefix . 'title:' . $siteId . ':key:' . $this->encodeKeySegment($documentKey);
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

    private function getDocumentElementKey(int $siteId, string $documentKey): string
    {
        return $this->keyPrefix . 'docelem:' . $siteId . ':' . $this->encodeKeySegment($documentKey);
    }

    private function getCompoundKey(int $siteId, int $elementId): string
    {
        return $this->keyPrefix . 'compound:' . $siteId . ':' . $elementId;
    }

    private function getCompoundKeyByDocumentKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getCompoundKey($siteId, $elementId);
        }

        return $this->keyPrefix . 'compound:' . $siteId . ':key:' . $this->encodeKeySegment($documentKey);
    }

    private function getParentKey(int $siteId, int $elementId): string
    {
        return $this->keyPrefix . 'parent:' . $siteId . ':' . $elementId;
    }

    private function addDocumentKeyForParent(int $siteId, int $elementId, string $documentKey): void
    {
        $this->redis->sAdd($this->getParentKey($siteId, $elementId), $documentKey);
    }

    private function removeDocumentKeyForParent(int $siteId, int $elementId, string $documentKey): void
    {
        $parentKey = $this->getParentKey($siteId, $elementId);
        $this->redis->sRem($parentKey, $documentKey);

        $remaining = $this->redis->sCard($parentKey);
        if ((int)$remaining <= 0) {
            $this->redis->del($parentKey);
        }
    }

    private function pageDocumentKey(int $siteId, int $elementId): string
    {
        return $elementId . '_' . $siteId;
    }

    private function elementIdFromDocumentKey(string $documentKey): ?int
    {
        if (preg_match('/^(\d+)(?:_|$)/', $documentKey, $match) === 1) {
            return (int)$match[1];
        }

        return null;
    }

    private function elementIdFromPageDocumentKey(int $siteId, string $documentKey): ?int
    {
        if (preg_match('/^(\d+)_(\d+)$/', $documentKey, $match) !== 1) {
            return null;
        }

        if ((int)$match[2] !== $siteId) {
            return null;
        }

        return (int)$match[1];
    }

    private function getCompoundRankKey(string $scope, string $language, string $normalizedSuggestion): string
    {
        return $this->keyPrefix . 'compoundidx:' . $scope . ':' . $this->encodeKeySegment($language) . ':' . $this->compoundShard($normalizedSuggestion) . ':rank';
    }

    private function getCompoundDisplayKey(string $scope, string $language, string $normalizedSuggestion): string
    {
        return $this->keyPrefix
            . 'compoundidx:' . $scope
            . ':' . $this->encodeKeySegment($language)
            . ':' . $this->compoundShard($normalizedSuggestion)
            . ':display:' . hash('sha256', $normalizedSuggestion);
    }

    private function getCompoundLookupRankKey(string $scope, string $language, string $normalizedPrefix): string
    {
        return $this->keyPrefix . 'compoundidx:' . $scope . ':' . $this->encodeKeySegment($language) . ':' . $this->compoundShard($normalizedPrefix) . ':rank';
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
     * @return array<int, array<string, mixed>>
     */
    private function readCompoundRows(int $siteId, int $elementId): array
    {
        $encoded = $this->redis->get($this->getCompoundKey($siteId, $elementId));
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $rows = json_decode($encoded, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCompoundRowsByKey(int $siteId, string $documentKey): array
    {
        $encoded = $this->redis->get($this->getCompoundKeyByDocumentKey($siteId, $documentKey));
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $rows = json_decode($encoded, true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyCompoundAggregateDelta(int $siteId, array $rows, int $direction): void
    {
        foreach ($rows as $row) {
            $normalizedSuggestion = (string)($row['normalizedSuggestion'] ?? '');
            $suggestion = (string)($row['suggestion'] ?? '');
            if ($normalizedSuggestion === '' || $suggestion === '') {
                continue;
            }

            $language = (string)($row['language'] ?? 'en');
            $frequency = max(0, (int)($row['frequency'] ?? 1)) * $direction;
            if ($frequency === 0) {
                continue;
            }

            foreach (['site' . $siteId, 'all'] as $scope) {
                $this->updateCompoundAggregate($scope, $language, $normalizedSuggestion, $suggestion, $frequency);
            }
        }
    }

    private function updateCompoundAggregate(
        string $scope,
        string $language,
        string $normalizedSuggestion,
        string $suggestion,
        int $frequencyDelta,
    ): void {
        $displayKey = $this->getCompoundDisplayKey($scope, $language, $normalizedSuggestion);
        $rankKey = $this->getCompoundRankKey($scope, $language, $normalizedSuggestion);
        $script = <<<'LUA'
local display = redis.call('HGETALL', KEYS[1])
local frequencies = {}

for i = 1, #display, 2 do
    frequencies[display[i]] = tonumber(display[i + 1]) or 0
end

local suggestion = ARGV[2]
local updated = (frequencies[suggestion] or 0) + tonumber(ARGV[3])
if updated <= 0 then
    frequencies[suggestion] = nil
    redis.call('HDEL', KEYS[1], suggestion)
else
    frequencies[suggestion] = updated
    redis.call('HSET', KEYS[1], suggestion, updated)
end

local total = 0
for _, frequency in pairs(frequencies) do
    if frequency > 0 then
        total = total + frequency
    end
end

if total <= 0 then
    redis.call('DEL', KEYS[1])
    redis.call('ZREM', KEYS[2], ARGV[1])
else
    redis.call('ZADD', KEYS[2], total, ARGV[1])
end

return total
LUA;

        $this->redis->eval($script, [$displayKey, $rankKey, $normalizedSuggestion, $suggestion, $frequencyDelta], 2);
    }

    /**
     * @return array<string, int>
     */
    private function getIndexedCompoundSuggestionsForAutocomplete(
        string $normalizedPrefix,
        ?int $siteId,
        ?string $language,
        int $limit,
    ): array {
        $scope = $siteId !== null ? 'site' . $siteId : 'all';
        if (!$this->hasCompoundAggregateIndex($scope)) {
            return [];
        }

        $languages = $language !== null ? [$language] : $this->getCompoundIndexedLanguages($scope);
        if (empty($languages)) {
            return [];
        }

        $suggestionsByNormalized = [];
        foreach ($languages as $lang) {
            $lang = (string)$lang;
            $rankKey = $this->getCompoundLookupRankKey($scope, (string)$lang, $normalizedPrefix);
            $ranked = $this->redis->zRevRange($rankKey, 0, -1, true);
            if (!is_array($ranked)) {
                continue;
            }

            $displayKeysByNormalized = [];
            foreach ($ranked as $normalizedSuggestion => $totalFrequency) {
                $normalizedSuggestion = (string)$normalizedSuggestion;
                if (!str_starts_with($normalizedSuggestion, $normalizedPrefix)) {
                    continue;
                }

                $displayKeysByNormalized[$normalizedSuggestion] = $this->getCompoundDisplayKey($scope, $lang, $normalizedSuggestion);
            }

            if (empty($displayKeysByNormalized)) {
                continue;
            }

            $this->redis->multi(\Redis::PIPELINE);
            foreach ($displayKeysByNormalized as $displayKey) {
                $this->redis->hGetAll($displayKey);
            }
            $displayResults = $this->redis->exec();

            foreach (array_keys($displayKeysByNormalized) as $index => $normalizedSuggestion) {
                $displayFrequencies = $displayResults[$index] ?? [];
                if (!is_array($displayFrequencies) || empty($displayFrequencies)) {
                    continue;
                }

                foreach ($displayFrequencies as $suggestion => $frequency) {
                    $suggestionsByNormalized[$normalizedSuggestion]['displayFrequencies'][(string)$suggestion] =
                        ($suggestionsByNormalized[$normalizedSuggestion]['displayFrequencies'][(string)$suggestion] ?? 0) + (int)$frequency;
                    $suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] =
                        ($suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] ?? 0) + (int)$frequency;
                }
            }
        }

        return $this->rankCompoundSuggestions($suggestionsByNormalized, $limit);
    }

    private function hasCompoundAggregateIndex(string $scope): bool
    {
        return !empty($this->scanKeys($this->keyPrefix . 'compoundidx:' . $scope . ':*:rank', 1));
    }

    /**
     * @return array<int, string>
     */
    private function getCompoundIndexedLanguages(string $scope): array
    {
        $languages = [];
        foreach ($this->scanKeys($this->keyPrefix . 'compoundidx:' . $scope . ':*:rank') as $key) {
            $pattern = '/^' . preg_quote($this->keyPrefix, '/') . 'compoundidx:' . preg_quote($scope, '/') . ':([^:]+):[^:]+:rank$/';
            if (preg_match($pattern, $key, $matches) === 1) {
                $languages[] = $this->decodeKeySegment($matches[1]);
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * @param array<string, array{totalFrequency?: int, displayFrequencies?: array<string, int>}> $suggestionsByNormalized
     * @return array<string, int>
     */
    private function rankCompoundSuggestions(array $suggestionsByNormalized, int $limit): array
    {
        $suggestions = [];
        foreach ($suggestionsByNormalized as $data) {
            $displayFrequencies = $data['displayFrequencies'] ?? [];
            arsort($displayFrequencies);
            $topFrequency = reset($displayFrequencies);
            $topSuggestions = array_keys(array_filter(
                $displayFrequencies,
                static fn(int $frequency): bool => $frequency === $topFrequency,
            ));
            sort($topSuggestions, SORT_STRING);
            if (!empty($topSuggestions)) {
                $suggestions[$topSuggestions[0]] = (int)($data['totalFrequency'] ?? 0);
            }
        }

        arsort($suggestions);

        return array_slice($suggestions, 0, $limit, true);
    }

    private function compoundShard(string $normalizedSuggestion): string
    {
        return $this->encodeKeySegment(mb_substr($normalizedSuggestion, 0, 1) ?: '_');
    }

    private function encodeKeySegment(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decodeKeySegment(string $value): string
    {
        $padding = str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value . $padding, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : $value;
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
            return App::env($envVarName) ?? $default;
        }

        // Return the value as-is (it's a plain string, not an env var reference)
        return $value;
    }
}
