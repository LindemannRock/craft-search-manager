<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search\storage;

/**
 * Storage operations required for per-section document identity.
 *
 * @since 5.54.0
 */
interface DocumentKeyStorageInterface extends StorageInterface
{
    /**
     * @param array<string, int> $termFreqs
     */
    public function storeDocumentByKey(int $siteId, int $elementId, string $documentKey, array $termFreqs, int $docLength, string $language = 'en'): void;

    /**
     * @return array<string, int>
     */
    public function getDocumentTermsByKey(int $siteId, string $documentKey): array;

    /**
     * @param array<int, string|int> $documentKeys
     * @return array<int|string, array<string, int>>
     */
    public function getDocumentTermsBatchByKeys(int $siteId, array $documentKeys): array;

    public function deleteDocumentByKey(int $siteId, string $documentKey): void;

    public function getDocumentLengthByKey(int $siteId, string $documentKey): int;

    /**
     * @param array<int, string|int> $documentKeys
     * @return array<int|string, string>
     */
    public function getDocumentLanguagesBatchByKeys(int $siteId, array $documentKeys): array;

    public function storeTermDocumentByKey(string $term, int $siteId, int $elementId, string $documentKey, int $frequency, string $language = 'en'): void;

    public function removeTermDocumentByKey(string $term, int $siteId, string $documentKey): void;

    public function storeElementByKey(int $siteId, int $elementId, string $documentKey, string $title, string $elementType, ?string $documentData = null): void;

    /**
     * @param array<int, string|int> $documentKeys
     * @return array<string, array<string, mixed>>
     */
    public function getElementsByDocumentKeys(int $siteId, array $documentKeys): array;

    /**
     * @param array<int, string> $titleTerms
     */
    public function storeTitleTermsByKey(int $siteId, int $elementId, string $documentKey, array $titleTerms): void;

    /**
     * @param array<int, string|int> $documentKeys
     * @return array<int|string, array<int, string>>
     */
    public function getTitleTermsBatchByKeys(int $siteId, array $documentKeys): array;

    public function deleteTitleTermsByKey(int $siteId, string $documentKey): void;

    /**
     * @param array<string, array{suggestion: string, normalizedSuggestion: string, tokenKey: string, frequency: int}> $suggestions
     */
    public function storeCompoundSuggestionsByKey(int $siteId, int $elementId, string $documentKey, array $suggestions, string $language = 'en'): void;

    public function deleteCompoundSuggestionsByKey(int $siteId, string $documentKey): void;

    /**
     * @return array<int, string>
     */
    public function getDocumentKeysByParent(int $siteId, int $elementId): array;
}
