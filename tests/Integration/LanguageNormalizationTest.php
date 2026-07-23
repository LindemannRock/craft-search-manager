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
use lindemannrock\searchmanager\interfaces\StorageBackedBackendInterface;
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\search\StopWords;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for public language sanitization and local autocomplete
 * language compatibility.
 */
final class LanguageNormalizationTest extends TestCase
{
    private bool $originalEnableCache;
    private bool $originalEnableAutocompleteCache;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = SearchManager::$plugin->getSettings();
        $this->originalEnableCache = (bool)$settings->enableCache;
        $this->originalEnableAutocompleteCache = (bool)$settings->enableAutocompleteCache;
        $settings->enableCache = false;
        $settings->enableAutocompleteCache = false;
    }

    protected function tearDown(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $settings->enableCache = $this->originalEnableCache;
        $settings->enableAutocompleteCache = $this->originalEnableAutocompleteCache;

        parent::tearDown();
    }

    public function testLanguageNormalizerAcceptsExpectedHandles(): void
    {
        self::assertSame('en', LanguageNormalizer::normalize('en'));
        self::assertSame('ar', LanguageNormalizer::normalize('AR'));
        self::assertSame('fr', LanguageNormalizer::normalize('fr'));
        self::assertSame('en-us', LanguageNormalizer::normalize('en-US'));
        self::assertSame('pt-br', LanguageNormalizer::normalize('pt_BR'));
    }

    public function testLanguageNormalizerRejectsPathAndWrapperPayloads(): void
    {
        foreach ([
            '../../../../tmp/payload',
            '..\\..\\payload',
            'php://filter',
            'en.php',
            "en\0us",
            'en/us',
            'en:us',
        ] as $payload) {
            self::assertNull(LanguageNormalizer::normalizeOrNull($payload), $payload);
            self::assertSame('en', LanguageNormalizer::normalize($payload), $payload);
        }
    }

    public function testDirectStopWordsConstructionFallsBackForUnsafeLanguage(): void
    {
        $GLOBALS['searchManagerStopWordsPayloadLoaded'] = false;
        $stopWords = new StopWords('../../../tests/Fixtures/stopwords-payload');

        self::assertFalse($GLOBALS['searchManagerStopWordsPayloadLoaded']);
        self::assertGreaterThan(0, $stopWords->getCount());
    }

    public function testSearchEngineNormalizesLanguageFilterVariants(): void
    {
        $storage = $this->makeLanguageStorage([
            '1:1' => 'en-us',
            '1:2' => 'fr',
        ]);
        $engine = new SearchEngine($storage, 'test-index');

        $hyphenResults = $engine->search('protein', 1, 0, ['language' => 'en-US']);
        $underscoreResults = $engine->search('protein', 1, 0, ['language' => 'en_US']);

        self::assertSame([1], array_keys($hyphenResults));
        self::assertSame([1], array_keys($underscoreResults));
    }

    public function testSearchEngineIgnoresUnsafeLanguageFilter(): void
    {
        $storage = $this->makeLanguageStorage([
            '1:1' => 'en',
            '1:2' => 'fr',
        ]);
        $engine = new SearchEngine($storage, 'test-index');

        $results = $engine->search('protein', 1, 0, ['language' => '../../../../tmp/payload']);

        self::assertSame([1, 2], array_keys($results));
    }

    public function testBackendServiceCanonicalizesPublicSearchLanguageOption(): void
    {
        $backend = new LanguageRecordingBackend();
        $service = new LanguageRecordingBackendService($backend);

        $service->search('content', '__sm_language_test_' . uniqid('', true), [
            'language' => 'en_US',
            'siteId' => 1,
            'skipAnalytics' => true,
        ]);

        self::assertSame('en-us', $backend->searchCalls[0]['options']['language'] ?? null);
    }

    public function testBackendServiceDropsUnsafePublicSearchLanguageOption(): void
    {
        $backend = new LanguageRecordingBackend();
        $service = new LanguageRecordingBackendService($backend);

        $service->search('content', '__sm_language_test_' . uniqid('', true), [
            'language' => '../../../../tmp/payload',
            'siteId' => 1,
            'skipAnalytics' => true,
        ]);

        self::assertArrayNotHasKey('language', $backend->searchCalls[0]['options']);
    }

    #[DataProvider('regionalAutocompleteLanguages')]
    public function testAutocompleteServicePassesBareRegionalLanguageToLocalPrefixStorage(
        string $publicLanguage,
        string $storedLanguage,
        string $query,
        string $term,
    ): void {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: [$term => 3],
        );
        $backend = new LanguageRecordingBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        $suggestions = SearchManager::$plugin->autocomplete->suggest($query, 'content', [
            'language' => $publicLanguage,
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
            'fuzzy' => false,
        ]);

        self::assertSame([$term], $suggestions);
        self::assertSame($storedLanguage, $storage->getTermsForAutocompleteCalls[0]['language'] ?? null);
    }

    #[DataProvider('regionalAutocompleteLanguages')]
    public function testAutocompleteServicePassesBareRegionalLanguageToLocalCompoundStorage(
        string $publicLanguage,
        string $storedLanguage,
        string $query,
        string $term,
    ): void {
        $compound = $term . '.twig';
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            compoundSuggestions: [$compound => 3],
        );
        $backend = new LanguageRecordingBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        $suggestions = SearchManager::$plugin->autocomplete->suggest($term . '.tw', 'content', [
            'language' => $publicLanguage,
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
            'fuzzy' => false,
        ]);

        self::assertSame([$compound], $suggestions);
        self::assertSame($storedLanguage, $storage->getCompoundSuggestionsForAutocompleteCalls[0]['language'] ?? null);
    }

    public function testAutocompleteServicePreservesBareAndNullLocalLanguageFilters(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: [
                'product' => 3,
                'protein' => 2,
            ],
        );
        $backend = new LanguageRecordingBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        $bareSuggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'language' => 'en',
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
            'fuzzy' => false,
        ]);
        $bareThreeLetterSuggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'language' => 'eng',
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
            'fuzzy' => false,
        ]);
        $unfilteredSuggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'fuzzy' => false,
        ]);

        self::assertSame(['product', 'protein'], $bareSuggestions);
        self::assertSame(['product', 'protein'], $bareThreeLetterSuggestions);
        self::assertSame(['product', 'protein'], $unfilteredSuggestions);
        self::assertSame('en', $storage->getTermsForAutocompleteCalls[0]['language'] ?? null);
        self::assertSame('eng', $storage->getTermsForAutocompleteCalls[1]['language'] ?? null);
        self::assertNull($storage->getTermsForAutocompleteCalls[2]['language'] ?? null);
    }

    public function testExternalNativeAutocompleteReceivesNormalizedRegionalLanguage(): void
    {
        $backend = new LanguageRecordingBackend();
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'language' => 'en_US',
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
        ]);

        self::assertSame(['provider:pro'], $suggestions);
        self::assertSame('en-us', $backend->autocompleteCalls[0]['options']['language'] ?? null);
    }

    public function testAutocompleteServiceFallsBackSafelyForUnsafeLanguage(): void
    {
        $storage = $this->makeLanguageStorage([
            '1:1' => 'en',
            '1:2' => 'fr',
        ]);
        $backend = new LanguageRecordingBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'language' => '../../../../tmp/payload',
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
        ]);

        self::assertNotSame('../../../../tmp/payload', $storage->getTermsForAutocompleteCalls[0]['language'] ?? null);
        self::assertMatchesRegularExpression('/\A[a-z]{2,3}\z/', $storage->getTermsForAutocompleteCalls[0]['language'] ?? '');
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function regionalAutocompleteLanguages(): iterable
    {
        yield 'English uppercase region' => ['en-US', 'en', 'pro', 'protein'];
        yield 'English lowercase region' => ['en-us', 'en', 'pro', 'protein'];
        yield 'Arabic uppercase region' => ['ar-SA', 'ar', 'بر', 'برنامج'];
        yield 'Arabic lowercase region' => ['ar-sa', 'ar', 'بر', 'برنامج'];
        yield 'Three-letter uppercase region' => ['eng-US', 'en', 'pro', 'protein'];
        yield 'Three-letter lowercase region' => ['eng-us', 'en', 'pro', 'protein'];
    }

    /**
     * @param array<string, string> $languages
     */
    private function makeLanguageStorage(array $languages): RecordingStorage
    {
        return new RecordingStorage(
            termDocs: [
                'protein' => ['1:1' => 3, '1:2' => 3],
            ],
            titleByElement: [
                1 => ['protein'],
                2 => ['protein'],
            ],
            docLengths: [
                '1:1' => 10,
                '1:2' => 10,
            ],
            totalDocs: 2,
            avgDocLength: 10.0,
            documentLanguagesById: $languages,
        );
    }
}

final class LanguageRecordingBackendService extends BackendService
{
    public function __construct(private readonly LanguageRecordingBackend $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->backend;
    }
}

final class LanguageRecordingBackend implements AutocompleteBackendInterface, BackendInterface, StorageBackedBackendInterface
{
    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $searchCalls = [];

    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $autocompleteCalls = [];

    public function __construct(private readonly ?RecordingStorage $storage = null)
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
        $this->searchCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        return ['hits' => [], 'total' => 0];
    }

    public function autocomplete(string $indexName, string $query, array $options = []): array
    {
        $this->autocompleteCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        return ['provider:' . $query];
    }

    public function supportsAutocomplete(): bool
    {
        return true;
    }

    public function getStorage(string $indexHandle): StorageInterface
    {
        if ($this->storage === null) {
            throw new \RuntimeException('No test storage configured.');
        }

        return $this->storage;
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

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }

    public function getName(): string
    {
        return 'language-recording';
    }

    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

    public function multipleQueries(array $queries = []): array
    {
        return ['results' => []];
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
}
