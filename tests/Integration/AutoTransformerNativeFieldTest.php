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
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Addresses;
use craft\fields\Assets;
use craft\fields\ButtonGroup;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\ContentBlock;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Icon;
use craft\fields\Json;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Time;
use craft\fields\Url;
use craft\fields\Users;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use lindemannrock\searchmanager\helpers\NativeFieldKeywordHelper;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\log\Logger;

/**
 * Locks AutoTransformer's first Craft-native searchable-field contract.
 *
 * @since 5.53.0
 */
#[CoversClass(AutoTransformer::class)]
#[CoversClass(NativeFieldKeywordHelper::class)]
final class AutoTransformerNativeFieldTest extends TestCase
{
    #[DataProvider('nativeFieldKeywordContracts')]
    public function testNativeFieldKeywordContractIsRecorded(string $fieldClass, string $behavior): void
    {
        self::assertTrue(class_exists($fieldClass));
        self::assertTrue(is_a($fieldClass, Field::class, true));
        self::assertNotSame('', $behavior);
    }

    public function testSearchablePlainTextBodyFieldUsesDedicatedBodySource(): void
    {
        $data = $this->transformWithField(
            new PlainText(['handle' => 'body', 'searchable' => true]),
            'Plain text needle',
        );

        self::assertSame('Plain text needle', $data['_bodyClean'] ?? null);
        self::assertSame('Plain text needle', $data['_fields']['body'] ?? null);
        self::assertStringNotContainsString('Plain text needle', $data['content']);
    }

