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
use lindemannrock\searchmanager\controllers\SearchController;
use lindemannrock\searchmanager\gql\queries\SearchQuery;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\models\QueryRule;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit batch 8 hardening.
 *
 * @since 5.53.0
 */
final class QueryRuleRegexValidationTest extends TestCase
{
    public function testQueryRuleRegexRejectsBacktrackingProbeFailures(): void
    {
        $rule = $this->queryRule('(a+)+$');

        self::assertFalse($rule->validate(['matchValue']));
        self::assertNotEmpty($rule->getErrors('matchValue'));
    }

    public function testQueryRuleRegexStillAcceptsNormalAdminPatterns(): void
    {
        $rule = $this->queryRule('^(coffee|tea)\\s+beans?$');

        self::assertTrue($rule->validate(['matchValue']));
        self::assertTrue($rule->matches('Coffee beans'));
        self::assertFalse($rule->matches('coffee grinder'));
    }

    private function queryRule(string $pattern): QueryRule
    {
        $rule = new QueryRule();
        $rule->name = 'Audit batch 8 regex';
        $rule->matchType = QueryRule::MATCH_REGEX;
        $rule->matchValue = $pattern;
        $rule->actionType = QueryRule::ACTION_SYNONYM;
        $rule->actionValue = ['terms' => ['coffee']];

        return $rule;
    }
}
