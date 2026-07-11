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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\helpers\AutoTransformerSectionSplitter;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\helpers\HtmlSectionSplitter;
use lindemannrock\searchmanager\helpers\SearchContentCleaner;
use lindemannrock\searchmanager\helpers\SearchFieldTypeContentHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchHitPresenter;
use lindemannrock\searchmanager\helpers\SearchRecordProjectionHelper;
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins split-section indexing for Commerce Product and Variant indices.
 *
 * @since 5.53.0
 */
#[CoversClass(AutoTransformerSectionSplitter::class)]
#[CoversClass(HtmlSectionSplitter::class)]
final class CommerceSplitSectionsTest extends TestCase
{
    private const INDEX_HANDLE = 'test_commerce_split_sections';
    private const ECO_SHIRT_ID = 49595;

    protected function tearDown(): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);
        parent::tearDown();
    }

    public function testEcoShirtProductSplitsAtHeadingBoundariesAndPreservesCommerceMetadata(): void
    {
        $product = $this->ecoShirtProduct();
        $pageData = $this->transform($product);
        $documents = $this->documentsFor($product, CommerceElementTypeHelper::productElementType(), $pageData);

        self::assertGreaterThan(8, count($documents));
        self::assertSame('intro', $documents[0]['sectionId']);
        self::assertSame(0, $documents[0]['sectionIndex']);
        self::assertStringContainsString((string)$pageData['title'], (string)$documents[0]['content']);

        $sectionIds = array_column($documents, 'sectionId');
        foreach ([
            'eco-shirt-overview',
            'designed-for-everyday-comfort',
            'soft-touch-fabric',
            'breathability',
            'sustainable-materials',
            'organic-cotton',
            'recycled-fibers',
            'frequently-asked-questions',
        ] as $expectedSectionId) {
            self::assertContains($expectedSectionId, $sectionIds);
        }

        $sectionTitles = array_column($documents, 'sectionTitle');
        self::assertContains('Eco Shirt Overview', $sectionTitles);
        self::assertContains('Designed for Everyday Comfort', $sectionTitles);
        self::assertContains('Soft Touch Fabric', $sectionTitles);
        self::assertContains('Breathability', $sectionTitles);
        self::assertContains('Sustainable Materials', $sectionTitles);
        self::assertContains('Organic Cotton', $sectionTitles);
        self::assertContains('Recycled Fibers', $sectionTitles);
        self::assertContains('Frequently Asked Questions', $sectionTitles);

        $metadataKeys = array_values(array_filter([
            'type',
            'elementType',
            'productType',
            'productTypeHandle',
            'variantSkus',
            'variantTitles',
            'variantOptions',
            'defaultVariantSku',
            'defaultVariantTitle',
            'price',
        ], static fn(string $key): bool => array_key_exists($key, $pageData)));

        self::assertContains('productTypeHandle', $metadataKeys);
        self::assertTrue(in_array('variantSkus', $metadataKeys, true) || in_array('defaultVariantSku', $metadataKeys, true));

        foreach ($documents as $document) {
            foreach ($metadataKeys as $key) {
                self::assertSame($pageData[$key], $document[$key] ?? null, $key . ' differs on ' . ($document['sectionId'] ?? '?'));
            }
        }

        $heading = $documents[1];
        self::assertSame('heading', $heading['sectionType']);
        self::assertSame($heading['sectionTitle'], $heading['content']);
        self::assertArrayNotHasKey('_fields', $heading);
        self::assertArrayNotHasKey('fields', $heading);
    }

    public function testHeadinglessProductAndVariantStayNormalRecords(): void
    {
        $product = $this->ecoShirtProduct();
        $pageData = $this->transform($product);
        $headingFieldHandle = $this->richTextHeadingFieldHandle($product);
        $original = $product->getFieldValue($headingFieldHandle);
        $product->setFieldValue($headingFieldHandle, '<p>Headingless product body.</p>');

        $productDocuments = $this->documentsFor($product, CommerceElementTypeHelper::productElementType(), $pageData);
        self::assertCount(1, $productDocuments);
        self::assertSame($pageData, $productDocuments[0]);

        $product->setFieldValue($headingFieldHandle, $original);

        $variant = $this->headinglessVariant();
        $variantData = $this->transform($variant);
        $variantDocuments = $this->documentsFor($variant, CommerceElementTypeHelper::variantElementType(), $variantData);

        self::assertCount(1, $variantDocuments);
        self::assertSame($variantData, $variantDocuments[0]);
        self::assertArrayNotHasKey('sectionId', $variantDocuments[0]);
    }

    public function testCommerceSplitSectionsUsePublicRestGraphqlAndProjectionContracts(): void
    {
        $product = $this->ecoShirtProduct();
        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['productTypeHandle']);
        $documents = $this->documentsFor($product, CommerceElementTypeHelper::productElementType());
        $heading = $documents[1];

        $record = SearchRecordProjectionHelper::localDocumentData(self::INDEX_HANDLE, $heading);
        self::assertArrayHasKey('_sectionBodyWithCode', $record);
        self::assertArrayNotHasKey('sectionBody', $record);
        self::assertArrayNotHasKey('fields', $record);

        $public = SearchHitPresenter::present($record);
        self::assertSame('heading', $public['sectionType'] ?? null);
        self::assertSame($heading['sectionId'], $public['sectionId'] ?? null);
        self::assertArrayNotHasKey('_sectionBodyWithCode', $public);
        self::assertArrayNotHasKey('sectionBody', $public);
    }

    public function testEcoShirtWysiwygMirrorIsControlledOnlyByRetrievableFields(): void
    {
        $product = $this->ecoShirtProduct();
        if (!$product->getFieldLayout()?->getFieldByHandle('wysiwyg')) {
            self::markTestSkipped('Requires the Eco Shirt product fixture with a wysiwyg rich-text field.');
        }

        $data = $this->transform($product);
        self::assertArrayHasKey('wysiwyg', $data['_fields'] ?? []);
        self::assertArrayHasKey('_bodyClean', $data);

        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['*']);
        $record = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $data);
        self::assertArrayHasKey('wysiwyg', $record['fields'] ?? []);
        self::assertArrayHasKey('wysiwyg', $record['_snippetFields'] ?? []);
        self::assertSame($data['_fields']['wysiwyg'], $record['fields']['wysiwyg']);
        self::assertSame($data['_fields']['wysiwyg'], $record['_snippetFields']['wysiwyg']);

        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['*', '-wysiwyg']);
        $excludedRecord = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $data);
        self::assertArrayNotHasKey('wysiwyg', $excludedRecord['fields'] ?? []);
        self::assertArrayHasKey('heading', $excludedRecord['fields'] ?? []);
        self::assertArrayHasKey('productDescription', $excludedRecord['fields'] ?? []);
        self::assertArrayHasKey('wysiwyg', $excludedRecord['_snippetFields'] ?? []);

        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['productDescription']);
        $narrowRecord = SearchRecordProjectionHelper::externalRecord(self::INDEX_HANDLE, $data);
        self::assertArrayNotHasKey('wysiwyg', $narrowRecord['fields'] ?? []);
        self::assertArrayHasKey('wysiwyg', $narrowRecord['_snippetFields'] ?? []);
    }

    public function testTypeFilterAndPromotionUseProductSectionMetadata(): void
    {
        $product = $this->ecoShirtProduct();
        $documents = $this->documentsFor($product, CommerceElementTypeHelper::productElementType());

        $method = new \ReflectionMethod(SearchManager::$plugin->backend, 'filterHitsByType');
        $method->setAccessible(true);

        self::assertCount(count($documents), $method->invoke(SearchManager::$plugin->backend, $documents, 'product'));
        self::assertSame([], $method->invoke(SearchManager::$plugin->backend, $documents, 'variant'));

        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['*']);
        $stub = $this->installStubBackend();
        $intro = $documents[0];
        $stub->documentsByElementId[self::INDEX_HANDLE . ':' . (int)$product->id . ':' . (int)$product->siteId] = $intro;

        $promotion = new Promotion();
        $promotion->id = 2147483101;
        $promotion->title = 'Eco Shirt promotion';
        $promotion->query = 'eco shirt';
        $promotion->matchType = 'exact';
        $promotion->elementId = (int)$product->id;
        $promotion->elementType = CommerceElementTypeHelper::productElementType();
        $promotion->position = 1;
        $promotion->siteId = (int)$product->siteId;

        $results = SearchManager::$plugin->promotions->applyPromotions(
            [],
            'eco shirt',
            self::INDEX_HANDLE,
            (int)$product->siteId,
            [$promotion],
        );

        self::assertCount(1, $results);
        self::assertTrue($results[0]['promoted'] ?? false);
        self::assertSame('promoted-page', $results[0]['sectionType'] ?? null);
        self::assertSame('promoted-page', $results[0]['sectionId'] ?? null);
        self::assertSame('product', $results[0]['type'] ?? null);
        self::assertSame($intro['productTypeHandle'] ?? null, $results[0]['productTypeHandle'] ?? null);
    }

    public function testProductContentEditReslicesAndDeletesRemovedHeadingOrphans(): void
    {
        $product = $this->ecoShirtProduct();
        $headingFieldHandle = $this->richTextHeadingFieldHandle($product);
        $original = $product->getFieldValue($headingFieldHandle);
        $stub = $this->installStubBackend();
        $this->saveTestIndex(CommerceElementTypeHelper::productElementType(), ['*']);

        SearchManager::$plugin->indexing->indexElementNow($product);
        $firstKeepSet = $this->lastKeepSet($stub->calls);
        self::assertNotEmpty($firstKeepSet);

        $product->setFieldValue($headingFieldHandle, '<p>Edited product intro.</p><h2>Replacement Product Heading</h2><p>Replacement product body.</p>');
        SearchManager::$plugin->indexing->indexElementNow($product);
        $secondKeepSet = $this->lastKeepSet($stub->calls);

        $product->setFieldValue($headingFieldHandle, $original);

        self::assertContains(
            SearchHitIdentityHelper::sectionDocumentId((int)$product->id, (int)$product->siteId, 'replacement-product-heading'),
            $secondKeepSet,
        );
        self::assertNotSame($firstKeepSet, $secondKeepSet);
        foreach ($firstKeepSet as $oldKey) {
            if (str_ends_with($oldKey, '_intro')) {
                continue;
            }
            self::assertNotContains($oldKey, $secondKeepSet);
        }
    }

    public function testSplitSectionsSupportResolvesCommerceAndProjectTransformerFamilies(): void
    {
        $productIndex = new SearchIndex();
        $productIndex->name = 'Product Split';
        $productIndex->handle = 'product-split';
        $productIndex->elementType = CommerceElementTypeHelper::productElementType();
        $productIndex->splitSections = true;

        self::assertTrue($productIndex->usesSplitSections());

        $productIndex->transformerClass = CommerceTransformer::class;
        self::assertTrue($productIndex->usesSplitSections());

        $projectTransformer = 'modules\\searchmanager\\transformers\\ExampleCommercePostProcessorTransformer';
        if (class_exists($projectTransformer)) {
            $productIndex->transformerClass = $projectTransformer;
            self::assertTrue($productIndex->usesSplitSections());
        }

        $productIndex->transformerClass = self::class;
        $productIndex->validateSplitSectionsSupport('splitSections');
        self::assertContains(
            'Split Sections supports AutoTransformer-family indices, plus SourceDoc indices with DocsManagerTransformer-family transformers.',
            $productIndex->getErrors('splitSections'),
        );
    }

    private function ecoShirtProduct(): ElementInterface
    {
        if (!CommerceElementTypeHelper::productElementTypeAvailable()) {
            self::markTestSkipped('Craft Commerce Product elements are not available.');
        }

        $productClass = CommerceElementTypeHelper::productElementType();
        $product = $productClass::find()
            ->id(self::ECO_SHIRT_ID)
            ->status(null)
            ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
            ->one()
            ?? $productClass::find()
                ->title('Eco Shirt 9')
                ->status(null)
                ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
                ->one();

        if (!$product instanceof ElementInterface) {
            self::markTestSkipped('Requires the Eco Shirt 9 Commerce product fixture.');
        }

        $this->richTextHeadingFieldHandle($product);

        return $product;
    }

    private function headinglessVariant(): ElementInterface
    {
        if (!CommerceElementTypeHelper::variantElementTypeAvailable()) {
            self::markTestSkipped('Craft Commerce Variant elements are not available.');
        }

        $variantClass = CommerceElementTypeHelper::variantElementType();
        $variant = $variantClass::find()
            ->status(null)
            ->siteId((int)Craft::$app->getSites()->getPrimarySite()->id)
            ->one();

        if (!$variant instanceof ElementInterface) {
            self::markTestSkipped('Requires at least one Commerce variant fixture.');
        }

        return $variant;
    }

    private function richTextHeadingFieldHandle(ElementInterface $element): string
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            self::markTestSkipped('Element has no field layout.');
        }

        foreach ($layout->getCustomFields() as $field) {
            if (!(new SearchFieldTypeContentHelper(new SearchContentCleaner()))->isRichTextField($field)) {
                continue;
            }

            try {
                $value = (string)$element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if (preg_match('/<h[23][^>]*>/i', $value)) {
                return $field->handle;
            }
        }

        self::markTestSkipped('Requires a searchable rich-text field with h2/h3 headings.');
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(ElementInterface $element): array
    {
        $data = SearchManager::$plugin->transformers->transform($element, self::INDEX_HANDLE, null, [2, 3]);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed>|null $pageData
     * @return list<array<string, mixed>>
     */
    private function documentsFor(ElementInterface $element, string $elementType, ?array $pageData = null): array
    {
        $index = new SearchIndex();
        $index->name = 'Commerce Split';
        $index->handle = self::INDEX_HANDLE;
        $index->elementType = $elementType;
        $index->splitSections = true;
        $index->headingLevels = [2, 3];

        $method = new \ReflectionMethod(SearchManager::$plugin->indexing, 'documentsForIndex');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> */
        return $method->invoke(SearchManager::$plugin->indexing, $index, $element, $pageData ?? $this->transform($element));
    }

    /**
     * @param list<string> $retrievableFields
     */
    private function saveTestIndex(string $elementType, array $retrievableFields): void
    {
        $this->deleteTestIndexByHandle(self::INDEX_HANDLE);

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Test Commerce Split Sections',
            'handle' => self::INDEX_HANDLE,
            'elementType' => $elementType,
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

    private function deleteTestIndexByHandle(string $handle): void
    {
        $ids = (new Query())
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
}
