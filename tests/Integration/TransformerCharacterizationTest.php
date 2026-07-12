<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\docsmanager\records;

class SourceRecord
{
    public ?string $name = null;

    public static function findOne(mixed $condition): ?self
    {
        return null;
    }
}

namespace lindemannrock\docsmanager\elements;

class SourceDoc extends \craft\base\Element
{
    public ?int $sourceId = null;
    public ?string $slug = '';
    public string $category = '';
    public ?string $description = null;
    public ?string $htmlContent = null;

    /**
     * @var string[]
     */
    public array $keywords = [];

    public static function displayName(): string
    {
        return 'Source Doc';
    }

    public static function refHandle(): ?string
    {
        return 'source-doc';
    }

    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        return null;
    }
}

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\CategoryGroup;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Volume;
use craft\models\VolumeFolder;
use lindemannrock\docsmanager\elements\SourceDoc;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\SourceDocSectionSplitter;
use lindemannrock\searchmanager\services\TransformerService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use lindemannrock\searchmanager\transformers\DocsManagerTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Characterizes transformer document output so helper extraction stays byte-identical.
 *
 * @since 5.53.0
 */
#[CoversClass(AutoTransformer::class)]
#[CoversClass(CommerceTransformer::class)]
#[CoversClass(DocsManagerTransformer::class)]
#[CoversClass(SourceDocSectionSplitter::class)]
final class TransformerCharacterizationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::defineRichTextTestClass();
        self::defineCommerceTestClasses();
    }

    public function testStructureEntryDocumentDataIsByteIdentical(): void
    {
        $entry = new TransformerCharacterizationEntry();
        $entry->id = 300;
        $entry->siteId = 1;
        $entry->title = 'Nested Entry';
        $entry->slug = 'nested-entry';
        $entry->level = 3;
        $entry->testSection = new Section(['name' => 'Pages', 'handle' => 'pages', 'type' => Section::TYPE_STRUCTURE]);
        $entry->testAncestors = new ElementCollection([
            $this->entryAncestor(100, 'Root Entry'),
            $this->entryAncestor(200, 'Parent Entry'),
        ]);

        $this->assertDocumentSame([
            'objectID' => 300,
            'id' => 300,
            'elementId' => 300,
            'backendId' => '300_1',
            'type' => 'entry',
            'elementType' => 'entry',
            'title' => 'Nested Entry',
            'slug' => 'nested-entry',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'entrySection' => 'Pages',
            'entrySectionHandle' => 'pages',
            'entrySectionType' => 'structure',
            'ancestors' => [
                ['id' => 100, 'title' => 'Root Entry'],
                ['id' => 200, 'title' => 'Parent Entry'],
            ],
            'level' => 3,
            'content' => 'Nested Entry nested-entry Nested Entry',
            'excerpt' => 'Nested Entry nested-entry Nested Entry',
        ], (new AutoTransformer())->transform($entry));
    }

    public function testChannelEntryDocumentDataIsByteIdentical(): void
    {
        $fieldClass = 'craft\\ckeditor\\Field';
        $field = new $fieldClass(['handle' => 'hiddenBody', 'searchable' => false]);
        $entry = new TransformerCharacterizationEntry();
        $entry->id = 301;
        $entry->siteId = 1;
        $entry->title = 'Channel Entry';
        $entry->slug = 'channel-entry';
        $entry->testSection = new Section(['name' => 'News', 'handle' => 'news', 'type' => Section::TYPE_CHANNEL]);
        $entry->testAncestors = new ElementCollection([$this->entryAncestor(100, 'Ignored Root')]);
        $entry->setTestFieldLayout($this->fieldLayout([$field], TransformerCharacterizationEntry::class));
        $entry->setTestFieldValues(['hiddenBody' => 'Hidden searchable gap']);

        $this->assertDocumentSame([
            'objectID' => 301,
            'id' => 301,
            'elementId' => 301,
            'backendId' => '301_1',
            'type' => 'entry',
            'elementType' => 'entry',
            'title' => 'Channel Entry',
            'slug' => 'channel-entry',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'entrySection' => 'News',
            'entrySectionHandle' => 'news',
            'entrySectionType' => 'channel',
            'content' => 'Channel Entry channel-entry Channel Entry',
            'excerpt' => 'Channel Entry channel-entry Channel Entry',
        ], (new AutoTransformer())->transform($entry));
    }

    public function testNonSearchableRichTextFieldIsExcludedFromDocumentData(): void
    {
        $fieldClass = 'craft\\ckeditor\\Field';
        $field = new $fieldClass(['handle' => 'hiddenBody', 'searchable' => false]);
        $entry = new TransformerCharacterizationEntry();
        $entry->id = 302;
        $entry->siteId = 1;
        $entry->title = 'Hidden Rich Text Entry';
        $entry->slug = 'hidden-rich-text-entry';
        $entry->testSection = new Section(['name' => 'News', 'handle' => 'news', 'type' => Section::TYPE_CHANNEL]);
        $entry->testAncestors = new ElementCollection();
        $entry->setTestFieldLayout($this->fieldLayout([$field], TransformerCharacterizationEntry::class));
        $entry->setTestFieldValues([
            'hiddenBody' => '<h2 id="hidden-heading">Hidden Heading</h2><p>Hidden rich needle.</p><pre><code>hidden code needle</code></pre>',
        ]);

        $data = (new TransformerService())->transform($entry);

        self::assertArrayNotHasKey('_fields', $data);
        self::assertArrayNotHasKey('_headings', $data);
        self::assertArrayNotHasKey('headings', $data);
        self::assertStringNotContainsString('Hidden Heading', $data['content']);
        self::assertStringNotContainsString('Hidden rich needle', $data['content']);
        self::assertStringNotContainsString('hidden code needle', $data['content']);
        self::assertStringNotContainsString('Hidden Heading', $data['excerpt']);
        self::assertStringNotContainsString('Hidden rich needle', $data['excerpt']);
        self::assertStringNotContainsString('hidden code needle', $data['excerpt']);
    }

    public function testCategoryDocumentDataIsByteIdentical(): void
    {
        $category = new TransformerCharacterizationCategory();
        $category->id = 400;
        $category->siteId = 1;
        $category->title = 'Nested Category';
        $category->slug = 'nested-category';
        $category->level = 2;
        $category->testGroup = new CategoryGroup(['name' => 'Topics', 'handle' => 'topics']);
        $category->testAncestors = new ElementCollection([$this->categoryAncestor(250, 'Root Category')]);

        $this->assertDocumentSame([
            'objectID' => 400,
            'id' => 400,
            'elementId' => 400,
            'backendId' => '400_1',
            'type' => 'category',
            'elementType' => 'category',
            'title' => 'Nested Category',
            'slug' => 'nested-category',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'categoryGroup' => 'Topics',
            'categoryGroupHandle' => 'topics',
            'ancestors' => [
                ['id' => 250, 'title' => 'Root Category'],
            ],
            'level' => 2,
            'content' => 'Nested Category nested-category Nested Category',
            'excerpt' => 'Nested Category nested-category Nested Category',
        ], (new AutoTransformer())->transform($category));
    }

    public function testPublicAssetDocumentDataIsByteIdentical(): void
    {
        $root = new TransformerCharacterizationVolumeFolder(['id' => 10, 'name' => 'Uploads', 'path' => '']);
        $child = new TransformerCharacterizationVolumeFolder(['id' => 20, 'name' => 'Campaigns', 'path' => 'campaigns/']);
        $child->testParent = $root;

        $asset = new TransformerCharacterizationAsset();
        $asset->id = 500;
        $asset->siteId = 1;
        $asset->title = 'Hero Image';
        $asset->setFilename('Hero.jpg');
        $asset->kind = 'image';
        $asset->size = 123456;
        $asset->setWidth(1600);
        $asset->setHeight(900);
        $asset->testUrl = 'https://example.test/uploads/campaigns/hero.jpg';
        $asset->testVolume = new TransformerCharacterizationVolume(['name' => 'Uploads', 'handle' => 'uploads']);
        $asset->testVolume->testRootUrl = 'https://example.test/uploads/';
        $asset->testFolder = $child;

        $this->assertDocumentSame([
            'objectID' => 500,
            'id' => 500,
            'elementId' => 500,
            'backendId' => '500_1',
            'type' => 'asset',
            'elementType' => 'asset',
            'title' => 'Hero Image',
            'slug' => '',
            'url' => 'https://example.test/uploads/campaigns/hero.jpg',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'volume' => 'Uploads',
            'volumeHandle' => 'uploads',
            'filename' => 'Hero.jpg',
            'assetKind' => 'image',
            'extension' => 'jpg',
            'size' => 123456,
            'width' => 1600,
            'height' => 900,
            'ancestors' => [
                ['id' => 10, 'title' => 'Uploads'],
                ['id' => 20, 'title' => 'Campaigns'],
            ],
            'folderPath' => 'campaigns/',
            'content' => 'Hero Image jpg image Hero Image',
            'excerpt' => 'Hero Image jpg image Hero Image',
        ], (new AutoTransformer())->transform($asset));
    }

    public function testPrivateAssetDocumentDataIsByteIdentical(): void
    {
        $asset = new TransformerCharacterizationAsset();
        $asset->id = 501;
        $asset->siteId = 1;
        $asset->title = 'Pricing PDF';
        $asset->setFilename('Pricing.pdf');
        $asset->kind = 'pdf';
        $asset->size = 234567;
        $asset->testUrl = '';
        $asset->testVolume = new TransformerCharacterizationVolume(['name' => 'Private', 'handle' => 'private']);
        $asset->testVolume->testRootUrl = null;
        $asset->testFolder = new TransformerCharacterizationVolumeFolder(['id' => 20, 'name' => 'Private', 'path' => 'private/']);

        $this->assertDocumentSame([
            'objectID' => 501,
            'id' => 501,
            'elementId' => 501,
            'backendId' => '501_1',
            'type' => 'asset',
            'elementType' => 'asset',
            'title' => 'Pricing PDF',
            'slug' => '',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'volume' => 'Private',
            'volumeHandle' => 'private',
            'filename' => 'Pricing.pdf',
            'assetKind' => 'pdf',
            'extension' => 'pdf',
            'size' => 234567,
            'content' => 'Pricing PDF pdf pdf Pricing PDF',
            'excerpt' => 'Pricing PDF pdf pdf Pricing PDF',
        ], (new AutoTransformer())->transform($asset));
    }

    public function testUserDocumentDataIsByteIdentical(): void
    {
        $user = new User();
        $user->id = 601;
        $user->siteId = 1;
        $user->username = 'search-user';

        $this->assertDocumentSame([
            'objectID' => 601,
            'id' => 601,
            'elementId' => 601,
            'backendId' => '601_1',
            'type' => 'user',
            'elementType' => 'user',
            'title' => 'search-user',
            'slug' => '',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'content' => 'search-user',
            'excerpt' => 'search-user',
        ], (new AutoTransformer())->transform($user));
    }

    public function testCommerceProductDocumentDataIsByteIdentical(): void
    {
        $type = $this->productType('Shoes', 'shoes');
        $redVariant = $this->variant('SKU-RED', 'Red Sneaker', ['Color' => 'Red', 'Size' => 'Large']);
        $blueVariant = $this->variant('SKU-BLUE', 'Blue Sneaker', ['Color' => 'Blue']);
        $product = $this->product($type, [$redVariant, $blueVariant], $redVariant);
        $product->id = 101;
        $product->siteId = 1;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->fakeUrl = 'https://example.test/products/trail-sneaker';

        $this->assertDocumentSame([
            'objectID' => 101,
            'id' => 101,
            'elementId' => 101,
            'backendId' => '101_1',
            'type' => 'product',
            'elementType' => 'product',
            'title' => 'Trail Sneaker',
            'slug' => 'trail-sneaker',
            'url' => 'https://example.test/products/trail-sneaker',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'content' => 'Trail Sneaker SKU-RED SKU-BLUE trail-sneaker Trail Sneaker Trail Sneaker trail-sneaker Shoes shoes SKU-RED SKU-BLUE Red Sneaker Blue Sneaker Color Red Size Large Color Blue SKU-RED Red Sneaker',
            'excerpt' => 'Trail Sneaker SKU-RED SKU-BLUE trail-sneaker Trail Sneaker Trail Sneaker trail-sneaker Shoes shoes SKU-RED SKU-BLUE Red Sneaker Blue Sneaker Color Red Size Large Color Blue SKU-RED Red Sneaker',
            'productType' => 'Shoes',
            'productTypeHandle' => 'shoes',
            'variantSkus' => ['SKU-RED', 'SKU-BLUE'],
            'variantTitles' => ['Red Sneaker', 'Blue Sneaker'],
            'variantOptions' => ['Color Red', 'Size Large', 'Color Blue'],
            'defaultVariantSku' => 'SKU-RED',
            'defaultVariantTitle' => 'Red Sneaker',
            'price' => 0,
        ], (new CommerceTransformer())->transform($product));
    }

    public function testCommerceVariantDocumentDataIsByteIdentical(): void
    {
        $type = $this->productType('Shoes', 'shoes');
        $product = $this->product($type);
        $product->id = 101;
        $product->siteId = 1;
        $product->title = 'Trail Sneaker';
        $product->slug = 'trail-sneaker';
        $product->fakeUrl = 'https://example.test/products/trail-sneaker';

        $variant = $this->variant('SKU-RED', 'Red Sneaker', ['Color' => 'Red', 'Size' => 'Large'], $product);
        $variant->id = 301;
        $variant->siteId = 1;

        $this->assertDocumentSame([
            'objectID' => 301,
            'id' => 301,
            'elementId' => 301,
            'backendId' => '301_1',
            'type' => 'variant',
            'elementType' => 'variant',
            'title' => 'Red Sneaker',
            'slug' => '',
            'url' => 'https://example.test/products/trail-sneaker',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'content' => 'Red Sneaker SKU-RED Red Sneaker Red Sneaker SKU-RED Red Sneaker Color Red Size Large Trail Sneaker trail-sneaker Shoes shoes',
            'excerpt' => 'Red Sneaker SKU-RED Red Sneaker Red Sneaker SKU-RED Red Sneaker Color Red Size Large Trail Sneaker trail-sneaker Shoes shoes',
            'sku' => 'SKU-RED',
            'variantTitle' => 'Red Sneaker',
            'variantOptions' => ['Color Red', 'Size Large'],
            'price' => 0,
            'productType' => 'Shoes',
            'productTypeHandle' => 'shoes',
            'productId' => 101,
            'productTitle' => 'Trail Sneaker',
            'productSlug' => 'trail-sneaker',
            'productUrl' => 'https://example.test/products/trail-sneaker',
        ], (new CommerceTransformer())->transform($variant));
    }

    public function testSourceDocDocumentDataIsByteIdentical(): void
    {
        $sourceDoc = new SourceDoc();
        $sourceDoc->id = 701;
        $sourceDoc->siteId = 1;
        $sourceDoc->title = 'Quickstart';
        $sourceDoc->slug = 'quickstart';
        $sourceDoc->sourceId = null;
        $sourceDoc->category = 'Guides';
        $sourceDoc->description = 'Install and configure Search Manager.';
        $sourceDoc->htmlContent = '<h2 id="install">Install</h2><p>Run Composer install.</p><h3 id="deploy">Deploy</h3><p>Use the indexing command after deployment.</p>';
        $sourceDoc->keywords = ['setup', 'deployment'];

        $this->assertDocumentSame([
            'objectID' => 701,
            'id' => 701,
            'elementId' => 701,
            'backendId' => '701_1',
            'type' => 'source-doc',
            'elementType' => 'source-doc',
            'title' => 'Quickstart',
            'slug' => 'quickstart',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'source' => 'Docs',
            'docCategory' => 'Guides',
            'description' => 'Install and configure Search Manager.',
            'sourceId' => null,
            '_bodyClean' => 'Run Composer install. Use the indexing command after deployment.',
            '_bodyWithCode' => 'Run Composer install. Use the indexing command after deployment.',
            'headings' => 'Install Deploy',
            '_headings' => [
                [
                    'text' => 'Install',
                    'id' => 'install',
                    'level' => 2,
                    'description' => 'Run Composer install.',
                ],
                [
                    'text' => 'Deploy',
                    'id' => 'deploy',
                    'level' => 3,
                    'description' => 'Use the indexing command after deployment.',
                ],
            ],
            'keywords' => 'setup deployment',
            'content' => 'Quickstart Install and configure Search Manager. setup deployment',
            'excerpt' => 'Quickstart Install and configure Search Manager. setup deployment',
        ], (new DocsManagerTransformer())->transform($sourceDoc));
    }

    public function testSourceDocSplitSectionsCreateIntroAndHeadingDocuments(): void
    {
        $sourceDoc = new SourceDoc();
        $sourceDoc->id = 702;
        $sourceDoc->siteId = 1;
        $sourceDoc->title = 'Guide';
        $sourceDoc->slug = 'guide';
        $sourceDoc->category = 'Guides';
        $sourceDoc->description = 'Learn the workflow.';
        $sourceDoc->htmlContent = '<p>Intro overview before headings.</p><h2 id="install">Install</h2><p>Run Composer install.</p><h3>Deploy</h3><p>Rebuild the index.</p>';
        $sourceDoc->keywords = ['setup', 'deployment'];

        $pageData = (new DocsManagerTransformer())->transform($sourceDoc);
        $sections = SourceDocSectionSplitter::split($sourceDoc, $pageData, [2, 3]);

        self::assertCount(3, $sections);
        self::assertSame([702, 702, 702], array_column($sections, 'id'));
        self::assertSame(['702_1_intro', '702_1_install', '702_1_deploy'], array_column($sections, 'backendId'));
        self::assertCount(3, array_unique(array_column($sections, 'backendId')));

        self::assertSame('intro', $sections[0]['sectionType']);
        self::assertSame('intro', $sections[0]['sectionId']);
        self::assertSame('Guide', $sections[0]['sectionTitle']);
        self::assertNull($sections[0]['sectionLevel']);
        self::assertNull($sections[0]['sectionAnchor']);
        self::assertSame('', $sections[0]['sectionUrl']);
        self::assertSame('Intro overview before headings.', $sections[0]['sectionBody']);
        self::assertSame('Intro overview before headings.', $sections[0]['_bodyClean']);
        self::assertSame('Intro overview before headings.', $sections[0]['_sectionBodyWithCode']);
        self::assertSame('Guide Learn the workflow. setup deployment', $sections[0]['content']);

        self::assertSame('heading', $sections[1]['sectionType']);
        self::assertSame('install', $sections[1]['sectionId']);
        self::assertSame('Install', $sections[1]['sectionTitle']);
        self::assertSame(2, $sections[1]['sectionLevel']);
        self::assertSame('install', $sections[1]['sectionAnchor']);
        self::assertSame('#install', $sections[1]['sectionUrl']);
        self::assertSame('Run Composer install.', $sections[1]['sectionBody']);
        self::assertSame('Run Composer install.', $sections[1]['_bodyClean']);
        self::assertSame('Run Composer install.', $sections[1]['_sectionBodyWithCode']);
        self::assertSame('Install', $sections[1]['content']);
        self::assertStringNotContainsString('Learn the workflow.', $sections[1]['content']);
        self::assertStringNotContainsString('setup deployment', $sections[1]['content']);

        self::assertSame('heading', $sections[2]['sectionType']);
        self::assertSame('deploy', $sections[2]['sectionId']);
        self::assertSame('Deploy', $sections[2]['sectionTitle']);
        self::assertSame(3, $sections[2]['sectionLevel']);
        self::assertSame('deploy', $sections[2]['sectionAnchor']);
        self::assertSame('#deploy', $sections[2]['sectionUrl']);
        self::assertSame('Rebuild the index.', $sections[2]['sectionBody']);
        self::assertSame('Rebuild the index.', $sections[2]['_sectionBodyWithCode']);
        self::assertSame('Deploy', $sections[2]['content']);
        self::assertStringNotContainsString('Learn the workflow.', $sections[2]['content']);
        self::assertStringNotContainsString('setup deployment', $sections[2]['content']);

        foreach ($sections as $section) {
            self::assertSame([], $section['_headings']);
            self::assertSame('', $section['headings']);
        }
    }

    public function testSourceDocTabbedCodeChromeIsRemovedBeforeCleaningAndSplitting(): void
    {
        $sourceDoc = new SourceDoc();
        $sourceDoc->id = 703;
        $sourceDoc->siteId = 1;
        $sourceDoc->title = 'Installation';
        $sourceDoc->slug = 'installation';
        $sourceDoc->category = 'Guides';
        $sourceDoc->description = 'Install the plugin.';
        $sourceDoc->htmlContent = '<p>Choose a package command.</p><div class="code-tab-buttons" role="tablist"><button class="code-tab-btn">Composer</button><button class="code-tab-btn">DDEV</button></div><pre><code>composer require acme/package</code></pre><h2 id="php">PHP setup</h2><p>Run the project command.</p><div class="code-tab-buttons" role="tablist"><button class="code-tab-btn">PHP</button><button class="code-tab-btn">DDEV</button></div><pre><code>php craft migrate/all</code></pre>';
        $sourceDoc->keywords = [];

        $pageData = (new DocsManagerTransformer())->transform($sourceDoc);

        self::assertSame('Choose a package command. Run the project command.', $pageData['_bodyClean']);
        self::assertSame('Choose a package command. composer require acme/package Run the project command. php craft migrate/all', $pageData['_bodyWithCode']);
        self::assertStringNotContainsString('Choose a package command.', $pageData['content']);
        self::assertStringNotContainsString('composer require acme/package', $pageData['content']);
        self::assertStringNotContainsString('php craft migrate/all', $pageData['content']);
        self::assertStringNotContainsString('ComposerDDEV', $pageData['content']);
        self::assertStringNotContainsString('Composer DDEV', $pageData['content']);
        self::assertStringNotContainsString('PHPDDEV', $pageData['content']);
        self::assertStringNotContainsString('PHP DDEV', $pageData['content']);
        self::assertSame([
            [
                'text' => 'PHP setup',
                'id' => 'php',
                'level' => 2,
                'description' => 'Run the project command.',
            ],
        ], $pageData['_headings']);

        $sections = SourceDocSectionSplitter::split($sourceDoc, $pageData, [2]);

        self::assertCount(2, $sections);
        self::assertSame('Choose a package command.', $sections[0]['sectionBody']);
        self::assertSame('Run the project command.', $sections[1]['sectionBody']);
        self::assertSame('Choose a package command. composer require acme/package', $sections[0]['_sectionBodyWithCode']);
        self::assertSame('Run the project command. php craft migrate/all', $sections[1]['_sectionBodyWithCode']);
        self::assertStringNotContainsString('ComposerDDEV', $sections[0]['content']);
        self::assertStringNotContainsString('Composer DDEV', $sections[0]['content']);
        self::assertStringNotContainsString('PHPDDEV', $sections[1]['content']);
        self::assertStringNotContainsString('PHP DDEV', $sections[1]['content']);
    }

    public function testPreContentCleanFinalizesAndResetsByteIdentically(): void
    {
        $fieldClass = 'craft\\ckeditor\\Field';
        $field = new $fieldClass(['handle' => 'body', 'searchable' => true]);
        $entry = new TransformerCharacterizationEntry();
        $entry->id = 801;
        $entry->siteId = 1;
        $entry->title = 'Code Entry';
        $entry->slug = 'code-entry';
        $entry->testSection = new Section(['name' => 'News', 'handle' => 'news', 'type' => Section::TYPE_CHANNEL]);
        $entry->testAncestors = new ElementCollection();
        $entry->setTestFieldLayout($this->fieldLayout([$field], TransformerCharacterizationEntry::class));
        $entry->setTestFieldValues([
            'body' => '<p>Intro prose.</p><pre><code>secret code</code></pre><p>Outro prose.</p>',
        ]);

        $service = new TransformerService();
        $data = $service->transform($entry);

        $this->assertDocumentSame([
            'objectID' => 801,
            'id' => 801,
            'elementId' => 801,
            'backendId' => '801_1',
            'type' => 'entry',
            'elementType' => 'entry',
            'title' => 'Code Entry',
            'slug' => 'code-entry',
            'url' => '',
            'siteId' => 1,
            'dateCreated' => null,
            'dateUpdated' => null,
            'entrySection' => 'News',
            'entrySectionHandle' => 'news',
            'entrySectionType' => 'channel',
            '_fields' => [
                'body' => 'Intro prose. secret codeOutro prose.',
            ],
            '_bodyClean' => 'Intro prose. Outro prose.',
            'content' => 'Code Entry code-entry Code Entry',
            'excerpt' => 'Code Entry code-entry Code Entry',
        ], $data);

        $next = new TransformerCharacterizationEntry();
        $next->id = 802;
        $next->siteId = 1;
        $next->title = 'Plain Entry';
        $next->slug = 'plain-entry';
        $next->testSection = new Section(['name' => 'News', 'handle' => 'news', 'type' => Section::TYPE_CHANNEL]);
        $next->testAncestors = new ElementCollection();
        $next->setTestFieldLayout($this->fieldLayout([], TransformerCharacterizationEntry::class));

        $nextData = $service->transform($next);

        self::assertSame(
            json_encode($nextData, JSON_UNESCAPED_UNICODE),
            json_encode($service->transform($next), JSON_UNESCAPED_UNICODE),
        );
    }

    public function testNoCodeRichTextBodyStillStoresCleanBody(): void
    {
        $fieldClass = 'craft\\ckeditor\\Field';
        $field = new $fieldClass(['handle' => 'body', 'searchable' => true]);
        $entry = new TransformerCharacterizationEntry();
        $entry->id = 803;
        $entry->siteId = 1;
        $entry->title = 'Plain Body';
        $entry->slug = 'plain-body';
        $entry->testSection = new Section(['name' => 'News', 'handle' => 'news', 'type' => Section::TYPE_CHANNEL]);
        $entry->testAncestors = new ElementCollection();
        $entry->setTestFieldLayout($this->fieldLayout([$field], TransformerCharacterizationEntry::class));
        $entry->setTestFieldValues([
            'body' => '<p>Plain Composer body prose with inline <code>config</code> token.</p>',
        ]);

        $data = (new TransformerService())->transform($entry);

        self::assertSame('Plain Composer body prose with inline config token.', $data['_bodyClean'] ?? null);
        self::assertSame('Plain Composer body prose with inline config token.', $data['_fields']['body'] ?? null);
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed>|null $actual
     */
    private function assertDocumentSame(array $expected, ?array $actual): void
    {
        $expected = $this->withCommonSiteData($expected);

        self::assertSame($expected, $actual);
        self::assertSame(
            json_encode($expected, JSON_UNESCAPED_UNICODE),
            json_encode($actual, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function withCommonSiteData(array $expected): array
    {
        if (!isset($expected['siteId']) || array_key_exists('site', $expected) || array_key_exists('language', $expected)) {
            return $expected;
        }

        $site = Craft::$app->getSites()->getSiteById((int)$expected['siteId']);
        if ($site === null) {
            return $expected;
        }

        $document = [];
        foreach ($expected as $key => $value) {
            $document[$key] = $value;
            if ($key === 'siteId') {
                $document['site'] = $site->handle;
                $document['language'] = $site->language;
            }
        }

        return $document;
    }

    private function entryAncestor(int $id, string $title): Entry
    {
        $entry = new Entry();
        $entry->id = $id;
        $entry->siteId = 1;
        $entry->title = $title;

        return $entry;
    }

    private function categoryAncestor(int $id, string $title): Category
    {
        $category = new Category();
        $category->id = $id;
        $category->siteId = 1;
        $category->title = $title;

        return $category;
    }

    /**
     * @param Field[] $fields
     */
    private function fieldLayout(array $fields, string $type): FieldLayout
    {
        $layout = new FieldLayout(['type' => $type]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements(array_map(
            static fn(Field $field): CustomField => new CustomField($field),
            $fields,
        ));
        $layout->setTabs([$tab]);

        return $layout;
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

    private static function defineCommerceTestClasses(): void
    {
        if (!class_exists(CommerceElementTypeHelper::productElementType())) {
            eval(<<<'PHP'
namespace craft\commerce\elements;

class Product extends \craft\base\Element
{
    public ?string $fakeUrl = null;
    public ?object $fakeType = null;
    public ?object $fakeDefaultVariant = null;
    public array $fakeVariants = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getType(): ?object
    {
        return $this->fakeType;
    }

    public function getVariants(): array
    {
        return $this->fakeVariants;
    }

    public function getDefaultVariant(): ?object
    {
        return $this->fakeDefaultVariant;
    }
}

class Variant extends \craft\base\Element
{
    public string $sku = '';
    public ?string $fakeUrl = null;
    public ?Product $fakeProduct = null;
    public array $fakeOptions = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): void
    {
        $this->sku = $sku;
    }

    public function getProduct(): ?Product
    {
        return $this->fakeProduct;
    }

    public function getOptions(): array
    {
        return $this->fakeOptions;
    }
}
PHP);
            return;
        }

        if (!class_exists(__NAMESPACE__ . '\\TransformerCharacterizationProduct')) {
            eval(<<<'PHP'
namespace lindemannrock\searchmanager\tests\Integration;

class TransformerCharacterizationProduct extends \craft\commerce\elements\Product
{
    public ?string $fakeUrl = null;
    public ?\craft\commerce\models\ProductType $fakeType = null;
    public ?\craft\commerce\elements\Variant $fakeDefaultVariant = null;
    public array $fakeVariants = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getType(): \craft\commerce\models\ProductType
    {
        return $this->fakeType ?? new \craft\commerce\models\ProductType(['name' => '', 'handle' => '']);
    }

    public function getVariants(?bool $includeDisabled = null): \craft\commerce\elements\VariantCollection
    {
        return \craft\commerce\elements\VariantCollection::make($this->fakeVariants);
    }

    public function getDefaultVariant(bool $includeDisabled = false): ?\craft\commerce\elements\Variant
    {
        return $this->fakeDefaultVariant;
    }
}

class TransformerCharacterizationVariant extends \craft\commerce\elements\Variant
{
    public ?string $fakeUrl = null;
    public ?\craft\commerce\elements\Product $fakeProduct = null;
    public array $fakeOptions = [];

    public function getUrl(): ?string
    {
        return $this->fakeUrl;
    }

    public function getProduct(): ?\craft\commerce\elements\Product
    {
        return $this->fakeProduct;
    }

    public function getOptions(): array
    {
        return $this->fakeOptions;
    }
}
PHP);
        }
    }

    private function productType(string $name, string $handle): object
    {
        if (class_exists('craft\\commerce\\models\\ProductType')) {
            return new \craft\commerce\models\ProductType([
                'name' => $name,
                'handle' => $handle,
            ]);
        }

        return (object)[
            'name' => $name,
            'handle' => $handle,
        ];
    }

    /**
     * @param object[] $variants
     */
    private function product(object $type, array $variants = [], ?object $defaultVariant = null): object
    {
        $class = class_exists(__NAMESPACE__ . '\\TransformerCharacterizationProduct')
            ? __NAMESPACE__ . '\\TransformerCharacterizationProduct'
            : CommerceElementTypeHelper::productElementType();

        $product = new $class();
        $product->fakeType = $type;
        $product->fakeVariants = $variants;
        $product->fakeDefaultVariant = $defaultVariant;

        return $product;
    }

    /**
     * @param array<string, string> $options
     */
    private function variant(string $sku, string $title, array $options, ?object $product = null): object
    {
        $class = class_exists(__NAMESPACE__ . '\\TransformerCharacterizationVariant')
            ? __NAMESPACE__ . '\\TransformerCharacterizationVariant'
            : CommerceElementTypeHelper::variantElementType();

        $variant = new $class();
        $variant->title = $title;
        $variant->setSku($sku);
        $variant->fakeOptions = $options;
        $variant->fakeProduct = $product;

        return $variant;
    }
}

final class TransformerCharacterizationEntry extends Entry
{
    public Section $testSection;

    /**
     * @var ElementCollection<int, ElementInterface>
     */
    public ElementCollection $testAncestors;

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
}

final class TransformerCharacterizationCategory extends Category
{
    public CategoryGroup $testGroup;

    /**
     * @var ElementCollection<int, ElementInterface>
     */
    public ElementCollection $testAncestors;

    public function getGroup(): CategoryGroup
    {
        return $this->testGroup;
    }

    public function getAncestors(?int $dist = null): ElementQueryInterface|ElementCollection
    {
        return $this->testAncestors;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return null;
    }
}

final class TransformerCharacterizationAsset extends Asset
{
    public ?string $testUrl = null;

    public Volume $testVolume;

    public VolumeFolder $testFolder;

    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string
    {
        return $this->testUrl;
    }

    public function getVolume(): Volume
    {
        return $this->testVolume;
    }

    public function getFolder(): VolumeFolder
    {
        return $this->testFolder;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return null;
    }
}

final class TransformerCharacterizationVolume extends Volume
{
    public ?string $testRootUrl = null;

    public function getRootUrl(): ?string
    {
        return $this->testRootUrl;
    }
}

final class TransformerCharacterizationVolumeFolder extends VolumeFolder
{
    public ?VolumeFolder $testParent = null;

    public function getParent(): ?VolumeFolder
    {
        return $this->testParent;
    }
}
