<?php

namespace lindemannrock\searchmanager\search\storage;

use Craft;
use craft\db\Query;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\db\Expression;

/**
 * PostgreSqlStorage
 *
 * PostgreSQL-based storage implementation for the search engine.
 * Stores inverted index data in Craft's PostgreSQL database with optimized queries.
 *
 * @since 5.0.0
 */
class PostgreSqlStorage implements StorageInterface
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
     * Constructor
     *
     * @param string $indexHandle Index handle
     */
    public function __construct(string $indexHandle)
    {
        $this->setLoggingHandle('search-manager');
        $this->indexHandle = $indexHandle;
        $this->db = Craft::$app->getDb();

        $this->logDebug('Initialized PostgreSqlStorage', [
            'index' => $this->indexHandle,
        ]);
    }

    // =========================================================================
    // DOCUMENT OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        // Store term frequencies
        $values = [];
        foreach ($termFreqs as $term => $frequency) {
            $values[] = [
                $this->indexHandle,
                $siteId,
                $elementId,
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
            '_length',
            $docLength,
            $language,
        ];

        // Store _language as a special entry for easy retrieval
        $values[] = [
            $this->indexHandle,
            $siteId,
            $elementId,
            '_language',
            0,
            $language,
        ];

        $this->upsertRows(
            '{{%searchmanager_search_documents}}',
            ['indexHandle', 'siteId', 'elementId', 'term', 'frequency', 'language'],
            $values,
            ['indexHandle', 'siteId', 'elementId', 'term'],
            ['frequency', 'language'],
        );

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

    /**
     * @inheritdoc
     */
    public function getDocumentTerms(int $siteId, int $elementId): array
    {
        $rows = (new Query())
            ->select(['term', 'frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ])
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

    /**
     * @inheritdoc
     */
    public function deleteDocument(int $siteId, int $elementId): void
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
            $this->db->createCommand()->delete(
                $table,
                [
                    'indexHandle' => $this->indexHandle,
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                ]
            )->execute();
        }

        $this->logDebug('Deleted document from all tables', [
            'site_id' => $siteId,
            'element_id' => $elementId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLength(int $siteId, int $elementId): int
    {
        $result = (new Query())
            ->select(['frequency'])
            ->from('{{%searchmanager_search_documents}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
                'term' => '_length',
            ])
            ->scalar();

        return $result ? (int)$result : 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatch(array $docIds): array
    {
        $lengths = [];

        foreach ($docIds as $siteId => $elementIds) {
            $rows = (new Query())
                ->select(['siteId', 'elementId', 'frequency'])
                ->from('{{%searchmanager_search_documents}}')
                ->where([
                    'indexHandle' => $this->indexHandle,
                    'siteId' => $siteId,
                    'elementId' => $elementIds,
                    'term' => '_length',
                ])
                ->all();

            foreach ($rows as $row) {
                $docId = $row['siteId'] . ':' . $row['elementId'];
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
        // Upsert avoids duplicate-key failures when collation-equivalent terms collide.
        $this->db->createCommand()->upsert(
            '{{%searchmanager_search_terms}}',
            [
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
                'elementId' => $elementId,
                'frequency' => $frequency,
                'language' => $language,
            ],
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
            ->select(['siteId', 'elementId', 'frequency'])
            ->from('{{%searchmanager_search_terms}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
            ])
            ->all();

        $docs = [];
        foreach ($rows as $row) {
            $docId = $row['siteId'] . ':' . $row['elementId'];
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
            ->select(['term', 'siteId', 'elementId', 'frequency'])
            ->from('{{%searchmanager_search_terms}}')
            ->where([
                'indexHandle' => $this->indexHandle,
                'term' => array_values($terms),
                'siteId' => $siteId,
            ])
            ->all();

        $byTerm = [];
        foreach ($rows as $row) {
            $docId = $row['siteId'] . ':' . $row['elementId'];
            $byTerm[$row['term']][$docId] = (int)$row['frequency'];
        }

        return $byTerm;
    }

    /**
     * @inheritdoc
     */
    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $this->db->createCommand()->delete(
            '{{%searchmanager_search_terms}}',
            [
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ]
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
        // Normalize searchText for prefix matching (lowercase)
        $searchText = mb_strtolower(trim($title));

        $this->upsertRows(
            '{{%searchmanager_search_elements}}',
            ['indexHandle', 'siteId', 'elementId', 'title', 'elementType', 'searchText', 'documentData'],
            [[
                $this->indexHandle,
                $siteId,
                $elementId,
                $title,
                $elementType,
                $searchText,
                $documentData,
            ]],
            ['indexHandle', 'siteId', 'elementId'],
            ['title', 'elementType', 'searchText', 'documentData'],
        );

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
        if (empty($titleTerms)) {
            return;
        }

        $rows = [];
        foreach ($titleTerms as $term) {
            $rows[] = [$this->indexHandle, $siteId, $elementId, $term];
        }

        $this->insertRowsOnConflictDoNothing(
            '{{%searchmanager_search_titles}}',
            ['indexHandle', 'siteId', 'elementId', 'term'],
            $rows,
        );

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

    /**
     * @inheritdoc
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $this->db->createCommand()->delete(
            '{{%searchmanager_search_titles}}',
            [
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ]
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

        $rows = [];
        foreach ($ngrams as $ngram) {
            $rows[] = [$this->indexHandle, $ngram, $term, $siteId];
        }

        $this->insertRowsOnConflictDoNothing(
            '{{%searchmanager_search_ngrams}}',
            ['indexHandle', 'ngram', 'term', 'siteId'],
            $rows,
        );

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
                n.\"term\",
                COUNT(DISTINCT n.\"ngram\") AS \"intersection\",
                nc.\"ngramCount\" AS \"union_count\",
                (COUNT(DISTINCT n.\"ngram\") * 1.0) / (nc.\"ngramCount\" + :searchCount - COUNT(DISTINCT n.\"ngram\")) AS \"similarity\"
            FROM {{%searchmanager_search_ngrams}} n
            JOIN {{%searchmanager_search_ngram_counts}} nc
                ON nc.\"indexHandle\" = n.\"indexHandle\"
                AND nc.\"term\" = n.\"term\"
                AND nc.\"siteId\" = n.\"siteId\"
            WHERE
                n.\"indexHandle\" = :indexHandle
                AND n.\"siteId\" = :siteId
                AND n.\"ngram\" IN (" . implode(',', $ngramPlaceholders) . ")
            GROUP BY n.\"term\", nc.\"ngramCount\"
            HAVING (COUNT(DISTINCT n.\"ngram\") * 1.0) / (nc.\"ngramCount\" + :searchCount - COUNT(DISTINCT n.\"ngram\")) >= :threshold
            ORDER BY \"similarity\" DESC
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
        $this->deleteCompoundSuggestions($siteId, $elementId);

        if (empty($suggestions)) {
            return;
        }

        $rows = [];
        foreach ($suggestions as $suggestion) {
            $rows[] = [
                $this->indexHandle,
                $siteId,
                $elementId,
                (string)$suggestion['suggestion'],
                (string)$suggestion['normalizedSuggestion'],
                (string)$suggestion['tokenKey'],
                (int)$suggestion['frequency'],
                $language,
            ];
        }

        $this->upsertRows(
            '{{%searchmanager_search_compounds}}',
            ['indexHandle', 'siteId', 'elementId', 'suggestion', 'normalizedSuggestion', 'tokenKey', 'frequency', 'language'],
            $rows,
            ['indexHandle', 'siteId', 'elementId', 'suggestion'],
            ['normalizedSuggestion', 'tokenKey', 'frequency', 'language'],
        );
    }

    /**
     * @inheritdoc
     */
    public function deleteCompoundSuggestions(int $siteId, int $elementId): void
    {
        $this->db->createCommand()->delete(
            '{{%searchmanager_search_compounds}}',
            [
                'indexHandle' => $this->indexHandle,
                'siteId' => $siteId,
                'elementId' => $elementId,
            ],
        )->execute();
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
            ->select(['suggestion', new Expression('SUM([[frequency]]) AS [[totalFrequency]]')])
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
            ->groupBy(['suggestion'])
            ->orderBy(['totalFrequency' => SORT_DESC, 'suggestion' => SORT_ASC])
            ->limit($limit)
            ->all();

        $suggestions = [];
        foreach ($rows as $row) {
            $suggestions[(string)$row['suggestion']] = (int)$row['totalFrequency'];
        }

        return $suggestions;
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

        $sql = '
            INSERT INTO {{%searchmanager_search_metadata}}
                ("indexHandle", "siteId", "metaKey", "metaValue")
            VALUES
                (:indexHandle, :siteId, :metaKey, :initialValue)
            ON CONFLICT ("indexHandle", "siteId", "metaKey") DO UPDATE SET
                "metaValue" = GREATEST(CAST({{%searchmanager_search_metadata}}."metaValue" AS INTEGER) + :increment, :minimum)
        ';

        $this->db->createCommand($sql, [
            ':indexHandle' => $this->indexHandle,
            ':siteId' => $siteId,
            ':metaKey' => $metaKey,
            ':initialValue' => (string)$initialValue,
            ':increment' => $increment,
            ':minimum' => $minimum,
        ])->execute();
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     */
    private function insertRowsOnConflictDoNothing(string $table, array $columns, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $sql = $this->buildInsertSql($table, $columns, $rows) . ' ON CONFLICT DO NOTHING';
        $this->db->createCommand($sql)->execute();
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, string> $conflictColumns
     * @param array<int, string> $updateColumns
     */
    private function upsertRows(string $table, array $columns, array $rows, array $conflictColumns, array $updateColumns): void
    {
        if (empty($rows)) {
            return;
        }

        $assignments = [];
        foreach ($updateColumns as $column) {
            $quoted = $this->db->quoteColumnName($column);
            $assignments[] = $quoted . ' = EXCLUDED.' . $quoted;
        }

        $sql = $this->buildInsertSql($table, $columns, $rows)
            . ' ON CONFLICT (' . implode(', ', array_map($this->db->quoteColumnName(...), $conflictColumns)) . ') DO UPDATE SET '
            . implode(', ', $assignments);

        $this->db->createCommand($sql)->execute();
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     */
    private function buildInsertSql(string $table, array $columns, array $rows): string
    {
        $quotedColumns = array_map($this->db->quoteColumnName(...), $columns);
        $valueStrings = [];

        foreach ($rows as $row) {
            $valueStrings[] = '(' . implode(', ', array_map($this->quoteInsertValue(...), $row)) . ')';
        }

        return 'INSERT INTO ' . $table . ' (' . implode(', ', $quotedColumns) . ') VALUES ' . implode(', ', $valueStrings);
    }

    private function quoteInsertValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return $this->db->quoteValue((string)$value);
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
