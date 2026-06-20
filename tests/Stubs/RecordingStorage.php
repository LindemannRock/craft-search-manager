<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * In-memory {@see StorageInterface} that serves canned BM25 data and records
 * how the scoring path queries it. Used to prove the title boost reads title
 * terms via the batched {@see getTitleTermsBatch()} (one call per search)
 * instead of the per-document {@see getTitleTerms()} N+1, while ranking stays
 * equivalent.
 *
 * Only the read methods the engine's scoring path touches return data; the
 * write/maintenance surface is a no-op.
 *
 * @since 5.47.0
 */
final class RecordingStorage implements StorageInterface
{
    /** @var int Times getTitleTerms() (per-document) was called. */
    public int $getTitleTermsCalls = 0;

    /** @var int Times getTitleTermsBatch() was called. */
    public int $getTitleTermsBatchCalls = 0;

    /** @var int[] Element-id counts passed to each getTitleTermsBatch() call. */
    public array $getTitleTermsBatchSizes = [];

    /** @var int Times getTermDocuments() (single term) was called. */
    public int $getTermDocumentsCalls = 0;

    /** @var int Times getTermDocumentsBatch() was called. */
    public int $getTermDocumentsBatchCalls = 0;

    /** @var int[] Term counts passed to each getTermDocumentsBatch() call. */
    public array $getTermDocumentsBatchSizes = [];

    /** @var int Times updateMetadata() was called. */
    public int $updateMetadataCalls = 0;

    /** @var int Times getDocumentTerms() (per-document) was called. */
    public int $getDocumentTermsCalls = 0;

    /** @var int Times getDocumentTermsBatch() was called. */
    public int $getDocumentTermsBatchCalls = 0;

    /** @var int[] Element-id counts passed to each getDocumentTermsBatch() call. */
    public array $getDocumentTermsBatchSizes = [];

    /** @var int Times getDocumentLanguage() (per-document) was called. */
    public int $getDocumentLanguageCalls = 0;

    /** @var int Times getDocumentLanguagesBatch() was called. */
    public int $getDocumentLanguagesBatchCalls = 0;

    /** @var int[] Element-id counts passed to each getDocumentLanguagesBatch() call. */
    public array $getDocumentLanguagesBatchSizes = [];

    /** @var array<int, array{siteId: int, docLength: int, isAddition: bool}> */
    public array $updateMetadataEvents = [];

    /** @var list<array{siteId: int|null, language: string|null, limit: int}> */
    public array $getTermsForAutocompleteCalls = [];

    /**
     * @param array<string, array<string, int>> $termDocs term => [docId => freq] (docId = "siteId:elementId")
     * @param array<int, string[]> $titleByElement elementId => title terms
     * @param array<string, int> $docLengths docId => length
     * @param array<string, float> $fuzzyCandidates term => similarity, returned from getTermsByNgramSimilarity()
     * @param array<string, array<string, int>> $documentTermsById docId => [term => freq], for delete/indexing contract tests
     * @param array<string, int> $documentLengthsById docId => document length, for delete/indexing contract tests
     * @param array<string, string> $documentLanguagesById docId => language, for language-filter batching tests
     */
    public function __construct(
        private array $termDocs,
        private array $titleByElement,
        private array $docLengths,
        private int $totalDocs,
        private float $avgDocLength,
        private array $fuzzyCandidates = [],
        private array $documentTermsById = [],
        private array $documentLengthsById = [],
        private array $documentLanguagesById = [],
    ) {
    }

    public function getTermDocuments(string $term, int $siteId): array
    {
        $this->getTermDocumentsCalls++;

        return $this->termDocs[$term] ?? [];
    }

    public function getTermDocumentsBatch(array $terms, int $siteId): array
    {
        $this->getTermDocumentsBatchCalls++;
        $this->getTermDocumentsBatchSizes[] = count($terms);

        $byTerm = [];
        foreach ($terms as $term) {
            if (!empty($this->termDocs[$term])) {
                $byTerm[$term] = $this->termDocs[$term];
            }
        }

        return $byTerm;
    }

    public function getDocumentLengthsBatch(array $docIds): array
    {
        $out = [];
        foreach ($docIds as $siteId => $elementIds) {
            foreach ($elementIds as $elementId) {
                $docId = $siteId . ':' . $elementId;
                if (isset($this->docLengths[$docId])) {
                    $out[$docId] = $this->docLengths[$docId];
                }
            }
        }

        return $out;
    }

