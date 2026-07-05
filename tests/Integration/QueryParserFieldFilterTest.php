<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\QueryParser;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class QueryParserFieldFilterTest extends TestCase
{
    private const SITE_ID = 1;

    public function testUrlsTimestampsAndUnsupportedFieldsAreNotExtractedAsFilters(): void
    {
        $parsed = QueryParser::parse('Visit https://example.com/docs at 10:30 foo:bar title:test content:tutorial');

        self::assertSame(['title' => ['test'], 'content' => ['tutorial']], $parsed->fieldFilters);
        self::assertContains('https://example.com/docs', $parsed->terms);
        self::assertContains('10:30', $parsed->terms);
        self::assertContains('foo:bar', $parsed->terms);
    }

    public function testColonOnlyQueriesDoNotTriggerAdvancedParsing(): void
    {
        self::assertFalse(QueryParser::hasAdvancedOperators('https://example.com/docs 10:30 foo:bar'));
        self::assertTrue(QueryParser::hasAdvancedOperators('title:test'));
        self::assertTrue(QueryParser::hasAdvancedOperators('content:tutorial'));
    }

    public function testSupportedTitleFieldFilterStillRestrictsResults(): void
    {
        $engine = new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'protein' => ['1:1' => 1, '1:2' => 1],
                ],
                titleByElement: [
                    1 => ['protein'],
                    2 => ['shake'],
                ],
                docLengths: ['1:1' => 2, '1:2' => 2],
                totalDocs: 2,
                avgDocLength: 2.0,
            ),
            'test-index',
        );

        $results = $engine->search('protein title:protein', self::SITE_ID);

        self::assertSame([1], array_keys($results));
    }

    public function testQueryInitialNotIsParsedAsAdvancedExclusion(): void
    {
        self::assertTrue(QueryParser::hasAdvancedOperators('NOT spam'));

        $parsed = QueryParser::parse('NOT spam protein');

        self::assertSame(['spam'], $parsed->notTerms);
        self::assertContains('protein', $parsed->terms);
        self::assertNotContains('NOT', $parsed->terms);
        self::assertNotContains('spam', $parsed->terms);
    }

    public function testUnicodeWildcardAndBoostOperatorsAreParsed(): void
    {
        $parsed = QueryParser::parse('東京* über* نص^2');

        self::assertSame(['東京', 'über'], $parsed->wildcards);
        self::assertSame(['نص' => 2.0], $parsed->boosts);
        self::assertContains('نص', $parsed->terms);
    }
}
