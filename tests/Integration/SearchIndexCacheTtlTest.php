<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for the cross-process SearchIndex cache TTL.
 *
 * @since 5.54.0
 */
final class SearchIndexCacheTtlTest extends TestCase
{
    public function testExpiredProcessCacheReloadsDatabaseIndexChanges(): void
    {
        $index = null;
        foreach (SearchIndex::findAll() as $candidate) {
            if ($candidate->source === 'database' && $candidate->id !== null) {
                $index = $candidate;
                break;
            }
        }

        if ($index === null) {
            self::markTestSkipped('Requires a database-backed search index.');
        }

        $originalName = $index->name;
        $changedName = $originalName . ' TTL refresh';

        try {
            Craft::$app->getDb()->createCommand()
                ->update('{{%searchmanager_indices}}', ['name' => $changedName], ['id' => $index->id])
                ->execute();

            self::assertSame($originalName, $this->findCachedIndex($index->handle)?->name);

            $expiresAt = new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt');
            $expiresAt->setAccessible(true);
            $expiresAt->setValue(null, microtime(true) - 1.0);

            self::assertSame($changedName, $this->findCachedIndex($index->handle)?->name);
        } finally {
            Craft::$app->getDb()->createCommand()
                ->update('{{%searchmanager_indices}}', ['name' => $originalName], ['id' => $index->id])
                ->execute();
            SearchIndex::clearCache();
        }
    }

    private function findCachedIndex(string $handle): ?SearchIndex
    {
        foreach (SearchIndex::findAll() as $index) {
            if ($index->handle === $handle) {
                return $index;
            }
        }

        return null;
    }
}
