<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\QueryUnderstanding;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Layer-1 contract of the shared search/autocomplete core (#383/#384 Phase A):
 * one parse produces the normalized text, tokens, operator, compound flag, and
 * autocomplete last-token state both surfaces will consume.
 *
 * @since 5.53.0
 */
final class QueryUnderstandingParseTest extends TestCase
{
    public function testMultiWordInputTokenizesIntoIndividualTerms(): void
    {
        $parsed = QueryUnderstanding::parse('testing tool');

        self::assertSame(['testing', 'tool'], $parsed->tokens);
        self::assertSame('AND', $parsed->operator);
        self::assertSame('testing tool', $parsed->normalizedQuery);
        self::assertFalse($parsed->isCompound);
        self::assertFalse($parsed->lastTokenIncomplete, 'search parsing never flags an incomplete token');
    }

    public function testNormalizedQueryFoldsCaseAndCollapsesWhitespace(): void
    {
        $parsed = QueryUnderstanding::parse('  Testing   TOOL ');

        self::assertSame('testing tool', $parsed->normalizedQuery);
        self::assertSame(['testing', 'tool'], $parsed->tokens);
    }

    public function testOrOperatorIsDetectedAndTokensExcludeIt(): void
    {
        $parsed = QueryUnderstanding::parse('test OR tool');

        self::assertSame('OR', $parsed->operator);
        self::assertSame(['test', 'tool'], $parsed->tokens);
    }

    public function testQuotedPhraseStaysOutOfPlainTokens(): void
    {
        $parsed = QueryUnderstanding::parse('"testing tools" guide');

        self::assertSame(['testing tools'], $parsed->phrases);
        self::assertSame(['guide'], $parsed->tokens);
    }

    public function testDottedCompoundIsFlaggedWithNormalizedPrefix(): void
    {
        $parsed = QueryUnderstanding::parse('config.php');

        self::assertTrue($parsed->isCompound);
        self::assertSame('config.php', $parsed->compoundPrefix);
        self::assertSame(['config', 'php'], $parsed->tokens, 'compound still tokenizes like indexed content');
    }

    public function testAutocompleteFlagsLastTokenWhileTyping(): void
    {
        $typing = QueryUnderstanding::parse('testing tool', ['forAutocomplete' => true]);
        $completed = QueryUnderstanding::parse('testing tool ', ['forAutocomplete' => true]);
        $closedPhrase = QueryUnderstanding::parse('guide "testing tools"', ['forAutocomplete' => true]);

        self::assertTrue($typing->lastTokenIncomplete);
        self::assertFalse($completed->lastTokenIncomplete, 'trailing whitespace closes the last token');
        self::assertFalse($closedPhrase->lastTokenIncomplete, 'a closing quote closes the last token');
    }

    public function testArabicInputNormalizesTatweelAndTokenizes(): void
    {
        // First word carries a tatweel (U+0640) that normalization must strip.
        $parsed = QueryUnderstanding::parse("البحـث المتقدم", ['language' => 'ar']);

        self::assertSame(['البحث', 'المتقدم'], $parsed->tokens);
        self::assertSame('AND', $parsed->operator);
    }
}
