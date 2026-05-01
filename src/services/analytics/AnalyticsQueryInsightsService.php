<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services\analytics;

use Craft;
use craft\db\Query;
use craft\helpers\Db;

/**
 * Analytics Query Insights Service
 *
 * Query analysis, patterns, and search insights.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since 5.39.0
 */
class AnalyticsQueryInsightsService
{
    use AnalyticsQueryTrait;

    /**
     * Get most common search queries
     *
     * @param int|array|null $siteId
     * @param int $limit
     * @param string|null $dateRange
     * @return array
     */
    public function getMostCommonSearches(int|array|null $siteId, int $limit = 10, ?string $dateRange = null): array
    {
        $query = (new Query())
            ->select(['query', 'siteId', 'COUNT(*) as count', 'SUM(resultsCount) as totalResults', 'MAX(dateCreated) as lastSearched'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy(['query', 'siteId'])
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit);

        if ($dateRange !== null) {
            $this->applyDateRangeFilter($query, $dateRange);
        }

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        // Convert lastSearched dates from UTC to user's timezone
        $userTz = new \DateTimeZone(Craft::$app->getTimeZone());
        foreach ($results as &$result) {
            if (!empty($result['lastSearched'])) {
                $utcDate = new \DateTime($result['lastSearched'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone($userTz);
                $result['lastSearched'] = $utcDate->format('Y-m-d H:i:s');
            }
        }

        return $results;
    }

    /**
     * Get recent searches
     *
     * @param int|array|null $siteId
     * @param int $limit
     * @param bool|null $hasResults
     * @param string|null $dateRange
     * @return array
     */
    public function getRecentSearches(int|array|null $siteId, int $limit = 5, ?bool $hasResults = null, ?string $dateRange = null): array
    {
        $query = (new Query())
            ->select([
                'dateCreated', 'query', 'siteId', 'resultsCount', 'indexHandle', 'backend',
                'intent', 'source', 'platform', 'appVersion', 'deviceType', 'browser', 'osName',
                'city', 'country', 'wasRedirected', 'synonymsExpanded', 'rulesMatched', 'promotionsShown',
                'isHit',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            if ($hasResults) {
                // With results: found search results OR was redirected OR showed promotions
                $query->andWhere(['or', ['isHit' => 1], ['wasRedirected' => 1], ['>', 'promotionsShown', 0]]);
            } else {
                // No results: no search results AND not redirected AND no promotions (true content gaps)
                $query->andWhere(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0]);
            }
        }

        if ($dateRange !== null) {
            $this->applyDateRangeFilter($query, $dateRange);
        }

        $results = $query->all();

        // Convert dateCreated from UTC to user's timezone
        foreach ($results as &$result) {
            if (!empty($result['dateCreated'])) {
                $utcDate = new \DateTime($result['dateCreated'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
                $result['dateCreated'] = $utcDate;
            }
        }

        return $results;
    }

    /**
     * Get analytics count
     *
     * @param int|array|null $siteId
     * @param bool|null $hasResults
     * @param string|null $dateRange
     * @return int
     */
    public function getAnalyticsCount(int|array|null $siteId = null, ?bool $hasResults = null, ?string $dateRange = null): int
    {
        $query = (new Query())->from('{{%searchmanager_analytics}}');

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        if ($hasResults !== null) {
            if ($hasResults) {
                // With results: found search results OR was redirected OR showed promotions
                $query->andWhere(['or', ['isHit' => 1], ['wasRedirected' => 1], ['>', 'promotionsShown', 0]]);
            } else {
                // No results: no search results AND not redirected AND no promotions (true content gaps)
                $query->andWhere(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0]);
            }
        }

        if ($dateRange !== null) {
            $this->applyDateRangeFilter($query, $dateRange);
        }

        return (int)$query->count();
    }

    /**
     * Get query length distribution
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getQueryLengthDistribution(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        // Classify word count in SQL using LIKE space patterns (DB-agnostic)
        $query = (new Query())
            ->select([
                'bucket' => new \yii\db\Expression("CASE
                    WHEN [[query]] NOT LIKE '% %' THEN '1 word'
                    WHEN [[query]] NOT LIKE '% % % %' THEN '2-3 words'
                    ELSE '4+ words'
                END"),
                'count' => 'COUNT(*)',
            ])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy([new \yii\db\Expression("CASE
                    WHEN [[query]] NOT LIKE '% %' THEN '1 word'
                    WHEN [[query]] NOT LIKE '% % % %' THEN '2-3 words'
                    ELSE '4+ words'
                END")]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();

        $distribution = [
            '1 word' => 0,
            '2-3 words' => 0,
            '4+ words' => 0,
        ];

        foreach ($results as $row) {
            $distribution[$row['bucket']] = (int)$row['count'];
        }

        return [
            'labels' => array_keys($distribution),
            'values' => array_values($distribution),
        ];
    }

    /**
     * Get word cloud data
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getWordCloudData(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 50): array
    {
        // Only fetch the top 500 most frequent queries — the long tail contributes
        // negligible weight and isn't worth loading into memory for tokenization
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit(500);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        $words = [];

        // Use the plugin's StopWords class (multilingual, config-overridable)
        // Derive language from the filtered site, or primary site for "all sites"
        $site = ($siteId && !is_array($siteId))
            ? Craft::$app->getSites()->getSiteById($siteId)
            : Craft::$app->getSites()->getPrimarySite();
        $language = $site ? substr($site->language, 0, 2) : 'en';
        $stopWords = new \lindemannrock\searchmanager\search\StopWords($language);

        foreach ($results as $row) {
            // Simple tokenization
            $tokens = explode(' ', strtolower(trim($row['query'])));
            foreach ($tokens as $token) {
                $token = trim($token);
                // Skip empty or stop words
                if ($token === '' || $stopWords->isStopWord($token)) {
                    continue;
                }
                $words[$token] = ($words[$token] ?? 0) + (int)$row['count'];
            }
        }

        arsort($words);
        $topWords = array_slice($words, 0, $limit);

        $cloudData = [];
        foreach ($topWords as $word => $count) {
            $cloudData[] = [
                'text' => $word,
                'weight' => $count,
            ];
        }

        return $cloudData;
    }

    /**
     * Get zero-result clusters (content gaps)
     * Groups similar failed queries together
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @param int $limit
     * @return array
     */
    public function getZeroResultClusters(int|array|null $siteId, string $dateRange = 'last30days', int $limit = 20): array
    {
        // 1. Get all zero-result queries (excluding handled searches: redirected or showed promotions)
        $query = (new Query())
            ->select(['query', 'COUNT(*) as count', 'MAX(dateCreated) as lastSearched'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['isHit' => 0, 'wasRedirected' => 0, 'promotionsShown' => 0])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit * 3); // Get more candidates for clustering

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $failedQueries = $query->all();
        $clusters = [];

        foreach ($failedQueries as $row) {
            $term = strtolower(trim($row['query']));
            $matched = false;

            // Try to add to an existing cluster
            foreach ($clusters as &$cluster) {
                // Simple similarity check: contains or is contained by representative
                // or Levenshtein distance is small
                $rep = $cluster['representative'];

                if (str_contains($rep, $term) || str_contains($term, $rep) || levenshtein($term, $rep) <= 2) {
                    $cluster['count'] += (int)$row['count'];
                    $cluster['queries'][] = $row['query'];
                    // Update representative if this term is more frequent (handled by sorting order)
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $clusters[] = [
                    'representative' => $term,
                    'count' => (int)$row['count'],
                    'queries' => [$row['query']],
                    'lastSearched' => $row['lastSearched'],
                ];
            }
        }

        // Sort clusters by count
        usort($clusters, fn($a, $b) => $b['count'] <=> $a['count']);

        // Convert lastSearched from UTC to user's timezone for display
        $userTz = new \DateTimeZone(Craft::$app->getTimeZone());
        $result = array_slice($clusters, 0, $limit);
        foreach ($result as &$cluster) {
            if (!empty($cluster['lastSearched'])) {
                $utcDate = new \DateTime($cluster['lastSearched'], new \DateTimeZone('UTC'));
                $utcDate->setTimezone($userTz);
                $cluster['lastSearched'] = $utcDate->format('Y-m-d H:i:s');
            }
        }

        return $result;
    }

    /**
     * Get intent breakdown
     *
     * @param int|array|null $siteId
     * @param string $dateRange
     * @return array
     */
    public function getIntentBreakdown(int|array|null $siteId, string $dateRange = 'last30days'): array
    {
        $query = (new Query())
            ->select(['intent', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['not', ['intent' => null]])
            ->groupBy('intent')
            ->orderBy(['count' => SORT_DESC]);

        $this->applyDateRangeFilter($query, $dateRange);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $results = $query->all();
        $total = array_sum(array_column($results, 'count'));

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'intent' => $row['intent'],
                'count' => (int)$row['count'],
                'percentage' => $total > 0 ? round(($row['count'] / $total) * 100, 1) : 0,
            ];
        }

        return [
            'data' => $data,
            'labels' => array_column($data, 'intent'),
            'values' => array_column($data, 'count'),
            'percentages' => array_column($data, 'percentage'),
        ];
    }

    /**
     * Get trending queries - compares current period to previous period
     *
     * @param int|array|null $siteId Site ID(s) filter
     * @param string $dateRange Date range for current period
     * @param int $limit Number of queries to return
     * @return array Queries with trend data (up, down, new, same)
     */
    public function getTrendingQueries(int|array|null $siteId, string $dateRange = 'last7days', int $limit = 10): array
    {
        // Calculate date ranges for current and previous periods
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $now = new \DateTime('now', $tz);

        switch ($dateRange) {
            case 'today':
                $currentStart = (clone $now)->setTime(0, 0, 0);
                $previousStart = (clone $currentStart)->modify('-1 day');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'yesterday':
                $currentStart = (clone $now)->modify('-1 day')->setTime(0, 0, 0);
                $currentEnd = (clone $currentStart)->modify('+1 day')->modify('-1 second');
                $previousStart = (clone $currentStart)->modify('-1 day');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                $now = $currentEnd; // Use yesterday's end as "now"
                break;
            case 'last7days':
                $currentStart = (clone $now)->modify('-7 days');
                $previousStart = (clone $now)->modify('-14 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'last30days':
                $currentStart = (clone $now)->modify('-30 days');
                $previousStart = (clone $now)->modify('-60 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            case 'last90days':
                $currentStart = (clone $now)->modify('-90 days');
                $previousStart = (clone $now)->modify('-180 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
            default: // 'all' - compare last 30 days vs previous 30 days
                $currentStart = (clone $now)->modify('-30 days');
                $previousStart = (clone $now)->modify('-60 days');
                $previousEnd = (clone $currentStart)->modify('-1 second');
                break;
        }

        // Get current period queries
        $currentQuery = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($currentStart)])
            ->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($now)])
            ->groupBy('query')
            ->orderBy(['count' => SORT_DESC])
            ->limit($limit * 2); // Get more to account for filtering

        if ($siteId) {
            $currentQuery->andWhere(['siteId' => $siteId]);
        }

        $currentResults = $currentQuery->all();

        // Get previous period queries for comparison
        $previousQuery = (new Query())
            ->select(['query', 'COUNT(*) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['>=', 'dateCreated', Db::prepareDateForDb($previousStart)])
            ->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($previousEnd)])
            ->groupBy('query');

        if ($siteId) {
            $previousQuery->andWhere(['siteId' => $siteId]);
        }

        $previousResults = $previousQuery->all();

        // Index previous results by query
        $previousCounts = [];
        foreach ($previousResults as $row) {
            $previousCounts[strtolower($row['query'])] = (int)$row['count'];
        }

        // Calculate trends
        $trending = [];
        foreach ($currentResults as $row) {
            $queryText = $row['query'];
            $currentCount = (int)$row['count'];
            $queryKey = strtolower($queryText);
            $previousCount = $previousCounts[$queryKey] ?? 0;

            // Calculate percentage change
            if ($previousCount === 0) {
                $trend = 'new';
                $changePercent = 100;
            } elseif ($currentCount > $previousCount) {
                $trend = 'up';
                $changePercent = round((($currentCount - $previousCount) / $previousCount) * 100);
            } elseif ($currentCount < $previousCount) {
                $trend = 'down';
                $changePercent = round((($previousCount - $currentCount) / $previousCount) * 100);
            } else {
                $trend = 'same';
                $changePercent = 0;
            }

            $trending[] = [
                'query' => $queryText,
                'count' => $currentCount,
                'previousCount' => $previousCount,
                'trend' => $trend,
                'changePercent' => $changePercent,
            ];
        }

        // Sort by absolute change (most movement first), but keep high-count items visible
        usort($trending, function($a, $b) {
            // Prioritize significant trends with decent volume
            $aScore = $a['count'] * ($a['changePercent'] / 100 + 1);
            $bScore = $b['count'] * ($b['changePercent'] / 100 + 1);
            return $bScore <=> $aScore;
        });

        return array_slice($trending, 0, $limit);
    }

    /**
     * Get unique queries count
     *
     * @param int|array|null $siteId
     * @param int $days
     * @return int
     */
    public function getUniqueQueriesCount(int|array|null $siteId, int $days = 30): int
    {
        $query = (new Query())
            ->select(['COUNT(DISTINCT query) as count'])
            ->from('{{%searchmanager_analytics}}')
            ->where(['source' => 'frontend'])
            ->andWhere(['>=', 'dateCreated', Db::prepareDateForDb((new \DateTime())->modify("-{$days} days"))]);

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return (int)$query->scalar();
    }
}
