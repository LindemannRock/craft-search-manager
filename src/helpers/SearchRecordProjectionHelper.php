<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\searchmanager\models\SearchIndex;

/**
 * Builds the persisted search-record shape for local and provider backends.
 *
 * @since 5.53.0
 */
class SearchRecordProjectionHelper
{
    public const SEARCHABLE_TEXT_FIELDS = ['title', 'content', '_bodyClean', 'url'];
    public const TYPESENSE_QUERY_BY_WEIGHTS = [5, 3, 1, 1];

    private const NON_STORED_FIELDS = [
        '_fields',
        'body',
        'description',
        'excerpt',
        'headings',
        'sectionBody',
        'thumbnail',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function externalRecord(string $indexName, array $data): array
    {
        $record = self::project($indexName, $data, self::NON_STORED_FIELDS);
        if (isset($record['_bodyWithCode'], $record['_bodyClean']) && self::sameText($record['_bodyWithCode'], $record['_bodyClean'])) {
            unset($record['_bodyWithCode']);
        }
        if (isset($record['_sectionBodyWithCode'], $record['_bodyClean']) && self::sameText($record['_sectionBodyWithCode'], $record['_bodyClean'])) {
            unset($record['_sectionBodyWithCode']);
        }
        if (isset($record['_headings']) && $record['_headings'] === []) {
            unset($record['_headings']);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function localDocumentData(string $indexName, array $data): array
    {
        return self::project($indexName, $data, self::NON_STORED_FIELDS);
    }

    /**
     * Build the content string passed to the local BM25 engine.
     *
     * Local term extraction stores one combined term pool, so this is the local
     * equivalent of provider searchable attributes/query_by configuration.
     *
     * @param array<string, mixed> $data
     */
    public static function localMatchingText(array $data): string
    {
        return self::joinText([
            $data['content'] ?? null,
            $data['_bodyClean'] ?? null,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function providerSearchableAttributes(): array
    {
        return self::SEARCHABLE_TEXT_FIELDS;
    }

    public static function typesenseQueryBy(): string
    {
        return implode(',', self::SEARCHABLE_TEXT_FIELDS);
    }

    public static function typesenseQueryByWeights(): string
    {
        return implode(',', self::TYPESENSE_QUERY_BY_WEIGHTS);
    }

    /**
     * @param list<string>|null $retrievableFields
     * @return list<string>
     */
    public static function searchProjectionFields(?array $retrievableFields = null): array
    {
        $fields = [
            'id',
            'objectID',
            'elementId',
            'backendId',
            'siteId',
            'site',
            'language',
            'title',
            'slug',
            'url',
            'dateCreated',
            'dateUpdated',
            'type',
            'elementType',
            'source',
            'docCategory',
            'entrySection',
            'entrySectionHandle',
            'entrySectionType',
            'sectionType',
            'sectionId',
            'sectionTitle',
            'sectionLevel',
            'sectionAnchor',
            'sectionUrl',
            'sectionIndex',
            'ancestors',
            'level',
            'folderPath',
            'volume',
            'volumeHandle',
            'filename',
            'assetKind',
            'extension',
            'size',
            'width',
            'height',
            'categoryGroup',
            'categoryGroupHandle',
            '_categoryIds',
            'productType',
            'productTypeHandle',
            'content',
            '_bodyClean',
            '_bodyWithCode',
            '_sectionBodyWithCode',
            '_headings',
            '_snippetFields',
        ];

        if ($retrievableFields === null || $retrievableFields !== []) {
            $fields[] = 'fields';
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $remove
     * @return array<string, mixed>
     */
    private static function project(string $indexName, array $data, array $remove): array
    {
        $record = $data;
        foreach ($remove as $field) {
            unset($record[$field]);
        }

        $snippetFields = SearchFieldValueHelper::snippetFieldsFromHit($data);
        if ($snippetFields !== []) {
            $record['_snippetFields'] = $snippetFields;
        } else {
            unset($record['_snippetFields']);
        }

        $publicFields = SearchFieldValueHelper::filterFields(
            SearchFieldValueHelper::fieldsFromHit($data),
            self::storedRetrievableFields($indexName),
        );
        if ($publicFields !== []) {
            $record['fields'] = $publicFields;
        } else {
            unset($record['fields']);
        }

        if (isset($data['_bodyClean']) && is_scalar($data['_bodyClean']) && trim((string)$data['_bodyClean']) !== '') {
            $record['_bodyClean'] = trim((string)$data['_bodyClean']);
        }
        if (isset($record['sectionType'], $record['_sectionBodyWithCode'])) {
            unset($record['_bodyWithCode']);
        }

        return $record;
    }

    /**
     * @return list<string>
     */
    private static function storedRetrievableFields(string $indexName): array
    {
        return SearchIndex::normalizeRetrievableFields(SearchIndex::findByHandle($indexName)?->retrievableFields ?? ['*']);
    }

    /**
     * @param array<int, mixed> $parts
     */
    private static function joinText(array $parts): string
    {
        return trim(implode(' ', array_filter(array_map(
            static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
            $parts,
        ), static fn(string $value): bool => $value !== '')));
    }

    private static function sameText(mixed $a, mixed $b): bool
    {
        return is_scalar($a) && is_scalar($b) && trim((string)$a) === trim((string)$b);
    }
}
