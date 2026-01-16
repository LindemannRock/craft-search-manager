<?php

namespace lindemannrock\searchmanager\search\storage;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * FileStorage
 *
 * File-based storage implementation using PHP serialize() for persistence.
 * Stores inverted index data in .dat files organized by directory structure.
 *
 * Directory structure:
 * - docs/      - Document term frequencies and lengths
 * - terms/     - Inverted index (term -> documents)
 * - titles/    - Title terms per document
 * - ngrams/    - N-grams for fuzzy matching
 * - meta/      - Global metadata
 *
 * @since 5.0.0
 */
class FileStorage implements StorageInterface
{
    use LoggingTrait;

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
    public function __construct(string $indexHandle)
    {
        $this->setLoggingHandle('search-manager');
        $this->indexHandle = $indexHandle;

        // Set base path: @storage/runtime/search-manager/indices/{indexHandle}/
        $runtimePath = Craft::$app->getPath()->getRuntimePath();
        $this->basePath = $runtimePath . '/search-manager/indices/' . $indexHandle;

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
            $this->basePath . '/meta',
            $this->basePath . '/elements',
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
    public function deleteDocument(int $siteId, int $elementId): void
    {
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

        $this->logDebug('Deleted document, title, and element files', [
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

    // =========================================================================
    // TERM OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function storeTermDocument(string $term, int $siteId, int $elementId, int $frequency, string $language = 'en'): void
    {
        $termPath = $this->getTermPath($term, $siteId);
        $data = $this->readFile($termPath) ?: [];

        $docId = $siteId . ':' . $elementId;
        $data[$docId] = $frequency;
        // Note: File storage uses siteId for language context, language param not stored separately

        $this->writeFile($termPath, $data);
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
    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
        $termPath = $this->getTermPath($term, $siteId);
        $data = $this->readFile($termPath);

        if (!$data) {
            return;
        }

        $docId = $siteId . ':' . $elementId;
        unset($data[$docId]);

        if (empty($data)) {
            // Remove file if no documents left
            @unlink($termPath);
        } else {
            $this->writeFile($termPath, $data);
        }
    }

    /**
     * @inheritdoc
     */
    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000): array
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
            $basename = basename($file, '.dat');
            // Extract term from filename (test_1 → test)
            $parts = explode('_', $basename);
            array_pop($parts); // Remove site ID
            $term = implode('_', $parts);

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

            if (count($terms) >= $limit) {
                break;
            }
        }

        arsort($terms);

        return $terms;
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
    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
        $titlePath = $this->getTitlePath($siteId, $elementId);

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
     * @return void
     */
    public function storeElement(int $siteId, int $elementId, string $title, string $elementType): void
    {
        $elementPath = $this->getElementPath($siteId, $elementId);

        // Normalize searchText for prefix matching (lowercase)
        $searchText = mb_strtolower(trim($title));

        $data = [
            'title' => $title,
            'elementType' => $elementType,
            'searchText' => $searchText,
            'elementId' => $elementId,
        ];

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
     * @return array Map of elementId => ['title' => ..., 'elementType' => ...]
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
                    ];
                }
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
        $elementsDir = $this->basePath . '/elements';

        if (!is_dir($elementsDir)) {
            return [];
        }

        // Find all element files for this site
        $pattern = $elementsDir . '/' . $siteId . '_*.dat';
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
        // Store n-grams in site-specific directory
        $ngramDir = $this->basePath . '/ngrams/site' . $siteId;
        if (!is_dir($ngramDir)) {
            @mkdir($ngramDir, 0755, true);
        }

        $ngramPath = $ngramDir . '/' . $this->sanitizeFilename($term) . '.dat';
        $this->writeFile($ngramPath, $ngrams);

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
        $ngramDir = $this->basePath . '/ngrams/site' . $siteId;

        if (!is_dir($ngramDir)) {
            return [];
        }

        $searchNgramCount = count($ngrams);
        $similarities = [];

        // Scan all term n-gram files
        $files = glob($ngramDir . '/*.dat');

        foreach ($files as $file) {
            $termNgrams = $this->readFile($file);

            if (!$termNgrams) {
                continue;
            }

            // Calculate Jaccard similarity
            $intersection = count(array_intersect($ngrams, $termNgrams));
            $union = count(array_unique(array_merge($ngrams, $termNgrams)));

            $similarity = $union > 0 ? $intersection / $union : 0.0;

            if ($similarity >= $threshold) {
                // Extract term from filename
                $term = $this->extractTermFromFilename(basename($file));
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

        $termsDir = $this->basePath . '/terms/site' . $siteId;

        if (!is_dir($termsDir)) {
            return [];
        }

        $matchingTerms = [];
        $files = glob($termsDir . '/*.dat');

        foreach ($files as $file) {
            $term = $this->extractTermFromFilename(basename($file));
            if (str_starts_with($term, $prefix)) {
                $matchingTerms[] = $term;
            }
        }

        return $matchingTerms;
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
        // Update doc count
        $docCountPath = $this->getMetaPath($siteId, 'doc_count');
        $docCount = $this->readFile($docCountPath) ?: 0;
        $docCount += $isAddition ? 1 : -1;
        $docCount = max(0, $docCount);
        $this->writeFile($docCountPath, $docCount);

        // Update total length
        $lengthPath = $this->getMetaPath($siteId, 'total_length');
        $totalLength = $this->readFile($lengthPath) ?: 0;
        $totalLength += $isAddition ? $docLength : -$docLength;
        $totalLength = max(1, $totalLength); // Minimum 1 to avoid division by zero
        $this->writeFile($lengthPath, $totalLength);
    }

    // =========================================================================
    // MAINTENANCE OPERATIONS
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function clearSite(int $siteId): void
    {
        // Clear all files for this site
        $patterns = [
            $this->basePath . '/docs/' . $siteId . '_*.dat',
            $this->basePath . '/titles/' . $siteId . '_*.dat',
            $this->basePath . '/meta/' . $siteId . '_*.dat',
            $this->basePath . '/elements/' . $siteId . '_*.dat',
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
     * @param string $path File path
     * @return mixed Unserialized data or null
     */
    private function readFile(string $path)
    {
        if (!file_exists($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return unserialize($contents);
    }

    /**
     * Write data to file
     *
     * @param string $path File path
     * @param mixed $data Data to serialize
     * @return bool Success
     */
    private function writeFile(string $path, $data): bool
    {
        $serialized = serialize($data);
        $result = @file_put_contents($path, $serialized, LOCK_EX);

        return $result !== false;
    }

    /**
     * Sanitize filename (make safe for filesystem)
     *
     * @param string $filename Filename
     * @return string Sanitized filename
     */
    private function sanitizeFilename(string $filename): string
    {
        // Replace unsafe characters with underscore
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);

        // Limit length
        if (strlen($safe) > 200) {
            $safe = substr($safe, 0, 200) . '_' . md5($filename);
        }

        return $safe;
    }

    /**
     * Extract term from sanitized filename
     *
     * @param string $filename Filename (e.g., "term_name.dat")
     * @return string Original term (approximate - best effort)
     */
    private function extractTermFromFilename(string $filename): string
    {
        // Remove .dat extension
        $term = str_replace('.dat', '', $filename);

        // This is a limitation of file-based storage - we can't perfectly
        // reverse the sanitization, but for most terms this works
        return str_replace('_', '', $term);
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
