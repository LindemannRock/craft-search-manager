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
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\DbHelper;
use yii\db\Expression;

/**
 * Shared query utilities for analytics sub-services
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since 5.39.0
 */
trait AnalyticsQueryTrait
{
    private static array $analyticsColumnCache = [];

    private const OPTIONAL_ANALYTICS_COLUMNS = [
        'botCategory' => true,
        'botProducerName' => true,
        'isSystemAgent' => true,
        'trafficType' => true,
    ];

    /**
     * Apply date range filter to query
     *
     * @param Query $query
     * @param string $dateRange
     * @param string|null $column
     */
    public function applyDateRangeFilter(Query $query, string $dateRange, ?string $column = null): void
    {
        $column = $column ?: 'dateCreated';
        DateRangeHelper::applyToQuery($query, $dateRange, $column);
    }

    /**
     * Build the action-identity expression for deduping search-action counts.
     *
     * Multi-index searches write one row per (search, index) sharing a common
     * sessionId UUID. Single-index searches leave sessionId NULL. To count
     * "search actions" rather than rows, wrap this in COUNT(DISTINCT ...):
     *
     *   $expr = $this->actionIdentityExpression();
     *   $query->select(["COUNT(DISTINCT $expr) as actions"]);
     *
     * The id fallback is cast to text via DbHelper::castToText() so COALESCE()
     * returns a stable type under both MySQL and PostgreSQL.
     *
     * Uses unqualified column names — sufficient for queries on
     * searchmanager_analytics alone. If a future query joins to another table
     * with an `id` or `sessionId` column, qualify inline at the call site
     * rather than parameterising this helper.
     *
     * @return string Raw SQL expression suitable for COUNT(DISTINCT ...)
     * @since 5.46.0
     */
    public function actionIdentityExpression(): string
    {
        return 'COALESCE([[sessionId]], ' . DbHelper::castToText('id') . ')';
    }

    /**
     * HAVING condition for "action had no successful outcome" — no hit, no
     * redirect, no promotion shown — lifted from row level to action level.
     *
     * isHit/wasRedirected are boolean columns, so they go through
     * DbHelper::boolToInt() — PostgreSQL has no MAX() over boolean.
     * promotionsShown is an integer and aggregates directly.
     */
    protected function zeroOutcomeHaving(): string
    {
        return 'MAX(' . DbHelper::boolToInt('isHit') . ') = 0'
            . ' AND MAX(' . DbHelper::boolToInt('wasRedirected') . ') = 0'
            . ' AND MAX([[promotionsShown]]) = 0';
    }

    /**
     * Check whether an analytics column exists on the current install.
     *
     * Search Manager is pre-release, so Install.php is the source of truth for
     * new installs; this guard keeps existing local databases working until the
     * matching SQL has been applied.
     */
    protected function hasAnalyticsColumn(string $column): bool
    {
        if (!array_key_exists($column, self::$analyticsColumnCache)) {
            $schema = Craft::$app->getDb()->getTableSchema('{{%searchmanager_analytics}}');
            self::$analyticsColumnCache[$column] = $schema !== null && isset($schema->columns[$column]);
        }

        return self::$analyticsColumnCache[$column];
    }

    /**
     * Build a select expression that returns NULL when an optional analytics
     * column is not present on the current database.
     */
    protected function optionalAnalyticsColumn(string $column): string|Expression
    {
        if (!isset(self::OPTIONAL_ANALYTICS_COLUMNS[$column])) {
            throw new \InvalidArgumentException("Unsupported optional analytics column: {$column}");
        }

        return $this->hasAnalyticsColumn($column) ? $column : new Expression("NULL AS [[$column]]");
    }

    /**
     * Normalize daily rows into a contiguous local-date range.
     *
     * @param array $rows
     * @param string $dateRange
     * @param array $fields
     * @param bool $datesAreLocal
     * @return array
     */
    private function normalizeDailyCounts(array $rows, string $dateRange, array $fields, bool $datesAreLocal = false): array
    {
        if (empty($rows)) {
            return [];
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        $bounds = DateRangeHelper::getBounds($dateRange);
        $startLocal = $bounds['start'] ? (clone $bounds['start'])->setTimezone($tz) : null;
        $endLocal = $bounds['end'] ? (clone $bounds['end'])->setTimezone($tz)->modify('-1 day') : new \DateTime('now', $tz);

        $map = [];
        foreach ($rows as $row) {
            if (empty($row['date'])) {
                continue;
            }

            if ($datesAreLocal) {
                $key = $row['date'];
            } else {
                $rowDate = new \DateTime($row['date'], new \DateTimeZone('UTC'));
                $key = $rowDate->setTimezone($tz)->format('Y-m-d');
            }

            if (!isset($map[$key])) {
                $map[$key] = array_fill_keys($fields, 0);
            }
            foreach ($fields as $field) {
                $map[$key][$field] += (int)($row[$field] ?? 0);
            }
        }

        if ($startLocal === null) {
            ksort($map);
            $normalized = [];
            foreach ($map as $date => $values) {
                $normalized[] = ['date' => $date] + $values;
            }
            return $normalized;
        }

        $startLocal->setTime(0, 0, 0);
        $endLocal->setTime(0, 0, 0);
        $cursor = clone $startLocal;
        $normalized = [];

        while ($cursor <= $endLocal) {
            $key = $cursor->format('Y-m-d');
            $values = $map[$key] ?? array_fill_keys($fields, 0);
            $normalized[] = ['date' => $key] + $values;
            $cursor->modify('+1 day');
        }

        return $normalized;
    }
}
