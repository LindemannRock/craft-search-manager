<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\RedisStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for Redis local-backend audit #122/#124/#125.
 */
final class RedisStorageRegressionTest extends TestCase
{
    public function testTermsByPrefixUsesRealRedisTermKeyShape(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->storeTermDocument('protein', 1, 101, 3);
        $storage->storeTermDocument('product', 1, 102, 2);
        $storage->storeTermDocument('promo', 2, 201, 1);
        $storage->storeTermDocument('coffee', 1, 103, 1);

        $terms = $storage->getTermsByPrefix('pro', 1);
        sort($terms);

        self::assertSame(['product', 'protein'], $terms);
        self::assertSame(['sm:idx:test-index:term:pro*:1'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testWildcardSearchExpandsRedisPrefixTerms(): void
    {
        [$storage] = $this->makeStorage();
        $storage->storeTermDocument('protein', 1, 101, 3);
        $storage->storeTermDocument('product', 1, 102, 2);
        $storage->storeDocument(1, 101, ['protein' => 3], 8);
        $storage->storeDocument(1, 102, ['product' => 2], 7);
        $storage->storeTitleTerms(1, 101, ['protein']);
        $storage->storeTitleTerms(1, 102, ['product']);
        $storage->updateMetadata(1, 8, true);
        $storage->updateMetadata(1, 7, true);

        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);
        $results = $engine->search('pro*', 1);

        self::assertSame([101, 102], array_keys($results));
    }

    public function testDocumentTermsExcludeSpecialLanguageAndLengthRows(): void
    {
        [$storage] = $this->makeStorage();

        $storage->storeDocument(1, 101, ['alpha' => 2], 5, 'de');

        self::assertSame(['alpha' => 2], $storage->getDocumentTerms(1, 101));
        self::assertSame([101 => ['alpha' => 2]], $storage->getDocumentTermsBatch(1, [101]));
    }

    public function testAutocompleteHotPathUsesScanInsteadOfKeys(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeTermDocument('protein', 1, 101, 3);
        $storage->storeTermDocument('product', 2, 202, 2);

        $terms = $storage->getTermsForAutocomplete(null, null, 10);

        self::assertSame(['protein' => 3, 'product' => 2], $terms);
        self::assertSame(['sm:idx:test-index:term:*:*'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testAutocompleteRanksBySummedFrequencyInsteadOfDocumentCount(): void
    {
        [$storage] = $this->makeStorage();
        $storage->storeTermDocument('product', 1, 101, 1);
        $storage->storeTermDocument('product', 1, 102, 1);
        $storage->storeTermDocument('protein', 1, 103, 5);

        $terms = $storage->getTermsForAutocomplete(1, null, 10, 'pro');

        self::assertSame(['protein' => 5, 'product' => 2], $terms);
    }

    public function testAutocompletePrefixFilterUsesRedisScanPatternAndPreservesRanking(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeTermDocument('alpha', 1, 101, 1);
        $storage->storeTermDocument('product', 1, 102, 1);
        $storage->storeTermDocument('product', 1, 103, 1);
        $storage->storeTermDocument('protein', 1, 104, 1);
        $storage->storeTermDocument('protein', 2, 204, 3);
        $storage->storeTermDocument('profile', 1, 105, 1);

        $terms = $storage->getTermsForAutocomplete(null, null, 2, 'pro');

        self::assertSame(['protein' => 4, 'product' => 2], $terms);
        self::assertSame(['sm:idx:test-index:term:pro*:*'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testCompoundSuggestionsAggregateByPrefixAndUseAggregateIndex(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->storeCompoundSuggestions(1, 101, [
            'redirect.twig' => [
                'suggestion' => 'redirect.twig',
                'normalizedSuggestion' => 'redirect.twig',
                'tokenKey' => 'redirect twig',
                'frequency' => 2,
            ],
        ], 'en');
        $storage->storeCompoundSuggestions(1, 102, [
            'redirect.twig' => [
                'suggestion' => 'redirect.twig',
                'normalizedSuggestion' => 'redirect.twig',
                'tokenKey' => 'redirect twig',
                'frequency' => 1,
            ],
        ], 'en');
        $storage->storeCompoundSuggestions(2, 201, [
            'redirect.twig' => [
                'suggestion' => 'redirect.twig',
                'normalizedSuggestion' => 'redirect.twig',
                'tokenKey' => 'redirect twig',
                'frequency' => 5,
            ],
        ], 'en');

        self::assertSame(['redirect.twig' => 3], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', 1, 'en', 10));
        self::assertSame(['sm:idx:test-index:compoundidx:site1:*:rank'], $redis->scanPatterns);

        $redis->scanPatterns = [];
        self::assertSame(['redirect.twig' => 8], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', null, 'en', 10));
        self::assertSame(['sm:idx:test-index:compoundidx:all:*:rank'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);

        $storage->deleteCompoundSuggestions(1, 101);

        self::assertSame(['redirect.twig' => 1], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', 1, 'en', 10));
        self::assertSame(['redirect.twig' => 6], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', null, 'en', 10));
    }

    public function testCompoundSuggestionsUpdateAggregatesAndDisplayTieBreaks(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->storeCompoundSuggestions(1, 101, [
            'readme.twig' => [
                'suggestion' => 'readme.twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 2,
            ],
            'Readme.Twig' => [
                'suggestion' => 'Readme.Twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 2,
            ],
        ], 'en');
        $storage->storeCompoundSuggestions(2, 201, [
            'readme.twig' => [
                'suggestion' => 'readme.twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 3,
            ],
        ], 'en');

        self::assertSame(['Readme.Twig' => 4], $storage->getCompoundSuggestionsForAutocomplete('readme', 1, 'en', 10));
        self::assertSame(['readme.twig' => 7], $storage->getCompoundSuggestionsForAutocomplete('readme', null, 'en', 10));

        $redis->scanPatterns = [];
        $storage->deleteCompoundSuggestions(1, 101);

        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('readme', 1, 'en', 10));
        self::assertSame(['readme.twig' => 3], $storage->getCompoundSuggestionsForAutocomplete('readme', null, 'en', 10));
        self::assertNotContains('sm:idx:test-index:compound:1:*', $redis->scanPatterns);
    }

    public function testCompoundAggregateUpdatesUseAtomicLuaInsideStoreTransaction(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/RedisStorage.php');
        self::assertIsString($source);

        preg_match('/public function storeCompoundSuggestions\(.*?^    }$/ms', $source, $storeMatches);
        self::assertNotEmpty($storeMatches, 'storeCompoundSuggestions source should be found');
        self::assertStringContainsString('multi(\\Redis::MULTI)', $storeMatches[0]);
        self::assertStringContainsString('exec()', $storeMatches[0]);

        preg_match('/private function updateCompoundAggregate\(.*?^    }$/ms', $source, $aggregateMatches);
        self::assertNotEmpty($aggregateMatches, 'updateCompoundAggregate source should be found');
        self::assertStringContainsString('eval($script', $aggregateMatches[0]);
        self::assertStringNotContainsString('hGetAll($displayKey)', $aggregateMatches[0]);
        self::assertStringNotContainsString('hMSet($displayKey', $aggregateMatches[0]);
        self::assertStringNotContainsString('del($displayKey)', $aggregateMatches[0]);

        [$storage, $redis] = $this->makeStorage();
        $storage->storeCompoundSuggestions(1, 101, [
            'Readme.Twig' => [
                'suggestion' => 'Readme.Twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 2,
            ],
        ], 'en');

        $storage->storeCompoundSuggestions(1, 101, [
            'readme.twig' => [
                'suggestion' => 'readme.twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 3,
            ],
        ], 'en');

        self::assertGreaterThanOrEqual(2, $redis->multiCalls);
        self::assertGreaterThanOrEqual(4, $redis->evalCalls);
        self::assertSame(['readme.twig' => 3], $storage->getCompoundSuggestionsForAutocomplete('readme', 1, 'en', 10));
    }

    public function testCompoundDeleteKeepsRankAndDisplayHashesConsistent(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->storeCompoundSuggestions(1, 101, [
            'React.js' => [
                'suggestion' => 'React.js',
                'normalizedSuggestion' => 'react.js',
                'tokenKey' => 'react js',
                'frequency' => 2,
            ],
        ], 'en');
        $storage->storeCompoundSuggestions(1, 102, [
            'react.js' => [
                'suggestion' => 'react.js',
                'normalizedSuggestion' => 'react.js',
                'tokenKey' => 'react js',
                'frequency' => 1,
            ],
        ], 'en');

        $storage->deleteCompoundSuggestions(1, 101);

        $rankKey = $this->compoundRankKey('site1', 'en', 'react.js');
        $displayKey = $this->compoundDisplayKey('site1', 'en', 'react.js');

        self::assertSame(['react.js' => 1], $storage->getCompoundSuggestionsForAutocomplete('react', 1, 'en', 10));
        self::assertSame(['react.js' => 1], $redis->zRevRange($rankKey, 0, -1, true));
        self::assertSame(['react.js' => 1], array_map('intval', $redis->hGetAll($displayKey)));

        $storage->deleteCompoundSuggestions(1, 102);

        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('react', 1, 'en', 10));
        self::assertSame([], $redis->zRevRange($rankKey, 0, -1, true));
        self::assertSame([], $redis->hGetAll($displayKey));
    }

    public function testCompoundAutocompletePipelinesDisplayHashReadsAndPreservesTieBreaks(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->storeCompoundSuggestions(1, 101, [
            'Readme.Twig' => [
                'suggestion' => 'Readme.Twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 2,
            ],
            'readme.twig' => [
                'suggestion' => 'readme.twig',
                'normalizedSuggestion' => 'readme.twig',
                'tokenKey' => 'readme twig',
                'frequency' => 2,
            ],
        ], 'en');
        $storage->storeCompoundSuggestions(1, 102, [
            'readme.cache' => [
                'suggestion' => 'readme.cache',
                'normalizedSuggestion' => 'readme.cache',
                'tokenKey' => 'readme cache',
                'frequency' => 5,
            ],
        ], 'en');

        $redis->hGetAllPipelineCalls = 0;
        $redis->hGetAllSequentialCalls = 0;

        $suggestions = $storage->getCompoundSuggestionsForAutocomplete('readme', 1, 'en', 10);

        self::assertSame(['readme.cache' => 5, 'Readme.Twig' => 4], $suggestions);
        self::assertSame(2, $redis->hGetAllPipelineCalls);
        self::assertSame(0, $redis->hGetAllSequentialCalls);
    }

    public function testCompoundLookupRequiresAggregateIndex(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $redis->set('sm:idx:test-index:compound:1:101', json_encode([[
            'suggestion' => 'legacy.twig',
            'normalizedSuggestion' => 'legacy.twig',
            'tokenKey' => 'legacy twig',
            'frequency' => 5,
            'language' => 'en',
        ]], JSON_THROW_ON_ERROR));

        $redis->scanPatterns = [];

        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('legacy', 1, 'en', 10));
        self::assertSame(['sm:idx:test-index:compoundidx:site1:*:rank'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testAutocompleteTermExtractionIgnoresIndexHandleNamedTerm(): void
    {
        [$storage, $redis] = $this->makeStorage('term');
        $storage->storeTermDocument('protein', 1, 101, 3);

        $terms = $storage->getTermsForAutocomplete(null, null, 10);

        self::assertSame(['protein' => 3], $terms);
        self::assertSame(['sm:idx:term:term:*:*'], $redis->scanPatterns);
        self::assertArrayNotHasKey('term', $terms);
    }


    public function testElementSuggestionsAllSitesUseScanInsteadOfKeys(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeElement(1, 101, 'Protein Powder', 'entry');
        $storage->storeElement(2, 101, 'Protein Bar', 'entry');

        $suggestions = $storage->getElementSuggestions('protein', null, 10);

        self::assertSame([1, 2], array_column($suggestions, 'siteId'));
        self::assertSame([101, 101], array_column($suggestions, 'elementId'));
        self::assertSame(['sm:idx:test-index:elemindex:*'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testElementSuggestionsUseSearchTextFirstLexMembers(): void
    {
        [$storage] = $this->makeStorage();
        $storage->storeElement(1, 101, 'Coffee: Dark Roast', 'entry');

        $suggestions = $storage->getElementSuggestions('coffee', 1, 10);

        self::assertSame([101], array_column($suggestions, 'elementId'));
        self::assertSame(['Coffee: Dark Roast'], array_column($suggestions, 'title'));
    }

    public function testNgramSimilarityPipelinesCandidateTermReads(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeTermNgrams('protein', ['pr', 'ro'], 1);
        $storage->storeTermNgrams('product', ['pr', 'ro'], 1);

        $matches = $storage->getTermsByNgramSimilarity(['pr', 'ro'], 1, 0.5);

        self::assertSame(['protein', 'product'], array_keys($matches));
        self::assertGreaterThanOrEqual(2, $redis->multiCalls);
        self::assertSame(2, $redis->sMembersPipelineCalls);
    }

    public function testMetadataClampUsesAtomicLuaAndPreservesMinimums(): void
    {
        [$storage, $redis] = $this->makeStorage();

        $storage->updateMetadata(1, 25, false);

        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame(1, $storage->getTotalLength(1));
        self::assertSame(1, $redis->evalCalls);
        self::assertSame(0, $redis->keysCalls);
        self::assertSame(0, $redis->nonLuaSetCalls);
    }

    public function testClearSiteUsesScanAndDeletesOnlyMatchingSiteKeys(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeDocument(1, 101, ['protein' => 3], 8);
        $storage->storeTermDocument('protein', 1, 101, 3);
        $storage->storeTitleTerms(1, 101, ['protein']);
        $storage->storeTermNgrams('protein', ['pr', 'ro'], 1);
        $storage->updateMetadata(1, 8, true);
        $storage->storeElement(1, 101, 'Protein Powder', 'entry');
        $storage->storeCompoundSuggestions(1, 101, [
            'protein.powder' => [
                'suggestion' => 'protein.powder',
                'normalizedSuggestion' => 'protein.powder',
                'tokenKey' => 'protein powder',
                'frequency' => 1,
            ],
        ]);

        $storage->storeDocument(2, 202, ['protein' => 2], 7);
        $storage->storeTermDocument('protein', 2, 202, 2);
        $storage->storeTitleTerms(2, 202, ['protein']);
        $storage->storeTermNgrams('protein', ['pr', 'ro'], 2);
        $storage->updateMetadata(2, 7, true);
        $storage->storeElement(2, 202, 'Protein Bar', 'entry');
        $storage->storeCompoundSuggestions(2, 202, [
            'protein.bar' => [
                'suggestion' => 'protein.bar',
                'normalizedSuggestion' => 'protein.bar',
                'tokenKey' => 'protein bar',
                'frequency' => 1,
            ],
        ]);

        $storage->clearSite(1);

        self::assertSame(0, $redis->keysCalls);
        self::assertSame([
            'sm:idx:test-index:compound:1:*',
            'sm:idx:test-index:doc:1:*',
            'sm:idx:test-index:term:*:1',
            'sm:idx:test-index:title:1:*',
            'sm:idx:test-index:ngram:1:*',
            'sm:idx:test-index:ngramcount:1:*',
            'sm:idx:test-index:meta:1:*',
            'sm:idx:test-index:elem:1:*',
            'sm:idx:test-index:elemindex:1',
            'sm:idx:test-index:compoundidx:site1:*',
        ], $redis->scanPatterns);
        self::assertFalse($redis->hasKey('sm:idx:test-index:doc:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:term:protein:1'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:title:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:ngram:1:pr'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:ngramcount:1:protein'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:meta:1:doc_count'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:elem:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:elemindex:1'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:compound:1:101'));

        self::assertTrue($redis->hasKey('sm:idx:test-index:doc:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:term:protein:2'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:title:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:ngram:2:pr'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:ngramcount:2:protein'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:meta:2:doc_count'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:elem:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:elemindex:2'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:compound:2:202'));
    }

    public function testClearAllUsesScanAndDeletesOnlyCurrentIndexPrefix(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeDocument(1, 101, ['protein' => 3], 8);
        $storage->storeTermDocument('protein', 1, 101, 3);
        $redis->hSet('sm:idx:other-index:doc:1:101', 'protein', 3);

        $storage->clearAll();

        self::assertSame(0, $redis->keysCalls);
        self::assertSame(['sm:idx:test-index:*'], $redis->scanPatterns);
        self::assertFalse($redis->hasKey('sm:idx:test-index:doc:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:term:protein:1'));
        self::assertTrue($redis->hasKey('sm:idx:other-index:doc:1:101'));
    }

    public function testRedisStorageDoesNotContainBlockingKeysCalls(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/RedisStorage.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('->keys(', $source);

        foreach (['getTermsForAutocomplete', 'getElementSuggestions'] as $method) {
            preg_match('/public function ' . $method . '\(.*?^    }$/ms', $source, $matches);
            self::assertNotEmpty($matches, $method . ' source should be found');
            self::assertStringNotContainsString('->keys(', $matches[0], $method . ' must not use blocking KEYS');
            self::assertStringContainsString('scanKeys(', $matches[0], $method . ' should use SCAN iteration');
        }

        preg_match('/public function getCompoundSuggestionsForAutocomplete\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, 'getCompoundSuggestionsForAutocomplete source should be found');
        self::assertStringNotContainsString('->keys(', $matches[0], 'compound autocomplete must not use blocking KEYS');
        self::assertStringContainsString('getIndexedCompoundSuggestionsForAutocomplete(', $matches[0]);
        self::assertStringNotContainsString('compound:*', $matches[0]);
    }

    public function testRedisMaintenanceSurfacesDoNotContainBlockingKeysCalls(): void
    {
        foreach ([
            'src/controllers/UtilitiesController.php' => ['clearRedisStorage', 'getRedisStats'],
            'src/console/controllers/MaintenanceController.php' => ['clearRedisStorage', 'getRedisStats'],
        ] as $file => $methods) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            self::assertIsString($source);

            foreach ($methods as $method) {
                preg_match('/private function ' . $method . '\(.*?^    }$/ms', $source, $matches);
                self::assertNotEmpty($matches, $method . ' source should be found in ' . $file);
                self::assertStringNotContainsString('->keys(', $matches[0], $method . ' must not use blocking KEYS');
                self::assertStringContainsString('scanRedisKeys(', $matches[0], $method . ' should use SCAN iteration');
            }
        }
    }

    /**
     * @return array{0: RedisStorage, 1: RedisStorageFakeRedis}
     */
    private function makeStorage(string $indexHandle = 'test-index'): array
    {
        $redis = new RedisStorageFakeRedis();
        $reflection = new \ReflectionClass(RedisStorage::class);
        /** @var RedisStorage $storage */
        $storage = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'indexHandle' => $indexHandle,
            'keyPrefix' => 'sm:idx:' . $indexHandle . ':',
            'redis' => $redis,
        ] as $property => $value) {
            $refProperty = $reflection->getProperty($property);
            $refProperty->setAccessible(true);
            $refProperty->setValue($storage, $value);
        }

        return [$storage, $redis];
    }

    private function compoundRankKey(string $scope, string $language, string $normalizedSuggestion): string
    {
        return 'sm:idx:test-index:compoundidx:' . $scope . ':' . $this->encodeKeySegment($language) . ':' . $this->encodeKeySegment(mb_substr($normalizedSuggestion, 0, 1) ?: '_') . ':rank';
    }

    private function compoundDisplayKey(string $scope, string $language, string $normalizedSuggestion): string
    {
        return 'sm:idx:test-index:compoundidx:' . $scope . ':' . $this->encodeKeySegment($language) . ':' . $this->encodeKeySegment(mb_substr($normalizedSuggestion, 0, 1) ?: '_') . ':display:' . hash('sha256', $normalizedSuggestion);
    }

    private function encodeKeySegment(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

final class RedisStorageFakeRedis
{
    public int $keysCalls = 0;
    public int $evalCalls = 0;
    public int $nonLuaSetCalls = 0;
    public int $delCalls = 0;
    public int $multiCalls = 0;
    public int $sMembersPipelineCalls = 0;
    public int $hGetAllPipelineCalls = 0;
    public int $hGetAllSequentialCalls = 0;

    /** @var list<string> */
    public array $scanPatterns = [];

    /** @var array<string, array<string, mixed>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $sets = [];

    /** @var array<string, array<string, float|int>> */
    private array $zsets = [];

    /** @var array<string, int|string> */
    private array $strings = [];

    /** @var list<callable>|null */
    private ?array $pipeline = null;

    public function hSet(string $key, string $field, int|string $value): void
    {
        $this->hashes[$key][$field] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function hMSet(string $key, array $data): void
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($key, $data): bool {
                $this->hMSet($key, $data);

                return true;
            };

            return;
        }

        foreach ($data as $field => $value) {
            $this->hashes[$key][(string)$field] = $value;
        }
    }

    public function hGetAll(string $key): array|self
    {
        if ($this->pipeline !== null) {
            $this->hGetAllPipelineCalls++;
            $this->pipeline[] = fn (): array => $this->hashes[$key] ?? [];
            return $this;
        }

        $this->hGetAllSequentialCalls++;

        return $this->hashes[$key] ?? [];
    }

    public function hGet(string $key, string $field): mixed
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = fn (): mixed => $this->hashes[$key][$field] ?? false;
            return $this;
        }

        return $this->hashes[$key][$field] ?? false;
    }

    public function hDel(string $key, string $field): void
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($key, $field): bool {
                $this->hDel($key, $field);

                return true;
            };

            return;
        }

        unset($this->hashes[$key][$field]);
    }

    public function hLen(string $key): int
    {
        return count($this->hashes[$key] ?? []);
    }

    /**
     * @param array<int, string> $members
     */
    public function sAddArray(string $key, array $members): void
    {
        foreach ($members as $member) {
            $this->sets[$key][] = (string)$member;
        }
        $this->sets[$key] = array_values(array_unique($this->sets[$key]));
    }

    public function sAdd(string $key, string $member): void
    {
        $this->sAddArray($key, [$member]);
    }

    public function sMembers(string $key): array|self
    {
        if ($this->pipeline !== null) {
            $this->sMembersPipelineCalls++;
            $this->pipeline[] = fn (): array => $this->sets[$key] ?? [];
            return $this;
        }

        return $this->sets[$key] ?? [];
    }

    public function zAdd(string $key, int|float $score, string $member): void
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($key, $score, $member): bool {
                $this->zAdd($key, $score, $member);

                return true;
            };

            return;
        }

        $this->zsets[$key][$member] = $score;
    }

    public function zRem(string $key, string $member): void
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($key, $member): bool {
                $this->zRem($key, $member);

                return true;
            };

            return;
        }

        unset($this->zsets[$key][$member]);
    }

    public function zRevRange(string $key, int $start, int $end, bool $withScores = false): array
    {
        $members = $this->zsets[$key] ?? [];
        arsort($members);

        $length = $end === -1 ? null : $end - $start + 1;
        $sliced = array_slice($members, $start, $length, true);

        return $withScores ? $sliced : array_keys($sliced);
    }

    public function del(array|string $keys): int
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($keys): int {
                return $this->del($keys);
            };

            return 0;
        }

        $this->delCalls++;
        $deleted = 0;

        foreach ((array)$keys as $key) {
            $key = (string)$key;
            foreach (['hashes', 'sets', 'zsets', 'strings'] as $property) {
                if (isset($this->{$property}[$key])) {
                    unset($this->{$property}[$key]);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public function zRangeByLex(string $key, string $min, string $max, int $offset, int $limit): array
    {
        $members = array_keys($this->zsets[$key] ?? []);
        sort($members);

        $minValue = substr($min, 1);
        $maxValue = substr($max, 1);
        $matches = array_values(array_filter(
            $members,
            static fn (string $member): bool => strcmp($member, $minValue) >= 0 && strcmp($member, $maxValue) <= 0,
        ));

        return array_slice($matches, $offset, $limit);
    }

    public function multi(int $mode): self
    {
        $this->multiCalls++;
        $this->pipeline = [];

        return $this;
    }

    public function exec(): array
    {
        $pipeline = $this->pipeline ?? [];
        $this->pipeline = null;

        return array_map(static fn (callable $operation): mixed => $operation(), $pipeline);
    }

    public function get(string $key): string|int|false
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = fn (): string|int|false => $this->strings[$key] ?? false;
            return false;
        }

        return $this->strings[$key] ?? false;
    }

    public function exists(string $key): int
    {
        return $this->hasKey($key) ? 1 : 0;
    }

    public function set(string $key, int|string $value): void
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = function() use ($key, $value): bool {
                $this->set($key, $value);

                return true;
            };

            return;
        }

        $this->nonLuaSetCalls++;
        $this->strings[$key] = $value;
    }

    public function incrBy(string $key, int $change): int
    {
        $this->strings[$key] = ($this->strings[$key] ?? 0) + $change;

        return $this->strings[$key];
    }

    /**
     * @param array<int, string|int> $args
     */
    public function eval(string $script, array $args, int $numKeys): array|int|self
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = fn (): array|int => $this->evalNow($args);

            return $this;
        }

        return $this->evalNow($args);
    }

    /**
     * @param array<int, string|int> $args
     */
    private function evalNow(array $args): array|int
    {
        $this->evalCalls++;

        if (count($args) === 5) {
            [$displayKey, $rankKey, $normalizedSuggestion, $suggestion, $frequencyDelta] = $args;
            $displayKey = (string)$displayKey;
            $rankKey = (string)$rankKey;
            $normalizedSuggestion = (string)$normalizedSuggestion;
            $suggestion = (string)$suggestion;

            $displayFrequencies = array_map('intval', $this->hashes[$displayKey] ?? []);
            $updated = ($displayFrequencies[$suggestion] ?? 0) + (int)$frequencyDelta;
            if ($updated <= 0) {
                unset($displayFrequencies[$suggestion], $this->hashes[$displayKey][$suggestion]);
            } else {
                $displayFrequencies[$suggestion] = $updated;
                $this->hashes[$displayKey][$suggestion] = $updated;
            }

            $total = array_sum(array_filter($displayFrequencies, static fn (int $frequency): bool => $frequency > 0));
            if ($total <= 0) {
                unset($this->hashes[$displayKey], $this->zsets[$rankKey][$normalizedSuggestion]);
            } else {
                $this->zsets[$rankKey][$normalizedSuggestion] = $total;
            }

            return $total;
        }

        [$docCountKey, $lengthKey, $docCountChange, $lengthChange] = $args;
        $docCount = $this->incrBy((string)$docCountKey, (int)$docCountChange);
        if ($docCount < 0) {
            $this->strings[(string)$docCountKey] = 0;
        }

        $totalLength = $this->incrBy((string)$lengthKey, (int)$lengthChange);
        if ($totalLength < 1) {
            $this->strings[(string)$lengthKey] = 1;
        }

        return [
            $this->strings[(string)$docCountKey],
            $this->strings[(string)$lengthKey],
        ];
    }

    public function scan(null|int &$iterator, string $pattern, int $count): array|false
    {
        $this->scanPatterns[] = $pattern;

        if ($iterator === 0) {
            return false;
        }

        $iterator = 0;

        return array_values(array_filter(
            $this->allKeys(),
            static fn (string $key): bool => fnmatch($pattern, $key),
        ));
    }

    public function keys(string $pattern): array
    {
        $this->keysCalls++;

        return [];
    }

    public function hasKey(string $key): bool
    {
        return isset($this->hashes[$key], $this->sets[$key], $this->zsets[$key], $this->strings[$key])
            || isset($this->hashes[$key])
            || isset($this->sets[$key])
            || isset($this->zsets[$key])
            || isset($this->strings[$key]);
    }

    /**
     * @return array<int, string>
     */
    private function allKeys(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->hashes),
            array_keys($this->sets),
            array_keys($this->zsets),
            array_keys($this->strings),
        )));
    }
}
