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
use lindemannrock\searchmanager\search\LanguageNormalizer;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\search\StopWords;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit #121 language sanitization.
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

    public function testAutocompleteServicePassesCanonicalLanguageToStorage(): void
    {
        $storage = $this->makeLanguageStorage([
            '1:1' => 'en-us',
            '1:2' => 'fr',
        ]);
        $backend = new LanguageRecordingBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new LanguageRecordingBackendService($backend));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('pro', 'content', [
            'language' => 'en_US',
            'siteId' => 1,
            'limit' => 5,
            'minLength' => 1,
        ]);

        self::assertSame(['protein'], $suggestions);
        self::assertSame('en-us', $storage->getTermsForAutocompleteCalls[0]['language'] ?? null);
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
        self::assertMatchesRegularExpression('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}\z/', $storage->getTermsForAutocompleteCalls[0]['language'] ?? '');
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

final class LanguageRecordingBackend implements BackendInterface, StorageBackedBackendInterface
{
    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $searchCalls = [];

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
