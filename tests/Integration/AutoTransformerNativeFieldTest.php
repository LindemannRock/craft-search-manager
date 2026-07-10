<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

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
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testSearchablePlainTextFieldIsIncluded(): void
    {
        $data = $this->transformWithField(
            new PlainText(['handle' => 'body', 'searchable' => true]),
            'Plain text needle',
        );

        self::assertSame('Plain text needle', $data['_fields']['body'] ?? null);
        self::assertStringContainsString('Plain text needle', $data['content']);
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
        self::assertSame('entry', $data['elementType'] ?? null);
        self::assertSame($section->name, $data['section'] ?? null);
        self::assertSame($section->handle, $data['sectionHandle'] ?? null);
        self::assertSame($section->type, $data['sectionType'] ?? null);
        self::assertNotSame($section->handle, $data['type'] ?? null);
        self::assertNotSame($section->handle, $data['elementType'] ?? null);
        self::assertNotSame($section->type, $data['type'] ?? null);
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
        $element = new SearchManagerNativeFieldTestElement();
        $element->id = 123;
        $element->siteId = 1;
        $element->title = 'Native Field Test Element';
        $element->setTestFieldValues($values);
        $element->setTestFieldLayout($this->fieldLayout($fields));

        return (new AutoTransformer())->transform($element);
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
}

final class SearchManagerNativeFieldTestElement extends Element
{
    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

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
