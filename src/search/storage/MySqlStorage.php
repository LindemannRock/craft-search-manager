<?php

namespace lindemannrock\searchmanager\search\storage;

use Craft;
use craft\db\Query;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * MySqlStorage
 *
 * MySQL-based storage implementation for the search engine.
 * Stores inverted index data in MySQL tables with optimized queries.
 *
 * @since 5.0.0
 */
class MySqlStorage implements StorageInterface
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

        $this->logDebug('Initialized MySqlStorage', [
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

        $this->db->createCommand()->batchInsert(
            '{{%searchmanager_search_documents}}',
            ['indexHandle', 'siteId', 'elementId', 'term', 'frequency', 'language'],
            $values
        )->execute();

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
    public function deleteDocument(int $siteId, int $elementId): void
    {
        // Delete from all tables that have elementId-specific data
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
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
    public function storeTermDocument(string $term, int $siteId, int $elementId, int $frequency): void
    {
        $this->db->createCommand()->insert(
            '{{%searchmanager_search_terms}}',
            [
                'indexHandle' => $this->indexHandle,
                'term' => $term,
                'siteId' => $siteId,
                'elementId' => $elementId,
                'frequency' => $frequency,
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

        $values = [];
        foreach ($titleTerms as $term) {
            $values[] = [
                $this->indexHandle,
                $siteId,
                $elementId,
                $term,
            ];
        }

        $this->db->createCommand()->batchInsert(
            '{{%searchmanager_search_titles}}',
            ['indexHandle', 'siteId', 'elementId', 'term'],
            $values
        )->execute();

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

        // Store n-grams
        $values = [];
        foreach ($ngrams as $ngram) {
            $values[] = [
                $this->indexHandle,
                $ngram,
                $term,
                $siteId,
            ];
        }

        $this->db->createCommand()->batchInsert(
            '{{%searchmanager_search_ngrams}}',
            ['indexHandle', 'ngram', 'term', 'siteId'],
            $values
        )->execute();

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
    public function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold): array
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
            ->andWhere(['like', 'term', $prefix . '%', false])
            ->column();

        return $terms ?: [];
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
        // Try to update existing row
        $updated = $this->db->createCommand()
            ->update(
                '{{%searchmanager_search_metadata}}',
                ['metaValue' => new \yii\db\Expression('CAST(metaValue AS SIGNED) + :increment', [':increment' => $increment])],
                [
                    'indexHandle' => $this->indexHandle,
                    'siteId' => $siteId,
                    'metaKey' => $metaKey,
                ]
            )
            ->execute();

        // If no row exists, insert it
        if ($updated === 0) {
            try {
                $this->db->createCommand()->insert(
                    '{{%searchmanager_search_metadata}}',
                    [
                        'indexHandle' => $this->indexHandle,
                        'siteId' => $siteId,
                        'metaKey' => $metaKey,
                        'metaValue' => max(0, $increment), // Don't allow negative values
                    ]
                )->execute();
            } catch (\Exception $e) {
                // Ignore duplicate key errors (race condition)
                if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                    throw $e;
                }
            }
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
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
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
        $tables = [
            '{{%searchmanager_search_documents}}',
            '{{%searchmanager_search_terms}}',
            '{{%searchmanager_search_titles}}',
            '{{%searchmanager_search_ngrams}}',
            '{{%searchmanager_search_ngram_counts}}',
            '{{%searchmanager_search_metadata}}',
        ];

        foreach ($tables as $table) {
            $this->db->createCommand()->delete(
                $table,
                ['indexHandle' => $this->indexHandle]
            )->execute();
        }

        $this->logInfo('Cleared all data', [
            'index' => $this->indexHandle,
        ]);
    }
}
