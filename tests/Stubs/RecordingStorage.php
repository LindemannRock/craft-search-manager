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

    /** @var list<array{siteId: int|null, language: string|null, limit: int, prefix: string|null}> */
    public array $getTermsForAutocompleteCalls = [];

    /** @var list<array{normalizedPrefix: string, siteId: int|null, language: string|null, limit: int}> */
    public array $getCompoundSuggestionsForAutocompleteCalls = [];

    /** @var array<string, string> Display suggestion => normalized suggestion. */
    private array $compoundNormalizedBySuggestion = [];

    /** @var int Times getElementsByIds() was called. */
    public int $getElementsByIdsCalls = 0;

    /** @var int[] Element-id counts passed to each getElementsByIds() call. */
    public array $getElementsByIdsBatchSizes = [];

    /**
     * @param array<string, array<string, int>> $termDocs term => [docId => freq] (docId = "siteId:elementId")
     * @param array<int, string[]> $titleByElement elementId => title terms
     * @param array<string, int> $docLengths docId => length
     * @param array<string, float> $fuzzyCandidates term => similarity, returned from getTermsByNgramSimilarity()
     * @param array<string, array<string, int>> $documentTermsById docId => [term => freq], for delete/indexing contract tests
     * @param array<string, int> $documentLengthsById docId => document length, for delete/indexing contract tests
     * @param array<string, string> $documentLanguagesById docId => language, for language-filter batching tests
     * @param array<int, array<string, mixed>> $elementsById elementId => element metadata, for phrase/order checks
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
        private array $elementsById = [],
        private array $autocompleteTerms = ['protein' => 3],
        private array $compoundSuggestions = [],
    ) {
    }

    public function getTermDocuments(string $term, int $siteId): array
    {
        $this->getTermDocumentsCalls++;

        return $this->filterDocsForSite($this->termDocs[$term] ?? [], $siteId);
    }

    public function getTermDocumentsBatch(array $terms, int $siteId): array
    {
        $this->getTermDocumentsBatchCalls++;
        $this->getTermDocumentsBatchSizes[] = count($terms);

        $byTerm = [];
        foreach ($terms as $term) {
            if (!empty($this->termDocs[$term])) {
                $byTerm[$term] = $this->filterDocsForSite($this->termDocs[$term], $siteId);
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

    public function getTermsForAutocomplete(?int $siteId, ?string $language, int $limit = 1000, ?string $prefix = null): array
    {
        $this->getTermsForAutocompleteCalls[] = [
            'siteId' => $siteId,
            'language' => $language,
            'limit' => $limit,
            'prefix' => $prefix,
        ];

        $terms = $this->autocompleteTerms;
        if ($prefix !== null && $prefix !== '') {
            $terms = array_filter(
                $terms,
                static fn (string|int $term): bool => is_string($term) && str_starts_with($term, $prefix),
                ARRAY_FILTER_USE_KEY,
            );
        }

        arsort($terms);

        return array_slice($terms, 0, $limit, true);
    }

    public function getElementsByIds(int $siteId, array $elementIds): array
    {
        $this->getElementsByIdsCalls++;
        $this->getElementsByIdsBatchSizes[] = count($elementIds);

        $out = [];
        foreach ($elementIds as $elementId) {
            if (isset($this->elementsById[(int)$elementId])) {
                $out[(int)$elementId] = $this->elementsById[(int)$elementId];
            }
        }

        return $out;
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

    public function getCompoundSuggestionsForAutocomplete(string $normalizedPrefix, ?int $siteId, ?string $language, int $limit = 10): array
    {
        $this->getCompoundSuggestionsForAutocompleteCalls[] = [
            'normalizedPrefix' => $normalizedPrefix,
            'siteId' => $siteId,
            'language' => $language,
            'limit' => $limit,
        ];

        $suggestionsByNormalized = [];
        foreach ($this->compoundSuggestions as $suggestion => $frequency) {
            $normalizedSuggestion = $this->compoundNormalizedBySuggestion[(string)$suggestion] ?? (string)$suggestion;
            if (str_starts_with($normalizedSuggestion, $normalizedPrefix)) {
                $suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] =
                    ($suggestionsByNormalized[$normalizedSuggestion]['totalFrequency'] ?? 0) + (int)$frequency;
                $suggestionsByNormalized[$normalizedSuggestion]['displayFrequencies'][(string)$suggestion] = (int)$frequency;
            }
        }

        $suggestions = [];
        foreach ($suggestionsByNormalized as $data) {
            $displayFrequencies = $data['displayFrequencies'] ?? [];
            arsort($displayFrequencies);
            $topFrequency = reset($displayFrequencies);
            $topSuggestions = array_keys(array_filter(
                $displayFrequencies,
                static fn (int $frequency): bool => $frequency === $topFrequency,
            ));
            sort($topSuggestions, SORT_STRING);
            $suggestions[$topSuggestions[0]] = (int)$data['totalFrequency'];
        }

        arsort($suggestions);

        return array_slice($suggestions, 0, $limit, true);
    }

    public function getTotalLength(int $siteId): int
    {
        return (int)($this->avgDocLength * $this->totalDocs);
    }

    /**
     * @param array<string, int> $docs
     * @return array<string, int>
     */
    private function filterDocsForSite(array $docs, int $siteId): array
    {
        return array_filter(
            $docs,
            static fn (string $docId): bool => str_starts_with($docId, $siteId . ':'),
            ARRAY_FILTER_USE_KEY,
        );
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

    public function storeCompoundSuggestions(int $siteId, int $elementId, array $suggestions, string $language = 'en'): void
    {
        foreach ($suggestions as $suggestion) {
            $key = (string)$suggestion['suggestion'];
            $this->compoundSuggestions[$key] = ($this->compoundSuggestions[$key] ?? 0) + (int)$suggestion['frequency'];
            $this->compoundNormalizedBySuggestion[$key] = (string)$suggestion['normalizedSuggestion'];
        }
    }

    public function deleteCompoundSuggestions(int $siteId, int $elementId): void
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
