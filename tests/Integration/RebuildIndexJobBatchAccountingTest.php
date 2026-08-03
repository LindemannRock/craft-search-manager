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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\TransformerInterface;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\tests\Stubs\FixedConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for truthful rebuild accounting after transformation failures.
 *
 * @since 5.54.0
 */
final class RebuildIndexJobBatchAccountingTest extends TestCase
{
    private const ALL_FAILED_INDEX = '__sm_rebuild_transform_all_failed';
    private const PARTIAL_FAILED_INDEX = '__sm_rebuild_transform_partial_failed';
    private const CREATION_FAILED_INDEX = '__sm_rebuild_transform_creation_failed';
    private const INTENTIONAL_SKIP_INDEX = '__sm_rebuild_transform_intentional_skip';
    private const EMPTY_INDEX = '__sm_rebuild_transform_empty';
    private const ENTRY_PREFIX = '__sm_rebuild_accounting_';

    /** @var list<int> */
    private array $createdEntryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeOwnedRows();
        RebuildAccountingTransformer::$failingElementIds = [];
        RebuildCreationFailingTransformer::$constructionCount = 0;
        SearchManager::$plugin->getSettings()->enableCacheWarming = false;
        $this->swapPluginComponent(
            'search-manager',
            'configIndexValidator',
            new FixedConfigIndexValidator(new ConfigIndexValidationResult(
                ConfigIndexValidationResult::STATUS_ABSENT,
            )),
        );
    }

    protected function tearDown(): void
    {
        try {
            RebuildAccountingTransformer::$failingElementIds = [];
            RebuildCreationFailingTransformer::$constructionCount = 0;
            $this->purgeOwnedRows();
            $this->deleteCreatedEntries();
        } finally {
            parent::tearDown();
        }
    }

    public function testEveryTransformFailureFailsJobAndStoresZeroCount(): void
    {
        [$first, $second] = $this->createEntries();
        $index = $this->insertIndex(self::ALL_FAILED_INDEX, (int)$first->siteId, 17);
        $index->criteria = static fn($query) => $query->id([(int)$first->id, (int)$second->id]);
        RebuildAccountingTransformer::$failingElementIds = [(int)$first->id, (int)$second->id];

        $backend = new RebuildAccountingBackendService();
        $backend->seedDocument(self::ALL_FAILED_INDEX, ['elementId' => 999, 'title' => 'Old document']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->runRebuildExpectingFailure($index);

        self::assertStringContainsString(self::ALL_FAILED_INDEX, $error->getMessage());
        self::assertStringContainsString('transformation failed', $error->getMessage());
        self::assertCount(1, $backend->clearCallsFor(self::ALL_FAILED_INDEX));
        self::assertSame([], $backend->batchCallsFor(self::ALL_FAILED_INDEX));
        self::assertSame([], $backend->documentsFor(self::ALL_FAILED_INDEX));
        self::assertSame(0, $this->documentCount(self::ALL_FAILED_INDEX));
    }

    public function testPartialTransformFailureIndexesAndCountsOnlySuccessfulElementsButFailsJob(): void
    {
        [$first, $second] = $this->createEntries();
        $index = $this->insertIndex(self::PARTIAL_FAILED_INDEX, (int)$first->siteId, 17);
        $index->criteria = static fn($query) => $query->id([(int)$first->id, (int)$second->id]);
        RebuildAccountingTransformer::$failingElementIds = [(int)$second->id];

        $backend = new RebuildAccountingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->runRebuildExpectingFailure($index);

        self::assertStringContainsString(self::PARTIAL_FAILED_INDEX, $error->getMessage());
        self::assertStringContainsString((string)$second->id, $error->getMessage());
        self::assertCount(1, $backend->clearCallsFor(self::PARTIAL_FAILED_INDEX));
        self::assertCount(1, $backend->batchCallsFor(self::PARTIAL_FAILED_INDEX));
        self::assertSame([(int)$first->id], $backend->indexedElementIds(self::PARTIAL_FAILED_INDEX));
        self::assertSame(1, $this->documentCount(self::PARTIAL_FAILED_INDEX));
    }

    public function testTransformerCreationFailureFailsJobAndStoresZeroCount(): void
    {
        [$entry] = $this->createEntries();
        $index = $this->insertIndex(
            self::CREATION_FAILED_INDEX,
            (int)$entry->siteId,
            17,
            RebuildCreationFailingTransformer::class,
        );
        $index->criteria = static fn($query) => $query->id((int)$entry->id);

        $backend = new RebuildAccountingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $error = $this->runRebuildExpectingFailure($index);

        self::assertStringContainsString(self::CREATION_FAILED_INDEX, $error->getMessage());
        self::assertStringContainsString('could not be created', $error->getMessage());
        self::assertCount(1, $backend->clearCallsFor(self::CREATION_FAILED_INDEX));
        self::assertSame([], $backend->batchCallsFor(self::CREATION_FAILED_INDEX));
        self::assertSame([], $backend->documentsFor(self::CREATION_FAILED_INDEX));
        self::assertSame(0, $this->documentCount(self::CREATION_FAILED_INDEX));
    }

    public function testIntentionalTransformEventSkipRemainsSuccessful(): void
    {
        [$entry] = $this->createEntries();
        $index = $this->insertIndex(self::INTENTIONAL_SKIP_INDEX, (int)$entry->siteId, 17);
        $index->criteria = static fn($query) => $query->id((int)$entry->id);

        $backend = new RebuildAccountingBackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $handler = static function($event): void {
            $event->handled = true;
        };
        SearchManager::$plugin->transformers->on(TransformerService::EVENT_BEFORE_TRANSFORM, $handler);

        try {
            $this->withOnlySearchIndices([$index], static function(): void {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            });
        } finally {
            SearchManager::$plugin->transformers->off(TransformerService::EVENT_BEFORE_TRANSFORM, $handler);
        }

        self::assertCount(1, $backend->clearCallsFor(self::INTENTIONAL_SKIP_INDEX));
        self::assertSame([], $backend->batchCallsFor(self::INTENTIONAL_SKIP_INDEX));
        self::assertSame([], $backend->documentsFor(self::INTENTIONAL_SKIP_INDEX));
        self::assertSame(0, $this->documentCount(self::INTENTIONAL_SKIP_INDEX));
    }

    public function testGenuinelyEmptyCriteriaMatchRemainsSuccessfulEmptyRebuild(): void
    {
        [$first] = $this->createEntries();
        $index = $this->insertIndex(self::EMPTY_INDEX, (int)$first->siteId, 17);
        $index->criteria = static fn($query) => $query->id(-999999999);

        $backend = new RebuildAccountingBackendService();
        $backend->seedDocument(self::EMPTY_INDEX, ['elementId' => 998, 'title' => 'Old document']);
        $this->swapPluginComponent('search-manager', 'backend', $backend);

        $this->withOnlySearchIndices([$index], static function(): void {
            (new RebuildIndexJob())->execute(Craft::$app->queue);
        });

        self::assertCount(1, $backend->clearCallsFor(self::EMPTY_INDEX));
        self::assertSame([], $backend->batchCallsFor(self::EMPTY_INDEX));
        self::assertSame([], $backend->documentsFor(self::EMPTY_INDEX));
        self::assertSame(0, $this->documentCount(self::EMPTY_INDEX));
    }

    /** @return list<Entry> */
    private function createEntries(): array
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
            self::markTestSkipped('A primary-site section entry is required for rebuild accounting coverage.');
        }

        return [
            $this->createEntry($template, self::ENTRY_PREFIX . 'first'),
            $this->createEntry($template, self::ENTRY_PREFIX . 'second'),
        ];
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

    private function insertIndex(
        string $handle,
        int $siteId,
        int $documentCount,
        string $transformerClass = RebuildAccountingTransformer::class,
    ): SearchIndex {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => $handle,
            'handle' => $handle,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'criteria' => null,
            'transformerClass' => $transformerClass,
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
            'documentCount' => $documentCount,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();

        $index = SearchIndex::findByHandle($handle);
        self::assertNotNull($index);

        return $index;
    }

    private function runRebuildExpectingFailure(SearchIndex $index): \RuntimeException
    {
        return $this->withOnlySearchIndices([$index], function(): \RuntimeException {
            try {
                (new RebuildIndexJob())->execute(Craft::$app->queue);
            } catch (\RuntimeException $e) {
                return $e;
            }

            self::fail('Expected the rebuild job to report transformation failures.');
        });
    }

    private function documentCount(string $handle): int
    {
        return (int)(new Query())
            ->select('documentCount')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handle])
            ->scalar();
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
        $handles = [
            self::ALL_FAILED_INDEX,
            self::PARTIAL_FAILED_INDEX,
            self::CREATION_FAILED_INDEX,
            self::INTENTIONAL_SKIP_INDEX,
            self::EMPTY_INDEX,
        ];
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => $handles])
            ->execute();
        foreach ($handles as $handle) {
            Craft::$app->getDb()->createCommand()
                ->delete($this->queueTable(), ['like', 'job', $handle])
                ->execute();
        }
        SearchIndex::clearCache();
    }
}

