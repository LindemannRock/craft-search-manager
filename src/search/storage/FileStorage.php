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
 * - term-languages/ - Term posting languages (term -> document languages)
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
    private const MANIFEST_FILENAME = 'manifest.json';
    private const MANIFEST_FORMAT = 'search-manager-file-index-manifest';
    private const MANIFEST_READINESS_READY = 'ready';
    private const MANIFEST_READINESS_UPDATING = 'updating';
    private const MANIFEST_VERSION = 1;
    private const MAX_FILENAME_SEGMENT_LENGTH = 200;

    /**
     * @var array<string, true> Manifest failures already logged in this request.
     */
    private static array $loggedManifestFailures = [];

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

        $hasPersistedData = $this->hasPersistedIndexData();
        $hasManifestLock = is_file($this->getManifestLockPath());

        // Create directory structure
        $this->ensureDirectoryStructure();
        $this->initializeManifestForNewIndex($hasPersistedData || $hasManifestLock);

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
            $this->basePath . '/term-languages',
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

    /** @inheritdoc */
    public function getDistinctParentCount(int $siteId): int
    {
        $manifest = $this->readManifest('counting distinct File index parents');
        if ($manifest === null) {
            return 0;
        }

        $parentIds = [];
        foreach ($manifest['documents'] as $document) {
            if (is_array($document) && (int)($document['siteId'] ?? 0) === $siteId) {
                $parentIds[(int)($document['elementId'] ?? 0)] = true;
            }
        }

        unset($parentIds[0]);

        return count($parentIds);
    }

    /**
     * @inheritdoc
     */
    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void
    {
        $docPath = $this->getDocPath($siteId, $elementId);
        $documentKey = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
        $manifestId = $this->manifestDocumentId($siteId, $documentKey);

        // Add _length and _language to the data
        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;

        $this->mutateStorageAndManifest(
            'storing File document metadata',
            static function(array $manifest) use ($manifestId, $siteId, $elementId, $documentKey, $docLength, $language): array {
                $manifest['documents'][$manifestId] = [
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                    'documentKey' => $documentKey,
                    'length' => $docLength,
                    'language' => $language,
                ];

                return $manifest;
            },
            function() use ($docPath, $data): void {
                $this->writeFileOrFail($docPath, $data);
            },
        );

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
        $manifest = $this->readManifest('reading File document language', true);
        $documentKey = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
        $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;

        return is_array($data) ? (string)($data['language'] ?? 'en') : 'en';
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLanguagesBatch(int $siteId, array $elementIds): array
    {
        $manifest = $this->readManifest('reading File document languages', true);
        $byElement = [];

        foreach (array_values(array_unique(array_map('intval', $elementIds))) as $elementId) {
            $documentKey = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
            $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;
            $byElement[$elementId] = is_array($data) ? (string)($data['language'] ?? 'en') : 'en';
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
        $documentKeys = [];
        $this->mutateStorageAndManifest(
            'deleting File documents for an element',
            static function(array $manifest) use ($siteId, $elementId, &$documentKeys): array {
                foreach ($manifest['documents'] as $manifestId => $document) {
                    if (
                        is_array($document)
                        && (int)($document['siteId'] ?? 0) === $siteId
                        && (int)($document['elementId'] ?? 0) === $elementId
                    ) {
                        $documentKey = (string)($document['documentKey'] ?? '');
                        if ($documentKey !== '') {
                            $documentKeys[] = $documentKey;
                        }
                        unset($manifest['documents'][$manifestId]);
                    }
                }

                unset($manifest['elements'][$siteId . '_' . $elementId]);

                return $manifest;
            },
            function() use ($siteId, $elementId, &$documentKeys): void {
                if ($documentKeys === []) {
                    $documentKeys[] = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
                }

                foreach (array_values(array_unique($documentKeys)) as $documentKey) {
                    $this->deleteDocumentPhysicalByKeyOrFail($siteId, $elementId, $documentKey);
                }

                $this->deleteFileOrFail($this->getElementPath($siteId, $elementId));
            },
        );

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
        $manifest = $this->readManifest('reading File document length', true);
        $documentKey = SearchHitIdentityHelper::pageDocumentId($elementId, $siteId);
        $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;

        return is_array($data) ? (int)($data['length'] ?? 0) : 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatch(array $docIds): array
    {
        $manifest = $this->readManifest('reading File document lengths', true);
        $lengths = [];

        foreach ($docIds as $siteId => $elementIds) {
            foreach ($elementIds as $elementId) {
                $documentKey = SearchHitIdentityHelper::pageDocumentId((int)$elementId, (int)$siteId);
                $data = $manifest['documents'][$this->manifestDocumentId((int)$siteId, $documentKey)] ?? null;

                if (is_array($data) && isset($data['length'])) {
                    $docId = $siteId . ':' . $elementId;
                    $lengths[$docId] = (int)$data['length'];
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

        $data = $termFreqs;
        $data['_length'] = $docLength;
        $data['_language'] = $language;
        $data['_elementId'] = $elementId;
        $data['_documentKey'] = $documentKey;
        $manifestId = $this->manifestDocumentId($siteId, $documentKey);

        $this->mutateStorageAndManifest(
            'storing File document metadata',
            static function(array $manifest) use ($manifestId, $siteId, $elementId, $documentKey, $docLength, $language): array {
                $manifest['documents'][$manifestId] = [
                    'siteId' => $siteId,
                    'elementId' => $elementId,
                    'documentKey' => $documentKey,
                    'length' => $docLength,
                    'language' => $language,
                ];

                return $manifest;
            },
            function() use ($siteId, $elementId, $documentKey, $data): void {
                $this->addDocumentKeyForParentOrFail($siteId, $elementId, $documentKey);
                $this->writeFileOrFail($this->getDocPathByKey($siteId, $documentKey), $data);
            },
        );
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
        $manifestId = $this->manifestDocumentId($siteId, $documentKey);
        $elementId = null;
        $removeElement = false;
        $this->mutateStorageAndManifest(
            'deleting a File document',
            function(array $manifest) use ($manifestId, $siteId, $documentKey, &$elementId, &$removeElement): array {
                $document = $manifest['documents'][$manifestId] ?? null;
                $elementId = is_array($document)
                    ? (int)($document['elementId'] ?? 0)
                    : $this->elementIdFromDocumentKey($documentKey);
                unset($manifest['documents'][$manifestId]);

                if ($elementId !== null && $elementId !== 0) {
                    $hasSibling = false;
                    foreach ($manifest['documents'] as $candidate) {
                        if (
                            is_array($candidate)
                            && (int)($candidate['siteId'] ?? 0) === $siteId
                            && (int)($candidate['elementId'] ?? 0) === $elementId
                        ) {
                            $hasSibling = true;
                            break;
                        }
                    }

                    if (!$hasSibling) {
                        unset($manifest['elements'][$siteId . '_' . $elementId]);
                        $removeElement = true;
                    }
                }

                return $manifest;
            },
            function() use ($siteId, $documentKey, &$elementId, &$removeElement): void {
                $this->deleteDocumentPhysicalByKeyOrFail($siteId, $elementId, $documentKey);
                if ($removeElement && $elementId !== null && $elementId !== 0) {
                    $this->deleteFileOrFail($this->getElementPath($siteId, $elementId));
                }
            },
        );
    }

    public function getDocumentLengthByKey(int $siteId, string $documentKey): int
    {
        $manifest = $this->readManifest('reading File document length', true);
        $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;

        return is_array($data) ? (int)($data['length'] ?? 0) : 0;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentLengthsBatchByKeys(int $siteId, array $documentKeys): array
    {
        $manifest = $this->readManifest('reading File document lengths', true);
        $byDocument = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;
            $byDocument[$documentKey] = is_array($data) ? (int)($data['length'] ?? 0) : 0;
        }

        return $byDocument;
    }

    public function getDocumentLanguagesBatchByKeys(int $siteId, array $documentKeys): array
    {
        $manifest = $this->readManifest('reading File document languages', true);
        $byDocument = [];

        foreach (array_values(array_unique(array_map('strval', $documentKeys))) as $documentKey) {
            $data = $manifest['documents'][$this->manifestDocumentId($siteId, $documentKey)] ?? null;
            $byDocument[$documentKey] = is_array($data) ? (string)($data['language'] ?? 'en') : 'en';
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
        $termLanguagePath = $this->getTermLanguagePath($term, $siteId);

        $docId = $siteId . ':' . $elementId;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId, $frequency): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $frequency;

                return $data;
            },
        );
        $this->updateJsonFile(
            $termLanguagePath,
            static function(mixed $current) use ($docId, $language): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $language;

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
        $termLanguagePath = $this->getTermLanguagePath($term, $siteId);
        $docId = $siteId . ':' . $elementId;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId): array {
                $data = is_array($current) ? $current : [];
                unset($data[$docId]);

                return $data;
            },
        );
        $this->updateJsonFile(
            $termLanguagePath,
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
        $termLanguagePath = $this->getTermLanguagePath($term, $siteId);
        $docId = $siteId . ':' . $documentKey;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId, $frequency): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $frequency;

                return $data;
            },
        );
        $this->updateJsonFile(
            $termLanguagePath,
            static function(mixed $current) use ($docId, $language): array {
                $data = is_array($current) ? $current : [];
                $data[$docId] = $language;

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
        $termLanguagePath = $this->getTermLanguagePath($term, $siteId);
        $docId = $siteId . ':' . $documentKey;

        $this->updateJsonFile(
            $termPath,
            static function(mixed $current) use ($docId): array {
                $data = is_array($current) ? $current : [];
                unset($data[$docId]);

                return $data;
            },
        );
        $this->updateJsonFile(
            $termLanguagePath,
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

        $files = $this->termFilesForPrefix($prefix, $siteId);

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
            if ($language !== null && is_array($data)) {
                $termSiteId = $siteId ?? $this->extractSiteIdFromTermFilename(basename($file));
                if ($termSiteId === null) {
                    continue;
                }

                $postingLanguages = $this->readFile($this->getTermLanguagePath($term, $termSiteId));
                $data = array_intersect_key(
                    $data,
                    array_filter(
                        is_array($postingLanguages) ? $postingLanguages : [],
                        static fn(mixed $postingLanguage): bool => $postingLanguage === $language,
                    ),
                );
            }
            $frequency = is_array($data) ? array_sum(array_map('intval', $data)) : 0;

            if ($frequency > 0) {
                // Aggregate frequencies for all-sites
                if (isset($terms[$term])) {
                    $terms[$term] += $frequency;
                } else {
                    $terms[$term] = $frequency;
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

        $this->mutateStorageAndManifest(
            'storing File element suggestion data',
            static function(array $manifest) use ($siteId, $elementId, $title, $elementType, $searchText): array {
                $manifest['elements'][$siteId . '_' . $elementId] = [
                    'title' => $title,
                    'elementType' => $elementType,
                    'searchText' => $searchText,
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                ];

                return $manifest;
            },
            function() use ($elementPath, $data): void {
                $this->writeFileOrFail($elementPath, $data);
            },
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
        $elementPath = $this->getElementPath($siteId, $elementId);

        $this->mutateStorageAndManifest(
            'deleting File element suggestion data',
            static function(array $manifest) use ($siteId, $elementId): array {
                unset($manifest['elements'][$siteId . '_' . $elementId]);

                return $manifest;
            },
            function() use ($elementPath): void {
                $this->deleteFileOrFail($elementPath);
            },
        );
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

        $searchText = TermNormalizer::normalizeSearchText($title);
        $elementData = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];
        $documentElementData = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'documentKey' => $documentKey,
        ];

        if ($documentData !== null) {
            $decodedDocumentData = json_decode($documentData, true);
            $elementData['documentData'] = $decodedDocumentData;
            $documentElementData['documentData'] = $decodedDocumentData;
        }

        $this->mutateStorageAndManifest(
            'storing split File element suggestion data',
            static function(array $manifest) use ($siteId, $elementId, $title, $elementType, $searchText): array {
                $manifest['elements'][$siteId . '_' . $elementId] = [
                    'title' => $title,
                    'elementType' => $elementType,
                    'searchText' => $searchText,
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                ];

                return $manifest;
            },
            function() use ($siteId, $elementId, $documentKey, $elementData, $documentElementData): void {
                $this->writeFileOrFail($this->getElementPath($siteId, $elementId), $elementData);
                $this->addDocumentKeyForParentOrFail($siteId, $elementId, $documentKey);
                $this->writeFileOrFail(
                    $this->getDocumentElementPath($siteId, $documentKey),
                    $documentElementData,
                );
            },
        );
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
        $manifest = $this->readManifest('reading File element suggestions');
        if ($manifest === null) {
            return [];
        }

        $elements = $manifest['elements'];
        ksort($elements, SORT_STRING);
        $results = [];

        foreach ($elements as $data) {
            if (!is_array($data)) {
                continue;
            }

            $candidateSiteId = (int)($data['siteId'] ?? 0);
            if ($siteId !== null && $candidateSiteId !== $siteId) {
                continue;
            }

            if (!str_starts_with((string)($data['searchText'] ?? ''), $searchText)) {
                continue;
            }

            if ($elementType !== null && (string)($data['elementType'] ?? '') !== $elementType) {
                continue;
            }

            $results[] = [
                'title' => (string)($data['title'] ?? ''),
                'elementType' => (string)($data['elementType'] ?? 'entry'),
                'elementId' => (int)($data['elementId'] ?? 0),
                'siteId' => $candidateSiteId,
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
     *
     * The stored per-term ngram file is authoritative for bucket removal;
     * the passed $ngrams only fill in when the file is unreadable.
     */
    public function removeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
        $ngramPath = $this->basePath . '/ngrams/site' . $siteId . '/' . $this->sanitizeFilename($term) . '.dat';

        $storedNgrams = file_exists($ngramPath) ? $this->readFile($ngramPath) : null;
        $this->removeTermFromNgramBuckets($term, is_array($storedNgrams) ? $storedNgrams : $ngrams, $siteId);

        if (file_exists($ngramPath)) {
            @unlink($ngramPath);
        }
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
        if ($prefix === '') {
            return [];
        }

        $termsDir = $this->basePath . '/terms';

        if (!is_dir($termsDir)) {
            return [];
        }

        $matchingTerms = [];
        $files = $this->termFilesForPrefix($prefix, $siteId);

        foreach ($files as $file) {
            $term = $this->extractTermFromFilename(basename($file), $siteId);
            if (str_starts_with($term, $prefix)) {
                $matchingTerms[] = $term;
            }
        }

        return $matchingTerms;
    }

    /**
     * Narrow ordinary term filenames by prefix while retaining encoded and
     * hashed filename families so prefix lookup semantics stay unchanged.
     *
     * @return list<string>
     */
    private function termFilesForPrefix(?string $prefix, ?int $siteId): array
    {
        $termsPath = $this->basePath . '/terms';
        $siteSuffix = $siteId !== null ? '_' . $siteId : '_*';
        $patterns = [];

        if ($prefix !== null && $prefix !== '') {
            if (preg_match('/\A[A-Za-z0-9_-]+\z/', $prefix) === 1) {
                $patterns[] = $termsPath . '/' . $prefix . '*' . $siteSuffix . '.dat';
            }

            // Encoded and long hashed terms do not preserve arbitrary textual
            // prefixes in their filenames, so scan only those reserved families
            // and apply the exact decoded prefix check at the caller.
            $patterns[] = $termsPath . '/' . self::ENCODED_FILENAME_PREFIX . '*' . $siteSuffix . '.dat';
            $patterns[] = $termsPath . '/' . self::HASHED_FILENAME_PREFIX . '*' . $siteSuffix . '.dat';
        } else {
            $patterns[] = $termsPath . '/*' . $siteSuffix . '.dat';
        }

        $files = [];
        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if (is_array($matches)) {
                $files = array_merge($files, $matches);
            }
        }

        return array_values(array_unique($files));
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
        $manifest = $this->readManifest('reading File document keys', true);
        $keys = [];

        foreach ($manifest['documents'] as $document) {
            if (
                is_array($document)
                && (int)($document['siteId'] ?? 0) === $siteId
                && (int)($document['elementId'] ?? 0) === $elementId
            ) {
                $keys[] = (string)($document['documentKey'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($keys, static fn(string $key): bool => $key !== '')));
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
        $this->mutateStorageAndManifest(
            'clearing a File index site',
            static function(array $manifest) use ($siteId): array {
                $manifest['elements'] = array_filter(
                    $manifest['elements'],
                    static fn(mixed $element): bool => !is_array($element) || (int)($element['siteId'] ?? 0) !== $siteId,
                );
                $manifest['documents'] = array_filter(
                    $manifest['documents'],
                    static fn(mixed $document): bool => !is_array($document) || (int)($document['siteId'] ?? 0) !== $siteId,
                );

                return $manifest;
            },
            function() use ($siteId): void {
                $compoundFiles = $this->globFilesOrFail($this->basePath . '/compounds/' . $siteId . '_*.dat');
                foreach ($compoundFiles as $file) {
                    $rows = $this->readFile($file);
                    if (
                        is_array($rows)
                        && !$this->applyCompoundAggregateDelta(
                            $siteId,
                            array_values(array_filter($rows, 'is_array')),
                            -1,
                        )
                    ) {
                        throw new \RuntimeException('Unable to update File compound aggregates while clearing a site.');
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
                    foreach ($this->globFilesOrFail($pattern) as $file) {
                        $this->deleteFileOrFail($file);
                    }
                }

                // Clear site-specific n-grams
                foreach ([
                    $this->basePath . '/ngrams/site' . $siteId,
                    $this->basePath . '/ngrams-index/site' . $siteId,
                    $this->basePath . '/compounds-index/site' . $siteId,
                ] as $directory) {
                    $this->deleteDirectoryOrFail($directory);
                }

                // Clear site-specific terms
                foreach ([
                    $this->basePath . '/terms/*_' . $siteId . '.dat',
                    $this->basePath . '/term-languages/*_' . $siteId . '.dat',
                ] as $pattern) {
                    foreach ($this->globFilesOrFail($pattern) as $file) {
                        $this->deleteFileOrFail($file);
                    }
                }
            },
        );

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
        $this->withManifestLock(LOCK_EX, function(): void {
            $inProgress = $this->emptyManifest();
            $inProgress['readiness'] = self::MANIFEST_READINESS_UPDATING;
            $this->writeManifestUnlocked($inProgress);

            if (is_dir($this->basePath)) {
                $this->deleteDirectoryOrFail($this->basePath);
            }

            $this->ensureDirectoryStructure();
            $this->writeManifestUnlocked($this->emptyManifest());
        });

        $this->logInfo('Cleared all data', [
            'index' => $this->indexHandle,
        ]);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * @return array{
     *     format: string,
     *     version: int,
     *     readiness: string,
     *     elements: array<string, array<string, mixed>>,
     *     documents: array<string, array<string, mixed>>
     * }
     */
    private function emptyManifest(): array
    {
        return [
            'format' => self::MANIFEST_FORMAT,
            'version' => self::MANIFEST_VERSION,
            'readiness' => self::MANIFEST_READINESS_READY,
            'elements' => [],
            'documents' => [],
        ];
    }

    private function initializeManifestForNewIndex(bool $hasExistingState): void
    {
        if ($hasExistingState || is_file($this->getManifestPath())) {
            return;
        }

        $this->withManifestLock(LOCK_EX, function(): void {
            if (is_file($this->getManifestPath()) || $this->hasPersistedIndexData()) {
                return;
            }

            $this->writeManifestUnlocked($this->emptyManifest());
        });
    }

    private function hasPersistedIndexData(): bool
    {
        if (!is_dir($this->basePath)) {
            return false;
        }

        $entries = scandir($this->basePath);
        if (!is_array($entries)) {
            return false;
        }

        foreach (array_diff($entries, ['.', '..', self::MANIFEST_FILENAME]) as $entry) {
            $path = $this->basePath . '/' . $entry;
            if (is_file($path)) {
                return true;
            }

            if (!is_dir($path)) {
                continue;
            }

            $children = scandir($path);
            if (!is_array($children)) {
                continue;
            }

            foreach (array_diff($children, ['.', '..']) as $child) {
                $childPath = $path . '/' . $child;
                if (is_file($childPath)) {
                    return true;
                }

                if (is_dir($childPath) && $this->directoryContainsFile($childPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function directoryContainsFile(string $directory): bool
    {
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return false;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_file($path) || (is_dir($path) && $this->directoryContainsFile($path))) {
                return true;
            }
        }

        return false;
    }

    private function manifestDocumentId(int $siteId, string $documentKey): string
    {
        return $siteId . ':' . $documentKey;
    }

    private function getManifestPath(): string
    {
        return $this->basePath . '/' . self::MANIFEST_FILENAME;
    }

    private function getManifestLockPath(): string
    {
        return $this->basePath . '.manifest.lock';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $operation, bool $throwOnFailure = false): ?array
    {
        return $this->withManifestLock(
            LOCK_SH,
            function() use ($operation, $throwOnFailure): ?array {
                [$manifest, $reason] = $this->loadManifestUnlocked();
                if ($manifest !== null) {
                    return $manifest;
                }

                return $this->handleManifestFailure((string)$reason, $operation, $throwOnFailure);
            },
        );
    }

    /**
     * Run a physical File mutation and its authoritative manifest update as one
     * fail-closed transaction.
     *
     * Lock ordering is always manifest lock, then individual physical-file
     * locks. Physical helpers never acquire the manifest lock, so this method
     * cannot recursively acquire the fixed manifest lock or form a lock cycle.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $prepareManifest
     * @param callable(): void $mutatePhysicalStorage
     */
    private function mutateStorageAndManifest(
        string $operation,
        callable $prepareManifest,
        callable $mutatePhysicalStorage,
    ): void {
        $this->withManifestLock(
            LOCK_EX,
            function() use ($operation, $prepareManifest, $mutatePhysicalStorage): void {
                [$manifest, $reason] = $this->loadManifestUnlocked();
                if ($manifest === null) {
                    $this->handleManifestFailure((string)$reason, $operation, true);
                    return;
                }

                $finalManifest = $prepareManifest($manifest);
                if ($this->manifestInvalidReason($finalManifest) !== null) {
                    throw new \RuntimeException('The File index manifest update produced an invalid manifest.');
                }

                $inProgressManifest = $manifest;
                $inProgressManifest['readiness'] = self::MANIFEST_READINESS_UPDATING;
                $this->writeManifestUnlocked($inProgressManifest);

                try {
                    $mutatePhysicalStorage();
                    $this->writeManifestUnlocked($finalManifest);
                } catch (\Throwable $e) {
                    throw new \RuntimeException(sprintf(
                        'File index mutation failed while %s. The manifest remains incomplete. Rebuild the File index "%s" before using it.',
                        $operation,
                        $this->indexHandle,
                    ), 0, $e);
                }
            },
        );
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function loadManifestUnlocked(): array
    {
        $path = $this->getManifestPath();
        if (!is_file($path)) {
            return [
                null,
                $this->hasPersistedIndexData()
                    ? 'legacy populated index has no manifest'
                    : 'manifest is missing',
            ];
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [null, 'manifest is corrupt'];
        }

        $manifest = json_decode($contents, true);
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            return [null, 'manifest is corrupt'];
        }

        $reason = $this->manifestInvalidReason($manifest);

        return $reason === null ? [$manifest, null] : [null, $reason];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifestInvalidReason(array $manifest): ?string
    {
        if (($manifest['format'] ?? null) !== self::MANIFEST_FORMAT) {
            return 'manifest format is unsupported';
        }

        if (($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
            return 'manifest version is unsupported';
        }

        if (
            ($manifest['readiness'] ?? null) !== self::MANIFEST_READINESS_READY
            || !is_array($manifest['elements'] ?? null)
            || !is_array($manifest['documents'] ?? null)
        ) {
            return 'manifest is incomplete';
        }

        return null;
    }

    private function handleManifestFailure(string $reason, string $operation, bool $throwOnFailure): null
    {
        $message = sprintf(
            'File index manifest unavailable while %s: %s. Rebuild the File index "%s" before using it.',
            $operation,
            $reason,
            $this->indexHandle,
        );
        $logKey = $this->getManifestPath() . ':' . $reason;
        if (!isset(self::$loggedManifestFailures[$logKey])) {
            self::$loggedManifestFailures[$logKey] = true;
            $this->logError($message, [
                'index' => $this->indexHandle,
                'path' => $this->getManifestPath(),
                'reason' => $reason,
            ]);
        }

        if ($throwOnFailure) {
            throw new \RuntimeException($message);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifestUnlocked(array $manifest): void
    {
        try {
            $json = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $temporaryPath = $this->getManifestPath() . '.tmp.' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Unable to encode the File index manifest.', 0, $e);
        }

        $written = @file_put_contents($temporaryPath, $json, LOCK_EX);
        if ($written !== strlen($json)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to write the File index manifest temporary file.');
        }

        if (!@rename($temporaryPath, $this->getManifestPath())) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to replace the File index manifest atomically.');
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withManifestLock(int $operation, callable $callback): mixed
    {
        $lockPath = $this->getManifestLockPath();
        $lockDirectory = dirname($lockPath);
        if (!is_dir($lockDirectory)) {
            @mkdir($lockDirectory, 0755, true);
        }

        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the File index manifest lock.');
        }

        try {
            if (!flock($handle, $operation)) {
                throw new \RuntimeException('Unable to acquire the File index manifest lock.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

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

    private function getTermLanguagePath(string $term, int $siteId): string
    {
        $safeTerm = $this->sanitizeFilename($term);
        return $this->basePath . '/term-languages/' . $safeTerm . '_' . $siteId . '.dat';
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

    private function writeFileOrFail(string $path, mixed $data): void
    {
        if (!$this->writeFile($path, $data)) {
            throw new \RuntimeException('Unable to write File index storage at: ' . $path);
        }
    }

    private function deleteFileOrFail(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Unable to delete File index storage at: ' . $path);
        }
    }

    /**
     * @return list<string>
     */
    private function globFilesOrFail(string $pattern): array
    {
        $files = glob($pattern);
        if ($files === false) {
            throw new \RuntimeException('Unable to enumerate File index storage for: ' . $pattern);
        }

        return array_values($files);
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

    private function rememberFilenameKeyOrFail(string $filename): void
    {
        $safe = $this->sanitizeFilename($filename);
        if ($safe === $filename) {
            return;
        }

        $this->writeFileOrFail($this->basePath . '/keys/' . $safe . '.dat', [
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

    private function addDocumentKeyForParentOrFail(int $siteId, int $elementId, string $documentKey): void
    {
        $this->rememberFilenameKeyOrFail($documentKey);
        $path = $this->getParentPath($siteId, $elementId);

        if (!$this->updateJsonFile(
            $path,
            static function(mixed $current) use ($documentKey): array {
                $keys = is_array($current) ? array_values(array_map('strval', $current)) : [];
                $keys[] = $documentKey;

                return array_values(array_unique($keys));
            },
        )) {
            throw new \RuntimeException('Unable to update File parent document keys at: ' . $path);
        }
    }

    private function removeDocumentKeyForParentOrFail(int $siteId, int $elementId, string $documentKey): void
    {
        $path = $this->getParentPath($siteId, $elementId);

        if (!$this->updateJsonFile(
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
        )) {
            throw new \RuntimeException('Unable to update File parent document keys at: ' . $path);
        }
    }

    private function deleteDocumentPhysicalByKeyOrFail(
        int $siteId,
        ?int $elementId,
        string $documentKey,
    ): void {
        foreach ([
            $this->getDocPathByKey($siteId, $documentKey),
            $this->getTitlePathByKey($siteId, $documentKey),
            $this->getDocumentElementPath($siteId, $documentKey),
        ] as $path) {
            $this->deleteFileOrFail($path);
        }

        $oldRows = $this->readCompoundRowsByKey($siteId, $documentKey);
        if (!empty($oldRows) && !$this->applyCompoundAggregateDelta($siteId, $oldRows, -1)) {
            throw new \RuntimeException('Unable to update File compound aggregates while deleting a document.');
        }
        $this->deleteFileOrFail($this->getCompoundPathByKey($siteId, $documentKey));

        if ($elementId !== null && $elementId !== 0) {
            $this->removeDocumentKeyForParentOrFail($siteId, $elementId, $documentKey);
        }
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

    private function extractSiteIdFromTermFilename(string $filename): ?int
    {
        $stem = str_ends_with($filename, '.dat') ? substr($filename, 0, -4) : $filename;
        if (preg_match('/_(\d+)$/', $stem, $matches) !== 1) {
            return null;
        }

        return (int)$matches[1];
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
    private function applyCompoundAggregateDelta(int $siteId, array $rows, int $direction): bool
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
                if (!$this->updateCompoundAggregateBucket(
                    $scope,
                    $language,
                    $normalizedSuggestion,
                    $suggestion,
                    $frequency,
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    private function updateCompoundAggregateBucket(
        string $scope,
        string $language,
        string $normalizedSuggestion,
        string $suggestion,
        int $frequencyDelta,
    ): bool {
        $path = $this->getCompoundBucketPath($scope, $language, $normalizedSuggestion);
        return $this->updateJsonFile(
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

    private function deleteDirectoryOrFail(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if (!is_array($entries)) {
            throw new \RuntimeException('Unable to enumerate File index directory: ' . $dir);
        }

        foreach (array_diff($entries, ['.', '..']) as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->deleteDirectoryOrFail($path);
            } else {
                $this->deleteFileOrFail($path);
            }
        }

        if (!@rmdir($dir)) {
            throw new \RuntimeException('Unable to delete File index directory: ' . $dir);
        }
    }
}
