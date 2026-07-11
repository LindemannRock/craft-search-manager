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
use lindemannrock\searchmanager\search\storage\MySqlStorage;
use lindemannrock\searchmanager\search\storage\PostgreSqlStorage;
use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regressions for confirming-scan audit #247 and #249.
 *
 * @since 5.53.0
 */
final class AuditConfirmingScanRegressionTest extends TestCase
{
    private const MYSQL_INDEX_HANDLE = 'test_audit_confirming_scan';
    private const TEST_SITE_ID = 1;

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
            'applyPromotions',
            'public',
        );

        self::assertStringContainsString('$indexedDocuments = $this->indexedPromotionDocuments($promotions, $indexHandle, $siteId);', $source);
        self::assertStringContainsString('$promotedItem = $indexedDocuments[$elementId] ?? null;', $source);
        self::assertStringContainsString('Skipping promotion because target document is not indexed', $source);
        self::assertStringNotContainsString('promotionIdentity', $source);
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

    public function testMySqlPrefixSearchTreatsLikeWildcardsLiterally(): void
    {
        $storage = new MySqlStorage(self::MYSQL_INDEX_HANDLE);
        $storage->storeTermDocument('foo_bar', self::TEST_SITE_ID, 101, 1);
        $storage->storeTermDocument('fooXbar', self::TEST_SITE_ID, 102, 1);
        $storage->storeTermDocument('sale%off', self::TEST_SITE_ID, 103, 1);
        $storage->storeTermDocument('saleXoff', self::TEST_SITE_ID, 104, 1);
        $storage->storeElement(self::TEST_SITE_ID, 201, 'Foo_Bar Product', 'entry');
        $storage->storeElement(self::TEST_SITE_ID, 202, 'FooXBar Product', 'entry');
        $storage->storeElement(self::TEST_SITE_ID, 203, 'Sale%Off Product', 'entry');
        $storage->storeElement(self::TEST_SITE_ID, 204, 'SaleXOff Product', 'entry');

        self::assertSame(['foo_bar'], $storage->getTermsByPrefix('foo_', self::TEST_SITE_ID));
        self::assertSame(['sale%off'], $storage->getTermsByPrefix('sale%', self::TEST_SITE_ID));
        self::assertSame([201], array_map('intval', array_column($storage->getElementSuggestions('foo_', self::TEST_SITE_ID), 'elementId')));
        self::assertSame([203], array_map('intval', array_column($storage->getElementSuggestions('sale%', self::TEST_SITE_ID), 'elementId')));
    }

    public function testElementSuggestionsDedupeSplitSectionDocumentKeys(): void
    {
        $storage = new MySqlStorage(self::MYSQL_INDEX_HANDLE);
        $storage->storeElementByKey(self::TEST_SITE_ID, 301, '301_1_intro', 'Install Guide', 'source-doc');
        $storage->storeElementByKey(self::TEST_SITE_ID, 301, '301_1_install', 'Install Guide', 'source-doc');
        $storage->storeElementByKey(self::TEST_SITE_ID, 301, '301_1_configure', 'Install Guide', 'source-doc');

        $suggestions = $storage->getElementSuggestions('install', self::TEST_SITE_ID);

        self::assertCount(1, $suggestions);
        self::assertSame([301], array_map('intval', array_column($suggestions, 'elementId')));
        self::assertSame(['Install Guide'], array_column($suggestions, 'title'));
    }

    public function testPostgreSqlPrefixSearchEscapesLikeWildcardsAndRuntimeMatchesWhenAvailable(): void
    {
        $source = $this->readPluginSource('src/search/storage/PostgreSqlStorage.php');
        $termsBody = $this->methodBody($source, 'getTermsByPrefix', 'public');
        $suggestionsBody = $this->methodBody($source, 'getElementSuggestions', 'public');

        self::assertStringContainsString("self::escapeLikePrefix(\$prefix) . '%'", $termsBody);
        self::assertStringContainsString("self::escapeLikePrefix(\$searchText) . '%'", $suggestionsBody);
        self::assertStringContainsString("->groupBy(['title', 'elementType', 'elementId', 'siteId'])", $suggestionsBody);
        self::assertStringNotContainsString("\$prefix . '%'", $termsBody);
        self::assertStringNotContainsString("\$searchText . '%'", $suggestionsBody);

        if (Craft::$app->getDb()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL runtime coverage requires Craft DB driver pgsql.');
        }

        $storage = new PostgreSqlStorage(self::MYSQL_INDEX_HANDLE);
        $storage->clearAll();

        try {
            $storage->storeTermDocument('foo_bar', self::TEST_SITE_ID, 101, 1);
            $storage->storeTermDocument('fooXbar', self::TEST_SITE_ID, 102, 1);
            $storage->storeElement(self::TEST_SITE_ID, 201, 'Foo_Bar Product', 'entry');
            $storage->storeElement(self::TEST_SITE_ID, 202, 'FooXBar Product', 'entry');

            self::assertSame(['foo_bar'], $storage->getTermsByPrefix('foo_', self::TEST_SITE_ID));
            self::assertSame([201], array_map('intval', array_column($storage->getElementSuggestions('foo_', self::TEST_SITE_ID), 'elementId')));
        } finally {
            $storage->clearAll();
        }
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