/**
 * Deterministic transformer used by rebuild accounting coverage.
 *
 * @since 5.54.0
 */
final class RebuildAccountingTransformer implements TransformerInterface
{
    /** @var list<int> */
    public static array $failingElementIds = [];

    public function transform(ElementInterface $element): array
    {
        if (in_array((int)$element->id, self::$failingElementIds, true)) {
            throw new \RuntimeException('Synthetic transformation failure for element ' . $element->id);
        }

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
 * Transformer whose runtime construction fails after rebuild preflight succeeds.
 *
 * @since 5.54.0
 */
final class RebuildCreationFailingTransformer implements TransformerInterface
{
    public static int $constructionCount = 0;

    public function __construct()
    {
        self::$constructionCount++;
        if (self::$constructionCount > 1) {
            throw new \RuntimeException('Synthetic transformer construction failure');
        }
    }

    public function transform(ElementInterface $element): array
    {
        return [];
    }

    public function supports(ElementInterface $element): bool
    {
        return $element instanceof Entry;
    }
}

/**
 * In-memory backend recorder for rebuild accounting coverage.
 *
 * @since 5.54.0
 */
final class RebuildAccountingBackendService extends BackendService
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $documents = [];

    /** @var list<string> */
    private array $clearCalls = [];

    /** @var array<string, list<list<array<string, mixed>>>> */
    private array $batchCalls = [];

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return new FileBackend();
    }

