<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\services\PromotionService;
use lindemannrock\searchmanager\services\QueryRuleService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #429 and #431-#437.
 *
 * @since 5.54.0
 */
final class SourceAbsenceContractTest extends TestCase
{
    public function testDeadOptionBuildersAreRemoved(): void
    {
        self::assertFalse(method_exists(PromotionService::class, 'getIndexOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getIndexOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getSectionOptions'));
        self::assertFalse(method_exists(QueryRuleService::class, 'getCategoryGroupOptions'));
    }

    public function testOrphanedIndexStylesTemplateIsRemoved(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/templates/_partials/common/index-styles.twig');
    }
}
