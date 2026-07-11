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
    public static function present(array $hit, bool $includeQueryRuleDebug = false, ?array $retrievableFields = null): array
    {
        $hit = SearchHitIdentityHelper::normalizeHit($hit);
        $hit = SearchFieldValueHelper::exposeFields($hit, $retrievableFields);
        $hit = SearchHeadingValueHelper::exposeHeadings($hit);
        if (!array_key_exists('index', $hit) && is_string($hit['_index'] ?? null) && $hit['_index'] !== '') {
            $hit['index'] = $hit['_index'];
        }
        $hit['snippet'] = array_key_exists('snippet', $hit) ? $hit['snippet'] : null;
        unset(
            $hit['content'],
            $hit['body'],
            $hit['description'],
            $hit['excerpt'],
            $hit['highlights'],
            $hit['sectionBody'],
            $hit['thumbnail'],
            $hit['_index'],
            $hit['_elementType'],
            $hit['_bodyClean'],
            $hit['_bodyWithCode'],
            $hit['_contentClean'],
            $hit['_sectionBody'],
            $hit['_sectionBodyWithCode'],
        );
        if (!$includeQueryRuleDebug) {
            unset($hit['_queryRuleDebug']);
        }

        $ordered = [];
        foreach ([
            'id',
            'elementId',
            'siteId',
            'site',
            'language',
            'index',
            'backendId',
            'objectID',
            'title',
            'slug',
            'url',
            'dateCreated',
            'dateUpdated',
            'elementType',
            'type',
            'section',
            'sectionHandle',
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
            'group',
            'groupHandle',
            'productType',
            'productTypeHandle',
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
    public static function presentResults(array $results, bool $includeQueryRuleDebug = false, array $retrievableFieldsByIndex = []): array
    {
        if (empty($results['hits']) || !is_array($results['hits'])) {
            return $results;
        }

        foreach ($results['hits'] as &$hit) {
            if (is_array($hit)) {
                $hit = self::present($hit, $includeQueryRuleDebug, self::retrievableFieldsForHit($hit, $retrievableFieldsByIndex));
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
}
