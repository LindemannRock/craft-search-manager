<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Formats search hits for public/debug output without changing search logic.
 *
 * @since 5.53.0
 */
class SearchHitPresenter
{
    /**
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    /**
     * @param list<string>|null $retrievableFields
     */
    public static function present(
        array $hit,
        bool $includeQueryRuleDebug = false,
        ?array $retrievableFields = null,
        bool $includeSnippetDebug = false,
    ): array {
        $hit = SearchHitIdentityHelper::normalizeHit($hit);
        $hit = SearchFieldValueHelper::exposeFields($hit, $retrievableFields);
        $hit = SearchHeadingValueHelper::exposeHeadings($hit);
        if (!array_key_exists('index', $hit) && is_string($hit['_index'] ?? null) && $hit['_index'] !== '') {
            $hit['index'] = $hit['_index'];
        }
        if (is_array($hit['_categoryIds'] ?? null)) {
            $hit['categoryIds'] = self::publicCategoryIds($hit['_categoryIds']);
        }
        if (($hit['fields'] ?? null) === []) {
            $hit['fields'] = new \stdClass();
        }
        $hit['snippet'] = array_key_exists('snippet', $hit) ? $hit['snippet'] : null;
        $slug = is_scalar($hit['slug'] ?? null) ? trim((string)$hit['slug']) : '';
        if ($slug !== '') {
            $hit['slug'] = $slug;
        } else {
            unset($hit['slug']);
        }
        if (!self::isSplitSectionType($hit['sectionType'] ?? null)) {
            unset($hit['sectionType']);
        }
        $hit['matchedIn'] = self::stringList($hit['matchedIn'] ?? []);
        $hit['matchedTerms'] = self::matchedTerms($hit['matchedTerms'] ?? []);
        $hit['matchedPhrases'] = self::stringList($hit['matchedPhrases'] ?? []);
        $queryRuleDebug = $includeQueryRuleDebug && array_key_exists('_queryRuleDebug', $hit)
            ? $hit['_queryRuleDebug']
            : null;
        $snippetDebug = $includeSnippetDebug && array_key_exists('_snippet', $hit)
            ? $hit['_snippet']
            : null;
        unset(
            $hit['id'],
            $hit['objectID'],
            $hit['elementType'],
            $hit['section'],
            $hit['sectionHandle'],
            $hit['group'],
            $hit['groupHandle'],
            $hit['category'],
            $hit['content'],
            $hit['body'],
            $hit['description'],
            $hit['excerpt'],
            $hit['highlights'],
            $hit['sectionBody'],
            $hit['thumbnail'],
        );

        foreach (array_keys($hit) as $key) {
            if (is_string($key) && str_starts_with($key, '_')) {
                unset($hit[$key]);
            }
        }

        if ($includeQueryRuleDebug && $queryRuleDebug !== null) {
            $hit['_queryRuleDebug'] = $queryRuleDebug;
        }
        if ($includeSnippetDebug && $snippetDebug !== null) {
            $hit['_snippet'] = $snippetDebug;
        }

        $ordered = [];
        foreach ([
            'elementId',
            'siteId',
            'site',
            'language',
            'index',
            'backendId',
            'title',
            'slug',
            'url',
            'dateCreated',
            'dateUpdated',
            'type',
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
            'productType',
            'productTypeHandle',
            'categoryIds',
            'promoted',
            'position',
            'snippet',
            'headings',
        ] as $key) {
            if (array_key_exists($key, $hit)) {
                $ordered[$key] = $hit[$key];
                unset($hit[$key]);
            }
        }

        $resultMeta = [];
        foreach ([
            'score',
            'matchedIn',
            'matchedTerms',
            'matchedPhrases',
        ] as $key) {
            if (array_key_exists($key, $hit)) {
                $resultMeta[$key] = $hit[$key];
                unset($hit[$key]);
            }
        }

        return array_merge($ordered, $hit, $resultMeta);
    }

    /**
     * @param array<string, mixed> $results
     * @return array<string, mixed>
     */
    /**
     * @param array<string, list<string>> $retrievableFieldsByIndex
     */
    public static function presentResults(
        array $results,
        bool $includeQueryRuleDebug = false,
        array $retrievableFieldsByIndex = [],
        bool $includeSnippetDebug = false,
    ): array {
        if (empty($results['hits']) || !is_array($results['hits'])) {
            return $results;
        }

        foreach ($results['hits'] as &$hit) {
            if (is_array($hit)) {
                $hit = self::present(
                    $hit,
                    $includeQueryRuleDebug,
                    self::retrievableFieldsForHit($hit, $retrievableFieldsByIndex),
                    $includeSnippetDebug,
                );
            }
        }
        unset($hit);

        return $results;
    }

    /**
     * @param array<string, mixed> $hit
     * @param array<string, list<string>> $retrievableFieldsByIndex
     * @return list<string>|null
     */
    private static function retrievableFieldsForHit(array $hit, array $retrievableFieldsByIndex): ?array
    {
        $index = $hit['index'] ?? $hit['_index'] ?? null;
        if (!is_string($index) || $index === '') {
            return null;
        }

        return $retrievableFieldsByIndex[$index] ?? null;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /**
     * @param mixed $value
     * @return array{title: list<string>, content: list<string>}
     */
    private static function matchedTerms(mixed $value): array
    {
        $terms = is_array($value) ? $value : [];

        return [
            'title' => self::stringList($terms['title'] ?? []),
            'content' => self::stringList($terms['content'] ?? []),
        ];
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private static function publicCategoryIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $categoryId) {
            if (is_numeric($categoryId) && (int)$categoryId > 0) {
                $ids[(int)$categoryId] = true;
            }
        }

        return array_keys($ids);
    }

    private static function isSplitSectionType(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['heading', 'intro', 'promoted-page'], true);
    }
}
