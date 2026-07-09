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
use craft\elements\Entry;
use craft\elements\User;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\gql\types\SearchHitType;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\BaseTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Locks indexed hit type and elementType semantics.
 *
 * @since 5.53.0
 */
#[CoversClass(AbstractSearchEngineBackend::class)]
#[CoversClass(BaseTransformer::class)]
#[CoversClass(SearchHitType::class)]
final class SearchHitDocumentTypeContractTest extends TestCase
{
    public function testBaseTransformerCommonDataIncludesEntryDocumentKind(): void
    {
        $entry = new Entry();
        $entry->id = 123;
        $entry->siteId = 1;
        $entry->title = 'Base Entry';

        $data = (new DocumentTypeContractBaseTransformer())->transform($entry);

        self::assertSame(123, $data['elementId'] ?? null);
        self::assertSame('entry', $data['type'] ?? null);
        self::assertSame('entry', $data['elementType'] ?? null);
    }

    public function testBaseTransformerResolvesCoreElementDocumentKinds(): void
    {
        $transformer = new DocumentTypeContractBaseTransformer();

        self::assertSame('category', $transformer->publicResolveDocumentType(new Category()));
        self::assertSame('asset', $transformer->publicResolveDocumentType(new Asset()));
        self::assertSame('user', $transformer->publicResolveDocumentType(new User()));
    }

    public function testLocalBackendHitMergeKeepsTransformerDocumentKindOverStoredElementType(): void
    {
        $backend = $this->localBackend();
        $method = new \ReflectionMethod($backend, 'buildSearchHit');
        $method->setAccessible(true);

        $hit = $method->invoke($backend, [
            'elementType' => 'testsection',
            'documentData' => [
                'elementType' => 'entry',
                'type' => 'entry',
                'section' => 'Test Section',
                'sectionHandle' => 'testSection',
                'sectionType' => 'structure',
            ],
        ], [
            'id' => 123,
            'elementId' => 123,
            'siteId' => 1,
            'type' => 'testsection',
            'elementType' => 'testsection',
        ]);

        self::assertSame('entry', $hit['type'] ?? null);
        self::assertSame('entry', $hit['elementType'] ?? null);
        self::assertSame('Test Section', $hit['section'] ?? null);
        self::assertSame('testSection', $hit['sectionHandle'] ?? null);
        self::assertSame('structure', $hit['sectionType'] ?? null);
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
                'elementType' => 'entry',
                'type' => 'entry',
                'title' => 'Public title',
                'slug' => 'public-slug',
            ],
        ], [
            'id' => 123,
            'elementId' => 123,
            'siteId' => 1,
            'type' => 'entry',
            'elementType' => 'entry',
        ]);

        self::assertSame('Public title', $hit['title'] ?? null);
        self::assertSame('public-slug', $hit['slug'] ?? null);
        self::assertArrayNotHasKey('_title', $hit);
        self::assertArrayNotHasKey('_slug', $hit);
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
        self::assertSame('The stable lowercase document kind.', $fields['elementType']['description'] ?? null);
        self::assertArrayHasKey('sectionHandle', $fields);
        self::assertArrayHasKey('sectionType', $fields);
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
