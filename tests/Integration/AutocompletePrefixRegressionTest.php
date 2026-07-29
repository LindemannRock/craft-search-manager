<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\interfaces\StorageBackedBackendInterface;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;
use yii\caching\ArrayCache;
use yii\redis\Cache;
use yii\redis\Connection;

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

    public function testCacheKeySeparatesEveryResultShapingSuggestionOption(): void
    {
        $service = SearchManager::$plugin->autocomplete;
        $method = new \ReflectionMethod($service, 'generateCacheKey');
        $method->setAccessible(true);

        $base = $method->invoke($service, 'suggest', 'dev_content', 'pro', 1, 'en', 10, true);
        $differentLimit = $method->invoke($service, 'suggest', 'dev_content', 'pro', 1, 'en', 5, true);
        $differentFuzzy = $method->invoke($service, 'suggest', 'dev_content', 'pro', 1, 'en', 10, false);

        self::assertNotSame($base, $differentLimit);
        self::assertNotSame($base, $differentFuzzy);
        self::assertNotSame($differentLimit, $differentFuzzy);
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
            termDocs: [
                '0' => ['1:1' => 8],
            ],
            titleByElement: [],
            docLengths: ['1:1' => 1],
            totalDocs: 1,
            avgDocLength: 1.0,
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

    public function testTwoCharacterPrefixAutocompleteRemainsAvailable(): void
    {
        $storage = new RecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: [
                'tools' => 8,
                'toolbar' => 5,
                'testing' => 3,
            ],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('to', 'content', [
            'limit' => 2,
            'minLength' => 2,
            'siteId' => 1,
        ]);

        self::assertSame(['tools', 'toolbar'], $suggestions);
        self::assertSame('to', $storage->getTermsForAutocompleteCalls[0]['prefix'] ?? null);
    }

    public function testZeroPrefixReachesExistingAutocompleteStoreAtMinimumLengthOne(): void
    {
        $storage = new RecordingStorage(
            termDocs: [
                '0' => ['1:1' => 8],
            ],
            titleByElement: [],
            docLengths: ['1:1' => 1],
            totalDocs: 1,
            avgDocLength: 1.0,
            autocompleteTerms: [
                '0' => 8,
                '00' => 5,
                '01' => 3,
                '10' => 2,
            ],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('0', 'content', [
            'limit' => 3,
            'minLength' => 1,
            'siteId' => 1,
        ]);

        self::assertSame(['0', '00', '01'], $suggestions);
        self::assertSame('0', $storage->getTermsForAutocompleteCalls[0]['prefix'] ?? null);
    }

    public function testAutocompleteRejectsFirstCharacterFuzzyPollution(): void
    {
        $storage = new RecordingStorage(
            termDocs: [
                'best' => ['1:1' => 1],
            ],
            titleByElement: [],
            docLengths: ['1:1' => 1],
            totalDocs: 1,
            avgDocLength: 1.0,
            fuzzyCandidates: [
                'best' => 0.5,
            ],
            autocompleteTerms: [],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));

        $suggestions = SearchManager::$plugin->autocomplete->suggest('test', 'content', [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
            'fuzzy' => true,
        ]);

        self::assertNotContains('best', $suggestions);
        self::assertSame([], $suggestions);
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

    public function testFailedTokenAutocompleteIsNotCachedAndSuccessfulEmptyRetryIsReusable(): void
    {
        $this->withIsolatedRedisAutocompleteCache(function(AutocompleteFakeRedisConnection $redis): void {
            $storage = new AutocompleteFailureRecordingStorage(
                termDocs: [],
                titleByElement: [],
                docLengths: [],
                totalDocs: 0,
                avgDocLength: 0.0,
                autocompleteTerms: [],
            );
            $storage->termDocumentFailuresRemaining = 1;
            $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));
            $options = ['limit' => 5, 'minLength' => 1, 'siteId' => 1, 'fuzzy' => false];

            $first = SearchManager::$plugin->autocomplete->suggest('pr160', 'autocomplete-failure', $options);
            self::assertSame([], $first);
            self::assertSame(1, $storage->termDocumentCalls);
            self::assertSame([], $redis->setMembers($this->autocompleteTrackingSet()));

            $second = SearchManager::$plugin->autocomplete->suggest('pr160', 'autocomplete-failure', $options);
            self::assertSame([], $second);
            self::assertSame(2, $storage->termDocumentCalls);
            self::assertCount(1, $redis->setMembers($this->autocompleteTrackingSet()));

            $third = SearchManager::$plugin->autocomplete->suggest('pr160', 'autocomplete-failure', $options);
            self::assertSame([], $third);
            self::assertSame(2, $storage->termDocumentCalls);
        });
    }

    public function testRedisAutocompleteKeysExposeExactPrefixedIndexAndPreserveHashIdentity(): void
    {
        $this->withIsolatedRedisAutocompleteCache(function(AutocompleteFakeRedisConnection $redis): void {
            $storage = new AutocompleteFailureRecordingStorage(
                termDocs: [],
                titleByElement: [],
                docLengths: [],
                totalDocs: 0,
                avgDocLength: 0.0,
                autocompleteTerms: [
                    'product' => 5,
                    'profile' => 3,
                ],
            );
            $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));
            $options = ['limit' => 5, 'minLength' => 1, 'siteId' => 1, 'fuzzy' => false];

            self::assertSame(['product', 'profile'], SearchManager::$plugin->autocomplete->suggest('pro', 'news', $options));
            self::assertSame(['product', 'profile'], SearchManager::$plugin->autocomplete->suggest('pro', 'news', $options));

            $keys = $redis->setMembers($this->autocompleteTrackingSet());
            self::assertCount(1, $keys);
            $fullIndex = SearchManager::$plugin->getSettings()->getFullIndexName('news');
            $expectedPrefix = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . $fullIndex . ':';
            self::assertStringStartsWith($expectedPrefix, $keys[0]);
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', substr($keys[0], strlen($expectedPrefix)));
            self::assertSame(1, $storage->termDocumentCalls);
            self::assertTrue($redis->hasSetCommandWithTtl(SearchManager::$plugin->getSettings()->autocompleteCacheDuration));
        });
    }

    public function testRedisSelectiveClearIsDelimiterSafeAndOldOpaqueKeysRemainUntouched(): void
    {
        $this->withIsolatedRedisAutocompleteCache(function(AutocompleteFakeRedisConnection $redis, Cache $cache): void {
            $storage = new AutocompleteFailureRecordingStorage(
                termDocs: [],
                titleByElement: [],
                docLengths: [],
                totalDocs: 0,
                avgDocLength: 0.0,
                autocompleteTerms: ['newsroom' => 4],
            );
            $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));
            $options = ['limit' => 5, 'minLength' => 1, 'siteId' => 1, 'fuzzy' => false];
            $service = SearchManager::$plugin->autocomplete;

            $service->suggest('new', 'news', $options);
            $service->suggest('new', 'news-archive', $options);

            $oldOpaqueKey = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete') . str_repeat('a', 32);
            $cache->set($oldOpaqueKey, ['legacy'], 300);
            $redis->executeCommand('SADD', [$this->autocompleteTrackingSet(), $oldOpaqueKey]);

            $service->clearCache('news');

            $remaining = $redis->setMembers($this->autocompleteTrackingSet());
            $newsPrefix = $this->autocompleteIndexPrefix('news');
            $archivePrefix = $this->autocompleteIndexPrefix('news-archive');
            self::assertFalse((bool)array_filter($remaining, static fn(string $key): bool => str_starts_with($key, $newsPrefix)));
            self::assertTrue((bool)array_filter($remaining, static fn(string $key): bool => str_starts_with($key, $archivePrefix)));
            self::assertContains($oldOpaqueKey, $remaining);
            self::assertSame(['legacy'], $cache->get($oldOpaqueKey));

            $storage->getTermsForAutocompleteCalls = [];
            self::assertSame(['newsroom'], $service->suggest('new', 'news', $options));
            self::assertNotEmpty($storage->getTermsForAutocompleteCalls, 'The new format must not read the old opaque entry.');

            self::assertNotContains('SCAN', $redis->commands);
            self::assertNotContains('KEYS', $redis->commands);

            $service->clearCache();
            self::assertSame([], $redis->setMembers($this->autocompleteTrackingSet()));
            self::assertFalse($cache->get($oldOpaqueKey));
        });
    }

    public function testRedisUnavailableFallsBackToUnchangedPerIndexFileCache(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalStorageMethod = $settings->cacheStorageMethod;
        $originalIndexPrefix = $settings->indexPrefix;
        $originalCache = Craft::$app->getCache();
        $settings->enableAutocompleteCache = true;
        $settings->cacheStorageMethod = 'redis';
        $settings->indexPrefix = 'fs3_';
        Craft::$app->set('cache', new ArrayCache());
        $storage = new AutocompleteFailureRecordingStorage(
            termDocs: [],
            titleByElement: [],
            docLengths: [],
            totalDocs: 0,
            avgDocLength: 0.0,
            autocompleteTerms: ['fallback' => 4],
        );
        $this->swapPluginComponent('search-manager', 'backend', new AutocompletePrefixBackendService($storage));
        $service = SearchManager::$plugin->autocomplete;
        $handle = 'autocomplete-file-fallback';
        $options = ['limit' => 5, 'minLength' => 1, 'siteId' => 1, 'fuzzy' => false];

        try {
            $service->clearCache($handle);
            self::assertSame(['fallback'], $service->suggest('fall', $handle, $options));
            self::assertSame(['fallback'], $service->suggest('fall', $handle, $options));
            self::assertSame(1, $storage->termDocumentCalls);

            $service->clearCache($handle);
            self::assertSame(['fallback'], $service->suggest('fall', $handle, $options));
            self::assertSame(2, $storage->termDocumentCalls);
        } finally {
            $service->clearCache($handle);
            Craft::$app->set('cache', $originalCache);
            $settings->cacheStorageMethod = $originalStorageMethod;
            $settings->indexPrefix = $originalIndexPrefix;
        }
    }

    public function testMutationProducerAndSearchCacheKeepTheirEstablishedInvalidationStructures(): void
    {
        $indexingSource = file_get_contents(dirname(__DIR__, 2) . '/src/services/IndexingService.php');
        $searchCacheSource = file_get_contents(dirname(__DIR__, 2) . '/src/services/BackendService.php');

        self::assertIsString($indexingSource);
        self::assertIsString($searchCacheSource);
        self::assertStringContainsString(
            'SearchManager::$plugin->autocomplete->clearCache($indexHandle);',
            $indexingSource,
        );
        self::assertStringContainsString(
            "PluginHelper::getCacheKeyPrefix(SearchManager::\$plugin->id, 'search') . \$fullIndexName . ':' . \$cacheKey",
            $searchCacheSource,
        );
    }

    /**
     * @param callable(AutocompleteFakeRedisConnection, Cache): void $callback
     */
    private function withIsolatedRedisAutocompleteCache(callable $callback): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalStorageMethod = $settings->cacheStorageMethod;
        $originalIndexPrefix = $settings->indexPrefix;
        $originalCache = Craft::$app->getCache();
        $redis = new AutocompleteFakeRedisConnection();
        $cache = new Cache(['redis' => $redis]);
        $settings->enableAutocompleteCache = true;
        $settings->cacheStorageMethod = 'redis';
        $settings->indexPrefix = 'fs3_';
        Craft::$app->set('cache', $cache);

        try {
            $callback($redis, $cache);
        } finally {
            Craft::$app->set('cache', $originalCache);
            $settings->cacheStorageMethod = $originalStorageMethod;
            $settings->indexPrefix = $originalIndexPrefix;
        }
    }

    private function autocompleteTrackingSet(): string
    {
        return PluginHelper::getCacheKeySet(SearchManager::$plugin->id, 'autocomplete');
    }

    private function autocompleteIndexPrefix(string $handle): string
    {
        return PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'autocomplete')
            . SearchManager::$plugin->getSettings()->getFullIndexName($handle)
            . ':';
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

