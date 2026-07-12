<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search\storage;

use Craft;
use craft\db\Query;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use yii\db\Expression;

/**
 * MySqlStorage
 *
 * MySQL-based storage implementation for the search engine.
 * Stores inverted index data in MySQL tables with optimized queries.
 *
 * @since 5.0.0
 */
class MySqlStorage implements DocumentKeyStorageInterface, ElementSuggestionStorageInterface
{
    use LoggingTrait;

    /**
     * @var string Index handle
     */
    private string $indexHandle;

    /**
     * @var \yii\db\Connection Database connection
     */
    private $db;

    /**
     * @var array<string, bool>
     */
    private array $columnExists = [];

    /**
     * Constructor
     *
     * @param string $indexHandle Index handle
     */
    public function __construct(string $indexHandle)
    {
        $this->setLoggingHandle('search-manager');
        $this->indexHandle = $indexHandle;
        $this->db = Craft::$app->getDb();

        $this->logDebug('Initialized MySqlStorage', [
            'index' => $this->indexHandle,
        ]);
    }

    public function supportsDocumentKeys(): bool
    {
        return true;
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        $this->storeDocumentByKey($siteId, $elementId, $this->pageDocumentKey($siteId, $elementId), $termFreqs, $docLength, $language);
    }

    public function storeDocumentByKey(int $siteId, int $elementId, string $documentKey, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        // Store term frequencies
        $values = [];
        foreach ($termFreqs as $term => $frequency) {
            $values[] = [
                $this->indexHandle,
                $siteId,
                $elementId,
                $documentKey,
                $term,
                $frequency,
                $language,
            ];
        }

        // Store _length as a special entry
        $values[] = [
            $this->indexHandle,
            $siteId,
            $elementId,
            $documentKey,
            '_length',
            $docLength,
            $language,
        ];

        // Store _language as a special entry for easy retrieval
        $values[] = [
            $this->indexHandle,
            $siteId,
            $elementId,
            $documentKey,
            '_language',
            0,
            $language,
        ];

        // Use REPLACE INTO to handle duplicates (deletes old, inserts new)
        $columns = '`indexHandle`, `siteId`, `elementId`, `documentKey`, `term`, `frequency`, `language`';
        $sql = "REPLACE INTO {{%searchmanager_search_documents}}
                (" . $columns . ") VALUES ";

        $valueStrings = [];
        foreach ($values as $value) {
            $row = "("
                . $this->db->quoteValue($value[0]) . ", "
                . (int)$value[1] . ", "
                . (int)$value[2] . ", ";
            $row .= $this->db->quoteValue($value[3]) . ", "
                . $this->db->quoteValue($value[4]) . ", "
                . (int)$value[5] . ", "
                . $this->db->quoteValue($value[6]) . ")";
            $valueStrings[] = $row;
        }

        $sql .= implode(', ', $valueStrings);

        $this->db->createCommand($sql)->execute();

        $this->logDebug('Stored document', [
            'site_id' => $siteId,
            'element_id' => $elementId,
            'language' => $language,
            'term_count' => count($termFreqs),
            'doc_length' => $docLength,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLanguage(int $siteId, int $elementId): string
    {
        $language = (new Query())
            ->select(['language'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
                'term' => '_language',
            ])
            ->scalar();

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

        $rows = (new Query())
            ->select(['elementId', 'language'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => array_values(array_unique(array_map('intval', $elementIds))),
                'term' => '_language',
            ])
            ->all();

        $byElement = [];
        foreach ($rows as $row) {
            $byElement[(int)$row['elementId']] = (string)($row['language'] ?: 'en');
        }

        return $byElement;
    }

    public function getDocumentLanguagesBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        $rows = (new Query())
            ->select(['documentKey', 'language'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'documentKey' => array_values(array_unique(array_map('strval', $documentKeys))),
                'term' => '_language',
            ])
            ->all();

        $byDocument = [];
        foreach ($rows as $row) {
            $byDocument[(string)$row['documentKey']] = (string)($row['language'] ?: 'en');
        }

        return $byDocument;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTerms(int $siteId, int $elementId): array
    {
        return $this->getDocumentTermsByKey($siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function getDocumentTermsByKey(int $siteId, string $documentKey): array
    {
        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        $where = [
            'indexHandle' => $this->indexHandle,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];

        $rows = (new Query())
            ->select(['term', 'frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where($where)
            ->andWhere(['!=', 'term', '_length'])
            ->andWhere(['!=', 'term', '_language'])
            ->all();

        $terms = [];
        foreach ($rows as $row) {
            $terms[$row['term']] = (int)$row['frequency'];
        }

        return $terms;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTermsBatch(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['elementId', 'term', 'frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => array_values(array_unique(array_map('intval', $elementIds))),
            ])
            ->andWhere(['!=', 'term', '_length'])
            ->andWhere(['!=', 'term', '_language'])
            ->all();

        $byElement = [];
        foreach ($rows as $row) {
            $byElement[(int)$row['elementId']][(string)$row['term']] = (int)$row['frequency'];
        }

        return $byElement;
    }

    public function getDocumentTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        $rows = (new Query())
            ->select(['documentKey', 'term', 'frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'documentKey' => array_values(array_unique(array_map('strval', $documentKeys))),
            ])
            ->andWhere(['!=', 'term', '_length'])
            ->andWhere(['!=', 'term', '_language'])
            ->all();

        $byDocument = [];
        foreach ($rows as $row) {
            $byDocument[(string)$row['documentKey']][(string)$row['term']] = (int)$row['frequency'];
        }

        return $byDocument;
    }

    /**
     * @inheritdoc
     */
    public function deleteDocument(int $siteId, int $elementId): void
    {
        $this->deleteDocumentByKey($siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function deleteDocumentByKey(int $siteId, string $documentKey): void
    {
        // Delete from all tables that have elementId-specific data
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
        ];

        foreach ($tables as $table) {
            $this->requireDocumentKeyColumn($table);

            $condition = [
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'documentKey' => $documentKey,
            ];

            $this->db->createCommand()->delete(
                $table,
                $condition
            )->execute();
        }

        $this->logDebug('Deleted document from all tables', [
            'site_id' => $siteId,
            'document_key' => $documentKey,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLength(int $siteId, int $elementId): int
    {
        return $this->getDocumentLengthByKey($siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function getDocumentLengthByKey(int $siteId, string $documentKey): int
    {
        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        $where = [
            'indexHandle' => $this->indexHandle,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
            'term' => '_length',
        ];

        $result = (new Query())
            ->select(['frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where($where)
            ->scalar();

        return $result ? (int)$result : 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatch(array $docIds): array
    {
        $lengths = [];
        $this->requireDocumentKeyColumn('{{%searchmanager_search_documents}}');

        foreach ($docIds as $siteId => $documentIds) {
            $rows = (new Query())
                ->select(['siteId', 'documentKey', 'frequency'])
                ->from('{{%searchmanager_search_documents}}')
                ->where([
                    'indexHandle' => $this->indexHandle,
                    'siteId' => $siteId,
                    'documentKey' => $documentIds,
                    'term' => '_length',
                ])
                ->all();

            foreach ($rows as $row) {
                $docId = $row['siteId'] . ':' . $row['documentKey'];
                $lengths[$docId] = (int)$row['frequency'];
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
        $this->storeTermDocumentByKey($term, $siteId, $elementId, $this->pageDocumentKey($siteId, $elementId), $frequency, $language);
    }

    public function storeTermDocumentByKey(string $term, int $siteId, int $elementId, string $documentKey, int $frequency, string $language = 'en'): void
    {
        $values = [
            'indexHandle' => $this->indexHandle,
            'term' => $term,
            'siteId' => $siteId,
            'elementId' => $elementId,
            'documentKey' => $documentKey,
            'frequency' => $frequency,
            'language' => $language,
        ];
        $this->requireDocumentKeyColumn('{{%searchmanager_search_terms}}');

        // Upsert avoids duplicate-key failures when collation-equivalent terms collide.
        $this->db->createCommand()->upsert(
            '{{%searchmanager_search_terms}}',
            $values,
            [
                // Keep the larger frequency to avoid inflating BM25 from equivalent variants.
                'frequency' => new Expression('GREATEST([[frequency]], :incomingFrequency)', [
                    ':incomingFrequency' => $frequency,
                ]),
                'language' => $language,
            ]
        )->execute();
    }

    /**
     * @inheritdoc
     */
    public function getTermDocuments(string $term, int $siteId): array
    {
        $rows = (new Query())
            ->select(['siteId', 'documentKey', 'frequency'])
            ->from('{{%searchmanager_search_terms}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
            ])
            ->all();

        $docs = [];
        foreach ($rows as $row) {
            $docId = $row['siteId'] . ':' . $row['documentKey'];
            $docs[$docId] = (int)$row['frequency'];
        }

        return $docs;
    }

    /**
     * @inheritdoc
     */
    public function getTermDocumentsBatch(array $terms, int $siteId): array
    {
        if (empty($terms)) {
            return [];
        }

        $rows = (new Query())
            ->select(['term', 'siteId', 'documentKey', 'frequency'])
            ->from('{{%searchmanager_search_terms}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'term' => array_values($terms),
                'siteId' => $siteId,
            ])
            ->all();

        $byTerm = [];
        foreach ($rows as $row) {
            $docId = $row['siteId'] . ':' . $row['documentKey'];
            $byTerm[$row['term']][$docId] = (int)$row['frequency'];
        }

        return $byTerm;
    }

    /**
     * @inheritdoc
     */
    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $this->removeTermDocumentByKey($term, $siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function removeTermDocumentByKey(string $term, int $siteId, string $documentKey): void
    {
        $condition = [
            'indexHandle' => $this->indexHandle,
            'term' => $term,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];
        $this->requireDocumentKeyColumn('{{%searchmanager_search_terms}}');

        $this->db->createCommand()->delete(
            '{{%searchmanager_search_terms}}',
            $condition
        )->execute();
    }

    /**
     * @inheritdoc
     */
    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000, ?string $prefix = null): array
    {
        $this->logDebug('getTermsForAutocomplete: Starting query', [
            'indexHandle' => $this->indexHandle,
            'siteId' => $siteId,
            'language' => $language,
            'limit' => $limit,
            'prefix' => $prefix,
        ]);

        $query = (new \craft\db\Query())
            ->select(['term', 'SUM(frequency) as total_freq'])
            ->from('{{%searchmanager_search_terms}}')
            ->where(['indexHandle' => $this->indexHandle]);

        // Filter by siteId only if provided (for site-specific indices)
        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        // Filter by language if provided
        if ($language !== null) {
            $query->andWhere(['language' => $language]);
        }

        if ($prefix !== null && $prefix !== '') {
            $query->andWhere(['like', 'term', self::escapeLikePrefix($prefix) . '%', false]);
        }

        $results = $query
            ->groupBy(['term'])
            ->orderBy(['total_freq' => SORT_DESC])
            ->limit($limit)
            ->all();

        $this->logDebug('getTermsForAutocomplete: Query complete', [
            'indexHandle' => $this->indexHandle,
            'resultCount' => count($results),
            'sampleTerms' => array_slice(array_column($results, 'term'), 0, 5),
        ]);

        $terms = [];
        foreach ($results as $row) {
            $terms[$row['term']] = (int)$row['total_freq'];
        }

        return $terms;
    }

    private static function escapeLikePrefix(string $prefix): string
    {
        return addcslashes($prefix, '\\%_');
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
        $this->storeElementByKey($siteId, $elementId, $this->pageDocumentKey($siteId, $elementId), $title, $elementType, $documentData);
    }

    public function storeElementByKey(int $siteId, int $elementId, string $documentKey, string $title, string $elementType, ?string $documentData = null): void
    {
        // Normalize searchText for prefix matching (lowercase)
        $searchText = mb_strtolower(trim($title));
        $this->requireDocumentKeyColumn('{{%searchmanager_search_elements}}');

        $columns = '`indexHandle`, `siteId`, `elementId`, `documentKey`, `title`, `elementType`, `searchText`, `documentData`';
        $sql = "REPLACE INTO {{%searchmanager_search_elements}}
                (" . $columns . ") VALUES
                (" . $this->db->quoteValue($this->indexHandle) . ", "
                . (int)$siteId . ", "
                . (int)$elementId . ", ";
        $sql .= $this->db->quoteValue($documentKey) . ", "
                . $this->db->quoteValue($title) . ", "
                . $this->db->quoteValue($elementType) . ", "
                . $this->db->quoteValue($searchText) . ", "
                . ($documentData !== null ? $this->db->quoteValue($documentData) : 'NULL') . ")";

        $this->db->createCommand($sql)->execute();

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
        $this->db->createCommand()->delete(
            '{{%searchmanager_search_elements}}',
            [
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ]
        )->execute();
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

        $rows = (new Query())
            ->select(['elementId', 'title', 'elementType', 'documentData'])
            ->from('{{%searchmanager_search_elements}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementIds,
            ])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['elementId']] = [
                'title' => $row['title'],
                'elementType' => $row['elementType'],
                'documentData' => !empty($row['documentData']) ? json_decode($row['documentData'], true) : null,
            ];
        }

        return $result;
    }

    public function getElementsByDocumentKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_elements}}');

        $rows = (new Query())
            ->select(['documentKey', 'elementId', 'title', 'elementType', 'documentData'])
            ->from('{{%searchmanager_search_elements}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'documentKey' => $documentKeys,
            ])
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['documentKey']] = [
                'elementId' => (int)$row['elementId'],
                'title' => $row['title'],
                'elementType' => $row['elementType'],
                'documentData' => !empty($row['documentData']) ? json_decode($row['documentData'], true) : null,
            ];
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
    public function getElementSuggestions(string $query, ?int $siteId, int $limit = 10, ?string $elementType = null): array
    {
        $searchText = mb_strtolower(trim($query));

        $dbQuery = (new Query())
            ->select(['title', 'elementType', 'elementId', 'siteId'])
            ->from('{{%searchmanager_search_elements}}')
            ->where(['indexHandle' => $this->indexHandle])
            ->andWhere(['like', 'searchText', self::escapeLikePrefix($searchText) . '%', false])
            ->groupBy(['title', 'elementType', 'elementId', 'siteId'])
            ->limit($limit);

        // Filter by siteId if provided (null = all sites)
        if ($siteId !== null) {
            $dbQuery->andWhere(['siteId' => $siteId]);
        }

        if ($elementType !== null) {
            $dbQuery->andWhere(['elementType' => $elementType]);
        }

        return $dbQuery->all();
    }

    // =========================================================================
    // TITLE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
        $this->storeTitleTermsByKey($siteId, $elementId, $this->pageDocumentKey($siteId, $elementId), $titleTerms);
    }

    public function storeTitleTermsByKey(int $siteId, int $elementId, string $documentKey, array $titleTerms): void
    {
        if (empty($titleTerms)) {
            return;
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_titles}}');

        // Use REPLACE INTO to handle duplicates
        $columns = '`indexHandle`, `siteId`, `elementId`, `documentKey`, `term`';
        $sql = "REPLACE INTO {{%searchmanager_search_titles}}
                (" . $columns . ") VALUES ";

        $valueStrings = [];
        foreach ($titleTerms as $term) {
            $row = "("
                . $this->db->quoteValue($this->indexHandle) . ", "
                . (int)$siteId . ", "
                . (int)$elementId . ", ";
            $row .= $this->db->quoteValue($documentKey) . ", "
                . $this->db->quoteValue($term) . ")";
            $valueStrings[] = $row;
        }

        $sql .= implode(', ', $valueStrings);

        $this->db->createCommand($sql)->execute();

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
        return (new Query())
            ->select(['term'])
            ->from('{{%searchmanager_search_titles}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->column();
    }

    /**
     * @inheritdoc
     */
    public function getTitleTermsBatch(int $siteId, array $elementIds): array
    {
        if (empty($elementIds)) {
            return [];
        }

        $rows = (new Query())
            ->select(['elementId', 'term'])
            ->from('{{%searchmanager_search_titles}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementIds,
            ])
            ->all();

        $byElement = [];
        foreach ($rows as $row) {
            $byElement[(int)$row['elementId']][] = $row['term'];
        }

        return $byElement;
    }

    public function getTitleTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        if (empty($documentKeys)) {
            return [];
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_titles}}');

        $rows = (new Query())
            ->select(['documentKey', 'term'])
            ->from('{{%searchmanager_search_titles}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'documentKey' => $documentKeys,
            ])
            ->all();

        $byDocument = [];
        foreach ($rows as $row) {
            $byDocument[(string)$row['documentKey']][] = $row['term'];
        }

        return $byDocument;
    }

    /**
     * @inheritdoc
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $this->deleteTitleTermsByKey($siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function deleteTitleTermsByKey(int $siteId, string $documentKey): void
    {
        $condition = [
            'indexHandle' => $this->indexHandle,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];
        $this->requireDocumentKeyColumn('{{%searchmanager_search_titles}}');

        $this->db->createCommand()->delete(
            '{{%searchmanager_search_titles}}',
            $condition
        )->execute();
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

        // Check if n-grams already exist for this term (prevents duplicates)
        if ($this->termHasNgrams($term, $siteId)) {
            return; // N-grams already stored, skip
        }

        // Store n-grams using INSERT IGNORE to handle race conditions
        $sql = "INSERT IGNORE INTO {{%searchmanager_search_ngrams}}
                (`indexHandle`, `ngram`, `term`, `siteId`) VALUES ";

        $valueStrings = [];
        foreach ($ngrams as $ngram) {
            $valueStrings[] = "("
                . $this->db->quoteValue($this->indexHandle) . ", "
                . $this->db->quoteValue($ngram) . ", "
                . $this->db->quoteValue($term) . ", "
                . (int)$siteId . ")";
        }

        $sql .= implode(', ', $valueStrings);

        $this->db->createCommand($sql)->execute();

        // Store n-gram count (use upsert to handle duplicates gracefully)
        $this->db->createCommand()->upsert(
            '{{%searchmanager_search_ngram_counts}}',
            [
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
                'ngramCount' => count($ngrams),
            ],
            [
                'ngramCount' => count($ngrams), // Update if exists
            ]
        )->execute();

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
        $exists = (new Query())
            ->select(['term'])
            ->from('{{%searchmanager_search_ngram_counts}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
            ])
            ->exists();

        return $exists;
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

        // Find terms with matching n-grams and calculate Jaccard similarity
        // Use named parameters for ngrams to avoid PDO binding issues
        $ngramPlaceholders = [];
        $params = [
            ':indexHandle' => $this->indexHandle,
            ':siteId' => $siteId,
            ':searchCount' => $searchNgramCount,
            ':threshold' => $threshold,
        ];

        foreach ($ngrams as $i => $ngram) {
            $placeholder = ':ngram' . $i;
            $ngramPlaceholders[] = $placeholder;
            $params[$placeholder] = $ngram;
        }

        // Use configurable limit from settings (maxFuzzyCandidates)
        $sql = "
            SELECT
                n.term,
                COUNT(DISTINCT n.ngram) as intersection,
                nc.ngramCount as union_count,
                (COUNT(DISTINCT n.ngram) * 1.0) / (nc.ngramCount + :searchCount - COUNT(DISTINCT n.ngram)) as similarity
            FROM {{%searchmanager_search_ngrams}} n
            JOIN {{%searchmanager_search_ngram_counts}} nc
                ON nc.indexHandle = n.indexHandle
                AND nc.term = n.term
                AND nc.siteId = n.siteId
            WHERE
                n.indexHandle = :indexHandle
                AND n.siteId = :siteId
                AND n.ngram IN (" . implode(',', $ngramPlaceholders) . ")
            GROUP BY n.term, nc.ngramCount
            HAVING similarity >= :threshold
            ORDER BY similarity DESC
            LIMIT " . (int)$limit . "
        ";

        $rows = $this->db->createCommand($sql, $params)->queryAll();

        $results = [];
        foreach ($rows as $row) {
            $results[$row['term']] = (float)$row['similarity'];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getTermsByPrefix(string $prefix, int $siteId): array
    {
        if (empty($prefix)) {
            return [];
        }

        $terms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from('{{%searchmanager_search_terms}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
            ])
            ->andWhere(['like', 'term', self::escapeLikePrefix($prefix) . '%', false])
            ->column();

        return $terms ?: [];
    }

    /**
     * @inheritdoc
     */
    public function storeCompoundSuggestions(int $siteId, int $elementId, array $suggestions, string $language = 'en'): void
    {
        $this->storeCompoundSuggestionsByKey($siteId, $elementId, $this->pageDocumentKey($siteId, $elementId), $suggestions, $language);
    }

    public function storeCompoundSuggestionsByKey(int $siteId, int $elementId, string $documentKey, array $suggestions, string $language = 'en'): void
    {
        $this->deleteCompoundSuggestionsByKey($siteId, $documentKey);

        if (empty($suggestions)) {
            return;
        }

        $this->requireDocumentKeyColumn('{{%searchmanager_search_compounds}}');
        $rows = [];
        foreach ($suggestions as $suggestion) {
            $rows[] = [
                $this->indexHandle,
                $siteId,
                $elementId,
                $documentKey,
                (string)$suggestion['suggestion'],
                (string)$suggestion['normalizedSuggestion'],
                (string)$suggestion['tokenKey'],
                (int)$suggestion['frequency'],
                $language,
            ];
        }

        foreach ($rows as $row) {
            $insert = [
                'indexHandle' => $row[0],
                'siteId' => $row[1],
                'elementId' => $row[2],
                'documentKey' => $row[3],
                'suggestion' => $row[4],
                'normalizedSuggestion' => $row[5],
                'tokenKey' => $row[6],
                'frequency' => $row[7],
                'language' => $row[8],
            ];

            $this->db->createCommand()->upsert(
                '{{%searchmanager_search_compounds}}',
                $insert,
                [
                    'normalizedSuggestion' => $row[5],
                    'tokenKey' => $row[6],
                    'frequency' => $row[7],
                    'language' => $row[8],
                ],
            )->execute();
        }
    }

    private function pageDocumentKey(int $siteId, int $elementId): string
    {
        return SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
    }

    private function requireDocumentKeyColumn(string $table): void
    {
        if ($this->hasColumn($table, 'documentKey')) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Search Manager storage table %s is missing documentKey. Reinstall Search Manager or run the documented ALTER for the current Install.php schema.',
            $table,
        ));
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, $this->columnExists)) {
            return $this->columnExists[$key];
        }

        $tableSchema = $this->db->getTableSchema($this->db->getSchema()->getRawTableName($table), true)
            ?? $this->db->getTableSchema($table, true);

        return $this->columnExists[$key] = $tableSchema !== null && isset($tableSchema->columns[$column]);
    }

