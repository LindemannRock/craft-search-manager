<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\StorageBackedBackendInterface;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #217.
 */
final class AutocompletePrefixRegressionTest extends TestCase
{
    private bool $originalEnableAutocompleteCache;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableAutocompleteCache = (bool)$settings->enableAutocompleteCache;
        $settings->enableAutocompleteCache = false;
    }

    protected function tearDown(): void
    {
        SearchManager::$plugin->getSettings()->enableAutocompleteCache = $this->originalEnableAutocompleteCache;

        parent::tearDown();
    }

    public function testPrefixAutocompleteQueriesStorageByPrefixInsteadOfGlobalTopThousandPool(): void
    {
        $autocompleteTerms = [];
        for ($i = 0; $i < 1000; $i++) {
            $autocompleteTerms['topterm' . $i] = 2000 - $i;
        }
        $autocompleteTerms['product'] = 8;
        $autocompleteTerms['protein'] = 5;
        $autocompleteTerms['profile'] = 2;

        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: $autocompleteTerms,
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'limit' => 2,
            'minLength' => 1,
            'siteId' => 1,
        ]);

        self::assertSame(['product', 'protein'], $suggestions);
        self::assertSame('pro', $storage->getTermsForAutocompleteCalls[0]['prefix'] ?? null);
        self::assertSame(4, $storage->getTermsForAutocompleteCalls[0]['limit'] ?? null);
        self::assertArrayNotHasKey('profile', array_flip($suggestions));
    }

    public function testCompoundAutocompleteUsesStoredCompoundPrefixWithoutLastTokenFallback(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: [
                'twig' => 20,
                'twiggy' => 10,
                'redirect' => 5,
            ],
            compoundSuggestions: [
                'redirect.twig' => 7,
                'redirecttemplate.twig' => 1,
            ],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('redirect.tw', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
        ]);

        self::assertSame(['redirect.twig'], $suggestions);
        self::assertSame('redirect.tw', $storage->getCompoundSuggestionsForAutocompleteCalls[0]['normalizedPrefix'] ?? null);
        self::assertSame([], $storage->getTermsForAutocompleteCalls);
    }

    public function testCompoundAutocompleteFullDottedQueryUsesCompoundSuggestions(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: ['twig' => 20],
            compoundSuggestions: ['redirect.twig' => 7],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('redirect.twig', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
        ]);

        self::assertSame(['redirect.twig'], $suggestions);
        self::assertSame('redirect.twig', $storage->getCompoundSuggestionsForAutocompleteCalls[0]['normalizedPrefix'] ?? null);
        self::assertSame([], $storage->getTermsForAutocompleteCalls);
    }

    public function testLeadingDotAndOrdinaryTermsRemainOnNormalAutocompletePath(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: [
                'redirect' => 9,
                'twig' => 8,
            ],
            compoundSuggestions: ['redirect.twig' => 7],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        self::assertSame(['redirect'], SearchManager::$plugin->autocomplete->suggest('redirect', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
        ]));
        self::assertSame(['twig'], SearchManager::$plugin->autocomplete->suggest('twig', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
        ]));
        self::assertSame(['twig'], SearchManager::$plugin->autocomplete->suggest('.twig', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
        ]));

        self::assertSame([], $storage->getCompoundSuggestionsForAutocompleteCalls);
        self::assertSame(['redirect', 'twig', 'twig'], array_column($storage->getTermsForAutocompleteCalls, 'prefix'));
    }
}

final class AutocompletePrefixBackendService extends BackendService
{
    public function __construct(private readonly RecordingStorage $storage)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return new AutocompletePrefixBackend($this->storage);
    }
}

final class AutocompletePrefixBackend implements BackendInterface, StorageBackedBackendInterface
{
    public function __construct(private readonly RecordingStorage $storage)
    {
    }

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

    public function search(string $indexName, string $query, array $options = []): array
    {
        return [];
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'autocomplete-prefix';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }

    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

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

    public function getStorage(string $indexHandle): RecordingStorage
    {
        return $this->storage;
    }
}
