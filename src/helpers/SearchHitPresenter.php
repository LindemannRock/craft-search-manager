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
    public static function present(array $hit, bool $includeQueryRuleDebug = false): array
    {
        $hit = SearchHitIdentityHelper::normalizeHit($hit);
        $hit = SearchFieldValueHelper::exposeFields($hit);
        unset($hit['_elementType']);
        if (!$includeQueryRuleDebug) {
            unset($hit['_queryRuleDebug']);
        }

        $ordered = [];
        foreach ([
            'id',
            'elementId',
            'siteId',
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
            'volume',
            'volumeHandle',
            'group',
            'groupHandle',
            'productType',
            'productTypeHandle',
            'promoted',
            'position',
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
    public static function presentResults(array $results, bool $includeQueryRuleDebug = false): array
    {
        if (empty($results['hits']) || !is_array($results['hits'])) {
            return $results;
        }

        foreach ($results['hits'] as &$hit) {
            if (is_array($hit)) {
                $hit = self::present($hit, $includeQueryRuleDebug);
            }
        }
        unset($hit);

        return $results;
    }
}
