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
use craft\base\Field;
use craft\elements\ElementCollection;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use lindemannrock\searchmanager\controllers\SettingsController;
use lindemannrock\searchmanager\helpers\CommerceElementTypeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\transformers\CommerceTransformer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins the settings test-tool indexed document debug summary.
 *
 * @since 5.53.0
 */
#[CoversClass(SettingsController::class)]
final class TestToolIndexedDocumentDebugTest extends TestCase
{
    public function testServerDebugPayloadIsCuratedCommerceAndCustomContext(): void
    {
        $index = new SearchIndex();
        $index->elementType = CommerceElementTypeHelper::productElementType();
        $hit = [
            'id' => 123,
            'backendId' => '123_1_intro',
            'siteId' => 1,
            'title' => 'Presenter title',
            'url' => 'https://example.test/product',
            'snippet' => 'Presenter description',
            'content' => 'Long content',
            'excerpt' => 'Excerpt',
            'score' => 9.8,
            '_snippet' => ['snippetSource' => 'content'],
            'matchedTerms' => ['title' => ['shirt']],
            'matchedPhrases' => ['blue shirt'],
            'matchedIn' => ['content'],
            'site' => 'default',
            'siteName' => 'Default',
            'language' => 'en-US',
            'type' => 'product',
            'productType' => 'Clothing',
            'productTypeHandle' => 'clothing',
            'variantSkus' => ['SKU-1', 'SKU-2'],
            'variantOptions' => ['Size: M', 'Color: Blue'],
            'apiKey' => 'secret-api-key',
            'authorization' => 'Bearer secret-token',
            '_fields' => [
                'projectTransformer' => 'commerce-post-processor',
                'customRelevanceNote' => str_repeat('safe ', 40),
            ],
        ];

        $debug = $this->invokeSettingsControllerMethod('settingsTestIndexedDocumentDebug', [$hit, $index]);

        self::assertSame(CommerceTransformer::class, $debug['transformerClass'] ?? null);
        self::assertSame(CommerceElementTypeHelper::productElementType(), $debug['indexElementType'] ?? null);
        self::assertSame('123_1_intro', $debug['documentKey'] ?? null);
        self::assertSame('product', $debug['documentType'] ?? null);
        self::assertSame([
            'name' => 'Clothing',
            'handle' => 'clothing',
        ], $debug['commerce']['productType'] ?? null);
        self::assertSame(['SKU-1', 'SKU-2'], $debug['commerce']['variantSkus'] ?? null);
        self::assertSame(['Size: M', 'Color: Blue'], $debug['commerce']['variantOptions'] ?? null);

        $customFieldLabels = array_column($debug['customFields'] ?? [], 'label');
        self::assertContains('Project Transformer', $customFieldLabels);
        self::assertContains('Custom Relevance Note', $customFieldLabels);

        $encoded = json_encode($debug, JSON_THROW_ON_ERROR);
        foreach ([
            'secret-api-key',
            'Bearer secret-token',
            'Presenter title',
            'Presenter description',
            'matchedTerms',
            '_snippet',
            'score',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        self::assertStringContainsString('...', $encoded);
    }

    public function testServerDebugPayloadSupportsVariantParentProductContext(): void
    {
        $index = new SearchIndex();
        $index->elementType = CommerceElementTypeHelper::variantElementType();
        $hit = [
            'id' => 456,
            'siteId' => 1,
            'type' => 'variant',
            'productType' => 'Clothing',
            'productTypeHandle' => 'clothing',
            'variantOptions' => ['Size: L'],
            'productTitle' => 'Blue Shirt',
            'productSlug' => 'blue-shirt',
            'productUrl' => 'https://example.test/products/blue-shirt',
        ];

        $debug = $this->invokeSettingsControllerMethod('settingsTestIndexedDocumentDebug', [$hit, $index]);

        self::assertSame('variant', $debug['documentType'] ?? null);
        self::assertSame([
            'title' => 'Blue Shirt',
            'slug' => 'blue-shirt',
            'url' => 'https://example.test/products/blue-shirt',
        ], $debug['commerce']['parentProduct'] ?? null);
        self::assertArrayNotHasKey('customFields', $debug);
    }

    public function testServerDebugPayloadCanGroupNestedMatrixCustomFields(): void
    {
        $matrixField = new Matrix([
            'handle' => 'testcommerce',
            'name' => 'Testcommerce',
            'searchable' => true,
        ]);
        $visibleNestedField = new PlainText([
            'handle' => 'textArea',
            'name' => 'Text Area',
            'searchable' => true,
        ]);
        $hiddenNestedField = new PlainText([
            'handle' => 'passwordToken',
            'name' => 'Password Token',
            'searchable' => true,
        ]);
        $nonSearchableNestedField = new PlainText([
            'handle' => 'hiddenNested',
            'name' => 'Hidden Nested',
            'searchable' => false,
        ]);

        $nestedElement = new SearchManagerIndexedDebugTestElement();
        $nestedElement->setTestFieldLayout($this->fieldLayout([$visibleNestedField, $hiddenNestedField, $nonSearchableNestedField]));
        $nestedElement->setTestFieldValues([
            'textArea' => 'THIS IS A TEST TEXT WHAT IS THIS TEXT TEXT AREA',
            'passwordToken' => 'secret nested token',
            'hiddenNested' => 'Matrix nested hidden needle',
        ]);

        $element = new SearchManagerIndexedDebugTestElement();
        $element->setTestFieldLayout($this->fieldLayout([$matrixField]));
        $element->setTestFieldValues([
            'testcommerce' => new ElementCollection([$nestedElement]),
        ]);

        $fields = $this->invokeSettingsControllerMethod('settingsTestCustomIndexedFields', [[
            '_fields' => [
                'testcommerce' => 'THIS IS A TEST TEXT WHAT IS THIS TEXT TEXT AREA secret nested token Matrix nested hidden needle',
                'productDescription' => 'Lorem ipsum dolor sit amet',
            ],
        ], $element]);

        self::assertSame('Testcommerce', $fields[0]['label'] ?? null);
        self::assertArrayNotHasKey('value', $fields[0]);
        self::assertSame([
            [
                'label' => 'Text Area',
                'value' => 'THIS IS A TEST TEXT WHAT IS THIS TEXT TEXT AREA',
            ],
        ], $fields[0]['children'] ?? null);

        $encoded = json_encode($fields, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret nested token', $encoded);
        self::assertStringNotContainsString('Matrix nested hidden needle', $encoded);
        self::assertStringNotContainsString('{', (string)($fields[0]['children'][0]['value'] ?? ''));
    }

    public function testServerDebugPayloadFallsBackToFlatCustomFields(): void
    {
        $fields = $this->invokeSettingsControllerMethod('settingsTestCustomIndexedFields', [[
            '_fields' => [
                'productDescription' => 'Lorem ipsum dolor sit amet',
            ],
        ]]);

        self::assertSame([
            [
                'label' => 'Product Description',
                'value' => 'Lorem ipsum dolor sit amet',
            ],
        ], $fields);
    }

    public function testControllerOnlyAttachesPayloadWhenDebugMetadataIsIncluded(): void
    {
        $source = $this->readPluginFile('src/controllers/SettingsController.php');

        self::assertStringContainsString("\$includeDebugMeta = (bool) \$request->getBodyParam('includeDebugMeta', false);", $source);
        self::assertStringContainsString("\$indexedDocumentDebug = \$includeDebugMeta", $source);
        self::assertStringContainsString("if (\$includeDebugMeta) {\n                foreach (\$enhancedHits as &\$hit) {\n                    \$debugKey = \$this->settingsTestHitDebugKey(\$hit);", $source);
        self::assertStringContainsString("\$hit['_indexedDocument'] = \$indexedDocumentDebug[\$debugKey];", $source);
        self::assertStringContainsString("'includeSnippetDebug' => \$includeDebugMeta,", $source);
    }

    public function testPublicApiShapeDoesNotExposeSettingsTestDebugPayload(): void
    {
        $apiController = $this->readPluginFile('src/controllers/ApiController.php');
        $searchHitType = $this->readPluginFile('src/gql/types/SearchHitType.php');

        self::assertStringNotContainsString('_indexedDocument', $apiController);
        self::assertStringNotContainsString('_indexedDocument', $searchHitType);
    }

    public function testResultCardUsesSlimPublicCardAndElementIdContract(): void
    {
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        foreach ([
            'function hitElementId(hit)',
            'const raw = hit.elementId;',
            'function formatMetaLabel(label)',
            "const hasSectionHit = ['heading', 'intro', 'promoted-page'].includes(String(hit.sectionType || ''));",
            'const rawTitle = hasSectionHit ? (hit.sectionTitle || hit.title || T.untitled) : (hit.title || T.untitled);',
            'const rawUrl = hasSectionHit ? (hit.sectionUrl || hit.url) : hit.url;',
            'const rawType = hit.type || T.entry;',
            'const siteName = hit.siteName || hit.site || T.unknown;',
            'renderMetaPill(T.typeLabel, rawType)',
            'renderMetaPill(T.siteLabel, siteName)',
            'const rawDisplayText = rawSnippet;',
            'const displayText = rawSnippet ? smHighlight(rawSnippet.substring(0, 400), query, descTerms) : \'\';',
            '<strong class="sm-test-title">${title}</strong>',
            'class="sm-test-signals"',
            '${renderLiveComparison(hit)}',
            '${renderIndexedDocumentDebug(hit)}',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        self::assertStringNotContainsString('<span class="sm-test-meta-label">${T.sectionLabel}</span> ${section}', $source);
        self::assertStringNotContainsString('hit.productTypeName', $source);
        self::assertStringNotContainsString("isCommerceHit ? hit.section : ''", $source);
        self::assertStringNotContainsString('hit.id', $source);
        self::assertStringNotContainsString('hit.objectID || hit.id', $source);
        self::assertStringNotContainsString('const contextValue = isCommerceHit ? productType : hit.section;', $source);
        self::assertStringNotContainsString('function resultContext(hit, normalizedType)', $source);
        self::assertStringNotContainsString('formatMetaLabel(\'ID\')', $source);
        self::assertStringNotContainsString('backendIdDisplay', $source);
        self::assertStringNotContainsString('${contextMeta}', $source);
        self::assertStringNotContainsString('${hierarchyMeta}', $source);
        self::assertStringNotContainsString('${sectionMeta}', $source);
        self::assertStringNotContainsString('${renderHitFields(hit)}', $source);
        self::assertStringNotContainsString('matchedIn ? `<div class="sm-test-match-line"', $source);
        self::assertStringNotContainsString('matchedTerms.length > 0 || matchedPhrases.length > 0', $source);
        self::assertStringNotContainsString("const rawExcerpt = data.enriched ? '' : (hit.excerpt || hit.content || '');", $source);
        self::assertStringNotContainsString("const rawDisplayText = rawDescription || hit.excerpt || hit.content || '';", $source);
    }

    public function testIndexedDocumentDebugUsesTypeSpecificContractMetadata(): void
    {
        $controllerSource = $this->readPluginFile('src/controllers/SettingsController.php');
        $assetSource = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');

        foreach ([
            'private function settingsTestElementKindDebug(array $hit): array',
            "'entrySection' => \$this->settingsTestScalarDebugValue(\$hit['entrySection'] ?? null),",
            "'volume' => \$this->settingsTestScalarDebugValue(\$hit['volume'] ?? null),",
            "'categoryGroup' => \$this->settingsTestScalarDebugValue(\$hit['categoryGroup'] ?? null),",
            "'ancestors' => \$this->settingsTestAncestorsDebugValue(\$hit['ancestors'] ?? null),",
            "'level' => \$this->settingsTestIntegerDebugValue(\$hit['level'] ?? null),",
            "'folderPath' => \$this->settingsTestScalarDebugValue(\$hit['folderPath'] ?? null),",
            "'volumehandle',",
            "'categorygrouphandle',",
        ] as $needle) {
            self::assertStringContainsString($needle, $controllerSource);
        }

        foreach ([
            'function friendlyDebugLabel(key)',
            'entrySection: T.entrySectionLabel',
            'entrySectionHandle: T.entrySectionHandleLabel',
            'entrySectionType: T.entrySectionTypeLabel',
            'categoryGroup: T.categoryGroupLabel',
            'categoryGroupHandle: T.categoryGroupHandleLabel',
            'docCategory: T.documentCategoryLabel',
            'categoryIds: T.categoryIdsLabel',
            'function indexedDocumentData(hit)',
            'Object.entries(hit || {}).forEach(([key, value]) => {',
            'mergeDebugData(data, hit && hit._indexedDocument);',
            "if ((key === 'elementKind' || key === 'commerce') && isPlainObject(value)) {",
            "if (key === 'ancestors') {",
            'return renderDataRow(T.breadcrumbLabel, ancestorBreadcrumb(value));',
            "if (key === 'fields') {",
            'return renderFieldsRow(T.customFieldsLabel, value);',
            "if (key === 'headings') {",
            'return renderHeadingsRow(T.headingsLabel, value);',
            'return renderDataRow(friendlyDebugLabel(key), value);',
            "return ancestors.length > 0 ? ancestors.map(ancestor => ancestor.title).join(' › ') : '';",
        ] as $needle) {
            self::assertStringContainsString($needle, $assetSource);
        }
    }

    public function testSettingsSearchUsesCanonicalPipelineAndNoManualHydration(): void
    {
        $source = $this->readPluginFile('src/controllers/SettingsController.php');

        self::assertStringContainsString('CanonicalHitPipeline::presentHits', $source);
        self::assertStringContainsString('SearchManager::$plugin->liveComparison->compareHits', $source);
        self::assertStringNotContainsString('settingsTestDocumentTypeForElement', $source);
        self::assertStringNotContainsString('$hit[\'type\'] = $documentType;', $source);
        self::assertStringNotContainsString('$hit[\'elementType\'] = $documentType;', $source);
        self::assertStringNotContainsString('$hit[\'sectionHandle\'] = $section->handle;', $source);
        self::assertStringNotContainsString('$hit[\'sectionType\'] = $section->type;', $source);
        self::assertStringNotContainsString('$hit[\'type\'] = (new \ReflectionClass($element))->getShortName();', $source);
    }

    public function testAssetRendererIsAllowlistedEscapedTruncatedAndKeepsUrlHardening(): void
    {
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');
        $css = $this->readPluginFile('src/web/assets/testtool/src/test-tool.css');

        foreach ([
            'function renderIndexedDocumentDebug(hit)',
            'if (!isPlainObject(hit && hit._indexedDocument)) {',
            'function renderDataRow(label, value)',
            'function renderHeadingsRow(label, headings)',
            'function renderFieldsRow(label, fields)',
            'function debugDisplayValue(value)',
            'function mergeDebugData(target, source)',
            'transformerClass: T.transformerClassLabel',
            'indexElementType: T.indexElementTypeLabel',
            'documentKey: T.documentKeyLabel',
            'documentType: T.documentTypeLabel',
            'assetKind: T.assetKindLabel',
            'filename: T.filenameLabel',
            'width: T.widthLabel',
            'height: T.heightLabel',
            'source: T.sourceLabel',
            'escapeDisplay(truncateDisplay(field.label, 32))',
            'escapeDisplay(truncateDisplay(field.value, 96))',
            'function renderCustomField(field)',
            'function fieldRowsFromFields(fields)',
            'Array.isArray(field.children) ? field.children : []',
            "const hasSectionHit = ['heading', 'intro', 'promoted-page'].includes(String(hit.sectionType || ''));",
            'const rawTitle = hasSectionHit ? (hit.sectionTitle || hit.title || T.untitled) : (hit.title || T.untitled);',
            'const rawUrl = hasSectionHit ? (hit.sectionUrl || hit.url) : hit.url;',
            'const url = safeUrlAttribute(rawUrl);',
            'renderMetaPill(T.typeLabel, rawType)',
            'renderMetaPill(T.siteLabel, siteName)',
            'class="sm-test-indexed-custom-group"',
            'class="sm-test-indexed-custom-field-label"',
            'class="sm-test-indexed-custom-child"',
            'escapeDisplay(truncateDisplay(child.label, 32))',
            'escapeDisplay(truncateDisplay(child.value, 96))',
            '<details class="sm-test-indexed-debug">',
            '<summary>${T.indexedDocumentLabel}</summary>',
            'class="sm-test-indexed-row"',
            'class="sm-test-indexed-label"',
            'class="sm-test-indexed-value"',
            '${renderIndexedDocumentDebug(hit)}',
            "const snippetLengthInput = document.getElementById('snippetLength');",
            'const snippetLength = Math.min(maxSnippetLength, Math.max(minSnippetLength, parseInt(snippetLengthInput.value, 10) || defaultSnippetLength));',
            'snippetLengthInput.value = snippetLength;',
            'snippetLength: snippetLength,',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        foreach ([
            '.sm-test-indexed-debug',
            '.sm-test-indexed-custom-group',
            '.sm-test-indexed-custom-field-label',
            '.sm-test-indexed-custom-child-label',
            'grid-template-columns: minmax(140px, max-content) minmax(0, 1fr);',
            'overflow-wrap: anywhere;',
        ] as $needle) {
            self::assertStringContainsString($needle, $css);
        }

        foreach ([
            'Object.keys(hit)',
            'JSON.stringify(hit',
            'apiKey',
            'authorization',
            '#ffffbf',
            '#fef08a',
            '#854d0e',
            'color: #94a3b8',
            'color: #64748b;',
            'style=',
            'hit.thumbnail',
            'sm-test-thumb',
            '<img src="${hit.thumbnail}',
            '<a href="${hit.url}',
            'renderDebugPill(T.transformerClassLabel, debug.transformerClass)',
            'renderDebugList(T.variantSkusLabel, commerce.variantSkus)',
            'safeUrlAttribute(parentProduct.url)',
            'Object.keys(hit)',
            'formatMetaLabel(\'ID\')',
            '${renderHitFields(hit)}',
            '${sectionMeta}',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }

        self::assertStringContainsString('function safeUrlAttribute(value)', $source);
    }

    public function testTwigRegistersAssetBundleAndOnlyPassesTranslationConfig(): void
    {
        $twig = $this->readPluginFile('src/templates/settings/test/_partials/search.twig');

        self::assertStringContainsString("view.registerAssetBundle('lindemannrock\\\\searchmanager\\\\web\\\\assets\\\\testtool\\\\TestToolAsset')", $twig);
        self::assertStringContainsString('indexedDocumentLabel:', $twig);
        self::assertStringContainsString("'Indexed Document'|t('search-manager')", $twig);
        self::assertStringNotContainsString('function renderIndexedDocumentDebug', $twig);
        self::assertStringNotContainsString('function safeUrlAttribute', $twig);
        self::assertStringNotContainsString('Object.keys(hit)', $twig);
    }

    /**
     * @param list<mixed> $arguments
     * @return mixed
     */
    private function invokeSettingsControllerMethod(string $method, array $arguments): mixed
    {
        $controller = new SettingsController('settings', SearchManager::$plugin);
        $reflection = new \ReflectionMethod($controller, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($controller, $arguments);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }

    /**
     * @param Field[] $fields
     */
    private function fieldLayout(array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => SearchManagerIndexedDebugTestElement::class]);
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

final class SearchManagerIndexedDebugTestElement extends Element
{
    private ?FieldLayout $testFieldLayout = null;

    /**
     * @var array<string, mixed>
     */
    private array $testFieldValues = [];

    public static function displayName(): string
    {
        return 'Search Manager Indexed Debug Test Element';
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
