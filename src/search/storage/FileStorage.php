<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search\storage;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\helpers\FileBackendStoragePathHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\search\TermNormalizer;

/**
 * FileStorage
 *
 * File-based storage implementation using JSON for persistence.
 * Stores inverted index data in .json files organized by directory structure.
 *
 * Note: Changed from serialize() to json_encode() for security (no object injection risk).
 *
 * Directory structure:
 * - docs/      - Document term frequencies and lengths
 * - terms/     - Inverted index (term -> documents)
 * - titles/    - Title terms per document
 * - ngrams/    - N-grams for fuzzy matching
 * - ngrams-index/ - N-gram inverted lookup buckets
 * - compounds-index/ - Aggregated compound autocomplete buckets
 * - meta/      - Global metadata
 *
 * @since 5.0.0
 */
class FileStorage implements DocumentKeyStorageInterface, ElementSuggestionStorageInterface
{
    use LoggingTrait;

    private const ENCODED_FILENAME_PREFIX = '__utf8_';
    private const HASHED_FILENAME_PREFIX = '__utf8_sha256_';
    private const MAX_FILENAME_SEGMENT_LENGTH = 200;

    /**
     * @var string Index handle
     */
    private string $indexHandle;

    /**
     * @var string Base storage path
     */
    private string $basePath;

    /**
     * Constructor
     *
     * @param string $indexHandle Index handle
     */
    public function __construct(string $indexHandle, ?string $customBasePath = null)
    {
        $this->setLoggingHandle('search-manager');

        // Validate handle against path traversal
        if (preg_match('/[\/\\\\]|\.\./', $indexHandle)) {
            throw new \InvalidArgumentException('Invalid index handle: must not contain path separators or traversal characters.');
        }

        $this->indexHandle = $indexHandle;

        // Use custom base path if provided, otherwise default to runtime path
        if ($customBasePath !== null && $customBasePath !== '') {
            $this->basePath = FileBackendStoragePathHelper::resolve($customBasePath) . '/' . $indexHandle;
        } else {
            $this->basePath = FileBackendStoragePathHelper::defaultBasePath() . '/' . $indexHandle;
        }

        // Create directory structure
        $this->ensureDirectoryStructure();

        $this->logDebug('Initialized FileStorage', [
            'index' => $this->indexHandle,
            'path' => $this->basePath,
        ]);
    }

