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
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\gql\types\SearchHitType;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\AutoTransformer;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks indexed hit type semantics.
 *
 * @since 5.53.0
 */
#[CoversClass(AbstractSearchEngineBackend::class)]
#[CoversClass(AutoTransformer::class)]
#[CoversClass(BaseTransformer::class)]
#[CoversClass(SearchHitPresenter::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitDocumentTypeContractTest extends TestCase
{
    public function testBaseTransformerCommonDataIncludesEntryDocumentKind(): void
    {
        $entry = new Entry();
        $entry->id = 123;
        $site = Craft::$app->getSites()->getPrimarySite();
        $entry->siteId = $site->id;
        $entry->title = 'Base Entry';

        $data = (new DocumentTypeContractBaseTransformer())->transform($entry);

        self::assertSame(123, $data['elementId'] ?? null);
        self::assertSame('entry', $data['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $data);
        self::assertSame($site->handle, $data['site'] ?? null);
        self::assertSame($site->language, $data['language'] ?? null);
    }

    public function testBaseTransformerResolvesCoreElementDocumentKinds(): void
    {
        $transformer = new DocumentTypeContractBaseTransformer();

        self::assertSame('category', $transformer->publicResolveDocumentType(new Category()));
        self::assertSame('asset', $transformer->publicResolveDocumentType(new Asset()));
        self::assertSame('user', $transformer->publicResolveDocumentType(new User()));
    }

    public function testBaseTransformerUsesUserDisplayTitleFallbacks(): void
    {
        $transformer = new DocumentTypeContractBaseTransformer();
        $user = new User();
        $user->id = 123;
        $user->siteId = 1;
        $user->fullName = 'Ada Lovelace';
        $user->username = 'ada';
        $user->email = 'ada@example.test';

        self::assertSame('Ada Lovelace', $transformer->transform($user)['title'] ?? null);

        $user->fullName = '';
        self::assertSame('ada', $transformer->transform($user)['title'] ?? null);

        $user->username = '';
        self::assertSame('ada@example.test', $transformer->transform($user)['title'] ?? null);

        $user->email = '';
        self::assertSame('#123', $transformer->transform($user)['title'] ?? null);
    }

    public function testLocalBackendHitMergeKeepsTransformerDocumentKindOverStorageMetadata(): void
    {
        $backend = $this->localBackend();
        $method = new \ReflectionMethod($backend, 'buildSearchHit');
        $method->setAccessible(true);

        $hit = $method->invoke($backend, [
            'elementType' => 'testsection',
            'documentData' => [
                'type' => 'entry',
                'entrySection' => 'Test Section',
                'entrySectionHandle' => 'testSection',
                'entrySectionType' => 'structure',
            ],
        ], [
            'id' => 123,
            'elementId' => 123,
            'siteId' => 1,
            'type' => 'testsection',
        ]);

        self::assertSame('entry', $hit['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $hit);
        self::assertSame('Test Section', $hit['entrySection'] ?? null);
        self::assertSame('testSection', $hit['entrySectionHandle'] ?? null);
        self::assertSame('structure', $hit['entrySectionType'] ?? null);
    }

    public function testLocalBackendRawHitShapeUsesPublicSlugWithoutUnderscoreMirrors(): void
    {
        $backend = $this->localBackend();
        $method = new \ReflectionMethod($backend, 'buildSearchHit');
        $method->setAccessible(true);

        $hit = $method->invoke($backend, [
            'elementType' => 'entry',
            'documentData' => [
                'elementId' => 123,
                'type' => 'entry',
                'title' => 'Public title',
                'slug' => 'public-slug',
            ],
        ], [
            'id' => 123,
            'elementId' => 123,
            'siteId' => 1,
            'type' => 'entry',
        ]);

        self::assertSame('Public title', $hit['title'] ?? null);
        self::assertSame('public-slug', $hit['slug'] ?? null);
        self::assertArrayNotHasKey('_title', $hit);
        self::assertArrayNotHasKey('_slug', $hit);
        self::assertArrayNotHasKey('elementType', $hit);
    }

    public function testCustomTransformerTypeRoundTripsAndFiltersWithoutElementType(): void
    {
        $backend = $this->localBackend();
        $buildHit = new \ReflectionMethod($backend, 'buildSearchHit');
        $buildHit->setAccessible(true);
        $matchesTypeFilter = new \ReflectionMethod($backend, 'matchesTypeFilter');
        $matchesTypeFilter->setAccessible(true);

        $hit = $buildHit->invoke($backend, [
            'elementType' => 'entry',
            'documentData' => [
                'elementId' => 321,
                'type' => 'recipe',
                'title' => 'Recipe',
            ],
        ], [
            'elementId' => 321,
            'siteId' => 1,
            'type' => 'entry',
        ]);

        self::assertSame('recipe', $hit['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $hit);
        self::assertTrue($matchesTypeFilter->invoke($backend, (string)$hit['type'], 'recipe'));
        self::assertFalse($matchesTypeFilter->invoke($backend, (string)$hit['type'], 'entry'));
    }

    public function testCustomTransformerLegacyElementTypeDoesNotPromoteToType(): void
    {
        $backend = $this->localBackend();
        $method = new \ReflectionMethod($backend, 'buildSearchHit');
        $method->setAccessible(true);

        $hit = $method->invoke($backend, [
            'elementType' => 'entry',
            'documentData' => [
                'elementId' => 321,
                'elementType' => 'recipe',
                'title' => 'Recipe',
            ],
        ], [
            'elementId' => 321,
            'siteId' => 1,
            'type' => 'entry',
        ]);

        self::assertSame('entry', $hit['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $hit);
    }

    public function testTypeFilterUsesCanonicalLowercaseDocumentKind(): void
    {
        $backend = $this->localBackend();
        $method = new \ReflectionMethod($backend, 'matchesTypeFilter');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($backend, 'entry', 'entry,product'));
        self::assertFalse($method->invoke($backend, 'entry', 'testsection,product'));
    }

    public function testGraphQlHitTypeExposesEntrySectionMetadataSeparately(): void
    {
        $fields = SearchHitType::getFieldDefinitions();

        self::assertSame('The stable lowercase document kind.', $fields['type']['description'] ?? null);
        self::assertArrayNotHasKey('elementType', $fields);
        self::assertArrayHasKey('snippet', $fields);
        self::assertArrayNotHasKey('description', $fields);
        self::assertArrayNotHasKey('thumbnail', $fields);
        self::assertArrayHasKey('entrySection', $fields);
        self::assertArrayHasKey('entrySectionHandle', $fields);
        self::assertArrayHasKey('entrySectionType', $fields);
        self::assertArrayHasKey('sectionType', $fields);
        self::assertArrayNotHasKey('section', $fields);
        self::assertArrayNotHasKey('sectionHandle', $fields);
        self::assertArrayNotHasKey('id', $fields);
        self::assertArrayNotHasKey('objectID', $fields);
        self::assertArrayHasKey('categoryIds', $fields);
        self::assertArrayHasKey('volume', $fields);
        self::assertArrayHasKey('volumeHandle', $fields);
        self::assertArrayHasKey('filename', $fields);
        self::assertArrayHasKey('assetKind', $fields);
        self::assertArrayHasKey('extension', $fields);
        self::assertArrayHasKey('size', $fields);
        self::assertArrayHasKey('width', $fields);
        self::assertArrayHasKey('height', $fields);
        self::assertArrayHasKey('categoryGroup', $fields);
        self::assertArrayHasKey('categoryGroupHandle', $fields);
        self::assertArrayHasKey('docCategory', $fields);
        self::assertArrayNotHasKey('group', $fields);
        self::assertArrayNotHasKey('groupHandle', $fields);
        self::assertArrayNotHasKey('category', $fields);
        self::assertArrayHasKey('productType', $fields);
        self::assertArrayHasKey('productTypeHandle', $fields);
        self::assertArrayNotHasKey('productTypeName', $fields);
    }

    public function testGraphQlSlugResolvesFromPublicSlugOnly(): void
    {
        $type = new SearchHitType([
            'name' => 'SearchManagerSearchHitTest',
            'fields' => SearchHitType::getFieldDefinitions(),
        ]);
        $method = new \ReflectionMethod($type, 'resolve');
        $method->setAccessible(true);

        $resolveInfo = $this->createMock(\GraphQL\Type\Definition\ResolveInfo::class);
        $resolveInfo->fieldName = 'slug';

        self::assertSame('public-slug', $method->invoke($type, [
            'slug' => 'public-slug',
            '_slug' => 'legacy-slug',
        ], [], null, $resolveInfo));
        self::assertNull($method->invoke($type, [
            '_slug' => 'legacy-slug',
        ], [], null, $resolveInfo));
        self::assertNull($method->invoke($type, [
            'slug' => '',
        ], [], null, $resolveInfo));
    }

    public function testPresenterOmitsEmptyAssetSlugButKeepsSlugBearingKinds(): void
    {
        $asset = SearchHitPresenter::present([
            'elementId' => 500,
            'siteId' => 1,
            'backendId' => '500_1',
            'title' => 'Hero Image',
            'type' => 'asset',
            'elementType' => 'asset',
            'slug' => '',
            'url' => '/uploads/hero.jpg',
            'volume' => 'Uploads',
            'volumeHandle' => 'uploads',
            'filename' => 'Hero.jpg',
            'assetKind' => 'image',
            'extension' => 'jpg',
            'size' => 123456,
            'width' => 1600,
            'height' => 900,
        ]);

        self::assertSame('asset', $asset['type'] ?? null);
        self::assertArrayNotHasKey('elementType', $asset);
        self::assertSame('Uploads', $asset['volume'] ?? null);
        self::assertSame('Hero.jpg', $asset['filename'] ?? null);
        self::assertSame('image', $asset['assetKind'] ?? null);
        self::assertSame('jpg', $asset['extension'] ?? null);
        self::assertSame(123456, $asset['size'] ?? null);
        self::assertSame(1600, $asset['width'] ?? null);
        self::assertSame(900, $asset['height'] ?? null);
        self::assertArrayNotHasKey('slug', $asset);

        foreach ([
            'entry' => 'getting-started',
            'category' => 'knowledge-base',
            'product' => 'trail-sneaker',
            'source-doc' => 'installation',
        ] as $kind => $slug) {
            $hit = SearchHitPresenter::present([
                'elementId' => 123,
                'siteId' => 1,
                'backendId' => '123_1_' . $kind,
                'title' => ucfirst($kind) . ' hit',
                'type' => $kind,
                'elementType' => $kind,
                'slug' => $slug,
                'url' => '/' . $slug,
            ]);

            self::assertSame($slug, $hit['slug'] ?? null, $kind);
            self::assertArrayNotHasKey('elementType', $hit, $kind);
            self::assertNotSame('', $hit['slug'] ?? '', $kind);
            self::assertArrayNotHasKey('filename', $hit, $kind);
            self::assertArrayNotHasKey('assetKind', $hit, $kind);
            self::assertArrayNotHasKey('extension', $hit, $kind);
            self::assertArrayNotHasKey('size', $hit, $kind);
            self::assertArrayNotHasKey('width', $hit, $kind);
            self::assertArrayNotHasKey('height', $hit, $kind);
        }
    }

    public function testPresenterStripsLegacyElementTypeAcrossContractShapes(): void
    {
        $shapes = [
            'page entry' => ['type' => 'entry', 'entrySection' => 'News'],
            'asset' => ['type' => 'asset', 'filename' => 'hero.jpg', 'assetKind' => 'image'],
            'category' => ['type' => 'category', 'categoryGroup' => 'Topics'],
            'product' => ['type' => 'product', 'productType' => 'Shoes'],
            'source-doc' => ['type' => 'source-doc', 'source' => 'Docs', 'docCategory' => 'Guides'],
            'split section' => ['type' => 'entry', 'sectionType' => 'heading', 'sectionId' => 'install'],
            'promoted' => ['type' => 'entry', 'promoted' => true, 'position' => 1],
        ];

        foreach ($shapes as $label => $shape) {
            $hit = SearchHitPresenter::present(array_merge([
                'elementId' => 123,
                'siteId' => 1,
                'backendId' => '123_1_' . str_replace(' ', '-', $label),
                'title' => $label,
                'url' => '/test',
                'elementType' => $shape['type'],
            ], $shape));

            self::assertSame($shape['type'], $hit['type'] ?? null, $label);
            self::assertArrayNotHasKey('elementType', $hit, $label);
        }
    }

    private function localBackend(): AbstractSearchEngineBackend
    {
        return new class extends AbstractSearchEngineBackend {
            protected function createStorage(string $fullIndexName): StorageInterface
            {
                throw new \RuntimeException('Storage is not used by this test.');
            }

            protected function getBackendLabel(): string
            {
                return 'Test';
            }

            public function getName(): string
            {
                return 'test';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getStatus(): array
            {
                return ['available' => true];
            }
        };
    }

    private function readPluginFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . ltrim($path, '/'));
        self::assertIsString($contents, sprintf('Expected to read plugin file: %s', $path));

        return $contents;
    }
}

final class DocumentTypeContractBaseTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return ElementInterface::class;
    }

    public function transform(ElementInterface $element): array
    {
        return $this->getCommonData($element);
    }

    public function publicResolveDocumentType(ElementInterface $element): string
    {
        return $this->resolveDocumentType($element);
    }
}
