<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\db\ElementQueryInterface;
use craft\models\CategoryGroup;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Volume;
use craft\models\VolumeFolder;
use GraphQL\Type\Definition\ResolveInfo;
use lindemannrock\searchmanager\gql\types\SearchAncestorType;
use lindemannrock\searchmanager\gql\types\SearchHeadingType;
use lindemannrock\searchmanager\gql\types\SearchHitType;
use lindemannrock\searchmanager\services\EnrichmentService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks the search-hit hierarchy/path metadata contract.
 *
 * @since 5.53.0
 */
#[CoversClass(AutoTransformer::class)]
#[CoversClass(BaseTransformer::class)]
#[CoversClass(EnrichmentService::class)]
#[CoversClass(SearchAncestorType::class)]
#[CoversClass(SearchHeadingType::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitHierarchyContractTest extends TestCase
{
    public function testStructureEntryTransformWritesAncestorsAndLevel(): void
    {
        $entry = new SearchHierarchyTestEntry();
        $entry->id = 300;
        $entry->siteId = 1;
        $entry->title = 'Nested Entry';
        $entry->level = 3;
        $entry->testSection = new Section(['name' => 'Pages', 'handle' => 'pages', 'type' => Section::TYPE_STRUCTURE]);
        $entry->testAncestors = new ElementCollection([
            $this->entryAncestor(100, 'Root Entry'),
            $this->entryAncestor(200, 'Parent Entry'),
        ]);

        $data = (new AutoTransformer())->transform($entry);

        self::assertSame(3, $data['level'] ?? null);
        self::assertSame([
            ['id' => 100, 'title' => 'Root Entry'],
            ['id' => 200, 'title' => 'Parent Entry'],
        ], $data['ancestors'] ?? null);
    }

    public function testChannelAndSingleEntriesWriteNoHierarchy(): void
    {
        foreach ([Section::TYPE_CHANNEL, Section::TYPE_SINGLE] as $sectionType) {
            $entry = new SearchHierarchyTestEntry();
            $entry->id = 300;
            $entry->siteId = 1;
            $entry->title = 'Flat Entry';
            $entry->level = 1;
            $entry->testSection = new Section(['name' => 'Flat', 'handle' => 'flat', 'type' => $sectionType]);
            $entry->testAncestors = new ElementCollection([$this->entryAncestor(100, 'Root Entry')]);

            $data = (new AutoTransformer())->transform($entry);

            self::assertArrayNotHasKey('ancestors', $data);
            self::assertArrayNotHasKey('level', $data);
            self::assertArrayNotHasKey('folderPath', $data);
        }
    }

    public function testCategoryTransformWritesAncestorsAndLevel(): void
    {
        $category = new SearchHierarchyTestCategory();
        $category->id = 400;
        $category->siteId = 1;
        $category->title = 'Nested Category';
        $category->level = 2;
        $category->testGroup = new CategoryGroup(['name' => 'Topics', 'handle' => 'topics']);
        $category->testAncestors = new ElementCollection([$this->categoryAncestor(250, 'Root Category')]);

        $data = (new AutoTransformer())->transform($category);

        self::assertSame(2, $data['level'] ?? null);
        self::assertSame([
            ['id' => 250, 'title' => 'Root Category'],
        ], $data['ancestors'] ?? null);
    }

    public function testPublicAssetTransformWritesFolderAncestorsAndCanonicalFolderPath(): void
    {
        $root = new SearchHierarchyTestVolumeFolder(['id' => 10, 'name' => 'Uploads', 'path' => '']);
        $child = new SearchHierarchyTestVolumeFolder(['id' => 20, 'name' => 'Campaigns', 'path' => 'campaigns/']);
        $child->testParent = $root;

        $asset = new SearchHierarchyTestAsset();
        $asset->id = 500;
        $asset->siteId = 1;
        $asset->title = 'Hero Image';
        $asset->testUrl = 'https://example.test/uploads/campaigns/hero.jpg';
        $asset->testVolume = new SearchHierarchyTestVolume(['testRootUrl' => 'https://example.test/uploads/']);
        $asset->testFolder = $child;

        $data = (new AutoTransformer())->transform($asset);

        self::assertSame([
            ['id' => 10, 'title' => 'Uploads'],
            ['id' => 20, 'title' => 'Campaigns'],
        ], $data['ancestors'] ?? null);
        self::assertSame('campaigns/', $data['folderPath'] ?? null);
        self::assertArrayNotHasKey('folder', $data);
        self::assertArrayNotHasKey('folderId', $data);
    }

    public function testPrivateAssetAndUserWriteNoHierarchy(): void
    {
        $folder = new SearchHierarchyTestVolumeFolder(['id' => 20, 'name' => 'Private', 'path' => 'private/']);
        $asset = new SearchHierarchyTestAsset();
        $asset->id = 501;
        $asset->siteId = 1;
        $asset->title = 'Private Image';
        $asset->testUrl = '';
        $asset->testVolume = new SearchHierarchyTestVolume(['testRootUrl' => null]);
        $asset->testFolder = $folder;

        $assetData = (new AutoTransformer())->transform($asset);
        self::assertArrayNotHasKey('ancestors', $assetData);
        self::assertArrayNotHasKey('folderPath', $assetData);

        $user = new User();
        $user->id = 601;
        $user->siteId = 1;
        $user->username = 'search-user';

        $userData = (new AutoTransformer())->transform($user);
        self::assertArrayNotHasKey('ancestors', $userData);
        self::assertArrayNotHasKey('level', $userData);
        self::assertArrayNotHasKey('folderPath', $userData);
    }

    public function testEnrichmentReadsHierarchyOnlyFromHitMetadata(): void
    {
        $service = new EnrichmentService();

        $entry = new SearchHierarchyTestEntry();
        $entry->testSection = new Section(['name' => 'Live Section', 'handle' => 'live', 'type' => Section::TYPE_STRUCTURE]);
        $entry->testAncestors = new ElementCollection([$this->entryAncestor(999, 'Live Ancestor')]);

        $entryMetadata = $this->invokePrivate($service, 'entryMetadata', [[
            'section' => 'Hit Section',
            'sectionHandle' => 'hit',
            'sectionType' => 'structure',
            'ancestors' => [['id' => '100', 'title' => 'Hit Ancestor']],
            'level' => '2',
        ], $entry]);

        self::assertSame([['id' => 100, 'title' => 'Hit Ancestor']], $entryMetadata['ancestors'] ?? null);
        self::assertSame(2, $entryMetadata['level'] ?? null);

        $source = $this->readPluginFile('src/services/EnrichmentService.php');
        foreach (['->getAncestors(', '->getParent(', '->getFolder('] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }
    }

    public function testGraphQlHitTypeExposesAndNormalizesHierarchyFields(): void
    {
        $fields = SearchHitType::getFieldDefinitions();

        self::assertArrayHasKey('ancestors', $fields);
        self::assertArrayHasKey('level', $fields);
        self::assertArrayHasKey('folderPath', $fields);
        self::assertArrayNotHasKey('folder', $fields);
        self::assertArrayNotHasKey('folderId', $fields);
        self::assertSame('SearchManagerSearchAncestor', SearchAncestorType::getName());

        $type = new SearchHitType([
            'name' => 'SearchManagerSearchHitHierarchyTest',
            'fields' => $fields,
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(ResolveInfo::class);
        $resolveInfo->fieldName = 'ancestors';

        self::assertSame([
            ['id' => 1, 'title' => 'Root'],
            ['id' => 2, 'title' => 'Parent'],
        ], $method->invoke($type, [
            'ancestors' => [
                ['id' => '1', 'title' => 'Root'],
                ['id' => 2, 'title' => 'Parent'],
                ['id' => 'bad', 'title' => 'Dropped'],
                ['id' => 3, 'title' => ''],
            ],
        ], [], null, $resolveInfo));

        self::assertNull($method->invoke($type, ['ancestors' => []], [], null, $resolveInfo));

        $resolveInfo->fieldName = 'level';
        self::assertSame(3, $method->invoke($type, ['level' => '3'], [], null, $resolveInfo));
    }

    public function testGraphQlHeadingTypeUsesPublicSnippetShape(): void
    {
        $fields = SearchHeadingType::getFieldDefinitions();

        self::assertArrayHasKey('title', $fields);
        self::assertArrayHasKey('id', $fields);
        self::assertArrayHasKey('level', $fields);
        self::assertArrayHasKey('url', $fields);
        self::assertArrayHasKey('snippet', $fields);
        self::assertArrayNotHasKey('description', $fields);

        $type = new SearchHeadingType([
            'name' => 'SearchManagerSearchHeadingPublicShapeTest',
            'fields' => $fields,
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(ResolveInfo::class);
        $resolveInfo->fieldName = 'level';
        self::assertSame(2, $method->invoke($type, ['level' => '2'], [], null, $resolveInfo));

        $resolveInfo->fieldName = 'snippet';
        self::assertSame('Heading snippet', $method->invoke($type, ['snippet' => 'Heading snippet'], [], null, $resolveInfo));
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
     * @param array<int, mixed> $arguments
     */
    private function invokePrivate(object $object, string $methodName, array $arguments): mixed
    {
        $method = new \ReflectionMethod($object, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($path, '/'));
        self::assertIsString($contents, sprintf('Expected to read plugin file: %s', $path));

        return $contents;
    }
}

final class SearchHierarchyTestEntry extends Entry
{
    public Section $testSection;

    /** @var ElementCollection<int, ElementInterface> */
    public ElementCollection $testAncestors;

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
        return null;
    }
}

final class SearchHierarchyTestCategory extends Category
{
    public CategoryGroup $testGroup;

    /** @var ElementCollection<int, ElementInterface> */
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

final class SearchHierarchyTestAsset extends Asset
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

final class SearchHierarchyTestVolume extends Volume
{
    public ?string $testRootUrl = null;

    public function getRootUrl(): ?string
    {
        return $this->testRootUrl;
    }
}

final class SearchHierarchyTestVolumeFolder extends VolumeFolder
{
    public ?VolumeFolder $testParent = null;

    public function getParent(): ?VolumeFolder
    {
        return $this->testParent;
    }
}