    /**
     * Ensure directory structure exists
     *
     * @return void
     */
    private function ensureDirectoryStructure(): void
    {
        $dirs = [
            $this->basePath,
            $this->basePath . '/docs',
            $this->basePath . '/terms',
            $this->basePath . '/titles',
            $this->basePath . '/ngrams',
            $this->basePath . '/ngrams-index',
            $this->basePath . '/meta',
            $this->basePath . '/elements',
            $this->basePath . '/document-elements',
            $this->basePath . '/compounds',
            $this->basePath . '/compounds-index',
            $this->basePath . '/keys',
            $this->basePath . '/parents',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
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
        $docPath = $this->getDocPath($siteId, $elementId);

        // Add _length and _language to the data
        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;

        $this->writeFile($docPath, $data);

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
    public function getDocumentLanguage(int $siteId, int $elementId): string
    {
        $docPath = $this->getDocPath($siteId, $elementId);
        $data = $this->readFile($docPath);

        return $data['_language'] ?? 'en';
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLanguagesBatch(int $siteId, array $elementIds): array
    {
        $byElement = [];

        foreach (array_values(array_unique(array_map('intval', $elementIds))) as $elementId) {
            $data = $this->readFile($this->getDocPath($siteId, $elementId));
            $byElement[$elementId] = $data['_language'] ?? 'en';
        }

        return $byElement;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTerms(int $siteId, int $elementId): array
    {
        $docPath = $this->getDocPath($siteId, $elementId);
        $data = $this->readFile($docPath);

        if (!$data) {
            return [];
        }

        // Remove special keys from terms
        unset($data['_length'], $data['_language']);

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentTermsBatch(int $siteId, array $elementIds): array
    {
        $byElement = [];

        foreach (array_values(array_unique(array_map('intval', $elementIds))) as $elementId) {
            $data = $this->readFile($this->getDocPath($siteId, $elementId));
            if (empty($data)) {
                continue;
            }

            unset($data['_length'], $data['_language']);
            $byElement[$elementId] = array_map('intval', $data);
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

        // Delete document data file
        $docPath = $this->getDocPath($siteId, $elementId);
        if (file_exists($docPath)) {
            @unlink($docPath);
        }

        // Delete title terms file
        $titlePath = $this->getTitlePath($siteId, $elementId);
        if (file_exists($titlePath)) {
            @unlink($titlePath);
        }

        // Delete element metadata file
        $this->deleteElement($siteId, $elementId);
        $this->deleteCompoundSuggestions($siteId, $elementId);

        $this->logDebug('Deleted document, title, element, and compound files', [
            'site_id' => $siteId,
            'element_id' => $elementId,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLength(int $siteId, int $elementId): int
    {
        $docPath = $this->getDocPath($siteId, $elementId);
        $data = $this->readFile($docPath);

        return $data['_length'] ?? 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatch(array $docIds): array
    {
        $lengths = [];

        foreach ($docIds as $siteId => $elementIds) {
            foreach ($elementIds as $elementId) {
                $docPath = $this->getDocPath($siteId, $elementId);
                $data = $this->readFile($docPath);

                if (isset($data['_length'])) {
                    $docId = $siteId . ':' . $elementId;
                    $lengths[$docId] = (int)$data['_length'];
                }
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

        $this->rememberFilenameKey($documentKey);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);

        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;
        $data['_elementId'] = $elementId;
        $data['_documentKey'] = $documentKey;

        $this->writeFile($this->getDocPathByKey($siteId, $documentKey), $data);
    }

    public function getDocumentTermsByKey(int $siteId, string $documentKey): array
    {
        $data = $this->readFile($this->getDocPathByKey($siteId, $documentKey));
        if (empty($data)) {
            return [];
        }

        unset($data['_length'], $data['_language'], $data['_elementId'], $data['_documentKey']);

        return array_map('intval', $data);
    }

    public function getDocumentTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        $byDocument = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $terms = $this->getDocumentTermsByKey($siteId, $documentKey);
            if ($terms !== []) {
                $byDocument[$documentKey] = $terms;
            }
        }

        return $byDocument;
    }

    public function deleteDocumentByKey(int $siteId, string $documentKey): void
    {
        $data = $this->readFile($this->getDocPathByKey($siteId, $documentKey));
        $elementId = isset($data['_elementId']) ? (int)$data['_elementId'] : $this->elementIdFromDocumentKey($documentKey);

        foreach ([
            $this->getDocPathByKey($siteId, $documentKey),
            $this->getTitlePathByKey($siteId, $documentKey),
            $this->getDocumentElementPath($siteId, $documentKey),
        ] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $this->deleteCompoundSuggestionsByKey($siteId, $documentKey);

        if ($elementId !== null) {
            $this->removeDocumentKeyForParent($siteId, $elementId, $documentKey);
            if ($this->getDocumentKeysByParent($siteId, $elementId) === []) {
                $this->deleteElement($siteId, $elementId);
            }
        }
    }

    public function getDocumentLengthByKey(int $siteId, string $documentKey): int
    {
        $data = $this->readFile($this->getDocPathByKey($siteId, $documentKey));

        return (int)($data['_length'] ?? 0);
    }

    public function getDocumentLanguagesBatchByKeys(int $siteId, array $documentKeys): array
    {
        $byDocument = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $data = $this->readFile($this->getDocPathByKey($siteId, $documentKey));
            $byDocument[$documentKey] = (string)($data['_language'] ?? 'en');
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
        $this->rememberFilenameKey($term);
        $termPath = $this->getTermPath($term, $siteId);

        $docId = $siteId . ':' . $elementId;
        // Note: File storage uses siteId for language context, language param not stored separately

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId, $frequency): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $frequency;

                return $data;
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function getTermDocuments(string $term, int $siteId): array
    {
        $termPath = $this->getTermPath($term, $siteId);
        $data = $this->readFile($termPath);

        return $data ?: [];
    }

    /**
     * @inheritdoc
     */
    public function getTermDocumentsBatch(array $terms, int $siteId): array
    {
        $byTerm = [];

        foreach ($terms as $term) {
            $data = $this->readFile($this->getTermPath($term, $siteId));
            if (!empty($data)) {
                $byTerm[$term] = $data;
            }
        }

        return $byTerm;
    }

    /**
     * @inheritdoc
     */
    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termPath = $this->getTermPath($term, $siteId);
        $docId = $siteId . ':' . $elementId;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId): array {
                $data = is_array($current) ? $current : [];
                unset($data[$docId]);

                return $data;
            },
        );
    }

    public function storeTermDocumentByKey(string $term, int $siteId, int $elementId, string $documentKey, int $frequency, string $language = 'en'): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeTermDocument($term, $siteId, $elementId, $frequency, $language);
            return;
        }

        $this->rememberFilenameKey($term);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);

        $termPath = $this->getTermPath($term, $siteId);
        $docId = $siteId . ':' . $documentKey;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId, $frequency): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $frequency;

                return $data;
            },
        );
    }

    public function removeTermDocumentByKey(string $term, int $siteId, string $documentKey): void
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            $this->removeTermDocument($term, $siteId, $elementId);
            return;
        }

