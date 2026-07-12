<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\interfaces\AutocompleteBackendInterface;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\services\AutocompleteService;
use lindemannrock\searchmanager\tests\TestCase;
use lindemannrock\searchmanager\variables\BackendVariableProxy;
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
        $variable->searchMultiple(['content', 'products'], 'coffee', ['resultsLimit' => 999]);

        $searchCalls = $stub->callsFor('search');
        self::assertCount(1, $searchCalls);
        self::assertSame(200, $searchCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('resultsLimit', $searchCalls[0]['items'][0]['options']);

        $searchMultipleCalls = $stub->callsFor('searchMultiple');
        self::assertCount(1, $searchMultipleCalls);
        self::assertSame(200, $searchMultipleCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('resultsLimit', $searchMultipleCalls[0]['items'][0]['options']);
    }

    public function testTwigVariableAndProxyNormalizeSearchLimitsIdentically(): void
    {
        $cases = [
            [['limit' => 0], 20],
            [['limit' => -5], 20],
            [['limit' => 'invalid'], 20],
            [['resultsLimit' => 0], 20],
            [['resultsLimit' => 999], 200],
            [['limit' => 5, 'resultsLimit' => 999], 5],
        ];

        foreach ($cases as [$options, $expectedLimit]) {
            $variable = new SearchManagerVariable();
            $variableStub = $this->installStubBackend();
            $proxyStub = new SearchManagerVariableRecordingBackend();
            $proxy = new BackendVariableProxy($proxyStub, 'stub');

            $variable->search('content', 'coffee', $options);
            $proxy->search('content', 'coffee', $options);

            $variableOptions = $variableStub->callsFor('search')[0]['items'][0]['options'];
            $proxyOptions = $proxyStub->callsFor('search')[0]['items'][0]['options'];

            self::assertSame($expectedLimit, $variableOptions['limit']);
            self::assertSame($expectedLimit, $proxyOptions['limit']);
            self::assertSame($variableOptions, $proxyOptions);
            self::assertArrayNotHasKey('resultsLimit', $variableOptions);
            self::assertArrayNotHasKey('resultsLimit', $proxyOptions);
        }
    }

    public function testTwigSuggestUsesAutocompleteSizedLimitCap(): void
    {
        $variable = new SearchManagerVariable();
        $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $suggestions = $variable->suggest('coffee', 'content', ['resultsLimit' => 999]);

        self::assertSame(['coffee'], $suggestions);
        self::assertCount(1, $autocomplete->suggestCalls);
        self::assertSame(100, $autocomplete->suggestCalls[0]['options']['limit']);
        self::assertArrayNotHasKey('resultsLimit', $autocomplete->suggestCalls[0]['options']);
    }

    public function testTwigVariableAndProxyNormalizeAutocompleteLimitsIdentically(): void
    {
        $cases = [
            [['limit' => 0], 10],
            [['limit' => -5], 10],
            [['limit' => 'invalid'], 10],
            [['resultsLimit' => 0], 10],
            [['resultsLimit' => 999], 100],
            [['limit' => 5, 'resultsLimit' => 999], 5],
        ];

        foreach ($cases as [$options, $expectedLimit]) {
            $variable = new SearchManagerVariable();
            $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
            $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

            $proxyStub = new SearchManagerVariableRecordingBackend();
            $proxy = new BackendVariableProxy($proxyStub, 'stub');

            $variable->suggest('coffee', 'content', $options);
            $proxy->suggest('coffee', 'content', $options);

            $variableOptions = $autocomplete->suggestCalls[0]['options'];
            $proxyOptions = $proxyStub->callsFor('autocomplete')[0]['items'][0]['options'];

            self::assertSame($expectedLimit, $variableOptions['limit']);
            self::assertSame($expectedLimit, $proxyOptions['limit']);
            self::assertSame($variableOptions, $proxyOptions);
            self::assertArrayNotHasKey('resultsLimit', $variableOptions);
            self::assertArrayNotHasKey('resultsLimit', $proxyOptions);
        }
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

    public function testProxySearchQueryGuardsMatchTwigVariableGuards(): void
    {
        $stub = new SearchManagerVariableRecordingBackend();
        $proxy = new BackendVariableProxy($stub, 'stub');
        $query = str_repeat('a', 257);

        $response = $proxy->search('content', $query, ['limit' => 10]);

        self::assertSame([], $response['hits']);
        self::assertSame(0, $response['total']);
        self::assertSame($query, $response['query']);
        self::assertSame('Query too long (max 256 characters)', $response['error']);
        self::assertSame([], $stub->callsFor('search'));

        $proxy->search('content', 'coffee', ['resultsLimit' => 999]);

        $searchCalls = $stub->callsFor('search');
        self::assertCount(1, $searchCalls);
        self::assertSame(200, $searchCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('resultsLimit', $searchCalls[0]['items'][0]['options']);
    }

    public function testProxySuggestQueryGuardsMatchTwigVariableGuards(): void
    {
        $stub = new SearchManagerVariableRecordingBackend();
        $proxy = new BackendVariableProxy($stub, 'stub');
        $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        $overlongSuggestions = $proxy->suggest(str_repeat('a', 257), 'content', ['limit' => 10]);

        self::assertSame([], $overlongSuggestions);
        self::assertSame([], $autocomplete->suggestCalls);

        $suggestions = $proxy->suggest('coffee', 'content', ['resultsLimit' => 999]);

        self::assertSame(['backend-coffee'], $suggestions);
        self::assertSame([], $autocomplete->suggestCalls);
        self::assertSame([], $stub->callsFor('search'));

        $autocompleteCalls = $stub->callsFor('autocomplete');
        self::assertCount(1, $autocompleteCalls);
        self::assertSame(100, $autocompleteCalls[0]['items'][0]['options']['limit']);
        self::assertArrayNotHasKey('resultsLimit', $autocompleteCalls[0]['items'][0]['options']);
    }

    public function testProxySuggestReturnsEmptyWhenSelectedBackendCannotAutocomplete(): void
    {
        $stub = new SearchManagerVariableRecordingBackend();
        $stub->autocompleteSupported = false;
        $proxy = new BackendVariableProxy($stub, 'stub');
        $autocomplete = new SearchManagerVariableRecordingAutocompleteService();
        $this->swapPluginComponent('search-manager', 'autocomplete', $autocomplete);

        self::assertSame([], $proxy->suggest('coffee', 'content', ['limit' => 10]));
        self::assertSame([], $autocomplete->suggestCalls);
        self::assertSame([], $stub->callsFor('autocomplete'));
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

final class SearchManagerVariableRecordingBackend implements BackendInterface, AutocompleteBackendInterface
{
    /** @var list<array{method: string, indexName: string, items?: array<int, array<string, mixed>>}> */
    public array $calls = [];
    public bool $autocompleteSupported = true;

    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    public function indexWithResult(string $indexName, array $data): array
    {
        return [
            'success' => true,
            'wasCreated' => true,
        ];
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    public function batchDelete(string $indexName, array $items): bool
    {
        return true;
    }

    public function deleteOrphanDocuments(string $indexName, int $elementId, ?int $siteId, array $keepBackendIds): bool
    {
        return true;
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        return [
            'success' => true,
            'existed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'search',
            'indexName' => $indexName,
            'items' => [
                [
                    'query' => $query,
                    'options' => $options,
                ],
            ],
        ];

        return [
            'hits' => [],
            'total' => 0,
        ];
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'test';
    }

    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function multipleQueries(array $queries = []): array
    {
        return [];
    }

    public function parseFilters(array $filters = []): string
    {
        return '';
    }

    public function supportsBrowse(): bool
    {
        return false;
    }

    public function supportsMultipleQueries(): bool
    {
        return false;
    }

    public function supportsAutocomplete(): bool
    {
        return $this->autocompleteSupported;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function autocomplete(string $indexName, string $query, array $options = []): array
    {
        $this->calls[] = [
            'method' => 'autocomplete',
            'indexName' => $indexName,
            'items' => [
                [
                    'query' => $query,
                    'options' => $options,
                ],
            ],
        ];

        return ['backend-coffee'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndices(): array
    {
        return [];
    }

    public function setConfiguredSettings(array $settings): void
    {
    }

    public function setBackendHandle(string $handle): void
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsFor(string $method): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method,
        ));
    }
}
