<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

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
            'siteId' => 1,
            'title' => 'Presenter title',
            'url' => 'https://example.test/product',
            'description' => 'Presenter description',
            'descriptionSafe' => 'Presenter safe description',
            'content' => 'Long content',
            'excerpt' => 'Excerpt',
            'score' => 9.8,
            '_snippet' => ['snippetSource' => 'content'],
            '_contentClean' => 'Internal prose-only content',
            'matchedTerms' => ['title' => ['shirt']],
            'matchedPhrases' => ['blue shirt'],
            'matchedIn' => ['content'],
            'site' => 'default',
            'siteName' => 'Default',
            'language' => 'en-US',
            'elementType' => 'product',
            'productTypeName' => 'Clothing',
            'productTypeHandle' => 'clothing',
            'variantSkus' => ['SKU-1', 'SKU-2'],
            'variantOptions' => ['Size: M', 'Color: Blue'],
            'apiKey' => 'secret-api-key',
            'authorization' => 'Bearer secret-token',
            'projectTransformer' => 'commerce-post-processor',
            'customRelevanceNote' => str_repeat('safe ', 40),
        ];

        $debug = $this->invokeSettingsControllerMethod('settingsTestIndexedDocumentDebug', [$hit, $index]);

        self::assertSame(CommerceTransformer::class, $debug['transformerClass'] ?? null);
        self::assertSame(CommerceElementTypeHelper::productElementType(), $debug['indexElementType'] ?? null);
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
            'Internal prose-only content',
            'matchedTerms',
            '_snippet',
            'descriptionSafe',
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
            'elementType' => 'variant',
            'productTypeName' => 'Clothing',
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

    public function testControllerOnlyAttachesPayloadWhenDebugMetadataIsIncluded(): void
    {
        $source = $this->readPluginFile('src/controllers/SettingsController.php');

        self::assertStringContainsString("\$includeDebugMeta = (bool) \$request->getBodyParam('includeDebugMeta', true);", $source);
        self::assertStringContainsString("\$indexedDocumentDebug = \$includeDebugMeta", $source);
        self::assertStringContainsString("if (\$includeDebugMeta) {\n                        \$debugKey = \$this->settingsTestHitDebugKey(\$hit);", $source);
        self::assertStringContainsString("\$hit['_indexedDocument'] = \$indexedDocumentDebug[\$debugKey];", $source);
        self::assertStringContainsString("'includeDebugMeta' => \$includeDebugMeta,", $source);
    }

    public function testPublicApiShapeDoesNotExposeSettingsTestDebugPayload(): void
    {
        $apiController = $this->readPluginFile('src/controllers/ApiController.php');
        $searchHitType = $this->readPluginFile('src/gql/types/SearchHitType.php');

        self::assertStringNotContainsString('_indexedDocument', $apiController);
        self::assertStringNotContainsString('_indexedDocument', $searchHitType);
    }

    public function testAssetRendererIsAllowlistedEscapedTruncatedAndKeepsUrlHardening(): void
    {
        $source = $this->readPluginFile('src/web/assets/testtool/src/test-tool.js');
        $css = $this->readPluginFile('src/web/assets/testtool/src/test-tool.css');

        foreach ([
            'function renderIndexedDocumentDebug(hit)',
            'const debug = hit._indexedDocument;',
            'renderDebugPill(T.transformerClassLabel, debug.transformerClass)',
            'renderDebugPill(T.indexElementTypeLabel, debug.indexElementType)',
            'renderDebugPill(T.documentTypeLabel, debug.documentType)',
            'renderDebugList(T.variantSkusLabel, commerce.variantSkus)',
            'renderDebugList(T.variantOptionsLabel, commerce.variantOptions)',
            'safeUrlAttribute(parentProduct.url)',
            'escapeDisplay(truncateDisplay(field.label, 32))',
            'escapeDisplay(truncateDisplay(field.value, 96))',
            '<details class="sm-test-indexed-debug">',
            '<summary>${T.indexedDocumentLabel}</summary>',
            'class="sm-test-indexed-row"',
            'class="sm-test-indexed-label"',
            'class="sm-test-indexed-value"',
            '${renderIndexedDocumentDebug(hit)}',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }

        foreach ([
            '.sm-test-indexed-debug',
            'grid-template-columns: minmax(140px, max-content) minmax(0, 1fr);',
            'overflow-wrap: anywhere;',
        ] as $needle) {
            self::assertStringContainsString($needle, $css);
        }

        foreach ([
            'Object.keys(hit)',
            'JSON.stringify(hit',
            'descriptionSafe',
            'backendId',
            'apiKey',
            'authorization',
            '#ffffbf',
            '#fef08a',
            '#854d0e',
            'color: #94a3b8',
            'color: #64748b;',
            'style=',
            '<img src="${hit.thumbnail}',
            '<a href="${hit.url}',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $source);
        }

        self::assertStringContainsString('const thumbnail = safeUrlAttribute(hit.thumbnail);', $source);
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
}
