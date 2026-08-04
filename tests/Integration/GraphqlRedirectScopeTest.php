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
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit findings #447-#452.
 *
 * @since 5.54.0
 */
final class GraphqlRedirectScopeTest extends TestCase
{
    private const DATABASE_INDEX_HANDLE = '__sm_audit_batch8_database';
    private const CONFIG_INDEX_HANDLE = '__sm_audit_batch8_config';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseIndex();
    }

    protected function tearDown(): void
    {
        $this->purgeRows();
        $this->setConfigCache($this->originalConfigCache);
        SearchIndex::clearCache();

        parent::tearDown();
    }

    public function testGraphqlMultiSiteMergeKeepsFirstRedirectInScopeOrder(): void
    {
        $stub = $this->installStubBackend();
        $stub->searchResponsesBySiteId = [
            20 => ['hits' => [], 'total' => 0, 'redirect' => '/first-site'],
            10 => ['hits' => [], 'total' => 0, 'redirect' => '/second-site'],
        ];

        $method = new \ReflectionMethod(SearchResolver::class, 'runSearch');
        $method->setAccessible(true);
        $result = $method->invoke(null, [self::DATABASE_INDEX_HANDLE], 'redirect me', [
            'limit' => 20,
            'offset' => 0,
        ], [20, 10]);

        self::assertIsArray($result);
        self::assertSame('/first-site', $result['redirect']);
        self::assertCount(2, $stub->callsFor('search'));
    }

    private function insertDatabaseIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 8 Database Index',
            'handle' => self::DATABASE_INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 9,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => [
                self::DATABASE_INDEX_HANDLE,
                self::CONFIG_INDEX_HANDLE,
            ]])
            ->execute();
        SearchIndex::clearCache();
    }
}
