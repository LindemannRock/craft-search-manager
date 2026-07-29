<?php
/**
 * Search Manager plugin for Craft CMS 5.x
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

        self::assertSame(['東京', 'uber'], $parsed->wildcards);
        self::assertSame(['نص' => 2.0], $parsed->boosts);
        self::assertContains('نص', $parsed->terms);
    }

    public function testStructuredOperandsUseCanonicalNormalizationAfterParsing(): void
    {
        $query = "TITLE:Café,CAFÉ content:البحـث١٢ Über* ハ\u{309A}*";
        $parsed = QueryParser::parse($query, 'ar');

        self::assertSame($query, $parsed->originalQuery);
        self::assertSame([
            'title' => ['cafe', 'cafe'],
            'content' => ['البحث12'],
        ], $parsed->fieldFilters);
        self::assertSame(['uber', 'パ'], $parsed->wildcards);
    }

    public function testNormalizedFieldOperandsRestrictIndexedTermsWithoutFuzzyExpansion(): void
    {
        $engine = new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'cafe' => ['1:1' => 1, '1:2' => 1],
                ],
                titleByElement: [
                    1 => ['cafe'],
                    2 => ['coffee'],
                ],
                docLengths: ['1:1' => 1, '1:2' => 1],
                totalDocs: 2,
                avgDocLength: 1.0,
            ),
            'test-index',
        );

        self::assertSame([1], array_keys($engine->search('TITLE:CAFÉ', self::SITE_ID)));
        self::assertSame([], $engine->search('title:COFFÉE', self::SITE_ID));

        $contentEngine = new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    'cafe' => ['1:1' => 1, '1:2' => 1],
                ],
                titleByElement: [
                    1 => ['cafe'],
                    2 => ['guide'],
                ],
                docLengths: ['1:1' => 1, '1:2' => 1],
                totalDocs: 2,
                avgDocLength: 1.0,
                documentTermsById: [
                    '1:1' => ['cafe' => 1],
                    '1:2' => ['cafe' => 1],
                ],
            ),
            'test-index',
        );

        self::assertSame([2], array_keys($contentEngine->search('CONTENT:CAFÉ', self::SITE_ID)));
    }

    public function testZeroSurvivesEverySupportedStructuredQueryForm(): void
    {
        self::assertSame(['0'], QueryParser::parse('0')->terms);
        self::assertSame(['0'], QueryParser::parse('"0"')->phrases);
        self::assertSame(['title' => ['0']], QueryParser::parse('title:0')->fieldFilters);
        self::assertSame(['content' => ['0']], QueryParser::parse('content:0')->fieldFilters);
        self::assertSame(['0'], QueryParser::parse('alpha NOT 0')->notTerms);
        self::assertSame(['0'], QueryParser::parse('0*')->wildcards);
        self::assertSame(['0' => 2.0], QueryParser::parse('0^2')->boosts);

        $combined = QueryParser::parse('"0" OR title:0,00 NOT 00 0* 0^2');
        self::assertSame('OR', $combined->operator);
        self::assertSame(['0'], $combined->phrases);
        self::assertSame(['title' => ['0', '00']], $combined->fieldFilters);
        self::assertSame(['00'], $combined->notTerms);
        self::assertSame(['0'], $combined->wildcards);
        self::assertSame(['0' => 2.0], $combined->boosts);

        $explicitAnd = QueryParser::parse('title:CAFÉ AND content:ÜBER NOT 0 "Exact Phrase" 00^2');
        self::assertSame('AND', $explicitAnd->operator);
        self::assertSame(['title' => ['cafe'], 'content' => ['uber']], $explicitAnd->fieldFilters);
        self::assertSame(['0'], $explicitAnd->notTerms);
        self::assertSame(['Exact Phrase'], $explicitAnd->phrases);
        self::assertSame(['00' => 2.0], $explicitAnd->boosts);
    }

    public function testPlainZeroReturnsIndexedNumericMatch(): void
    {
        $engine = new SearchEngine(
            new RecordingStorage(
                termDocs: [
                    '0' => ['1:1' => 2],
                    '00' => ['1:2' => 1],
                ],
                titleByElement: [
                    1 => ['0'],
                    2 => ['00'],
                ],
                docLengths: ['1:1' => 2, '1:2' => 1],
                totalDocs: 2,
                avgDocLength: 1.5,
            ),
            'test-index',
        );

        self::assertSame([1], array_keys($engine->search('0', self::SITE_ID)));
        self::assertSame([2], array_keys($engine->search('00', self::SITE_ID)));
    }
}