    /**
     * @inheritdoc
     */
    public function deleteCompoundSuggestions(int $siteId, int $elementId): void
    {
        $this->deleteCompoundSuggestionsByKey($siteId, $this->pageDocumentKey($siteId, $elementId));
    }

    public function deleteCompoundSuggestionsByKey(int $siteId, string $documentKey): void
    {
        $condition = [
            'indexHandle' => $this->indexHandle,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];
        $this->requireDocumentKeyColumn('{{%searchmanager_search_compounds}}');

        $this->db->createCommand()->delete(
            '{{%searchmanager_search_compounds}}',
            $condition,
        )->execute();
    }

    /**
     * @return string[]
     */
    public function getDocumentKeysByParent(int $siteId, int $elementId): array
    {
        $this->requireDocumentKeyColumn('{{%searchmanager_search_elements}}');

        return array_map('strval', (new Query())
            ->select(['documentKey'])
            ->from('{{%searchmanager_search_elements}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
            ->column());
    }

    /**
     * @inheritdoc
     */
    public function getCompoundSuggestionsForAutocomplete(string $normalizedPrefix, ?int $siteId, ?string $language, int $limit = 10): array
    {
        if ($normalizedPrefix === '') {
            return [];
        }

        $query = (new Query())
            ->select(['suggestion', 'normalizedSuggestion', new Expression('SUM([[frequency]]) AS [[displayFrequency]]')])
            ->from('{{%searchmanager_search_compounds}}')
            ->where(['indexHandle' => $this->indexHandle])
            ->andWhere(['like', 'normalizedSuggestion', self::escapeLikePrefix($normalizedPrefix) . '%', false]);

        if ($siteId !== null) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($language !== null) {
            $query->andWhere(['language' => $language]);
        }

        $rows = $query
            ->groupBy(['normalizedSuggestion', 'suggestion'])
            ->all();

        $suggestionsByNormalized = [];
        foreach ($rows as $row) {
            $normalizedSuggestion = (string)$row['normalizedSuggestion'];
            $suggestion = (string)$row['suggestion'];
            $frequency = (int)$row['displayFrequency'];
            $suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] =
                ($suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] ?? 0) + $frequency;
            $suggestionsByNormalized[$normalizedSuggestion]['displayFrequencies'][$suggestion] = $frequency;
        }

