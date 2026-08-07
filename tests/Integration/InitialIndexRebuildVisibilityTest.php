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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Verifies that initial index rebuilds see resources created by another process.
 *
 * @since 5.55.0
 */
final class InitialIndexRebuildVisibilityTest extends TestCase
{
    private string $backendHandle;

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->backendHandle = $this->nextTestMarker('sm_initial_rebuild_', 'backend');
        $this->insertMySqlBackend();

        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = false;
        $settings->enableAutocompleteCache = false;
        $settings->enableCacheWarming = false;
    }

    protected function tearDown(): void
    {
        try {
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testDatabaseIndexQueuedAgainstWarmWorkerBecomesSearchable(): void
    {
        [$entry, $term, $siteId, $sectionHandle] = $this->createSearchableEntry('database');
        $handle = $this->nextTestMarker('sm_initial_rebuild_', 'database_index');
        $staleWorkerIndices = $this->primeWorkerWithoutHandle($handle);

        $index = new SearchIndex([
            'name' => 'Initial database index visibility',
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => ['sections' => [$sectionHandle]],
            'backend' => $this->backendHandle,
            'enabled' => true,
        ]);

        self::assertTrue($index->validate(), json_encode($index->getErrors()));
        self::assertTrue($index->save(), json_encode($index->getErrors()));
        self::assertTrue($index->wasRebuildQueuedOnLastSave());

        $this->restoreWarmWorkerIndices($staleWorkerIndices);
        $this->executeQueuedRebuild($handle);
        $this->assertIndexAvailableToSearchAndAutocomplete($handle, $entry, $term, $siteId);
    }

    public function testFirstMaterializedConfigIndexQueuedAgainstWarmWorkerBecomesSearchable(): void
    {
        [$entry, $term, $siteId, $sectionHandle] = $this->createSearchableEntry('config');
        $handle = $this->nextTestMarker('sm_initial_rebuild_', 'config_index');
        $staleWorkerIndices = $this->primeWorkerWithoutHandle($handle);

        $this->withConfigFileIndices([
            $handle => [
                'name' => 'Initial config index visibility',
                'elementType' => Entry::class,
                'siteId' => $siteId,
                'criteria' => ['sections' => [$sectionHandle]],
                'backend' => $this->backendHandle,
                'enabled' => true,
            ],
        ]);

        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);
        self::assertNull($index->id, 'The config index must begin without materialized metadata.');
        self::assertTrue($index->syncMetadataFromConfig(), json_encode($index->getErrors()));
        self::assertNotNull($index->id);

        $this->restoreWarmWorkerIndices($staleWorkerIndices);
        $this->executeQueuedRebuild($handle);
        $this->assertIndexAvailableToSearchAndAutocomplete($handle, $entry, $term, $siteId);
    }

    /**
     * @return array{Entry, string, int, string}
     */
    private function createSearchableEntry(string $kind): array
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $template = Entry::find()
            ->siteId($siteId)
            ->sectionId(Craft::$app->getEntries()->getAllSectionIds())
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();

        if (!$template instanceof Entry || !$template->sectionId || !$template->typeId || $template->getSection() === null) {
            self::markTestSkipped('A primary-site section entry is required for initial rebuild visibility coverage.');
        }

        $term = 'visibility' . $kind . bin2hex(random_bytes(5));
        $entry = new Entry();
        $entry->sectionId = (int)$template->sectionId;
        $entry->typeId = (int)$template->typeId;
        $entry->siteId = (int)$template->siteId;
        $entry->authorId = $template->authorId;
        $entry->title = $term;
        $entry->slug = $term . '-' . bin2hex(random_bytes(4));
        $entry->enabled = true;

        $saved = $this->withOnlySearchIndices(
            [],
            fn(): \craft\base\ElementInterface => $this->saveTestElement($entry, false, true, false),
        );
        self::assertSame($entry, $saved);

        return [$entry, $term, $siteId, $template->getSection()->handle];
    }

    /** @return list<SearchIndex> */
    private function primeWorkerWithoutHandle(string $handle): array
    {
        SearchIndex::clearCache();
        $indices = SearchIndex::findAll();
        self::assertNotContains($handle, array_map(
            static fn(SearchIndex $index): string => $index->handle,
            $indices,
        ));

        return $indices;
    }

    /** @param list<SearchIndex> $indices */
    private function restoreWarmWorkerIndices(array $indices): void
    {
        $cache = new \ReflectionProperty(SearchIndex::class, 'allCache');
        $cache->setValue(null, $indices);

        $expiresAt = new \ReflectionProperty(SearchIndex::class, 'allCacheExpiresAt');
        $expiresAt->setValue(null, microtime(true) + 5.0);
    }

    private function executeQueuedRebuild(string $handle): void
    {
        $jobId = (new Query())
            ->select(['id'])
            ->from($this->queueTable())
            ->where(['like', 'job', 'RebuildIndexJob'])
            ->andWhere(['like', 'job', $handle])
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->scalar();

        self::assertNotFalse($jobId, 'The initial rebuild must be serialized to the isolated queue.');
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(\craft\queue\Queue::class, $queue);
        self::assertTrue($queue->executeJob((string)$jobId));
    }

    private function assertIndexAvailableToSearchAndAutocomplete(
        string $handle,
        Entry $entry,
        string $term,
        int $siteId,
    ): void {
        $rebuilt = SearchIndex::findByHandle($handle);
        self::assertNotNull($rebuilt);
        self::assertNotNull($rebuilt->lastIndexed);
        self::assertGreaterThan(0, $rebuilt->documentCount);

        $result = SearchManager::$plugin->backend->search($handle, $term, [
            'siteId' => $siteId,
            'skipAnalytics' => true,
        ]);
        self::assertContains((int)$entry->id, array_map(
            static fn(array $hit): int => (int)($hit['elementId'] ?? 0),
            $result['hits'] ?? [],
        ));

        $suggestions = SearchManager::$plugin->autocomplete->suggest(substr($term, 0, -2), $handle, [
            'siteId' => $siteId,
            'minLength' => 2,
            'fuzzy' => false,
        ]);
        self::assertContains($term, $suggestions);
    }

    private function insertMySqlBackend(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Initial rebuild visibility backend',
            'handle' => $this->backendHandle,
            'backendType' => 'mysql',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
    }
}