    /** @param array<string, mixed> $document */
    public function seedDocument(string $indexHandle, array $document): void
    {
        $this->documents[$indexHandle][] = $document;
    }

    public function clearIndex(string $indexName): bool
    {
        $this->clearCalls[] = $indexName;
        $this->documents[$indexName] = [];

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    public function batchIndex(string $indexName, array $items): bool
    {
        $this->batchCalls[$indexName][] = $items;
        $this->documents[$indexName] ??= [];
        array_push($this->documents[$indexName], ...$items);

        return true;
    }

    public function clearSearchCache(string $indexName): void
    {
    }

    /** @return list<array<string, mixed>> */
    public function documentsFor(string $indexHandle): array
    {
        return $this->documents[$indexHandle] ?? [];
    }

    /** @return list<string> */
    public function clearCallsFor(string $indexHandle): array
    {
        return array_values(array_filter(
            $this->clearCalls,
            static fn(string $handle): bool => $handle === $indexHandle,
        ));
    }

    /** @return list<list<array<string, mixed>>> */
    public function batchCallsFor(string $indexHandle): array
    {
        return $this->batchCalls[$indexHandle] ?? [];
    }

    /** @return list<int> */
    public function indexedElementIds(string $indexHandle): array
    {
        $ids = array_map(
            static fn(array $item): int => (int)($item['elementId'] ?? 0),
            $this->documentsFor($indexHandle),
        );
        sort($ids);

        return $ids;
    }
}
