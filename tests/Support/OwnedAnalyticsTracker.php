<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Support;

use Craft;
use craft\db\Query;

/**
 * Captures and removes only analytics IDs created after a test-owned baseline.
 *
 * @since 5.54.0
 */
final class OwnedAnalyticsTracker
{
    private const TABLES = [
        '{{%searchmanager_analytics}}',
        '{{%searchmanager_rule_analytics}}',
        '{{%searchmanager_promotion_analytics}}',
    ];

    /** @var array<string, list<int>> */
    private array $baselineIds = [];

    /** @param array<mixed> $condition */
    public function __construct(private readonly array $condition)
    {
        foreach (self::TABLES as $table) {
            $this->baselineIds[$table] = $this->ids($table);
        }
    }

    public static function forQueryPrefix(string $prefix): self
    {
        return new self(['like', 'query', $prefix . '%', false]);
    }

    /** @return array<string, list<int>> */
    public function cleanupOwnedRows(): array
    {
        $ownedByTable = [];
        foreach (array_reverse(self::TABLES) as $table) {
            $ownedIds = array_values(array_diff($this->ids($table), $this->baselineIds[$table]));
            $ownedByTable[$table] = $ownedIds;
            if ($ownedIds !== []) {
                Craft::$app->getDb()->createCommand()->delete($table, ['id' => $ownedIds])->execute();
            }

            $remaining = array_values(array_diff($this->ids($table), $this->baselineIds[$table]));
            if ($remaining !== []) {
                throw new \RuntimeException(sprintf(
                    'Owned analytics cleanup left IDs in %s: %s',
                    $table,
                    implode(', ', $remaining),
                ));
            }
        }

        return $ownedByTable;
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        return array_map(
            'intval',
            (new Query())
                ->select(['id'])
                ->from($table)
                ->where($this->condition)
                ->orderBy(['id' => SORT_ASC])
                ->column(),
        );
    }
}
