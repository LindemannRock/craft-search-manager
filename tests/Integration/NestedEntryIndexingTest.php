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
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\services\Elements;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use yii\base\Event;

/**
 * Regression coverage for nested entries remaining owner-only search content.
 *
 * @since 5.54.0
 */
final class NestedEntryIndexingTest extends TestCase
{
    private const INDEX_HANDLE = '__sm_nested_entry_indexing';
    private const ENTRY_PREFIX = '__sm_nested_entry_';

    /** @var list<int> */
    private array $createdEntryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeOwnedRows();
        SearchManager::$plugin->getSettings()->enableCacheWarming = false;
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeOwnedRows();
            $this->deleteCreatedEntries();
        } finally {
            parent::tearDown();
        }
    }

    public function testCatchAllEntryIndexExcludesNestedEntryFromExpectedCountAndRebuild(): void
    {
        [$owner, $nested] = $this->createOwnerAndNestedEntry();
        $index = $this->insertCatchAllIndex((int)$owner->siteId);

        $unfilteredIds = Entry::find()
            ->siteId((int)$owner->siteId)
            ->status(Entry::STATUS_LIVE)
            ->drafts(false)
            ->revisions(false)
            ->ids();
        self::assertContains((int)$nested->id, array_map('intval', $unfilteredIds));

        $standaloneCount = (int)Entry::find()
            ->siteId((int)$owner->siteId)
            ->status(Entry::STATUS_LIVE)
            ->drafts(false)
            ->revisions(false)
            ->andWhere(['entries.primaryOwnerId' => null])
            ->count();
        self::assertSame($standaloneCount, $index->getExpectedCount());

        $backend = new NestedEntryRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices([$index], static function(): void {
            (new RebuildIndexJob([
                'indexHandle' => self::INDEX_HANDLE,
            ]))->execute(Craft::$app->queue);
        });

        $indexedIds = $backend->indexedElementIds(self::INDEX_HANDLE);
        self::assertCount($standaloneCount, $indexedIds);
        self::assertContains((int)$owner->id, $indexedIds);
        self::assertNotContains((int)$nested->id, $indexedIds);
    }

    public function testNestedSaveEventDeletesStandaloneDocumentWhileOwnerSaveReindexesOwner(): void
    {
        [$owner, $nested] = $this->createOwnerAndNestedEntry();
        $index = $this->insertCatchAllIndex((int)$owner->siteId);
        $backend = new NestedEntryRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->triggerElementEvent(Elements::EVENT_AFTER_SAVE_ELEMENT, $nested, $index);
        $nestedRows = $this->pendingRowsFor($index->handle, (int)$nested->id);
        self::assertCount(1, $nestedRows);
        self::assertSame(PendingSyncRepository::OP_UPSERT, $nestedRows[0]['op']);

        $nestedResult = $this->processor->process($nestedRows);
        self::assertSame([], $nestedResult['failures']);
        self::assertSame([], $backend->indexedElementIds($index->handle));
        self::assertSame([(int)$nested->id], $backend->deletedElementIds($index->handle));

        $this->truncateBuffer();
        $backend->resetCalls();

        $this->triggerElementEvent(Elements::EVENT_AFTER_SAVE_ELEMENT, $owner, $index);
        $ownerRows = $this->pendingRowsFor($index->handle, (int)$owner->id);
        self::assertCount(1, $ownerRows);

        $ownerResult = $this->processor->process($ownerRows);
        self::assertSame([], $ownerResult['failures']);
        self::assertSame([(int)$owner->id], $backend->indexedElementIds($index->handle));
        self::assertSame([], $backend->deletedElementIds($index->handle));
    }

    public function testNestedDeleteEventQueuesIdempotentStandaloneCleanup(): void
    {
        [$owner, $nested] = $this->createOwnerAndNestedEntry();
        $index = $this->insertCatchAllIndex((int)$owner->siteId);
        $backend = new NestedEntryRecordingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->triggerElementEvent(Elements::EVENT_AFTER_DELETE_ELEMENT, $nested, $index);
        $rows = $this->pendingRowsFor($index->handle, (int)$nested->id);
        self::assertCount(1, $rows);
        self::assertSame(PendingSyncRepository::OP_DELETE, $rows[0]['op']);

        $result = $this->processor->process($rows);
        self::assertSame([], $result['failures']);
        self::assertSame([(int)$nested->id], $backend->deletedElementIds($index->handle));
        self::assertSame([], $backend->indexedElementIds($index->handle));
    }

    /** @return array{0: Entry, 1: Entry} */
    private function createOwnerAndNestedEntry(): array
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $ownerTemplate = Entry::find()
            ->siteId($siteId)
            ->sectionId(Craft::$app->getEntries()->getAllSectionIds())
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();
        $matrixField = $this->matrixFieldWithEntryType();

        if (!$ownerTemplate instanceof Entry || !$ownerTemplate->sectionId || !$ownerTemplate->typeId) {
            self::markTestSkipped('A primary-site section entry is required for nested-entry coverage.');
        }
        if (!$matrixField instanceof Matrix || !$matrixField->id || $matrixField->getEntryTypes() === []) {
            self::markTestSkipped('No Matrix field with an entry type is available for a nested-entry fixture.');
        }

        $owner = new Entry();
        $owner->sectionId = (int)$ownerTemplate->sectionId;
        $owner->typeId = (int)$ownerTemplate->typeId;
        $owner->siteId = $siteId;
        $owner->authorId = $ownerTemplate->authorId;
        $owner->title = self::ENTRY_PREFIX . 'owner';
        $owner->slug = self::ENTRY_PREFIX . 'owner-' . bin2hex(random_bytes(4));
        $owner->enabled = true;
        $this->saveEntry($owner);

        $nestedType = $matrixField->getEntryTypes()[0];
        $nested = new Entry();
        $nested->fieldId = (int)$matrixField->id;
        $nested->typeId = (int)$nestedType->id;
        $nested->siteId = $siteId;
        $nested->title = self::ENTRY_PREFIX . 'matrix-block';
        $nested->slug = self::ENTRY_PREFIX . 'matrix-block-' . bin2hex(random_bytes(4));
        $nested->enabled = true;
        $nested->setPrimaryOwner($owner);
        $nested->setOwner($owner);
        $this->saveEntry($nested);

        $reloaded = Entry::find()
            ->id((int)$nested->id)
            ->siteId($siteId)
            ->status(null)
            ->one();
        self::assertInstanceOf(Entry::class, $reloaded);
        self::assertSame((int)$owner->id, $reloaded->getPrimaryOwnerId());
        self::assertNull($reloaded->sectionId);

        return [$owner, $reloaded];
    }

    private function matrixFieldWithEntryType(): ?Matrix
    {
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof Matrix && $field->id && $field->getEntryTypes() !== []) {
                return $field;
            }
        }

        return null;
    }

    private function saveEntry(Entry $entry): void
    {
        $saved = $this->withOnlySearchIndices(
            [],
            static fn(): bool => Craft::$app->getElements()->saveElement($entry, false, true, false),
        );
        self::assertTrue($saved, print_r($entry->getErrors(), true));
        $this->createdEntryIds[] = (int)$entry->id;
    }

    private function insertCatchAllIndex(int $siteId): SearchIndex
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => self::INDEX_HANDLE,
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => null,
            'transformerClass' => NestedEntryRecordingTransformer::class,
            'headingLevels' => null,
            'language' => null,
            'backend' => null,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => json_encode(['*'], JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();

        $index = SearchIndex::findByHandle(self::INDEX_HANDLE);
        self::assertNotNull($index);

        return $index;
    }

    private function triggerElementEvent(string $eventName, Entry $entry, SearchIndex $index): void
    {
        $this->withOnlySearchIndices([$index], static function() use ($eventName, $entry): void {
            Event::trigger(
                Elements::class,
                $eventName,
                new ElementEvent(['element' => $entry]),
            );
        });
    }

    /** @return list<array<string, mixed>> */
    private function pendingRowsFor(string $indexHandle, int $elementId): array
    {
        return (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where([
                'indexHandle' => $indexHandle,
                'elementId' => $elementId,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function deleteCreatedEntries(): void
    {
        foreach (array_reverse($this->createdEntryIds) as $entryId) {
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
            ->delete('{{%queue}}', ['like', 'job', self::INDEX_HANDLE])
            ->execute();
        SearchIndex::clearCache();
    }
}

/**
 * Deterministic transformer for nested-entry rebuild and sync coverage.
 *
 * @since 5.54.0
 */
final class NestedEntryRecordingTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): array
    {
        return [
            'elementId' => (int)$element->id,
            'siteId' => (int)$element->siteId,
            'title' => (string)$element,
            'content' => (string)$element,
            'type' => 'entry',
        ];
    }

    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry;
    }
}

/**
 * In-memory backend recorder for nested-entry rebuild and sync coverage.
 *
 * @since 5.54.0
 */
final class NestedEntryRecordingBackendService extends BackendService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $indexed = [];

    /** @var array<string, list<array{elementId: int, siteId: int}>> */
    private array $deleted = [];

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return new FileBackend();
    }

    public function clearIndex(string $indexName): bool
    {
        $this->indexed[$indexName] = [];
        $this->deleted[$indexName] = [];

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->indexed[$indexName] ??= [];
        array_push($this->indexed[$indexName], ...$items);

        return true;
    }

    /** @param list<array{elementId: int, siteId: int}> $items */
    public function batchDelete(string $indexName, array $items): bool
    {
        $this->deleted[$indexName] ??= [];
        array_push($this->deleted[$indexName], ...$items);

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
    }

    /** @return list<int> */
    public function indexedElementIds(string $indexName): array
    {
        $ids = array_map(
            static fn(array $item): int => (int)($item['elementId'] ?? 0),
            $this->indexed[$indexName] ?? [],
        );
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    public function deletedElementIds(string $indexName): array
    {
        $ids = array_map(
            static fn(array $item): int => (int)$item['elementId'],
            $this->deleted[$indexName] ?? [],
        );
        sort($ids);

        return $ids;
    }

    public function resetCalls(): void
    {
        $this->indexed = [];
        $this->deleted = [];
    }
}
