<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\controllers\ApiController;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit batch 8 hardening.
 *
 * @since 5.53.0
 */
final class PublicLanguageBoundaryTest extends TestCase
{
    public function testPublicLanguageEntryPointsNormalizeAndDropUnsafeValues(): void
    {
        self::assertSame('en-us', ApiController::normalizePublicLanguage('en_US'));
        self::assertSame('pt-br', SearchResolver::normalizePublicLanguage('pt_BR'));
        self::assertNull(ApiController::normalizePublicLanguage('../../../../tmp/payload'));
        self::assertNull(SearchResolver::normalizePublicLanguage('en.php'));
    }

    public function testGraphqlSchemaExposesLangAliasForSearchAndAutocomplete(): void
    {
        $queries = SearchQuery::getQueries(false);

        self::assertArrayHasKey('lang', $queries['searchManagerSearch']['args']);
        self::assertArrayHasKey('lang', $queries['searchManagerAutocomplete']['args']);
    }
}
