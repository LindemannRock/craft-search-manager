<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\IndexedSnippetService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins display-field scope in indexed snippet term derivation.
 *
 * @since 5.54.0
 */
#[CoversClass(IndexedSnippetService::class)]
final class IndexedSnippetFieldScopeTest extends TestCase
{
    public function testFieldScopedAndBareQueriesDeriveTermsForTheCorrectDisplayFields(): void
    {
        $service = SearchManager::$plugin->indexedSnippets;

        self::assertSame([], $this->snippetTerms($service, 'title:search', ['content' => ['search']]));
        self::assertSame(['search'], $this->titleTerms($service, 'title:search'));
        self::assertSame([], $this->titleTerms($service, 'content:search', ['title' => ['search']]));
        self::assertSame(['search'], $this->snippetTerms($service, 'content:search'));
        self::assertSame(['search'], $this->titleTerms($service, 'search'));
        self::assertSame(['search'], $this->snippetTerms($service, 'search'));
    }

    public function testSnippetProjectionRepeatsExactFirstSelectionForDisplayedText(): void
    {
        $service = SearchManager::$plugin->indexedSnippets;
        $resolved = [
            'test' => [
                ['term' => 'test', 'matchType' => 'exact', 'similarity' => 1.0],
                ['term' => 'text', 'matchType' => 'fuzzy', 'similarity' => 0.2],
            ],
        ];

        $exact = $service->prepareHitSnippets([
            '_fields' => ['description' => 'TEST TEXT appears in this displayed area.'],
            'matchedTerms' => ['content' => ['test']],
            '_resultHighlightTerms' => $resolved,
        ], 'test');
        $fuzzy = $service->prepareHitSnippets([
            '_fields' => ['description' => 'Only TEXT appears in this displayed area.'],
            'matchedTerms' => ['content' => ['test']],
            '_resultHighlightTerms' => $resolved,
        ], 'test');

        self::assertSame(['test'], $exact['snippetMatchedTerms']);
        self::assertSame(['text'], $fuzzy['snippetMatchedTerms']);
    }

    /**
     * @return list<string>
     */
    private function snippetTerms(IndexedSnippetService $service, string $query, array $matchedTerms = []): array
    {
        $method = new \ReflectionMethod($service, 'resolveFieldSnippetTerms');

        return $method->invoke($service, [], $matchedTerms, $query, '');
    }

    /**
     * @return list<string>
     */
    private function titleTerms(IndexedSnippetService $service, string $query, array $matchedTerms = []): array
    {
        $method = new \ReflectionMethod($service, 'resolveTitleMatchTerms');

        return $method->invoke($service, [], $matchedTerms, $query, '');
    }
}
