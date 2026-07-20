<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use lindemannrock\searchmanager\models\SearchIndex;

/**
 * Builds the canonical per-site element queries for a search index.
 *
 * @since 5.54.0
 */
final class SearchIndexQueryHelper
{
    /**
     * @return array<int, ElementQuery>
     * @since 5.54.0
     */
    public static function buildSiteQueries(SearchIndex $index): array
    {
        $elementType = $index->elementType;
        $siteIds = $index->getSiteIds();
        if ($siteIds === null) {
            $siteIds = array_map(
                static fn($site): int => (int)$site->id,
                Craft::$app->getSites()->getAllSites(),
            );
        }

        $queries = [];
        foreach ($siteIds as $siteId) {
            try {
                /** @var ElementQuery $query */
                $query = $elementType::find()
                    ->siteId((int)$siteId)
                    ->drafts(false)
                    ->revisions(false);

                if (!empty($index->criteria)) {
                    $query = SearchIndexCriteriaHelper::apply($query, $elementType, $index->criteria);
                }

                SearchElementAvailabilityHelper::applyToQuery($query, $elementType);

                if ($index->skipEntriesWithoutUrl && $elementType === Entry::class) {
                    $query->andWhere(['not', ['elements_sites.uri' => null]])
                        ->andWhere(['<>', 'elements_sites.uri', '']);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException("for site {$siteId}: {$e->getMessage()}", 0, $e);
            }

            $queries[(int)$siteId] = $query;
        }

        return $queries;
    }
}
