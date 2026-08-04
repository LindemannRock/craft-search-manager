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
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for Closure criteria surviving config metadata sync.
 *
 * @since 5.54.0
 */
final class ConfigClosureCriteriaRebuildTest extends TestCase
{
    private const INDEX_HANDLE = 'sm-test-config-closure-rebuild';
    private const BACKEND_HANDLE = 'sm-test-config-closure-backend';
    private const ENTRY_PREFIX = '__sm_config_closure_';

    /** @var list<int> */
    private array $createdEntryIds = [];

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeOwnedRows();
        $this->insertBackend();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeOwnedRows();
            $this->deleteCreatedEntries();
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testRebuildJobPreservesClosureCriteriaAndIndexesExpectedElements(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $template = Entry::find()
            ->siteId($siteId)
            ->sectionId(Craft::$app->getEntries()->getAllSectionIds())
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();

        if (!$template instanceof Entry || !$template->sectionId || !$template->typeId) {
            self::markTestSkipped('A primary-site section entry is required to seed the rebuild regression.');
        }

        $included = $this->createEntry($template, self::ENTRY_PREFIX . 'included');
        $excluded = $this->createEntry($template, self::ENTRY_PREFIX . 'excluded');
        $criteria = static fn($query) => $query->id((int)$included->id);

        $this->withConfigFileIndices([
            self::INDEX_HANDLE => [
                'name' => 'Config Closure Rebuild Regression',
                'elementType' => Entry::class,
                'siteId' => $siteId,
                'criteria' => $criteria,
                'backend' => self::BACKEND_HANDLE,
                'enabled' => true,
            ],
        ]);
        $this->insertLegacyConfigMetadata($siteId);

        $index = SearchIndex::findByHandle(self::INDEX_HANDLE);
        self::assertNotNull($index);
        self::assertSame($criteria, $index->criteria);

        $closureQuery = Entry::find()
            ->siteId($siteId)
            ->drafts(false)
            ->revisions(false);
        $criteria($closureQuery);
        SearchElementAvailabilityHelper::applyToQuery($closureQuery, Entry::class);

        $closureFilteredCount = (int)$closureQuery->count();
        $expectedCount = $index->getExpectedCount();
        self::assertSame(1, $closureFilteredCount);
        self::assertSame($closureFilteredCount, $expectedCount);

        $backend = new ConfigClosureCriteriaRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        SearchManager::$plugin->getSettings()->enableCacheWarming = false;

        (new RebuildIndexJob([
            'indexHandle' => self::INDEX_HANDLE,
        ]))->execute(Craft::$app->queue);

        $indexedElementIds = $backend->indexedElementIds();
        self::assertSame($expectedCount, count($indexedElementIds));
        self::assertSame([(int)$included->id], $indexedElementIds);
        self::assertNotContains((int)$excluded->id, $indexedElementIds);

        $row = (new Query())
            ->select(['criteria', 'documentCount'])
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => self::INDEX_HANDLE])
            ->one();
        self::assertIsArray($row);
        self::assertNull($row['criteria']);
        self::assertSame($expectedCount, (int)$row['documentCount']);
    }

    private function createEntry(Entry $template, string $title): Entry
    {
        $entry = new Entry();
        $entry->sectionId = (int)$template->sectionId;
        $entry->typeId = (int)$template->typeId;
        $entry->siteId = (int)$template->siteId;
        $entry->authorId = $template->authorId;
        $entry->title = $title;
        $entry->slug = $title . '-' . bin2hex(random_bytes(4));
        $entry->enabled = true;

        $saved = $this->withOnlySearchIndices(
            [],
            static fn(): bool => Craft::$app->getElements()->saveElement($entry, false, true, false),
        );
        self::assertTrue($saved, print_r($entry->getErrors(), true));
        $this->createdEntryIds[] = (int)$entry->id;

        return $entry;
    }

    private function insertLegacyConfigMetadata(int $siteId): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Config Closure Rebuild Regression',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'config',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function deleteCreatedEntries(): void
    {
        foreach ($this->createdEntryIds as $entryId) {
            $entry = Entry::find()
                ->id($entryId)
                ->site('*')
                ->status(null)
                ->one();

            if ($entry instanceof Entry) {
                $this->withOnlySearchIndices(
                    [],
                    static fn(): bool => Craft::$app->getElements()->deleteElement($entry, true),
                );
            }
        }

        $this->createdEntryIds = [];
    }

    private function purgeOwnedRows(): void
    {
        $indexIds = (new Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => self::INDEX_HANDLE])
            ->column();

        if ($indexIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $indexIds])
                ->execute();
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete($this->queueTable(), ['like', 'job', self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => self::BACKEND_HANDLE])
            ->execute();
        SearchIndex::clearCache();
    }

    private function insertBackend(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Config Closure Backend',
            'handle' => self::BACKEND_HANDLE,
            'backendType' => 'file',
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }
}

final class ConfigClosureCriteriaRecordingBackendService extends BackendService
{
    /** @var list<array<string, mixed>> */
    private array $indexedItems = [];

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return new \lindemannrock\searchmanager\backends\FileBackend();
    }

    public function clearIndex(string $indexName): bool
    {
        $this->indexedItems = [];

        return true;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        array_push($this->indexedItems, ...$items);

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
    }

    /** @return list<int> */
    public function indexedElementIds(): array
    {
        $ids = array_map(
            static fn(array $item): int => (int)($item['elementId'] ?? 0),
            $this->indexedItems,
        );
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