    public function testEntryDocumentTypeUsesStableKindAndSeparateSectionMetadata(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        self::assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [, $entry] = $pair;
        self::assertInstanceOf(Entry::class, $entry);

        $section = $entry->getSection();
        self::assertNotNull($section);

        $data = (new AutoTransformer())->transform($entry);

        self::assertSame('entry', $data['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $data);
        self::assertSame($section->name, $data['entrySection'] ?? null);
        self::assertSame($section->handle, $data['entrySectionHandle'] ?? null);
        self::assertSame($section->type, $data['entrySectionType'] ?? null);
        self::assertArrayNotHasKey('section', $data);
        self::assertArrayNotHasKey('sectionHandle', $data);
        self::assertArrayNotHasKey('sectionType', $data);
        self::assertNotSame($section->handle, $data['type']);
        self::assertNotSame($section->type, $data['type']);
    }

    public function testEntrySearchableAttributesDoNotCreateUnderscoreMirrorFields(): void
    {
        $pair = $this->findWorkingIndexAndElement();
        self::assertNotNull($pair, 'Test install must have at least one enabled Entry index with a matching element.');

        [, $entry] = $pair;
        self::assertInstanceOf(Entry::class, $entry);

        $data = (new AutoTransformer())->transform($entry);

        self::assertSame($entry->title, $data['title'] ?? null);
        self::assertSame($entry->slug, $data['slug'] ?? null);
        self::assertStringContainsString($entry->title, $data['content']);
        if ($entry->slug !== '') {
            self::assertStringContainsString($entry->slug, $data['content']);
        }

        self::assertArrayNotHasKey('_title', $data);
        self::assertArrayNotHasKey('_slug', $data);
    }

    public function testNonSearchablePlainTextFieldIsExcluded(): void
    {
        $data = $this->transformWithField(
            new PlainText(['handle' => 'body', 'searchable' => false]),
            'Hidden plain text needle',
        );

        self::assertArrayNotHasKey('body', $data);
        self::assertArrayNotHasKey('body', $data['_fields'] ?? []);
        self::assertStringNotContainsString('Hidden plain text needle', $data['content']);
    }

    public function testSearchableOptionFieldUsesCraftValueAndLabelKeywords(): void
    {
        $field = new Dropdown([
            'handle' => 'topic',
            'searchable' => true,
            'options' => [
                ['label' => 'Friendly Label', 'value' => 'canonical-value', 'default' => false],
            ],
        ]);

        $data = $this->transformWithField($field, $field->normalizeValue('canonical-value', null));

        self::assertSame('canonical-value Friendly Label', $data['_fields']['topic'] ?? null);
        self::assertStringContainsString('canonical-value Friendly Label', $data['content']);
    }

    public function testNonSearchableOptionFieldIsExcluded(): void
    {
        $field = new Dropdown([
            'handle' => 'topic',
            'searchable' => false,
            'options' => [
                ['label' => 'Hidden Friendly Label', 'value' => 'hidden-canonical', 'default' => false],
            ],
        ]);

        $data = $this->transformWithField($field, $field->normalizeValue('hidden-canonical', null));

        self::assertArrayNotHasKey('topic', $data);
        self::assertArrayNotHasKey('topic', $data['_fields'] ?? []);
        self::assertStringNotContainsString('hidden-canonical', $data['content']);
        self::assertStringNotContainsString('Hidden Friendly Label', $data['content']);
    }

    public function testSearchableRelationFieldUsesCraftRelatedElementKeywords(): void
    {
        $related = new Entry();
        $related->id = 456;
        $related->siteId = 1;
        $related->title = 'Related entry needle';

        $data = $this->transformWithField(
            new Entries(['handle' => 'relatedEntries', 'searchable' => true]),
            new ElementCollection([$related]),
        );

        self::assertSame('Related entry needle', $data['_fields']['relatedEntries'] ?? null);
        self::assertStringContainsString('Related entry needle', $data['content']);
    }

    public function testNonSearchableRelationFieldIsExcluded(): void
    {
        $related = new Entry();
        $related->id = 456;
        $related->siteId = 1;
        $related->title = 'Hidden related entry needle';

        $data = $this->transformWithField(
            new Entries(['handle' => 'relatedEntries', 'searchable' => false]),
            new ElementCollection([$related]),
        );

        self::assertArrayNotHasKey('relatedEntries', $data);
        self::assertArrayNotHasKey('relatedEntries', $data['_fields'] ?? []);
        self::assertStringNotContainsString('Hidden related entry needle', $data['content']);
    }

    public function testSearchableTableFieldDelegatesToCraftKeywordsAndSkipsDateTimeCells(): void
    {
        $data = $this->transformWithField(
            new Table([
                'handle' => 'specs',
                'searchable' => true,
                'columns' => [
                    'col1' => ['heading' => 'Name', 'handle' => 'name', 'type' => 'singleline'],
                    'col2' => ['heading' => 'Date', 'handle' => 'date', 'type' => 'date'],
                ],
            ]),
            [
                [
                    'col1' => 'Table text needle',
                    'col2' => new \DateTime('2026-07-09 12:00:00'),
                ],
            ],
        );

        self::assertSame('Table text needle', $data['_fields']['specs'] ?? null);
        self::assertStringContainsString('Table text needle', $data['content']);
        self::assertStringNotContainsString('2026-07-09', $data['content']);
    }

    public function testNonSearchableTableFieldIsExcluded(): void
    {
        $data = $this->transformWithField(
            new Table([
                'handle' => 'specs',
                'searchable' => false,
                'columns' => [
                    'col1' => ['heading' => 'Name', 'handle' => 'name', 'type' => 'singleline'],
                ],
            ]),
            [
                ['col1' => 'Hidden table text needle'],
            ],
        );

        self::assertArrayNotHasKey('specs', $data);
        self::assertArrayNotHasKey('specs', $data['_fields'] ?? []);
        self::assertStringNotContainsString('Hidden table text needle', $data['content']);
    }

    public function testSearchableMatrixFieldDelegatesToNestedSearchableFieldKeywords(): void
    {
        $data = $this->transformWithField(
            new SearchManagerMatrixKeywordField([
                'handle' => 'matrixContent',
                'searchable' => true,
                'nestedFields' => [
                    new PlainText(['handle' => 'searchableNested', 'searchable' => true]),
                    new PlainText(['handle' => 'hiddenNested', 'searchable' => false]),
                ],
                'nestedValues' => [
                    'searchableNested' => 'Matrix nested searchable needle',
                    'hiddenNested' => 'Matrix nested hidden needle',
                ],
            ]),
            'unused matrix value',
        );

        self::assertSame('Nested Block Title Matrix nested searchable needle', $data['_fields']['matrixContent'] ?? null);
        self::assertStringContainsString('Matrix nested searchable needle', $data['content']);
        self::assertStringNotContainsString('Matrix nested hidden needle', $data['content']);
    }

    public function testMatrixContainerFlattensNestedEntryFieldsIntoOwnerDocument(): void
    {
        $nestedEntry = $this->nestedElement(
            'Matrix nested entry',
            'Matrix owner-searchable flattening needle',
        );

        $data = $this->transformWithField(
            new Matrix(['handle' => 'matrixContainer', 'searchable' => true]),
            new ElementCollection([$nestedEntry]),
        );

        self::assertStringContainsString('Matrix owner-searchable flattening needle', $data['content']);
        self::assertStringContainsString(
            'Matrix owner-searchable flattening needle',
            SearchRecordProjectionHelper::localMatchingText($data),
        );
        self::assertStringContainsString(
            'Matrix owner-searchable flattening needle',
            $data['_fields']['matrixContainer'] ?? '',
        );
    }

    public function testContentBlockContainerFlattensSingleNestedElementIntoOwnerDocument(): void
    {
        $contentBlock = $this->nestedElement(
            'Content Block nested element',
            'Content Block owner-searchable flattening needle',
        );

        $data = $this->transformWithField(
            new ContentBlock(['handle' => 'contentBlockContainer', 'searchable' => true]),
            $contentBlock,
        );

        self::assertStringContainsString('Content Block owner-searchable flattening needle', $data['content']);
        self::assertStringContainsString(
            'Content Block owner-searchable flattening needle',
            SearchRecordProjectionHelper::localMatchingText($data),
        );
        self::assertStringContainsString(
            'Content Block owner-searchable flattening needle',
            $data['_fields']['contentBlockContainer'] ?? '',
        );
    }

    public function testCkeditorContainerKeepsMarkupAndFlattensEmbeddedEntryIntoOwnerDocument(): void
    {
        if (!class_exists('craft\\ckeditor\\Field') || !class_exists('craft\\ckeditor\\data\\FieldData')) {
            self::markTestSkipped('CKEditor is not installed, so an embedded-entry field fixture cannot be created.');
        }

        $entryType = Craft::$app->getEntries()->getAllEntryTypes()[0] ?? null;
        if ($entryType === null) {
            self::markTestSkipped('CKEditor embedded-entry coverage requires one Craft entry type.');
        }

        /** @var \craft\ckeditor\Field $field */
        $field = new \craft\ckeditor\Field([
            'handle' => 'ckeditorContainer',
            'searchable' => true,
        ]);
        $field->setEntryTypes([$entryType]);

        $fieldValue = new \craft\ckeditor\data\FieldData(
            '<p>CKEditor markup-searchable needle</p><craft-entry data-entry-id="987654321">&nbsp;</craft-entry>',
            1,
            $field,
        );
        $chunks = $fieldValue->getChunks(false);
        $fieldValue->loadEntries();

        $embeddedEntry = $this->nestedEntry(
            'CKEditor embedded entry',
            'CKEditor embedded owner-searchable flattening needle',
        );
        foreach ($chunks as $chunk) {
            if (is_a($chunk, 'craft\\ckeditor\\data\\Entry') && method_exists($chunk, 'setEntry')) {
                $chunk->setEntry($embeddedEntry);
            }
        }

        $data = $this->transformWithField($field, $fieldValue);

        self::assertStringContainsString('CKEditor markup-searchable needle', $data['_bodyClean'] ?? '');
        self::assertStringContainsString(
            'CKEditor embedded owner-searchable flattening needle',
            $data['_bodyClean'] ?? '',
        );
        self::assertStringContainsString(
            'CKEditor embedded owner-searchable flattening needle',
            SearchRecordProjectionHelper::localMatchingText($data),
        );
        self::assertStringContainsString(
            'CKEditor embedded owner-searchable flattening needle',
            $data['_fields']['ckeditorContainer'] ?? '',
        );
    }

    public function testDateAndTimeFieldsRemainEmptyByCraftNativeKeywordBehavior(): void
    {
        $data = $this->transformWithFields([
            new Date(['handle' => 'eventDate', 'searchable' => true]),
            new Time(['handle' => 'eventTime', 'searchable' => true]),
        ], [
            'eventDate' => new \DateTimeImmutable('2026-07-09 12:00:00'),
            'eventTime' => new \DateTimeImmutable('2026-07-09 14:30:00'),
        ]);

        self::assertArrayNotHasKey('eventDate', $data);
        self::assertArrayNotHasKey('eventTime', $data);
        self::assertStringNotContainsString('2026-07-09', $data['content']);
        self::assertStringNotContainsString('14:30', $data['content']);
    }

    public function testAutoTransformerDoesNotHardcodePluginFieldImplementations(): void
    {
        $source = $this->readPluginFile('src/transformers/AutoTransformer.php');

        self::assertStringNotContainsString('iconmanager', $source);
        self::assertStringNotContainsString('IconManager', $source);
        self::assertStringNotContainsString('IconManagerField', $source);
        self::assertStringNotContainsString('lindemannrock\\iconmanager', $source);
    }

    public function testAutoTransformerIndexesCompactCategoryRelationMetadata(): void
    {
        $source = $this->readPluginFile('src/transformers/AutoTransformer.php');
        $helperSource = $this->readPluginFile('src/helpers/SearchCategoryRelationMetadataHelper.php');

        self::assertStringContainsString('SearchCategoryRelationMetadataHelper::categoryIds($element)', $source);
        self::assertStringContainsString("\$data['_categoryIds'] = \$categoryIds;", $source);
        self::assertStringContainsString('if (!$field instanceof Categories', $helperSource);
        self::assertStringContainsString('$element->getFieldValue($field->handle)', $helperSource);
        self::assertStringContainsString('sort($categoryIds);', $helperSource);
    }

    public function testThrowingSearchableAttributeLogsSafeContextAndKeepsHealthyAttribute(): void
    {
        $element = $this->testElement();
        $element->setTestAttributeKeywords([
            'a5ThrowingAttribute' => 'A5_ATTRIBUTE_VALUE_SENTINEL',
            'a5HealthyAttribute' => 'Healthy attribute needle',
        ]);
        $element->setThrowingAttributes(['a5ThrowingAttribute']);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Healthy attribute needle', $data['content']);
        $warning = $this->warningForBoundary($warnings, 'searchable-attribute');
        self::assertStringContainsString('"elementId":123', $warning);
        self::assertStringContainsString('"elementType":"' . addslashes(SearchManagerNativeFieldTestElement::class), $warning);
        self::assertStringContainsString('"attribute":"a5ThrowingAttribute"', $warning);
        self::assertStringContainsString('"exceptionClass":"RuntimeException"', $warning);
        self::assertStringNotContainsString('A5_ATTRIBUTE_VALUE_SENTINEL', $warning);
    }

    public function testThrowingTopLevelFieldLogsSafeContextAndKeepsHealthyField(): void
    {
        $element = $this->testElement();
        $throwing = new PlainText(['handle' => 'throwingTopLevel', 'searchable' => true]);
        $healthy = new PlainText(['handle' => 'healthyTopLevel', 'searchable' => true]);
        $element->setTestFieldLayout($this->fieldLayout([$throwing, $healthy]));
        $element->setTestFieldValues([
            'throwingTopLevel' => 'A5_TOP_LEVEL_VALUE_SENTINEL',
            'healthyTopLevel' => 'Healthy top-level field needle',
        ]);
        $element->setThrowingFieldHandles(['throwingTopLevel']);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Healthy top-level field needle', $data['content']);
        $warning = $this->warningForBoundary($warnings, 'field-materialization');
        self::assertStringContainsString('"fieldHandle":"throwingTopLevel"', $warning);
        self::assertStringContainsString('"fieldClass":"' . addslashes(PlainText::class), $warning);
        self::assertStringNotContainsString('A5_TOP_LEVEL_VALUE_SENTINEL', $warning);
    }

    public function testThrowingNestedFieldLogsSafeContextAndKeepsHealthyNestedField(): void
    {
        $nested = $this->nestedElement('Nested warning fixture', 'Healthy nested field needle');
        $throwingNested = new PlainText(['handle' => 'throwingNested', 'searchable' => true]);
        $healthyNested = new PlainText(['handle' => 'nestedText', 'searchable' => true]);
        $nested->setTestFieldLayout($this->fieldLayout([$throwingNested, $healthyNested]));
        $nested->setTestFieldValues([
            'throwingNested' => 'A5_NESTED_VALUE_SENTINEL',
            'nestedText' => 'Healthy nested field needle',
        ]);
        $nested->setThrowingFieldHandles(['throwingNested']);
        $element = $this->testElement();
        $element->setTestFieldLayout($this->fieldLayout([
            new Matrix(['handle' => 'matrixContainer', 'searchable' => true]),
        ]));
        $element->setTestFieldValues([
            'matrixContainer' => new ElementCollection([$nested]),
        ]);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Healthy nested field needle', $data['content']);
        $warning = $this->warningForBoundary($warnings, 'nested-field-value');
        self::assertStringContainsString('"fieldHandle":"matrixContainer.throwingNested"', $warning);
        self::assertStringContainsString('"elementId":789', $warning);
        self::assertStringNotContainsString('A5_NESTED_VALUE_SENTINEL', $warning);
    }

    public function testThrowingRelationMaterializationLogsSafeContextAndIndexingStillSucceeds(): void
    {
        $element = $this->testElement();
        $relation = new A5ThrowingRelationField(['handle' => 'throwingRelation', 'searchable' => true]);
        $healthy = new PlainText(['handle' => 'healthySibling', 'searchable' => true]);
        $element->setTestFieldLayout($this->fieldLayout([$relation, $healthy]));
        $element->setTestFieldValues([
            'throwingRelation' => new A5ThrowingRelationCollection(),
            'healthySibling' => 'Healthy relation sibling needle',
        ]);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Healthy relation sibling needle', $data['content']);
        $warning = $this->warningForBoundary($warnings, 'field-materialization');
        self::assertStringContainsString('"fieldHandle":"throwingRelation"', $warning);
        self::assertStringContainsString('"exceptionClass":"RuntimeException"', $warning);
        self::assertStringNotContainsString('A5_RELATION_CONTENT_SENTINEL', $warning);
    }

    public function testThrowingContainerMaterializationLogsSafeContextAndKeepsHealthyField(): void
    {
        $element = $this->testElement();
        $container = new Matrix(['handle' => 'throwingContainer', 'searchable' => true]);
        $healthy = new PlainText(['handle' => 'healthyContainerSibling', 'searchable' => true]);
        $element->setTestFieldLayout($this->fieldLayout([$container, $healthy]));
        $element->setTestFieldValues([
            'throwingContainer' => new A5ThrowingRelationCollection(),
            'healthyContainerSibling' => 'Healthy container sibling needle',
        ]);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Healthy container sibling needle', $data['content']);
        $warning = $this->warningForBoundary($warnings, 'container-materialization');
        self::assertStringContainsString('"fieldHandle":"throwingContainer"', $warning);
        self::assertStringContainsString('"fieldClass":"' . addslashes(Matrix::class), $warning);
        self::assertStringNotContainsString('A5_RELATION_CONTENT_SENTINEL', $warning);
    }

    public function testBestEffortExtractionRemainsSuccessfulTransformation(): void
    {
        $element = $this->testElement();
        $element->setTestFieldLayout($this->fieldLayout([
            new PlainText(['handle' => 'throwingButOptional', 'searchable' => true]),
            new PlainText(['handle' => 'healthyAcceptedSibling', 'searchable' => true]),
        ]));
        $element->setTestFieldValues([
            'throwingButOptional' => 'A5_SUCCESS_VALUE_SENTINEL',
            'healthyAcceptedSibling' => 'Healthy accepted sibling needle',
        ]);
        $element->setThrowingFieldHandles(['throwingButOptional']);

        $result = SearchManager::$plugin->transformers->transformWithResult(
            $element,
            '__sm_a5_best_effort',
            AutoTransformer::class,
        );

        self::assertSame('transformed', $result['status']);
        self::assertIsArray($result['data']);
        self::assertStringContainsString('Healthy accepted sibling needle', (string)$result['data']['content']);
    }

    public function testNormalExtractionEmitsNoFailureWarningAndCommerceKeepsAutoInheritance(): void
    {
        $element = $this->testElement();
        $element->setTestFieldLayout($this->fieldLayout([
            new PlainText(['handle' => 'normalField', 'searchable' => true]),
        ]));
        $element->setTestFieldValues(['normalField' => 'Normal extraction needle']);

        [$data, $warnings] = $this->transformWithWarnings($element);

        self::assertStringContainsString('Normal extraction needle', $data['content']);
        self::assertSame([], array_values(array_filter(
            $warnings,
            static fn(array $message): bool => str_contains(
                (string)($message[0] ?? ''),
                'Automatic search content extraction skipped',
            ),
        )));
        self::assertTrue(is_subclass_of(CommerceTransformer::class, AutoTransformer::class));
    }

    /**
     * @return array<string, array{0: class-string<Field>, 1: string}>
     */
    public static function nativeFieldKeywordContracts(): array
    {
        return [
            'PlainText fallback' => [PlainText::class, 'Craft Field::searchKeywords() string fallback'],
            'Email fallback' => [Email::class, 'Craft Field::searchKeywords() string fallback'],
            'Url fallback' => [Url::class, 'Craft Field::searchKeywords() string fallback'],
            'Number fallback' => [Number::class, 'Craft Field::searchKeywords() string fallback'],
            'Range fallback' => [Range::class, 'Craft Field::searchKeywords() string fallback'],
            'Color fallback' => [Color::class, 'Craft Field::searchKeywords() string fallback'],
            'Lightswitch fallback' => [Lightswitch::class, 'Craft Field::searchKeywords() string fallback'],
            'Money fallback' => [Money::class, 'Craft Field::searchKeywords() string fallback'],
            'Icon fallback' => [Icon::class, 'Craft Field::searchKeywords() string fallback'],
            'Json fallback' => [Json::class, 'Craft Field::searchKeywords() string fallback'],
            'Link fallback' => [Link::class, 'Craft Field::searchKeywords() string fallback'],
            'Country fallback' => [Country::class, 'Craft Field::searchKeywords() string fallback'],
            'Dropdown options' => [Dropdown::class, 'Craft BaseOptionsField selected value and label keywords'],
            'RadioButtons options' => [RadioButtons::class, 'Craft BaseOptionsField selected value and label keywords'],
            'ButtonGroup options' => [ButtonGroup::class, 'Craft BaseOptionsField selected value and label keywords'],
            'Checkboxes options' => [Checkboxes::class, 'Craft BaseOptionsField selected value and label keywords'],
            'MultiSelect options' => [MultiSelect::class, 'Craft BaseOptionsField selected value and label keywords'],
            'Entries relation' => [Entries::class, 'Craft BaseRelationField related element string keywords'],
            'Categories relation' => [Categories::class, 'Craft BaseRelationField related element string keywords'],
            'Tags relation' => [Tags::class, 'Craft BaseRelationField related element string keywords'],
            'Assets relation' => [Assets::class, 'Craft BaseRelationField related element string keywords'],
            'Users relation' => [Users::class, 'Craft BaseRelationField related element string keywords'],
            'Matrix nested' => [Matrix::class, 'Craft Matrix delegates to NestedElementManager keywords'],
            'ContentBlock nested' => [ContentBlock::class, 'Craft ContentBlock delegates to NestedElementManager keywords'],
            'Table cells' => [Table::class, 'Craft Table cell keywords excluding DateTime cells'],
            'Addresses nested' => [Addresses::class, 'Craft Addresses delegates to address manager keywords'],
            'Date empty' => [Date::class, 'Craft Date returns empty keywords'],
            'Time empty' => [Time::class, 'Craft Time returns empty keywords'],
        ];
    }

    private function transformWithField(Field $field, mixed $value): array
    {
        return $this->transformWithFields([$field], [$field->handle => $value]);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @param Field[] $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function transformWithFields(array $fields, array $values): array
    {
        $element = $this->testElement();
        $element->setTestFieldValues($values);
        $element->setTestFieldLayout($this->fieldLayout($fields));

        return (new AutoTransformer())->transform($element);
    }

    private function testElement(): SearchManagerNativeFieldTestElement
    {
        $element = new SearchManagerNativeFieldTestElement();
        $element->id = 123;
        $element->siteId = 1;
        $element->title = 'Native Field Test Element';

        return $element;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array<mixed>>}
     */
    private function transformWithWarnings(SearchManagerNativeFieldTestElement $element): array
    {
        $logger = Craft::getLogger();
        $before = count($logger->messages);
        $data = (new AutoTransformer())->transform($element);
        $warnings = array_values(array_filter(
            array_slice($logger->messages, $before),
            static fn(array $message): bool => ($message[1] ?? null) === Logger::LEVEL_WARNING
                && ($message[2] ?? null) === 'search-manager',
        ));

        return [$data, $warnings];
    }

    /**
     * @param list<array<mixed>> $warnings
     */
    private function warningForBoundary(array $warnings, string $boundary): string
    {
        $matching = array_values(array_filter(
            $warnings,
            static fn(array $message): bool => str_contains(
                (string)($message[0] ?? ''),
                '"boundary":"' . $boundary . '"',
            ),
        ));
        self::assertCount(1, $matching, "Expected one safe warning for {$boundary}.");

        return (string)$matching[0][0];
    }

    /**
     * @param Field[] $fields
     */
    private function fieldLayout(array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => SearchManagerNativeFieldTestElement::class]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements(array_map(
            static fn(Field $field): CustomField => new CustomField($field),
            $fields,
        ));

        $layout->setTabs([$tab]);

        return $layout;
    }

    private function nestedElement(string $title, string $needle): SearchManagerNativeFieldTestElement
    {
        $field = new PlainText(['handle' => 'nestedText', 'searchable' => true]);
        $element = new SearchManagerNativeFieldTestElement();
        $element->id = 789;
        $element->siteId = 1;
        $element->title = $title;
        $element->setTestFieldValues(['nestedText' => $needle]);
        $element->setTestFieldLayout($this->fieldLayout([$field]));

        return $element;
    }

    private function nestedEntry(string $title, string $needle): SearchManagerNativeFieldTestEntry
    {
        $field = new PlainText(['handle' => 'nestedText', 'searchable' => true]);
        $entry = new SearchManagerNativeFieldTestEntry();
        $entry->id = 987654321;
        $entry->siteId = 1;
        $entry->title = $title;
        $entry->enabled = true;
        $entry->setTestFieldValues(['nestedText' => $needle]);
        $entry->setTestFieldLayout($this->fieldLayout([$field]));

        return $entry;
    }
}

final class SearchManagerNativeFieldTestElement extends Element
{
    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

    /** @var list<string> */
    private array $throwingAttributes = [];

    /** @var list<string> */
    private array $throwingFieldHandles = [];

    /** @var array<string, string> */
    private array $testAttributeKeywords = [];

    public static function displayName(): string
    {
        return 'Search Manager Native Field Test Element';
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->testFieldLayout;
    }

    public function getFieldValue(string $fieldHandle): mixed
    {
        if (in_array($fieldHandle, $this->throwingFieldHandles, true)) {
            throw new \RuntimeException('A5 field extraction failed: ' . ($this->testFieldValues[$fieldHandle] ?? ''));
        }

        return $this->testFieldValues[$fieldHandle] ?? null;
    }

    public function getSearchKeywords(string $attribute): string
    {
        if (in_array($attribute, $this->throwingAttributes, true)) {
            throw new \RuntimeException('A5 attribute extraction failed: ' . ($this->testAttributeKeywords[$attribute] ?? ''));
        }
        if (str_starts_with($attribute, 'a5')) {
            return $this->testAttributeKeywords[$attribute] ?? '';
        }

        return parent::getSearchKeywords($attribute);
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

    /**
     * @param array<string, string> $keywords
     */
    public function setTestAttributeKeywords(array $keywords): void
    {
        $this->testAttributeKeywords = $keywords;
    }

    /**
     * @param list<string> $attributes
     */
    public function setThrowingAttributes(array $attributes): void
    {
        $this->throwingAttributes = $attributes;
    }

    /**
     * @param list<string> $fieldHandles
     */
    public function setThrowingFieldHandles(array $fieldHandles): void
    {
        $this->throwingFieldHandles = $fieldHandles;
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['a5ThrowingAttribute', 'a5HealthyAttribute'];
    }
}

final class SearchManagerNativeFieldTestEntry extends Entry
{
    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

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
}

final class A5ThrowingRelationCollection
{
    /**
     * @return list<ElementInterface>
     */
    public function all(): array
    {
        throw new \RuntimeException('A5_RELATION_CONTENT_SENTINEL');
    }
}

final class A5ThrowingRelationField extends Entries
{
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (is_object($value) && method_exists($value, 'all')) {
            $value->all();
        }

        return '';
    }
}

final class SearchManagerMatrixKeywordField extends Matrix
{
    /**
     * @var Field[]
     */
    public array $nestedFields = [];

    /**
     * @var array<string, mixed>
     */
    public array $nestedValues = [];

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $nestedElement = new SearchManagerNativeFieldTestElement();
        $nestedElement->id = 789;
        $nestedElement->siteId = 1;
        $nestedElement->title = 'Nested Block Title';
        $nestedElement->setTestFieldValues($this->nestedValues);

        $keywords = [$nestedElement->title];

        foreach ($this->nestedFields as $field) {
            if ($field->searchable && $field->handle !== null) {
                $keywords[] = $field->getSearchKeywords($this->nestedValues[$field->handle] ?? null, $nestedElement);
            }
        }

        return implode(' ', array_filter($keywords));
    }
}
