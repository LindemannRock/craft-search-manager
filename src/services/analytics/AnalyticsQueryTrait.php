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

/**
 * Shared query utilities for analytics sub-services
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.0.0
 */
trait AnalyticsQueryTrait
{
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
