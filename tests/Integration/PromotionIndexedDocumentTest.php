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
use lindemannrock\searchmanager\models\Promotion;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for confirming-scan audit #247 and #249.
 *
 * @since 5.53.0
 */
final class PromotionIndexedDocumentTest extends TestCase
{
    private const MYSQL_INDEX_HANDLE = 'test_audit_confirming_scan';
    protected function tearDown(): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_search_terms}}', ['indexHandle' => self::MYSQL_INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_search_elements}}', ['indexHandle' => self::MYSQL_INDEX_HANDLE])
            ->execute();

        parent::tearDown();
    }

    public function testPromotionServiceFetchesIndexedDocumentsByElementId(): void
    {
        $source = $this->methodBody(
            $this->readPluginSource('src/services/PromotionService.php'),
            'applyPromotionsWithOutcome',
            'public',
        );

        self::assertStringContainsString('$indexedDocuments = $this->indexedPromotionDocuments($promotions, $indexHandle, $siteId);', $source);
        self::assertStringContainsString('$promotedItem = $indexedDocuments[$elementId] ?? null;', $source);
        self::assertStringContainsString('Skipping promotion because target document is not indexed', $source);
        self::assertStringContainsString('$this->promotionIdentity($elementId, $siteId)', $source);
        self::assertStringNotContainsString('siteIdsByPromotion', $source);
    }

    public function testPromotionsUseSearchedSiteIndexedDocumentMetadata(): void
    {
        $stub = $this->installStubBackend();
        $elementId = 2147482901;
        $siteId = 2;
        $stub->documentsByElementId['test-index:' . $elementId . ':' . $siteId] = [
            'id' => $elementId,
            'elementId' => $elementId,
            'siteId' => $siteId,
            'title' => 'Indexed searched-site promotion',
            'url' => '/indexed-searched-site-promotion',
            'type' => 'entry',
        ];

        $promotion = $this->makePromotion(24701, $elementId, $siteId, 1);

        $results = (new PromotionService())->applyPromotions(
            [['elementId' => 999999, 'siteId' => $siteId]],
            'duplicate promotion site',
            'test-index',
            $siteId,
            [$promotion],
        );

        self::assertSame($siteId, $results[0]['siteId'] ?? null);
        self::assertSame($elementId, $results[0]['elementId'] ?? null);
        self::assertSame('Indexed searched-site promotion', $results[0]['title'] ?? null);
    }

    private function makePromotion(int $id, int $elementId, int $siteId, int $position): Promotion
    {
        $promotion = new Promotion();
        $promotion->id = $id;
        $promotion->query = 'duplicate promotion site';
        $promotion->indexHandle = 'test-index';
        $promotion->elementId = $elementId;
        $promotion->elementType = \craft\elements\Entry::class;
        $promotion->siteId = $siteId;
        $promotion->position = $position;
        $promotion->enabled = true;

        return $promotion;
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }
}