        $termPath = $this->getTermPath($term, $siteId);
        $docId = $siteId . ':' . $documentKey;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId): array {
                $data = is_array($current) ? $current : [];
                unset($data[$docId]);

                return $data;
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000, ?string $prefix = null): array
    {
        $termsPath = $this->basePath . '/terms';

        if (!is_dir($termsPath)) {
            return [];
        }

        // File storage uses: term_siteId.dat format (e.g., test_1.dat)
        if ($siteId !== null) {
            // Specific site
            $files = glob($termsPath . '/*_' . $siteId . '.dat');
        } else {
            // All sites - get all .dat files
            $files = glob($termsPath . '/*.dat');
        }

        if (!is_array($files)) {
            return [];
        }

        $terms = [];
        foreach ($files as $file) {
            $term = $this->extractTermFromFilename(
                basename($file),
                $siteId,
                $siteId === null,
            );
            if ($prefix !== null && $prefix !== '' && !str_starts_with($term, $prefix)) {
                continue;
            }

            // Read serialized data
            $data = $this->readFile($file);
            $count = is_array($data) ? count($data) : 0;

            if ($count > 0) {
                // Aggregate frequencies for all-sites
                if (isset($terms[$term])) {
                    $terms[$term] += $count;
                } else {
                    $terms[$term] = $count;
                }
            }
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
        $titlePath = $this->getTitlePath($siteId, $elementId);
        $this->writeFile($titlePath, $titleTerms);

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
        $titlePath = $this->getTitlePath($siteId, $elementId);
        $data = $this->readFile($titlePath);

        return $data ?: [];
    }

    /**
     * @inheritdoc
     */
    public function getTitleTermsBatch(int $siteId, array $elementIds): array
    {
        $byElement = [];

        foreach ($elementIds as $elementId) {
            $data = $this->readFile($this->getTitlePath($siteId, (int)$elementId));
            if (!empty($data)) {
                $byElement[(int)$elementId] = $data;
            }
        }

        return $byElement;
    }

    /**
     * @inheritdoc
     */
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titlePath = $this->getTitlePath($siteId, $elementId);

        if (file_exists($titlePath)) {
            @unlink($titlePath);
        }
    }

    public function storeTitleTermsByKey(int $siteId, int $elementId, string $documentKey, array $titleTerms): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeTitleTerms($siteId, $elementId, $titleTerms);
            return;
        }

        $this->rememberFilenameKey($documentKey);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);
        $this->writeFile($this->getTitlePathByKey($siteId, $documentKey), $titleTerms);
    }

    public function getTitleTermsBatchByKeys(int $siteId, array $documentKeys): array
    {
        $byDocument = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $data = $this->readFile($this->getTitlePathByKey($siteId, $documentKey));
            if (!empty($data)) {
                $byDocument[$documentKey] = array_values(array_map('strval', $data));
            }
        }

        return $byDocument;
    }

    public function deleteTitleTermsByKey(int $siteId, string $documentKey): void
    {
        $titlePath = $this->getTitlePathByKey($siteId, $documentKey);
        if (file_exists($titlePath)) {
            @unlink($titlePath);
        }
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
        $elementPath = $this->getElementPath($siteId, $elementId);

        // Normalize searchText for prefix matching (lowercase)
        $searchText = TermNormalizer::normalizeSearchText($title);

        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];

        if ($documentData !== null) {
            $data['documentData'] = json_decode($documentData, true);
        }

        $this->writeFile($elementPath, $data);

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
        $elementPath = $this->getElementPath($siteId, $elementId);

        if (file_exists($elementPath)) {
            @unlink($elementPath);
        }
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

        $result = [];

        foreach ($elementIds as $elementId) {
            $elementPath = $this->getElementPath($siteId, (int)$elementId);

            if (file_exists($elementPath)) {
                $data = $this->readFile($elementPath);
                if (!empty($data)) {
                    $result[(int)$elementId] = [
                        'title' => $data['title'] ?? '',
                        'elementType' => $data['elementType'] ?? 'entry',
                        'documentData' => $data['documentData'] ?? null,
                    ];
                }
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
        $this->rememberFilenameKey($documentKey);
        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);

        $searchText = TermNormalizer::normalizeSearchText($title);
        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];

        if ($documentData !== null) {
            $data['documentData'] = json_decode($documentData, true);
        }

        $this->writeFile($this->getDocumentElementPath($siteId, $documentKey), $data);
    }

    public function getElementsByDocumentKeys(int $siteId, array $documentKeys): array
    {
        $result = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $data = $this->readFile($this->getDocumentElementPath($siteId, $documentKey));

            if (empty($data)) {
                $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
                if ($elementId !== null) {
                    $legacy = $this->getElementsByIds($siteId, [$elementId]);
                    if (isset($legacy[$elementId])) {
                        $result[$documentKey] = $legacy[$elementId];
                    }
                }

                continue;
            }

            $result[$documentKey] = [
                'title' => $data['title'] ?? '',
                'elementType' => $data['elementType'] ?? 'entry',
                'documentData' => $data['documentData'] ?? null,
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
        $searchText = TermNormalizer::normalizeSearchText($query);
        $elementsDir = $this->basePath . '/elements';

        if (!is_dir($elementsDir)) {
            return [];
        }

        // Find all element files (siteId_elementId.dat pattern)
        // null siteId = search all sites, otherwise filter by specific site
        $pattern = $elementsDir . '/' . ($siteId !== null ? $siteId : '*') . '_*.dat';
        $files = glob($pattern);

        $results = [];

        foreach ($files as $file) {
            $data = $this->readFile($file);

            if (!$data) {
                continue;
            }

            // Check prefix match
            if (!str_starts_with($data['searchText'] ?? '', $searchText)) {
                continue;
            }

            // Apply type filter if specified
            if ($elementType !== null && ($data['elementType'] ?? '') !== $elementType) {
                continue;
            }

            $results[] = [
                'title' => $data['title'] ?? '',
                'elementType' => $data['elementType'] ?? 'entry',
                'elementId' => $data['elementId'] ?? 0,
                'siteId' => $data['siteId'] ?? $siteId,
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
        $this->rememberFilenameKey($term);

        $ngramDir = $this->basePath . '/ngrams/site' . $siteId;
        if (!is_dir($ngramDir)) {
            @mkdir($ngramDir, 0755, true);
        }

        $ngramPath = $ngramDir . '/' . $this->sanitizeFilename($term) . '.dat';
        $oldNgrams = $this->readFile($ngramPath);
        if (is_array($oldNgrams)) {
            $this->removeTermFromNgramBuckets($term, $oldNgrams, $siteId);
        }

        $this->writeFile($ngramPath, $ngrams);
        $this->addTermToNgramBuckets($term, $ngrams, $siteId);

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
        $ngramDir = $this->basePath . '/ngrams/site' . $siteId;
        $ngramPath = $ngramDir . '/' . $this->sanitizeFilename($term) . '.dat';

        return file_exists($ngramPath);
    }

    /**
     * @inheritdoc
     */
    public function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold, int $limit = 100): array
    {
        if (empty($ngrams)) {
            return [];
        }

        if (!is_dir($this->basePath . '/ngrams-index/site' . $siteId)) {
            return [];
        }

        return $this->getTermsByIndexedNgramSimilarity($ngrams, $siteId, $threshold, $limit);
    }

    /**
     * @inheritdoc
     */
    public function getTermsByPrefix(string $prefix, int $siteId): array
    {
        if (empty($prefix)) {
            return [];
        }

        $termsDir = $this->basePath . '/terms';

        if (!is_dir($termsDir)) {
            return [];
        }

        $matchingTerms = [];
        $files = glob($termsDir . '/*_' . $siteId . '.dat');

        foreach ($files as $file) {
            $term = $this->extractTermFromFilename(basename($file), $siteId);
            if (str_starts_with($term, $prefix)) {
                $matchingTerms[] = $term;
            }
        }

        return $matchingTerms;
    }

    /**
     * @inheritdoc
     */
    public function storeCompoundSuggestions(int $siteId, int $elementId, array $suggestions, string $language = 'en'): void
    {
        $oldRows = $this->readCompoundRows($siteId, $elementId);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        if (empty($suggestions)) {
            @unlink($this->getCompoundPath($siteId, $elementId));
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

        $this->writeFile($this->getCompoundPath($siteId, $elementId), $rows);
        $this->applyCompoundAggregateDelta($siteId, $rows, 1);
    }

    /**
     * @inheritdoc
     */
    public function deleteCompoundSuggestions(int $siteId, int $elementId): void
    {
        $oldRows = $this->readCompoundRows($siteId, $elementId);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        @unlink($this->getCompoundPath($siteId, $elementId));
    }

    public function storeCompoundSuggestionsByKey(int $siteId, int $elementId, string $documentKey, array $suggestions, string $language = 'en'): void
    {
        if ($this->elementIdFromPageDocumentKey($siteId, $documentKey) === $elementId) {
            $this->storeCompoundSuggestions($siteId, $elementId, $suggestions, $language);
            return;
        }

        $oldRows = $this->readCompoundRowsByKey($siteId, $documentKey);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        if (empty($suggestions)) {
            @unlink($this->getCompoundPathByKey($siteId, $documentKey));
            return;
        }

        $this->addDocumentKeyForParent($siteId, $elementId, $documentKey);

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

        $this->writeFile($this->getCompoundPathByKey($siteId, $documentKey), $rows);
        $this->applyCompoundAggregateDelta($siteId, $rows, 1);
    }

    public function deleteCompoundSuggestionsByKey(int $siteId, string $documentKey): void
    {
        $oldRows = $this->readCompoundRowsByKey($siteId, $documentKey);
        if (!empty($oldRows)) {
            $this->applyCompoundAggregateDelta($siteId, $oldRows, -1);
        }

        @unlink($this->getCompoundPathByKey($siteId, $documentKey));
    }

    public function getDocumentKeysByParent(int $siteId, int $elementId): array
    {
        $keys = $this->readFile($this->getParentPath($siteId, $elementId));
        if (is_array($keys) && $keys !== []) {
            return array_values(array_unique(array_map('strval', $keys)));
        }

        $pageDocumentKey = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
        return file_exists($this->getDocPath($siteId, $elementId)) ? [$pageDocumentKey] : [];
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
        $metaPath = $this->getMetaPath($siteId, 'doc_count');
        $value = $this->readFile($metaPath);

        return $value ? (int)$value : 0;
    }

    /**
     * @inheritdoc
     */
    public function getTotalLength(int $siteId): int
    {
        $metaPath = $this->getMetaPath($siteId, 'total_length');
        $value = $this->readFile($metaPath);

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
        $this->updateJsonFile(
            $this->getMetaPath($siteId, 'doc_count'),
            static fn(mixed $current): int => max(0, (int)$current + ($isAddition ? 1 : -1))
        );

        $this->updateJsonFile(
            $this->getMetaPath($siteId, 'total_length'),
            static fn(mixed $current): int => max(1, (int)$current + ($isAddition ? $docLength : -$docLength))
        );
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function clearSite(int $siteId): void
    {
        $compoundFiles = glob($this->basePath . '/compounds/' . $siteId . '_*.dat');
        if (is_array($compoundFiles)) {
            foreach ($compoundFiles as $file) {
                $rows = $this->readFile($file);
                if (is_array($rows)) {
                    $this->applyCompoundAggregateDelta($siteId, array_values(array_filter($rows, 'is_array')), -1);
                }
            }
        }

        // Clear all files for this site
        $patterns = [
            $this->basePath . '/docs/' . $siteId . '_*.dat',
            $this->basePath . '/titles/' . $siteId . '_*.dat',
            $this->basePath . '/meta/' . $siteId . '_*.dat',
            $this->basePath . '/elements/' . $siteId . '_*.dat',
            $this->basePath . '/document-elements/' . $siteId . '_*.dat',
            $this->basePath . '/compounds/' . $siteId . '_*.dat',
            $this->basePath . '/parents/' . $siteId . '_*.dat',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // Clear site-specific n-grams
        $ngramDir = $this->basePath . '/ngrams/site' . $siteId;
        if (is_dir($ngramDir)) {
            $this->deleteDirectory($ngramDir);
        }

        $ngramIndexDir = $this->basePath . '/ngrams-index/site' . $siteId;
        if (is_dir($ngramIndexDir)) {
            $this->deleteDirectory($ngramIndexDir);
        }

        $compoundSiteIndexDir = $this->basePath . '/compounds-index/site' . $siteId;
        if (is_dir($compoundSiteIndexDir)) {
            $this->deleteDirectory($compoundSiteIndexDir);
        }

        // Clear site-specific terms
        $termFiles = glob($this->basePath . '/terms/*_' . $siteId . '.dat');
        foreach ($termFiles as $file) {
            @unlink($file);
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
        if (is_dir($this->basePath)) {
            $this->deleteDirectory($this->basePath);
            $this->ensureDirectoryStructure();
        }

        $this->logInfo('Cleared all data', [
            'index' => $this->indexHandle,
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get document file path
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string File path
     */
    private function getDocPath(int $siteId, int $elementId): string
    {
        return $this->basePath . '/docs/' . $siteId . '_' . $elementId . '.dat';
    }

    private function getDocPathByKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getDocPath($siteId, $elementId);
        }

        return $this->basePath . '/docs/' . $siteId . '_' . $this->sanitizeFilename($documentKey) . '.dat';
    }

    /**
     * Get term file path
     *
     * @param string $term Term
     * @param int $siteId Site ID
     * @return string File path
     */
    private function getTermPath(string $term, int $siteId): string
    {
        $safeTerm = $this->sanitizeFilename($term);
        return $this->basePath . '/terms/' . $safeTerm . '_' . $siteId . '.dat';
    }

    /**
     * Get title file path
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string File path
     */
    private function getTitlePath(int $siteId, int $elementId): string
    {
        return $this->basePath . '/titles/' . $siteId . '_' . $elementId . '.dat';
    }

    private function getTitlePathByKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getTitlePath($siteId, $elementId);
        }

        return $this->basePath . '/titles/' . $siteId . '_' . $this->sanitizeFilename($documentKey) . '.dat';
    }

    /**
     * Get element file path (for autocomplete suggestions)
     *
     * @param int $siteId Site ID
     * @param int $elementId Element ID
     * @return string File path
     */
    private function getElementPath(int $siteId, int $elementId): string
    {
        return $this->basePath . '/elements/' . $siteId . '_' . $elementId . '.dat';
    }

    private function getDocumentElementPath(int $siteId, string $documentKey): string
    {
        return $this->basePath . '/document-elements/' . $siteId . '_' . $this->sanitizeFilename($documentKey) . '.dat';
    }

    private function getCompoundPath(int $siteId, int $elementId): string
    {
        return $this->basePath . '/compounds/' . $siteId . '_' . $elementId . '.dat';
    }

    private function getCompoundPathByKey(int $siteId, string $documentKey): string
    {
        $elementId = $this->elementIdFromPageDocumentKey($siteId, $documentKey);
        if ($elementId !== null) {
            return $this->getCompoundPath($siteId, $elementId);
        }

        return $this->basePath . '/compounds/' . $siteId . '_' . $this->sanitizeFilename($documentKey) . '.dat';
    }

    private function getParentPath(int $siteId, int $elementId): string
    {
        return $this->basePath . '/parents/' . $siteId . '_' . $elementId . '.dat';
    }

    private function getNgramBucketPath(int $siteId, string $ngram): string
    {
        return $this->basePath . '/ngrams-index/site' . $siteId . '/' . $this->sanitizeFilename($ngram) . '.dat';
    }

    private function getCompoundBucketPath(string $scope, string $language, string $normalizedSuggestion): string
    {
        $shard = $this->compoundShard($normalizedSuggestion);

        return $this->basePath . '/compounds-index/' . $scope . '/' . $this->sanitizeFilename($language) . '/' . $shard . '.dat';
    }

    private function getCompoundLookupBucketPath(string $scope, string $language, string $normalizedPrefix): string
    {
        $shard = $this->compoundShard($normalizedPrefix);

        return $this->basePath . '/compounds-index/' . $scope . '/' . $this->sanitizeFilename($language) . '/' . $shard . '.dat';
    }

    /**
     * Get metadata file path
     *
     * @param int $siteId Site ID
     * @param string $key Metadata key
     * @return string File path
     */
    private function getMetaPath(int $siteId, string $key): string
    {
        return $this->basePath . '/meta/' . $siteId . '_' . $key . '.dat';
    }

    /**
     * Read data from file
     *
     * Uses JSON for safe deserialization (no object injection risk).
     *
     * @param string $path File path
     * @return mixed Decoded data or null
     */
    private function readFile(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }

            $contents = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($contents === false || $contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        // Return null on JSON decode failure
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Write data to file
     *
     * Uses JSON for safe serialization.
     *
     * @param string $path File path
     * @param mixed $data Data to encode
     * @return bool Success
     */
    private function writeFile(string $path, $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($path, $json, LOCK_EX);

        return $result !== false;
    }

    private function rememberFilenameKey(string $filename): void
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === $filename) {
            return;
        }

        $this->writeFile($this->basePath . '/keys/' . $safe . '.dat', [
            'value' => $filename,
        ]);
    }

    private function addDocumentKeyForParent(int $siteId, int $elementId, string $documentKey): void
    {
        $this->rememberFilenameKey($documentKey);
        $path = $this->getParentPath($siteId, $elementId);

        $this->updateJsonFile(
            $path,
            static function(mixed $current) use ($documentKey): array {
                $keys = is_array($current) ? array_values(array_map('strval', $current)) : [];
                $keys[] = $documentKey;

                return array_values(array_unique($keys));
            },
        );
    }

    private function removeDocumentKeyForParent(int $siteId, int $elementId, string $documentKey): void
    {
        $path = $this->getParentPath($siteId, $elementId);

        $this->updateJsonFile(
            $path,
            static function(mixed $current) use ($documentKey): array {
                if (!is_array($current)) {
                    return [];
                }

                return array_values(array_filter(
                    array_map('strval', $current),
                    static fn(string $key): bool => $key !== $documentKey,
                ));
            },
        );
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

    /**
     * Update a JSON file while holding an exclusive lock across read/modify/write.
     *
     * @param callable(mixed): mixed $update
     */
    private function updateJsonFile(string $path, callable $update): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $contents = stream_get_contents($handle);
            $current = null;
            if (is_string($contents) && $contents !== '') {
                $decoded = json_decode($contents, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $current = $decoded;
                }
            }

            $json = json_encode($update($current), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }

            rewind($handle);
            ftruncate($handle, 0);
            return fwrite($handle, $json) !== false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Encode a storage key as a safe deterministic filename segment.
     *
     * Simple ASCII keys keep their historical path for compatibility. Any key
     * that needs escaping uses a reserved reversible UTF-8 base64url form, or
     * a hashed sidecar entry for very long keys, so file scans can recover the
     * exact original term without transliteration.
     *
     * @param string $filename Filename
     * @return string Sanitized filename
     */
    private function sanitizeFilename(string $filename): string
    {
        if (
            preg_match('/\A[A-Za-z0-9_-]+\z/', $filename) === 1
            && !str_starts_with($filename, self::ENCODED_FILENAME_PREFIX)
            && strlen($filename) <= self::MAX_FILENAME_SEGMENT_LENGTH
        ) {
            return $filename;
        }

        $encoded = self::ENCODED_FILENAME_PREFIX . rtrim(strtr(base64_encode($filename), '+/', '-_'), '=');
        if (strlen($encoded) <= self::MAX_FILENAME_SEGMENT_LENGTH) {
            return $encoded;
        }

        return self::HASHED_FILENAME_PREFIX . hash('sha256', $filename);
    }

    /**
     * Extract term from sanitized filename.
     *
     * Simple ASCII filenames are already the persisted searchable term. Encoded
     * filenames use the reversible UTF-8 base64url form from
     * {@see sanitizeFilename()}, with sidecar metadata for very long hashed
     * keys. Preserve literal underscores so underscore-containing terms
     * round-trip deterministically. For term-document files, strip the site
     * suffix added by {@see getTermPath()} before decoding. All-sites term
     * scans can explicitly request trailing numeric site suffix removal; n-gram
     * scans must not, because encoded filename segments can contain underscores.
     *
     * @param string $filename Filename (e.g., "term_name.dat" or "term_name_1.dat")
     * @param int|null $siteId Site ID suffix to remove for term-document files.
     * @param bool $stripNumericSiteSuffix Whether to strip a trailing _{siteId}
     *     suffix when scanning term files across all sites.
     * @return string Persisted term
     */
    private function extractTermFromFilename(string $filename, ?int $siteId = null, bool $stripNumericSiteSuffix = false): string
    {
        // Remove .dat extension
        $term = str_replace('.dat', '', $filename);

        if ($siteId !== null) {
            $suffix = '_' . $siteId;
            if (str_ends_with($term, $suffix)) {
                $term = substr($term, 0, -strlen($suffix));
            }
        } elseif ($stripNumericSiteSuffix && preg_match('/^(.*)_\d+$/', $term, $matches) === 1) {
            $term = $matches[1];
        }

        if (str_starts_with($term, self::HASHED_FILENAME_PREFIX)) {
            $metadata = $this->readFile($this->basePath . '/keys/' . $term . '.dat');
            if (is_array($metadata) && isset($metadata['value']) && is_string($metadata['value'])) {
                return $metadata['value'];
            }

            return $term;
        }

        if (str_starts_with($term, self::ENCODED_FILENAME_PREFIX)) {
            $encoded = substr($term, strlen(self::ENCODED_FILENAME_PREFIX));
            if ($encoded !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) === 1) {
                $padding = str_repeat('=', (4 - strlen($encoded) % 4) % 4);
                $decoded = base64_decode(strtr($encoded . $padding, '-_', '+/'), true);
                if (is_string($decoded)) {
                    return $decoded;
                }
            }
        }

        return $term;
    }

    private function getTermsByIndexedNgramSimilarity(array $ngrams, int $siteId, float $threshold, int $limit): array
    {
        $searchNgramCount = count($ngrams);
        $candidateIntersections = [];
        $candidateCounts = [];

        foreach (array_values(array_unique($ngrams)) as $ngram) {
            $bucket = $this->readFile($this->getNgramBucketPath($siteId, (string)$ngram));
            if (!is_array($bucket)) {
                continue;
            }

            foreach ($bucket as $term => $ngramCount) {
                $term = (string)$term;
                $candidateIntersections[$term] = ($candidateIntersections[$term] ?? 0) + 1;
                $candidateCounts[$term] = (int)$ngramCount;
            }
        }

        $similarities = [];
        foreach ($candidateIntersections as $term => $intersection) {
            $termNgramCount = $candidateCounts[$term] ?? 0;
            if ($termNgramCount <= 0) {
                continue;
            }

            $union = $searchNgramCount + $termNgramCount - $intersection;
            $similarity = $union > 0 ? $intersection / $union : 0.0;
            if ($similarity >= $threshold) {
                $similarities[$term] = $similarity;
            }
        }

        arsort($similarities);

        return array_slice($similarities, 0, $limit, true);
    }

    private function addTermToNgramBuckets(string $term, array $ngrams, int $siteId): void
    {
        $ngramCount = count($ngrams);
        foreach (array_values(array_unique($ngrams)) as $ngram) {
            $this->updateJsonFile(
                $this->getNgramBucketPath($siteId, (string)$ngram),
                static function(mixed $current) use ($term, $ngramCount): array {
                    $bucket = is_array($current) ? $current : [];
                    $bucket[$term] = $ngramCount;

                    return $bucket;
                },
            );
        }
    }

    private function removeTermFromNgramBuckets(string $term, array $ngrams, int $siteId): void
    {
        foreach (array_values(array_unique($ngrams)) as $ngram) {
            $path = $this->getNgramBucketPath($siteId, (string)$ngram);
            $this->updateJsonFile(
                $path,
                static function(mixed $current) use ($term): array {
                    $bucket = is_array($current) ? $current : [];
                    unset($bucket[$term]);

                    return $bucket;
                },
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCompoundRows(int $siteId, int $elementId): array
    {
        $rows = $this->readFile($this->getCompoundPath($siteId, $elementId));

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readCompoundRowsByKey(int $siteId, string $documentKey): array
    {
        $rows = $this->readFile($this->getCompoundPathByKey($siteId, $documentKey));

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
                $this->updateCompoundAggregateBucket($scope, $language, $normalizedSuggestion, $suggestion, $frequency);
            }
        }
    }

    private function updateCompoundAggregateBucket(
        string $scope,
        string $language,
        string $normalizedSuggestion,
        string $suggestion,
        int $frequencyDelta,
    ): void {
        $path = $this->getCompoundBucketPath($scope, $language, $normalizedSuggestion);
        $this->updateJsonFile(
            $path,
            static function(mixed $current) use ($normalizedSuggestion, $suggestion, $frequencyDelta): array {
                $bucket = is_array($current) ? $current : [];
                $entry = is_array($bucket[$normalizedSuggestion] ?? null) ? $bucket[$normalizedSuggestion] : [];
                $displayFrequencies = is_array($entry['displayFrequencies'] ?? null) ? $entry['displayFrequencies'] : [];

                $displayFrequencies[$suggestion] = (int)($displayFrequencies[$suggestion] ?? 0) + $frequencyDelta;
                if ($displayFrequencies[$suggestion] <= 0) {
                    unset($displayFrequencies[$suggestion]);
                }

                $totalFrequency = array_sum(array_map('intval', $displayFrequencies));
                if ($totalFrequency <= 0 || empty($displayFrequencies)) {
                    unset($bucket[$normalizedSuggestion]);

                    return $bucket;
                }

                $bucket[$normalizedSuggestion] = [
                    'totalFrequency' => $totalFrequency,
                    'displayFrequencies' => $displayFrequencies,
                ];

                return $bucket;
            },
        );
    }

    private function getIndexedCompoundSuggestionsForAutocomplete(
        string $normalizedPrefix,
        ?int $siteId,
        ?string $language,
        int $limit,
    ): array {
        $scope = $siteId !== null ? 'site' . $siteId : 'all';
        if (!is_dir($this->basePath . '/compounds-index/' . $scope)) {
            return [];
        }

        $languages = $language !== null ? [$language] : $this->getCompoundIndexedLanguages($siteId);
        if (empty($languages)) {
            return [];
        }

        $suggestionsByNormalized = [];

        foreach ($languages as $lang) {
            $bucket = $this->readFile($this->getCompoundLookupBucketPath($scope, (string)$lang, $normalizedPrefix));
            if (!is_array($bucket)) {
                continue;
            }

            foreach ($bucket as $normalizedSuggestion => $data) {
                $normalizedSuggestion = (string)$normalizedSuggestion;
                if (!str_starts_with($normalizedSuggestion, $normalizedPrefix) || !is_array($data)) {
                    continue;
                }

                $displayFrequencies = is_array($data['displayFrequencies'] ?? null) ? $data['displayFrequencies'] : [];
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

    /**
     * @return array<int, string>
     */
    private function getCompoundIndexedLanguages(?int $siteId): array
    {
        $scope = $siteId !== null ? 'site' . $siteId : 'all';
        $dir = $this->basePath . '/compounds-index/' . $scope;
        if (!is_dir($dir)) {
            return [];
        }

        $languages = [];
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $languageDir) {
            if (is_dir($dir . '/' . $languageDir)) {
                $languages[] = $this->extractTermFromFilename($languageDir);
            }
        }

        return $languages;
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
        return $this->sanitizeFilename(mb_substr($normalizedSuggestion, 0, 1) ?: '_');
    }

    /**
     * Recursively delete directory
     *
     * @param string $dir Directory path
     * @return void
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
