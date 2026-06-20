<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\SearchManagerVariable;

/**
 * Pins Twig-facing search guards for audit #104.
 */
final class SearchManagerVariableGuardTest extends TestCase
{
    public function testOverlongTwigSearchQueryIsRejectedWithoutDelegating(): void
    {
        $variable = new SearchManagerVariable();
        $stub = $this->installStubBackend();
        $query = str_repeat('a', 257);

        $response = $variable->search('content', $query, ['limit' => 10]);

        self::assertSame([], $response['hits']);
        self::assertSame(0, $response['total']);
        self::assertSame($query, $response['query']);
        self::assertSame('Query too long (max 256 characters)', $response['error']);
        self::assertSame([], $stub->callsFor('search'));

        $multiResponse = $variable->searchMultiple(['content', 'products'], $query, ['limit' => 10]);

        self::assertSame([], $multiResponse['hits']);
        self::assertSame(0, $multiResponse['total']);
        self::assertSame([], $multiResponse['indices']);
        self::assertSame($query, $multiResponse['query']);
        self::assertSame('Query too long (max 256 characters)', $multiResponse['error']);
        self::assertSame([], $stub->callsFor('searchMultiple'));
    }

    public function testTwigSearchLimitsAreClampedBeforeBackendDelegation(): void
    {
        $variable = new SearchManagerVariable();
        $stub = $this->installStubBackend();

        $variable->search('content', 'coffee', ['limit' => 999]);
        $variable->searchMultiple(['content', 'products'], 'coffee', ['hitsPerPage' => 999]);

        $searchCalls = $stub->callsFor('search');
        self::assertCount(1, $searchCalls);
        self::assertSame(200, $searchCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('hitsPerPage', $searchCalls[0]['items'][0]['options']);

        $searchMultipleCalls = $stub->callsFor('searchMultiple');
        self::assertCount(1, $searchMultipleCalls);
        self::assertSame(200, $searchMultipleCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('hitsPerPage', $searchMultipleCalls[0]['items'][0]['options']);
    }

    public function testTwigSuggestUsesAutocompleteSizedLimitCap(): void
    {
        $variable = new SearchManagerVariable();
        $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $suggestions = $variable->suggest('coffee', 'content', ['hitsPerPage' => 999]);

        self::assertSame(['coffee'], $suggestions);
        self::assertCount(1, $autocomplete->suggestCalls);
        self::assertSame(100, $autocomplete->suggestCalls[0]['options']['limit']);
        self::assertArrayNotHasKey('hitsPerPage', $autocomplete->suggestCalls[0]['options']);
    }

    public function testOverlongTwigSuggestReturnsEmptyWithoutDelegating(): void
    {
        $variable = new SearchManagerVariable();
        $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $suggestions = $variable->suggest(str_repeat('a', 257), 'content', ['limit' => 10]);

        self::assertSame([], $suggestions);
        self::assertSame([], $autocomplete->suggestCalls);
    }
}

final class SearchManagerVariableRecordingAutocompleteService extends AutocompleteService
{
    /** @var list<array{query: string, indexHandle: string, options: array<string, mixed>}> */
    public array $suggestCalls = [];

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function suggest(string $query, string $indexHandle, array $options = []): array
    {
        $this->suggestCalls[] = [
            'query' => $query,
            'indexHandle' => $indexHandle,
            'options' => $options,
        ];

        return ['coffee'];
    }
}