/**
 * @since 5.54.0
 */
final class AutocompleteFailureRecordingStorage extends RecordingStorage
{
    public int $termDocumentCalls = 0;
    public int $termDocumentFailuresRemaining = 0;

    public function getTermDocuments(string $term, int $siteId): array
    {
        $this->termDocumentCalls++;
        if ($this->termDocumentFailuresRemaining > 0) {
            $this->termDocumentFailuresRemaining--;
            throw new \RuntimeException('Deterministic autocomplete storage failure.');
        }

        return parent::getTermDocuments($term, $siteId);
    }
}

/**
 * @since 5.54.0
 */
final class AutocompleteFakeRedisConnection extends Connection
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<array{name: string, params: array<int, mixed>}> */
    public array $commandRecords = [];

    /** @var array<string, mixed> */
    private array $strings = [];

    /** @var array<string, array<string, true>> */
    private array $sets = [];

    public function executeCommand(string $name, array $params = [])
    {
        $name = strtoupper($name);
        $this->commands[] = $name;
        $this->commandRecords[] = ['name' => $name, 'params' => $params];

        return match ($name) {
            'GET' => $this->strings[(string)$params[0]] ?? null,
            'SET' => $this->setString($params),
            'DEL' => $this->deleteKeys($params),
            'SADD' => $this->addSetMember((string)$params[0], (string)$params[1]),
            'SMEMBERS' => array_keys($this->sets[(string)$params[0]] ?? []),
            'SREM' => $this->removeSetMember((string)$params[0], (string)$params[1]),
            'EXISTS' => isset($this->strings[(string)$params[0]]) || isset($this->sets[(string)$params[0]]) ? 1 : 0,
            default => throw new \RuntimeException('Unsupported fake Redis command: ' . $name),
        };
    }

    /**
     * @return list<string>
     */
    public function setMembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    public function hasSetCommandWithTtl(int $duration): bool
    {
        foreach ($this->commandRecords as $record) {
            if (
                $record['name'] === 'SET'
                && ($record['params'][2] ?? null) === 'PX'
                && ($record['params'][3] ?? null) === $duration * 1000
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function setString(array $params): string
    {
        $this->strings[(string)$params[0]] = $params[1];

        return 'OK';
    }

    /**
     * @param array<int, mixed> $keys
     */
    private function deleteKeys(array $keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            $key = (string)$key;
            if (isset($this->strings[$key])) {
                unset($this->strings[$key]);
                $deleted++;
            }
            if (isset($this->sets[$key])) {
                unset($this->sets[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function addSetMember(string $key, string $member): int
    {
        $added = isset($this->sets[$key][$member]) ? 0 : 1;
        $this->sets[$key][$member] = true;

        return $added;
    }

    private function removeSetMember(string $key, string $member): int
    {
        if (!isset($this->sets[$key][$member])) {
            return 0;
        }

        unset($this->sets[$key][$member]);
        if ($this->sets[$key] === []) {
            unset($this->sets[$key]);
        }

        return 1;
    }
}
