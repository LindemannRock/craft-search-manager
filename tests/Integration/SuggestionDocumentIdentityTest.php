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
final class SuggestionDocumentIdentityTest extends TestCase
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
}
