<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\CompoundSuggestionExtractor;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class CompoundSuggestionExtractorTest extends TestCase
{
    public function testExtractorCapturesFilenameLikeCompoundsAndIgnoresLeadingDotTerms(): void
    {
        $suggestions = (new CompoundSuggestionExtractor())->extract('Use Redirect.twig and .twig in config.yaml.');

        self::assertSame(['redirect.twig', 'config.yaml'], array_keys($suggestions));
        self::assertSame('Redirect.twig', $suggestions['redirect.twig']['suggestion']);
        self::assertSame('redirect twig', $suggestions['redirect.twig']['tokenKey']);
        self::assertArrayNotHasKey('.twig', $suggestions);
        self::assertArrayNotHasKey('twig', $suggestions);
    }

    public function testExtractorPreservesHighestFrequencyDisplayVariantAndAggregatesByNormalizedSuggestion(): void
    {
        $suggestions = (new CompoundSuggestionExtractor())->extract('Open MyFile.Twig, myfile.twig, myfile.twig, and redirect.twig.');

        self::assertSame(['myfile.twig', 'redirect.twig'], array_keys($suggestions));
        self::assertSame('myfile.twig', $suggestions['myfile.twig']['suggestion']);
        self::assertSame('myfile.twig', $suggestions['myfile.twig']['normalizedSuggestion']);
        self::assertSame(3, $suggestions['myfile.twig']['frequency']);
    }

    public function testExtractorDoesNotLetFirstDisplayVariantWinWhenAnotherVariantIsMoreFrequent(): void
    {
        $suggestions = (new CompoundSuggestionExtractor())->extract('Use Redirect.twig, redirect.twig, and redirect.twig.');

        self::assertSame('redirect.twig', $suggestions['redirect.twig']['suggestion']);
        self::assertSame('redirect.twig', $suggestions['redirect.twig']['normalizedSuggestion']);
        self::assertSame(3, $suggestions['redirect.twig']['frequency']);
    }

    public function testIndexingStoresExtractedCompoundSuggestions(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
        );
        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);

        self::assertTrue($engine->indexDocument(1, 101, 'Custom templates', 'Use Redirect.twig and .twig.'));

        self::assertSame(
            ['Redirect.twig' => 1],
            $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', 1, 'en', 10),
        );
        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('.twig', 1, 'en', 10));
    }

    public function testIndexingMatchesDisplayPreservedCompoundsThroughNormalizedPrefixes(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
        );
        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);

        self::assertTrue($engine->indexDocument(1, 101, 'Custom templates', 'Use MyFile.Twig and redirect.twig.'));

        self::assertSame(['MyFile.Twig' => 1], $storage->getCompoundSuggestionsForAutocomplete('myfile.tw', 1, 'en', 10));
        self::assertSame(['redirect.twig' => 1], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', 1, 'en', 10));
        self::assertSame(['redirect.twig' => 1], $storage->getCompoundSuggestionsForAutocomplete('redirect', 1, 'en', 10));
    }
}