    public function getTitleTerms(int $siteId, int $elementId): array
    {
        $this->getTitleTermsCalls++;

        return $this->titleByElement[$elementId] ?? [];
    }

    public function getTitleTermsBatch(int $siteId, array $elementIds): array
    {
        $this->getTitleTermsBatchCalls++;
        $this->getTitleTermsBatchSizes[] = count($elementIds);

        $out = [];
        foreach ($elementIds as $elementId) {
            if (!empty($this->titleByElement[(int)$elementId])) {
                $out[(int)$elementId] = $this->titleByElement[(int)$elementId];
            }
        }

        return $out;
    }

    public function getTotalDocCount(int $siteId): int
    {
        return $this->totalDocs;
    }

    public function getAverageDocLength(int $siteId): float
    {
        return $this->avgDocLength;
    }

    // ---- Unused read surface (scoring path never reaches these here) --------

    public function getDocumentTerms(int $siteId, int $elementId): array
    {
        $this->getDocumentTermsCalls++;

        return $this->documentTermsById[$siteId . ':' . $elementId] ?? [];
    }

    public function getDocumentTermsBatch(int $siteId, array $elementIds): array
    {
        $this->getDocumentTermsBatchCalls++;
        $this->getDocumentTermsBatchSizes[] = count($elementIds);

        $out = [];
        foreach ($elementIds as $elementId) {
            $terms = $this->documentTermsById[$siteId . ':' . (int)$elementId] ?? [];
            if (!empty($terms)) {
                $out[(int)$elementId] = $terms;
            }
        }

        return $out;
    }

    public function getDocumentLength(int $siteId, int $elementId): int
    {
        return $this->documentLengthsById[$siteId . ':' . $elementId] ?? 0;
    }

    public function getDocumentLanguage(int $siteId, int $elementId): string
    {
        $this->getDocumentLanguageCalls++;

        return $this->documentLanguagesById[$siteId . ':' . $elementId] ?? 'en';
    }

    public function getDocumentLanguagesBatch(int $siteId, array $elementIds): array
    {
        $this->getDocumentLanguagesBatchCalls++;
        $this->getDocumentLanguagesBatchSizes[] = count($elementIds);

        $out = [];
        foreach ($elementIds as $elementId) {
            $out[(int)$elementId] = $this->documentLanguagesById[$siteId . ':' . (int)$elementId] ?? 'en';
        }

        return $out;
    }

    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000): array
    {
        $this->getTermsForAutocompleteCalls[] = [
            'siteId' => $siteId,
            'language' => $language,
            'limit' => $limit,
        ];

        return ['protein' => 3];
    }

    public function getElementsByIds(int $siteId, array $elementIds): array
    {
        return [];
    }

    public function termHasNgrams(string $term, int $siteId): bool
    {
        return false;
    }

    public function getTermsByNgramSimilarity(array $ngrams, int $siteId, float $threshold, int $limit = 100): array
    {
        return $this->fuzzyCandidates;
    }

    public function getTermsByPrefix(string $prefix, int $siteId): array
    {
        return [];
    }

    public function getTotalLength(int $siteId): int
    {
        return (int)($this->avgDocLength * $this->totalDocs);
    }

    // ---- Write / maintenance surface — no-ops -------------------------------

    public function storeDocument(int $siteId, int $elementId, array $termFreqs, int $docLength, string $language = 'en'): void
    {
    }

    public function deleteDocument(int $siteId, int $elementId): void
    {
    }

    public function storeTermDocument(string $term, int $siteId, int $elementId, int $frequency, string $language = 'en'): void
    {
    }

    public function removeTermDocument(string $term, int $siteId, int $elementId): void
    {
    }

    public function storeElement(int $siteId, int $elementId, string $title, string $elementType, ?string $documentData = null): void
    {
    }

    public function storeTitleTerms(int $siteId, int $elementId, array $titleTerms): void
    {
    }

    public function deleteTitleTerms(int $siteId, int $elementId): void
    {
    }

    public function storeTermNgrams(string $term, array $ngrams, int $siteId): void
    {
    }

    public function updateMetadata(int $siteId, int $docLength, bool $isAddition): void
    {
        $this->updateMetadataCalls++;
        $this->updateMetadataEvents[] = [
            'siteId' => $siteId,
            'docLength' => $docLength,
            'isAddition' => $isAddition,
        ];
    }

    public function clearSite(int $siteId): void
    {
    }

    public function clearAll(): void
    {
    }
}
