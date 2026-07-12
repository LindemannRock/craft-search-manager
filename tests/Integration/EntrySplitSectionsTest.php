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
use craft\base\Field;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use lindemannrock\searchmanager\helpers\AutoTransformerSectionSplitter;
use lindemannrock\searchmanager\helpers\HtmlSectionSplitter;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins split-section indexing for AutoTransformer Entry indices.
 *
 * @since 5.53.0
 */
#[CoversClass(AutoTransformerSectionSplitter::class)]
#[CoversClass(HtmlSectionSplitter::class)]
final class EntrySplitSectionsTest extends TestCase
{
    private const INDEX_HANDLE = 'test_entry_split_sections';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::defineRichTextTestClass();
    }

    protected function tearDown(): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);
        parent::tearDown();
    }

    public function testEntryRichTextSplitsPerHeadingAndKeepsOtherFieldTextOnIntro(): void
    {
        $entry = $this->entry([
            'richText' => '<p>Intro prose before headings.</p><h2>Overview</h2><p>Overview body only.</p><h3>Install</h3><p>Install body only.</p><h2>Overview</h2><p>Repeated heading body.</p>',
            'introMarkdown' => 'Markdown intro field text that belongs on intro only.',
        ], [
            $this->richTextField('richText'),
            $this->plainTextField('introMarkdown'),
        ]);

        $documents = $this->documentsFor($entry, [2, 3]);

        self::assertCount(4, $documents);
        self::assertSame(['intro', 'overview', 'install', 'overview-2'], array_column($documents, 'sectionId'));
        self::assertSame([0, 1, 2, 3], array_column($documents, 'sectionIndex'));
        self::assertSame([
            '1087_1_intro',
            '1087_1_overview',
            '1087_1_install',
            '1087_1_overview-2',
        ], array_column($documents, 'backendId'));

        self::assertSame('intro', $documents[0]['sectionType']);
        self::assertSame('Intro prose before headings.', $documents[0]['_bodyClean']);
        self::assertStringContainsString('Markdown intro field text', (string)$documents[0]['content']);

        self::assertSame('heading', $documents[1]['sectionType']);
        self::assertSame('Overview', $documents[1]['sectionTitle']);
        self::assertSame(2, $documents[1]['sectionLevel']);
        self::assertSame('overview', $documents[1]['sectionAnchor']);
        self::assertSame('https://example.test/lorem-ipsum#overview', $documents[1]['sectionUrl']);
        self::assertSame('Overview body only.', $documents[1]['_bodyClean']);
        self::assertSame('Overview', $documents[1]['content']);
        self::assertStringNotContainsString('Markdown intro field text', (string)$documents[1]['content']);
        self::assertArrayNotHasKey('_fields', $documents[1]);
        self::assertArrayNotHasKey('_snippetFields', $documents[1]);

        self::assertSame('Install body only.', $documents[2]['_bodyClean']);
        self::assertSame('Repeated heading body.', $documents[3]['_bodyClean']);
    }

    public function testEntrySplitSectionsNeverSliceAcrossFieldBoundaries(): void
    {
        $entry = $this->entry([
            'firstRichText' => '<h2>First Field</h2><p>Alpha body.</p>',
            'secondRichText' => '<p>Second field intro.</p><h2>Second Field</h2><p>Beta body.</p>',
        ], [
            $this->richTextField('firstRichText'),
            $this->richTextField('secondRichText'),
        ]);

        $documents = $this->documentsFor($entry, [2]);

        self::assertSame(['intro', 'first-field', 'second-field'], array_column($documents, 'sectionId'));
        self::assertSame('Second field intro.', $documents[0]['_bodyClean']);
        self::assertSame('Alpha body.', $documents[1]['_bodyClean']);
        self::assertSame('Beta body.', $documents[2]['_bodyClean']);
        self::assertStringNotContainsString('Beta body.', (string)$documents[1]['_bodyClean']);
        self::assertStringNotContainsString('Alpha body.', (string)$documents[2]['_bodyClean']);
    }

    public function testHeadinglessEntryInSplitIndexStaysNormalRecord(): void
    {
        $entry = $this->entry([
            'richText' => '<p>Plain rich text without selected headings.</p>',
            'introMarkdown' => 'Plain markdown field text.',
        ], [
            $this->richTextField('richText'),
            $this->plainTextField('introMarkdown'),
        ]);

        $pageData = (new AutoTransformer())->transform($entry);
        $documents = $this->documentsFor($entry, [2], $pageData);

        self::assertCount(1, $documents);
        self::assertSame($pageData, $documents[0]);
        self::assertArrayNotHasKey('sectionId', $documents[0]);
        self::assertArrayNotHasKey('_sectionBodyWithCode', $documents[0]);
    }

    public function testFixtureEntryRichTextMirrorIsControlledOnlyByRetrievableFields(): void
    {
        $entry = Entry::find()
            ->id(1087)
            ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
            ->status(null)
            ->one();
        if (!$entry instanceof Entry || !$entry->getFieldLayout()?->getFieldByHandle('richText')) {
            self::markTestSkipped('Requires lorem-ipsum entry 1087 with a richText field.');
        }

        $data = (new AutoTransformer())->transform($entry);
        self::assertArrayHasKey('richText', $data['_fields'] ?? []);
        self::assertArrayHasKey('_bodyClean', $data);

        $this->saveTestIndex(['*']);
        $record = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $data);
        self::assertArrayHasKey('richText', $record['fields'] ?? []);
        self::assertArrayHasKey('richText', $record['_snippetFields'] ?? []);
        self::assertSame($data['_fields']['richText'], $record['fields']['richText']);
        self::assertSame($data['_fields']['richText'], $record['_snippetFields']['richText']);

        $this->saveTestIndex(['intro']);
        $narrowRecord = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $data);
        self::assertArrayNotHasKey('richText', $narrowRecord['fields'] ?? []);
        self::assertArrayHasKey('richText', $narrowRecord['_snippetFields'] ?? []);
    }

    public function testProjectionAndCodeSnippetFieldsMirrorDocsSplitContract(): void
    {
        $entry = $this->entry([
            'richText' => '<p>Intro prose.</p><h2>Code</h2><p>Run the command.</p><pre><code>ddev craft queue/run</code></pre>',
            'introMarkdown' => 'Intro markdown payload.',
        ], [
            $this->richTextField('richText'),
            $this->plainTextField('introMarkdown'),
        ]);

        $this->saveTestIndex(['introMarkdown']);
        $documents = $this->documentsFor($entry, [2]);
        $heading = $documents[1];

        self::assertSame('Run the command.', $heading['_bodyClean']);
        self::assertSame('Run the command. ddev craft queue/run', $heading['_sectionBodyWithCode']);

        $record = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $heading);
        self::assertArrayNotHasKey('sectionBody', $record);
        self::assertArrayNotHasKey('_bodyWithCode', $record);
        self::assertArrayHasKey('_sectionBodyWithCode', $record);
        self::assertArrayNotHasKey('fields', $record);

        $off = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($record, 'ddev', self::INDEX_HANDLE, [
            'snippetMaxLength' => 120,
            'snippetIncludeCodeBlocks' => false,
        ]);
        $on = SearchManager::$plugin->indexedSnippets->prepareHitSnippets($record, 'ddev', self::INDEX_HANDLE, [
            'snippetMaxLength' => 120,
            'snippetIncludeCodeBlocks' => true,
        ]);

        self::assertStringContainsString('Run the command.', (string)$off['snippet']);
        self::assertStringNotContainsString('ddev craft queue/run', (string)$off['snippet']);
        self::assertStringContainsString('ddev craft queue/run', (string)$on['snippet']);
    }

    public function testSplitSectionsSupportUsesAutoTransformerFamilyCapability(): void
    {
        $index = new SearchIndex();
        $index->name = 'Entry Split';
        $index->handle = 'entry-split';
        $index->elementType = Entry::class;
        $index->splitSections = true;

        self::assertTrue($index->usesSplitSections());

        $index->transformerClass = AutoTransformer::class;
        self::assertTrue($index->usesSplitSections());

        $index->transformerClass = EntrySplitSectionsAutoTransformer::class;
        self::assertTrue($index->usesSplitSections());

        $index->transformerClass = self::class;
        $index->validateSplitSectionsSupport('splitSections');
        self::assertContains(
            'Split Sections supports AutoTransformer-family indices, plus SourceDoc indices with DocsManagerTransformer-family transformers.',
            $index->getErrors('splitSections'),
        );
    }

    public function testEntryContentEditReslicesAndDeletesRemovedHeadingOrphans(): void
    {
        $entry = Entry::find()
            ->id(1087)
            ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
            ->status(null)
            ->one();
        if (!$entry instanceof Entry || !$entry->getFieldLayout()?->getFieldByHandle('richText')) {
            self::markTestSkipped('Requires lorem-ipsum entry 1087 with a richText field.');
        }

        $originalRichText = (string)$entry->getFieldValue('richText');
        if (stripos($originalRichText, '<h2') === false && stripos($originalRichText, '<h3') === false) {
            self::markTestSkipped('Entry 1087 richText has no h2/h3 headings.');
        }

        $stub = $this->installStubBackend();
        $this->saveTestIndex(['*']);
        $testIndex = SearchIndex::findByHandle(self::INDEX_HANDLE);
        self::assertNotNull($testIndex);
        $expectedCount = $testIndex->getExpectedCount();
        $testIndex->updateStats(0);

        SearchManager::$plugin->indexing->indexElementNow($entry);
        $firstKeepSet = $this->lastKeepSet($stub->calls);
        self::assertNotEmpty($firstKeepSet);
        self::assertContainsOnly('string', $firstKeepSet);
        $refreshedIndex = SearchIndex::findByHandle(self::INDEX_HANDLE);
        self::assertNotNull($refreshedIndex);
        self::assertSame(
            $expectedCount,
            $refreshedIndex->documentCount,
            'Direct split-section indexing must refresh documentCount from the expected element count.',
        );

        $entry->setFieldValue('richText', '<p>Edited intro text.</p><h2>Replacement Heading</h2><p>Replacement body text.</p>');
        SearchManager::$plugin->indexing->indexElementNow($entry);
        $secondKeepSet = $this->lastKeepSet($stub->calls);

        self::assertContains(SearchHitIdentityHelper::sectionDocumentId((int)$entry->id, (int)$entry->siteId, 'replacement-heading'), $secondKeepSet);
        self::assertNotSame($firstKeepSet, $secondKeepSet);
        foreach ($firstKeepSet as $oldKey) {
            if (str_ends_with($oldKey, '_intro')) {
                continue;
            }
            self::assertNotContains($oldKey, $secondKeepSet);
        }
    }

    /**
     * @param array<string, mixed> $fieldValues
     * @param list<Field> $fields
     */
    private function entry(array $fieldValues, array $fields): EntrySplitSectionsEntry
    {
        $entry = new EntrySplitSectionsEntry();
        $entry->id = 1087;
        $entry->siteId = 1;
        $entry->title = 'Lorem Ipsum';
        $entry->slug = 'lorem-ipsum';
        $entry->enabled = true;
        $entry->testUrl = 'https://example.test/lorem-ipsum';
        $entry->testSection = new Section(['name' => 'Test Section', 'handle' => 'test', 'type' => Section::TYPE_CHANNEL]);
        $entry->testAncestors = new ElementCollection();
        $entry->setTestFieldLayout($this->fieldLayout($fields));
        $entry->setTestFieldValues($fieldValues);

        return $entry;
    }

    /**
     * @param array<string, mixed>|null $pageData
     * @return list<array<string, mixed>>
     */
    private function documentsFor(Entry $entry, array $headingLevels, ?array $pageData = null): array
    {
        $index = new SearchIndex();
        $index->name = 'Entry Split';
        $index->handle = self::INDEX_HANDLE;
        $index->elementType = Entry::class;
        $index->splitSections = true;
        $index->headingLevels = $headingLevels;

        $method = new \ReflectionMethod(SearchManager::$plugin->indexing, 'documentsForIndex');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> */
        return $method->invoke(SearchManager::$plugin->indexing, $index, $entry, $pageData ?? (new AutoTransformer())->transform($entry));
    }

    /**
     * @param list<string> $retrievableFields
     */
    private function saveTestIndex(array $retrievableFields): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Test Entry Split Sections',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => null,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => json_encode([2, 3], JSON_THROW_ON_ERROR),
            'language' => null,
            'backend' => 'mysql',
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 1,
            'retrievableFields' => json_encode(SearchIndex::normalizeRetrievableFields($retrievableFields), JSON_THROW_ON_ERROR),
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        SearchIndex::clearCache();
    }

    /**
     * @param list<array{method: string, indexName: string, items?: array<int, array<string, mixed>>}> $calls
     * @return list<string>
     */
    private function lastKeepSet(array $calls): array
    {
        $orphanCalls = array_values(array_filter($calls, static fn(array $call): bool => $call['method'] === 'deleteOrphanDocuments'));
        self::assertNotEmpty($orphanCalls);
        $last = $orphanCalls[array_key_last($orphanCalls)];

        /** @var list<string> */
        return $last['items'][0]['keepBackendIds'] ?? [];
    }

    /**
     * @param list<Field> $fields
     */
    private function fieldLayout(array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => EntrySplitSectionsEntry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements(array_map(
            static fn(Field $field): CustomField => new CustomField($field),
            $fields,
        ));
        $layout->setTabs([$tab]);

        return $layout;
    }

    private function richTextField(string $handle): Field
    {
        $fieldClass = 'craft\\ckeditor\\Field';

        return new $fieldClass(['handle' => $handle, 'searchable' => true]);
    }

    private function plainTextField(string $handle): Field
    {
        return new \craft\fields\PlainText(['handle' => $handle, 'searchable' => true]);
    }

    private function deleteTestIndexByHandle(string $handle): void
    {
        $ids = (new \craft\db\Query())
            ->select('id')
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handle])
            ->column();

        if ($ids !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])
                ->execute();
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => $handle])
            ->execute();
        SearchIndex::clearCache();
    }

    private static function defineRichTextTestClass(): void
    {
        if (class_exists('craft\\ckeditor\\Field')) {
            return;
        }

        eval(<<<'PHP'
namespace craft\ckeditor;

class Field extends \craft\base\Field
{
}
PHP);
    }
}

final class EntrySplitSectionsEntry extends Entry
{
    public Section $testSection;

    /**
     * @var ElementCollection<int, ElementInterface>
     */
    public ElementCollection $testAncestors;

    public string $testUrl = '';

    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

    public function getSection(): ?Section
    {
        return $this->testSection;
    }

    public function getAncestors(?int $dist = null): ElementQueryInterface|ElementCollection
    {
        return $this->testAncestors;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->testFieldLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        return $this->testFieldValues[$fieldHandle] ?? null;
    }

    public function setTestFieldLayout(FieldLayout $fieldLayout): void
    {
        $this->testFieldLayout = $fieldLayout;
    }

    /**
     * @param array<string, mixed> $fieldValues
     */
    public function setTestFieldValues(array $fieldValues): void
    {
        $this->testFieldValues = $fieldValues;
    }

    public function getUrl(): ?string
    {
        return $this->testUrl;
    }

    public function getStatus(): string
    {
        return self::STATUS_LIVE;
    }
}

final class EntrySplitSectionsAutoTransformer extends AutoTransformer
{
}