        $suggestions = [];
        foreach ($suggestionsByNormalized as $data) {
            $displayFrequencies = $data['displayFrequencies'];
            arsort($displayFrequencies);
            $topFrequency = reset($displayFrequencies);
            $topSuggestions = array_keys(array_filter(
                $displayFrequencies,
                static fn(int $frequency): bool => $frequency === $topFrequency,
            ));
            sort($topSuggestions, SORT_STRING);
            $suggestions[$topSuggestions[0]] = (int)$data['totalFrequency'];
        }

        arsort($suggestions);

        return array_slice($suggestions, 0, $limit, true);
    }

    // =========================================================================
    // METADATA OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTotalDocCount(int $siteId): int
    {
        $result = (new Query())
            ->select(['metaValue'])
            ->from('{{%searchmanager_search_metadata}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'metaKey' => 'doc_count',
            ])
            ->scalar();

        return $result ? (int)$result : 0;
    }

    /**
     * @inheritdoc
     */
    public function getTotalLength(int $siteId): int
    {
        $result = (new Query())
            ->select(['metaValue'])
            ->from('{{%searchmanager_search_metadata}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'metaKey' => 'total_length',
            ])
            ->scalar();

        return $result ? (int)$result : 1; // Minimum 1 to avoid division by zero
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
        $docCountChange = $isAddition ? 1 : -1;
        $lengthChange = $isAddition ? $docLength : -$docLength;

        // Update doc count
        $this->incrementMetadata($siteId, 'doc_count', $docCountChange);

        // Update total length
        $this->incrementMetadata($siteId, 'total_length', $lengthChange);
    }

    /**
     * Increment a metadata value
     *
     * @param int $siteId Site ID
     * @param string $metaKey Metadata key
     * @param int $increment Amount to increment (can be negative)
     * @return void
     */
    private function incrementMetadata(int $siteId, string $metaKey, int $increment): void
    {
        $minimum = $metaKey === 'total_length' ? 1 : 0;
        $initialValue = max($minimum, $increment);

        $this->db->createCommand()->upsert(
            '{{%searchmanager_search_metadata}}',
            [
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'metaKey' => $metaKey,
                'metaValue' => (string)$initialValue,
            ],
            [
                'metaValue' => new Expression('GREATEST(CAST([[metaValue]] AS SIGNED) + :increment, :minimum)', [
                    ':increment' => $increment,
                    ':minimum' => $minimum,
                ]),
            ]
        )->execute();
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function clearSite(int $siteId): void
    {
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
        ];

        foreach ($tables as $table) {
            $this->db->createCommand()->delete(
                $table,
                [
                    'indexHandle' => $this->indexHandle,
                    'siteId' => $siteId,
                ]
            )->execute();
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
        $this->logInfo('clearAll() called - about to delete', [
            'indexHandle' => $this->indexHandle,
        ]);

        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
            '{{%searchmanager_search_elements}}',
            '{{%searchmanager_search_compounds}}',
        ];

        foreach ($tables as $table) {
            $this->db->createCommand()->delete(
                $table,
                ['indexHandle' => $this->indexHandle]
            )->execute();
        }

        $this->logInfo('clearAll() completed', [
            'index' => $this->indexHandle,
        ]);
    }
}
