<?php
/**
 * LindemannRock Search Manager
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

    public function testAutocompleteHotPathUsesScanInsteadOfKeys(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeTermDocument('protein', 1, 101, 3);
        $storage->storeTermDocument('product', 2, 202, 2);

        $terms = $storage->getTermsForAutocomplete(null, null, 10);

        self::assertSame(['protein' => 1, 'product' => 1], $terms);
        self::assertSame(['sm:idx:test-index:term:*'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
    }

    public function testElementSuggestionsAllSitesUseScanInsteadOfKeys(): void
    {
        [$storage, $redis] = $this->makeStorage();
        $storage->storeElement(1, 101, 'Protein Powder', 'entry');
        $storage->storeElement(2, 101, 'Protein Bar', 'entry');

        $suggestions = $storage->getElementSuggestions('101', null, 10);

        self::assertSame([1, 2], array_column($suggestions, 'siteId'));
        self::assertSame([101, 101], array_column($suggestions, 'elementId'));
        self::assertSame(['sm:idx:test-index:elemindex:*'], $redis->scanPatterns);
        self::assertSame(0, $redis->keysCalls);
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

        $storage->storeDocument(2, 202, ['protein' => 2], 7);
        $storage->storeTermDocument('protein', 2, 202, 2);
        $storage->storeTitleTerms(2, 202, ['protein']);
        $storage->storeTermNgrams('protein', ['pr', 'ro'], 2);
        $storage->updateMetadata(2, 7, true);
        $storage->storeElement(2, 202, 'Protein Bar', 'entry');

        $storage->clearSite(1);

        self::assertSame(0, $redis->keysCalls);
        self::assertSame([
            'sm:idx:test-index:doc:1:*',
            'sm:idx:test-index:term:*:1',
            'sm:idx:test-index:title:1:*',
            'sm:idx:test-index:ngram:1:*',
            'sm:idx:test-index:ngramcount:1:*',
            'sm:idx:test-index:meta:1:*',
            'sm:idx:test-index:elem:1:*',
            'sm:idx:test-index:elemindex:1',
        ], $redis->scanPatterns);
        self::assertFalse($redis->hasKey('sm:idx:test-index:doc:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:term:protein:1'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:title:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:ngram:1:pr'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:ngramcount:1:protein'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:meta:1:doc_count'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:elem:1:101'));
        self::assertFalse($redis->hasKey('sm:idx:test-index:elemindex:1'));

        self::assertTrue($redis->hasKey('sm:idx:test-index:doc:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:term:protein:2'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:title:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:ngram:2:pr'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:ngramcount:2:protein'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:meta:2:doc_count'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:elem:2:202'));
        self::assertTrue($redis->hasKey('sm:idx:test-index:elemindex:2'));
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
    }

    /**
     * @return array{0: RedisStorage, 1: RedisStorageFakeRedis}
     */
    private function makeStorage(): array
    {
        $redis = new RedisStorageFakeRedis();
        $reflection = new \ReflectionClass(RedisStorage::class);
        /** @var RedisStorage $storage */
        $storage = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'indexHandle' => 'test-index',
            'keyPrefix' => 'sm:idx:test-index:',
            'redis' => $redis,
        ] as $property => $value) {
            $refProperty = $reflection->getProperty($property);
            $refProperty->setAccessible(true);
            $refProperty->setValue($storage, $value);
        }

        return [$storage, $redis];
    }
}

final class RedisStorageFakeRedis
{
    public int $keysCalls = 0;
    public int $evalCalls = 0;
    public int $nonLuaSetCalls = 0;
    public int $delCalls = 0;

    /** @var list<string> */
    public array $scanPatterns = [];

    /** @var array<string, array<string, mixed>> */
    private array $hashes = [];

    /** @var array<string, array<int, string>> */
    private array $sets = [];

    /** @var array<string, array<string, float|int>> */
    private array $zsets = [];

    /** @var array<string, int> */
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
        foreach ($data as $field => $value) {
            $this->hashes[$key][(string)$field] = $value;
        }
    }

    public function hGetAll(string $key): array|self
    {
        if ($this->pipeline !== null) {
            $this->pipeline[] = fn (): array => $this->hashes[$key] ?? [];
            return $this;
        }

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
            $this->pipeline[] = fn (): array => $this->sets[$key] ?? [];
            return $this;
        }

        return $this->sets[$key] ?? [];
    }

    public function zAdd(string $key, int|float $score, string $member): void
    {
        $this->zsets[$key][$member] = $score;
    }

    public function del(array|string $keys): int
    {
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
        $this->pipeline = [];

        return $this;
    }

    public function exec(): array
    {
        $pipeline = $this->pipeline ?? [];
        $this->pipeline = null;

        return array_map(static fn (callable $operation): mixed => $operation(), $pipeline);
    }

    public function get(string $key): int|false
    {
        return $this->strings[$key] ?? false;
    }

    public function exists(string $key): int
    {
        return $this->hasKey($key) ? 1 : 0;
    }

    public function set(string $key, int $value): void
    {
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
    public function eval(string $script, array $args, int $numKeys): array
    {
        $this->evalCalls++;

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
