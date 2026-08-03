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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\events\IndexEvent;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\jobs\BatchSyncJob;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\services\sync\PendingSyncRepository;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\base\Event;
use yii\caching\ArrayCache;

/**
 * Regression coverage for PR1.70 File-storage failure truth.
 *
 * @since 5.54.0
 */
final class Pr170FileStorageFailureTruthTest extends TestCase
{
    private const FAULT_SCHEME = 'pr170fault';
    private const INDEX_PREFIX = '__sm_pr170_';

    private string $storageAlias;
    private string $storageBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageAlias = '@storage/runtime/search-manager-pr170-' . bin2hex(random_bytes(8));
        $this->storageBasePath = Craft::getAlias($this->storageAlias);
        FileHelper::createDirectory($this->storageBasePath);
        $this->trackTempPath($this->storageBasePath);
        $this->purgeTestIndices();
        $this->deleteBatchQueueRows();
    }

    protected function tearDown(): void
    {
        try {
            if (in_array(self::FAULT_SCHEME, stream_get_wrappers(), true)) {
                stream_wrapper_unregister(self::FAULT_SCHEME);
            }

            $this->purgeTestIndices();
            $this->deleteBatchQueueRows();
        } finally {
            parent::tearDown();
        }
    }

    public function testGenuinelyMissingOptionalFilesRemainSuccessfulAbsence(): void
    {
        $storage = $this->makeStorage('missing');

        self::assertSame([], $storage->getDocumentTerms(1, 101));
        self::assertSame([], $storage->getDocumentTermsByKey(1, '101_1_intro'));
        self::assertSame([], $storage->getTermDocuments('missing', 1));
        self::assertSame([], $storage->getTitleTerms(1, 101));
        self::assertSame([], $storage->getTitleTermsBatchByKeys(1, ['101_1_intro']));
        self::assertSame([], $storage->getTermsByNgramSimilarity(['mi'], 1, 0.1));
        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('mi', 1, 'en'));
        self::assertSame(0, $storage->getTotalDocCount(1));
        self::assertSame(1, $storage->getTotalLength(1));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingReadFailureProvider(): iterable
    {
        yield 'open denied' => ['open'];
        yield 'empty content' => ['empty'];
        yield 'JSON decode' => ['decode'];
    }

    #[DataProvider('existingReadFailureProvider')]
    public function testExistingFileOpenEmptyAndDecodeFailuresThrow(string $fault): void
    {
        $handle = 'read-' . $fault;
        $storage = $this->makeStorage($handle);
        $path = $this->indexPath($handle) . '/terms/failure_1.dat';
        file_put_contents($path, $fault === 'decode' ? '{invalid' : ($fault === 'empty' ? '' : '{"1:101":1}'));

        if ($fault === 'open') {
            chmod($path, 0000);
        }

        try {
            $this->assertRuntimeFailure(static fn(): array => $storage->getTermDocuments('failure', 1));
        } finally {
            if ($fault === 'open') {
                chmod($path, 0644);
            }
        }
    }

    public function testExistingFileLockAndReadFailuresThrow(): void
    {
        $this->registerFaultWrapper();
        $storage = $this->makeStorage('read-wrapper');
        $method = new \ReflectionMethod(FileStorage::class, 'readFile');

        Pr170FaultStreamWrapper::$modes['lock'] = 'lock-fail';
        $this->assertRuntimeFailure(static fn(): mixed => $method->invoke($storage, self::FAULT_SCHEME . '://root/lock'));

        Pr170FaultStreamWrapper::$modes['read'] = 'read-fail';
        $this->assertRuntimeFailure(static fn(): mixed => $method->invoke($storage, self::FAULT_SCHEME . '://root/read'));
    }

    public function testWriteAndLockedUpdatePrimitivesRequireCompleteSuccessfulOperations(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        $write = $this->methodBody($source, 'writeFile');
        self::assertStringContainsString('=== strlen($json)', $write);

        $update = $this->methodBody($source, 'updateJsonFile');
        self::assertStringContainsString('if (!rewind($handle))', $update);
        self::assertStringContainsString('if (!ftruncate($handle, 0))', $update);
        self::assertStringContainsString('=== strlen($json)', $update);

        $this->registerFaultWrapper();
        $storage = $this->makeStorage('write-wrapper');
        $method = new \ReflectionMethod(FileStorage::class, 'updateJsonFile');
        $requiredUpdate = new \ReflectionMethod(FileStorage::class, 'updateJsonFileOrFail');
        $requiredWrite = new \ReflectionMethod(FileStorage::class, 'writeFileOrFail');
        $identity = static fn(mixed $current): array => ['value' => $current];

        foreach (['lock-fail', 'rewind-fail', 'truncate-fail', 'zero-write', 'short-write'] as $fault) {
            Pr170FaultStreamWrapper::$modes[$fault] = $fault;
            self::assertFalse(
                $method->invoke($storage, self::FAULT_SCHEME . '://root/' . $fault, $identity),
                $fault,
            );
            $this->assertRuntimeFailure(
                static fn(): mixed => $requiredUpdate->invoke(
                    $storage,
                    self::FAULT_SCHEME . '://root/' . $fault,
                    $identity,
                ),
            );
        }

        foreach (['zero-file-write', 'short-file-write'] as $fault) {
            Pr170FaultStreamWrapper::$modes[$fault] = str_starts_with($fault, 'zero') ? 'zero-write' : 'short-write';
            $this->assertRuntimeFailure(
                static fn(): mixed => $requiredWrite->invoke(
                    $storage,
                    self::FAULT_SCHEME . '://root/' . $fault,
                    ['value' => true],
                ),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredMutationFamilyProvider(): iterable
    {
        yield 'ordinary term posting' => ['term'];
        yield 'ordinary term language' => ['term-language'];
        yield 'ordinary title' => ['title'];
        yield 'keyed title' => ['keyed-title'];
        yield 'ngram directory' => ['ngram-directory'];
        yield 'ngram indexed bucket' => ['ngram-bucket'];
        yield 'ordinary compound rows' => ['compound-row'];
        yield 'keyed compound rows' => ['keyed-compound-row'];
        yield 'compound aggregate bucket' => ['compound-bucket'];
        yield 'document count metadata' => ['metadata-count'];
        yield 'total length metadata' => ['metadata-length'];
        yield 'hashed term filename sidecar' => ['term-sidecar'];
        yield 'hashed document-key sidecar' => ['document-key-sidecar'];
        yield 'required title deletion' => ['title-delete'];
        yield 'required keyed title deletion' => ['keyed-title-delete'];
        yield 'required ngram deletion' => ['ngram-delete'];
        yield 'required compound deletion' => ['compound-delete'];
        yield 'required keyed compound deletion' => ['keyed-compound-delete'];
    }

    #[DataProvider('requiredMutationFamilyProvider')]
    public function testCompleteRequiredMutationFamilyFailsLoudly(string $family): void
    {
        $handle = 'mutation-' . $family;
        $storage = $this->makeStorage($handle);
        $indexPath = $this->indexPath($handle);
        $suggestions = [[
            'suggestion' => 'Alpha guide',
            'normalizedSuggestion' => 'alpha guide',
            'tokenKey' => 'alpha guide',
            'frequency' => 1,
        ]];

        $operation = match ($family) {
            'term' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/terms/alpha_1.dat');
                $storage->storeTermDocument('alpha', 1, 101, 1, 'en');
            },
            'term-language' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/term-languages/alpha_1.dat');
                $storage->storeTermDocument('alpha', 1, 101, 1, 'en');
            },
            'title' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/titles/1_101.dat');
                $storage->storeTitleTerms(1, 101, ['alpha']);
            },
            'keyed-title' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/titles/1_101_1_intro.dat');
                $storage->storeTitleTermsByKey(1, 101, '101_1_intro', ['alpha']);
            },
            'ngram-directory' => function() use ($storage, $indexPath): void {
                file_put_contents($indexPath . '/ngrams/site1', 'directory blocker');
                $storage->storeTermNgrams('alpha', ['al'], 1);
            },
            'ngram-bucket' => function() use ($storage, $indexPath): void {
                FileHelper::createDirectory($indexPath . '/ngrams-index/site1');
                $this->blockPath($indexPath . '/ngrams-index/site1/al.dat');
                $storage->storeTermNgrams('alpha', ['al'], 1);
            },
            'compound-row' => function() use ($storage, $indexPath, $suggestions): void {
                $this->blockPath($indexPath . '/compounds/1_101.dat');
                $storage->storeCompoundSuggestions(1, 101, $suggestions, 'en');
            },
            'keyed-compound-row' => function() use ($storage, $indexPath, $suggestions): void {
                $this->blockPath($indexPath . '/compounds/1_101_1_intro.dat');
                $storage->storeCompoundSuggestionsByKey(1, 101, '101_1_intro', $suggestions, 'en');
            },
            'compound-bucket' => function() use ($storage, $indexPath, $suggestions): void {
                FileHelper::createDirectory($indexPath . '/compounds-index/site1/en');
                $this->blockPath($indexPath . '/compounds-index/site1/en/a.dat');
                $storage->storeCompoundSuggestions(1, 101, $suggestions, 'en');
            },
            'metadata-count' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/meta/1_doc_count.dat');
                $storage->updateMetadata(1, 4, true);
            },
            'metadata-length' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/meta/1_total_length.dat');
                $storage->updateMetadata(1, 4, true);
            },
            'term-sidecar' => function() use ($storage, $indexPath): void {
                $term = str_repeat('α', 300);
                $safe = '__utf8_sha256_' . hash('sha256', $term);
                $this->blockPath($indexPath . '/keys/' . $safe . '.dat');
                $storage->storeTermDocument($term, 1, 101, 1, 'en');
            },
            'document-key-sidecar' => function() use ($storage, $indexPath): void {
                $documentKey = '101_1_' . str_repeat('β', 300);
                $safe = '__utf8_sha256_' . hash('sha256', $documentKey);
                $this->blockPath($indexPath . '/keys/' . $safe . '.dat');
                $storage->storeTitleTermsByKey(1, 101, $documentKey, ['alpha']);
            },
            'title-delete' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/titles/1_101.dat');
                $storage->deleteTitleTerms(1, 101);
            },
            'keyed-title-delete' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/titles/1_101_1_intro.dat');
                $storage->deleteTitleTermsByKey(1, '101_1_intro');
            },
            'ngram-delete' => function() use ($storage, $indexPath): void {
                FileHelper::createDirectory($indexPath . '/ngrams/site1');
                $this->blockPath($indexPath . '/ngrams/site1/alpha.dat');
                $storage->removeTermNgrams('alpha', ['al'], 1);
            },
            'compound-delete' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/compounds/1_101.dat');
                $storage->deleteCompoundSuggestions(1, 101);
            },
            'keyed-compound-delete' => function() use ($storage, $indexPath): void {
                $this->blockPath($indexPath . '/compounds/1_101_1_intro.dat');
                $storage->deleteCompoundSuggestionsByKey(1, '101_1_intro');
            },
        };

        $this->assertRuntimeFailure($operation);
    }

    public function testInvalidJsonEncodingCannotReportRequiredWriteSuccess(): void
    {
        $storage = $this->makeStorage('encode');
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $this->assertRuntimeFailure(static fn(): mixed => $storage->storeTitleTerms(1, 101, [$resource]));
        } finally {
            fclose($resource);
        }
    }

    public function testOrdinaryAndKeyedPageSplitFailuresReachSearchEngineAndPr159Truth(): void
    {
        $handle = 'batch';
        $storage = $this->makeStorage($handle);
        $backend = new Pr170LocalBackend($storage);
        $indexPath = $this->indexPath($handle);
        $documents = [
            [
                'elementId' => 101,
                'siteId' => 1,
                'backendId' => '101_1',
                'title' => 'PRIVATE_TITLE_SENTINEL',
                'content' => 'alpha content',
                'type' => 'entry',
            ],
            [
                'elementId' => 202,
                'siteId' => 1,
                'backendId' => '202_1_intro',
                'title' => 'Safe split sibling',
                'content' => 'beta content',
                'type' => 'entry',
            ],
        ];

        $this->blockPath($indexPath . '/titles/1_101.dat');
        self::assertFalse($backend->batchIndex('pr170-batch', $documents));
        self::assertSame(['101_1', '202_1_intro'], array_column($backend->getLastIndexingFailures(), 'backendId'));
        self::assertStringNotContainsString(
            'PRIVATE_TITLE_SENTINEL',
            json_encode($backend->getLastIndexingFailures(), JSON_THROW_ON_ERROR),
        );

        $keyedHandle = 'batch-keyed';
        $keyedBackend = new Pr170LocalBackend($this->makeStorage($keyedHandle));
        $this->blockPath($this->indexPath($keyedHandle) . '/titles/1_202_1_intro.dat');
        self::assertFalse($keyedBackend->batchIndex('pr170-batch-keyed', [$documents[1]]));
        self::assertSame(['202_1_intro'], array_column($keyedBackend->getLastIndexingFailures(), 'backendId'));
    }

    public function testA6RetainsAndReplaysWholeFileFailureGroupWithoutSuccessEffects(): void
    {
        [$index, $elements] = $this->workingIndexAndTwoElements();
        $handle = 'a6';
        $storage = $this->makeStorage($handle);
        $service = new Pr170BackendService(new Pr170LocalBackend($storage));
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->queueExactRows($index, $elements);
        $blockedTitle = $this->indexPath($handle) . '/titles/' . $elements[0]->siteId . '_' . $elements[0]->id . '.dat';
        $this->blockPath($blockedTitle);
        $beforeStats = $this->fetchSearchIndexStatsByHandle($index->handle);
        $afterEvents = 0;
        $handler = static function(IndexEvent $event) use (&$afterEvents, $index): void {
            if ($event->indexHandle === $index->handle) {
                $afterEvents++;
            }
        };
        Event::on(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $handler);

        try {
            (new BatchSyncJob())->execute(Craft::$app->getQueue());
        } finally {
            Event::off(IndexingService::class, IndexingService::EVENT_AFTER_INDEX, $handler);
        }

        $rows = $this->pendingRowsFor($index->handle);
        self::assertCount(2, $rows);
        self::assertSame(['failed'], array_values(array_unique(array_column($rows, 'status'))));
        self::assertSame(0, $afterEvents);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertSame(0, $service->documentCountCalls);
        self::assertSame($beforeStats, $this->fetchSearchIndexStatsByHandle($index->handle));

        unlink($blockedTitle . '/blocker');
        rmdir($blockedTitle);
        Craft::$app->getDb()->createCommand()->update(
            '{{%searchmanager_pending_syncs}}',
            ['nextAttemptAt' => Db::prepareDateForDb((new \DateTimeImmutable())->modify('-1 second'))],
            ['indexHandle' => $index->handle],
        )->execute();
        (new BatchSyncJob())->execute(Craft::$app->getQueue());

        $retriedRows = $this->pendingRowsFor($index->handle);
        self::assertCount(2, $retriedRows);
        self::assertSame(['failed'], array_values(array_unique(array_column($retriedRows, 'status'))));
        self::assertSame([2], array_values(array_unique(array_map('intval', array_column($retriedRows, 'attemptCount')))));
        self::assertCount(2, $service->batchCalls);
        self::assertSame(
            array_column($service->batchCalls[0], 'backendId'),
            array_column($service->batchCalls[1], 'backendId'),
        );
        self::assertSame(0, $afterEvents);
        self::assertSame(0, $service->clearSearchCacheCalls);
        self::assertSame(0, $service->documentCountCalls);
        self::assertSame($beforeStats, $this->fetchSearchIndexStatsByHandle($index->handle));
    }

    public function testFileSearchReadFailureIsPrivateNotCachedAndSuccessfulEmptyRetryIsCacheable(): void
    {
        $this->withIsolatedSearchCache(function(Pr170RecordingArrayCache $cache): void {
            $handle = 'search-cache';
            $storage = $this->makeStorage($handle);
            $backend = new Pr170LocalBackend($storage);
            $service = new Pr170BackendService($backend);
            $this->swapPluginComponent('search-manager', 'backend', $service);
            file_put_contents($this->indexPath($handle) . '/meta/1_doc_count.dat', '{invalid');
            $options = ['siteId' => 1, 'skipAnalytics' => true];

            $first = $service->search('pr170-search', 'pr170empty', $options);
            self::assertSame([], $first['hits']);
            self::assertSame(0, $first['total']);
            self::assertFalse($first['meta']['cached']);
            $this->assertNondisclosing($first);
            self::assertSame(0, $cache->searchCacheWriteCount());

            unlink($this->indexPath($handle) . '/meta/1_doc_count.dat');
            $second = $service->search('pr170-search', 'pr170empty', $options);
            $third = $service->search('pr170-search', 'pr170empty', $options);

            self::assertFalse($second['meta']['cached']);
            self::assertTrue($third['meta']['cached']);
            self::assertSame(1, $cache->searchCacheWriteCount());
            self::assertSame(2, $backend->searchCalls);
        });
    }

    public function testFileTokenAutocompleteReadFailureIsPrivateNotCachedAndSuccessfulEmptyRetryIsCacheable(): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalEnable = $settings->enableAutocompleteCache;
        $originalStorage = $settings->cacheStorageMethod;
        $originalPrefix = $settings->indexPrefix;
        $originalCache = Craft::$app->getCache();
        $handle = 'autocomplete-failure';
        $storageHandle = 'autocomplete-cache';
        $storage = $this->makeStorage($storageHandle);
        $backend = new Pr170LocalBackend($storage);
        $this->swapPluginComponent('search-manager', 'backend', new Pr170BackendService($backend));
        $options = [
            'limit' => 5,
            'minLength' => 1,
            'siteId' => 1,
            'fuzzy' => false,
            'includeMeta' => true,
        ];
        $termPath = $this->indexPath($storageHandle) . '/terms/pr170_1.dat';
        file_put_contents($termPath, '{invalid');
        $settings->enableAutocompleteCache = true;
        $settings->cacheStorageMethod = 'redis';
        $settings->indexPrefix = 'pr170_';
        Craft::$app->set('cache', new ArrayCache());

        try {
            SearchManager::$plugin->autocomplete->clearCache($handle);
            $first = SearchManager::$plugin->autocomplete->suggest('pr170', $handle, $options);
            self::assertSame([], $first['suggestions']);
            self::assertFalse($first['meta']['cached']);
            $this->assertNondisclosing($first);

            unlink($termPath);
            $second = SearchManager::$plugin->autocomplete->suggest('pr170', $handle, $options);
            $third = SearchManager::$plugin->autocomplete->suggest('pr170', $handle, $options);

            self::assertFalse($second['meta']['cached']);
            $callsAfterRetry = $storage->termDocumentCalls;
            self::assertTrue($third['meta']['cached']);
            self::assertGreaterThan(0, $callsAfterRetry);
            self::assertSame($callsAfterRetry, $storage->termDocumentCalls);
        } finally {
            SearchManager::$plugin->autocomplete->clearCache($handle);
            Craft::$app->set('cache', $originalCache);
            $settings->enableAutocompleteCache = $originalEnable;
            $settings->cacheStorageMethod = $originalStorage;
            $settings->indexPrefix = $originalPrefix;
        }
    }

    public function testMissingRequiredHashedFilenameSidecarFailsRecovery(): void
    {
        $handle = 'missing-sidecar';
        $storage = $this->makeStorage($handle);
        $term = str_repeat('γ', 300);
        $safe = '__utf8_sha256_' . hash('sha256', $term);
        file_put_contents($this->indexPath($handle) . '/terms/' . $safe . '_1.dat', '{"1:101":1}');

        $this->assertRuntimeFailure(static fn(): array => $storage->getTermsByPrefix($term, 1));
    }

    public function testRedundantParentKeySidecarRemainsNonAuthoritative(): void
    {
        $handle = 'parent-sidecar';
        $storage = $this->makeStorage($handle);
        $this->blockPath($this->indexPath($handle) . '/parents/1_301.dat');

        $storage->storeTitleTermsByKey(1, 301, '301_1_intro', ['alpha']);

        self::assertSame([
            '301_1_intro' => ['alpha'],
        ], $storage->getTitleTermsBatchByKeys(1, ['301_1_intro']));
    }

    private function makeStorage(string $handle): Pr170CountingFileStorage
    {
        return new Pr170CountingFileStorage($handle, $this->storageAlias);
    }

    private function indexPath(string $handle): string
    {
        return $this->storageBasePath . '/' . $handle;
    }

    private function blockPath(string $path): void
    {
        FileHelper::createDirectory($path);
        file_put_contents($path . '/blocker', 'blocker');
    }

    private function registerFaultWrapper(): void
    {
        if (!in_array(self::FAULT_SCHEME, stream_get_wrappers(), true)) {
            self::assertTrue(stream_wrapper_register(self::FAULT_SCHEME, Pr170FaultStreamWrapper::class));
        }
    }

    private function assertRuntimeFailure(callable $operation): void
    {
        try {
            $operation();
        } catch (\RuntimeException) {
            self::assertTrue(true);
            return;
        }

        self::fail('The required File operation reported success instead of throwing.');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertNondisclosing(array $result): void
    {
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->storageBasePath, $encoded);
        self::assertStringNotContainsString('_failed', $encoded);
        self::assertStringNotContainsString('failureReporter', $encoded);
        self::assertStringNotContainsString('Unable to', $encoded);
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match('/(?:public|private|protected) function ' . preg_quote($method, '/') . '\\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, $method . ' source should be found.');

        return $matches[0];
    }

    /**
     * @return array{SearchIndex, list<Entry>}
     */
    private function workingIndexAndTwoElements(): array
    {
        $catalogue = SearchManager::$plugin->dependencies->getIndexCatalogue();
        foreach (SearchIndex::findAll() as $index) {
            if (
                !$index->enabled
                || $index->usesSplitSections()
                || $index->elementType !== Entry::class
                || !($catalogue[$index->handle]['referenceable'] ?? false)
            ) {
                continue;
            }

            $siteId = (int)(($index->getSiteIds() ?? Craft::$app->getSites()->getAllSiteIds())[0] ?? 0);
            if ($siteId === 0) {
                continue;
            }

            $matches = [];
            foreach (Entry::find()
                ->siteId($siteId)
                ->status(null)
                ->drafts(false)
                ->revisions(false)
                ->andWhere(['entries.primaryOwnerId' => null])
                ->limit(50)
                ->all() as $entry) {
                if ($index->matchesElement($entry)) {
                    $matches[] = $entry;
                }
                if (count($matches) === 2) {
                    return [$index, $matches];
                }
            }
        }

        self::markTestSkipped('Requires an available non-split Entry index with two matching entries.');
    }

    /**
     * @param list<Entry> $elements
     */
    private function queueExactRows(SearchIndex $index, array $elements): void
    {
        $rows = [];
        foreach ($elements as $element) {
            $rows[] = [
                'indexHandle' => $index->handle,
                'elementType' => Entry::class,
                'elementId' => (int)$element->id,
                'siteId' => (int)$element->siteId,
                'op' => PendingSyncRepository::OP_UPSERT,
            ];
        }
        $this->repository->upsertRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingRowsFor(string $indexHandle): array
    {
        return (new Query())
            ->from('{{%searchmanager_pending_syncs}}')
            ->where(['indexHandle' => $indexHandle])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    private function purgeTestIndices(): void
    {
        $handles = (new Query())
            ->select(['handle'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'handle', self::INDEX_PREFIX . '%', false])
            ->column();
        if ($handles !== []) {
            $ids = (new Query())
                ->select(['id'])
                ->from('{{%searchmanager_indices}}')
                ->where(['handle' => $handles])
                ->column();
            Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_index_sites}}', ['indexId' => $ids])->execute();
            Craft::$app->getDb()->createCommand()->delete('{{%searchmanager_indices}}', ['handle' => $handles])->execute();
        }
        SearchIndex::clearCache();
        SearchManager::$plugin->dependencies->clearIndexCatalogue();
    }

    private function deleteBatchQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()->delete($this->queueTable(), [
            'and',
            ['like', 'job', 'searchmanager'],
            ['like', 'job', 'BatchSyncJob'],
        ])->execute();
    }

    /**
     * @param callable(Pr170RecordingArrayCache): void $callback
     */
    private function withIsolatedSearchCache(callable $callback): void
    {
        $settings = SearchManager::$plugin->getSettings();
        $originalEnable = $settings->enableCache;
        $originalStorage = $settings->cacheStorageMethod;
        $originalPrefix = $settings->indexPrefix;
        $originalCache = Craft::$app->getCache();
        $cache = new Pr170RecordingArrayCache();
        $settings->enableCache = true;
        $settings->cacheStorageMethod = 'redis';
        $settings->indexPrefix = 'pr170_';
        Craft::$app->set('cache', $cache);

        try {
            $callback($cache);
        } finally {
            Craft::$app->set('cache', $originalCache);
            $settings->enableCache = $originalEnable;
            $settings->cacheStorageMethod = $originalStorage;
            $settings->indexPrefix = $originalPrefix;
        }
    }
}

/**
 * @since 5.54.0
 */
final class Pr170CountingFileStorage extends FileStorage
{
    public int $termDocumentCalls = 0;

    public function getTermDocuments(string $term, int $siteId): array
    {
        $this->termDocumentCalls++;

        return parent::getTermDocuments($term, $siteId);
    }
}

/**
 * @since 5.54.0
 */
final class Pr170LocalBackend extends AbstractSearchEngineBackend
{
    public int $searchCalls = 0;

    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return $this->storage;
    }

    protected function getBackendLabel(): string
    {
        return 'PR1.70 File test';
    }

    public function getName(): string
    {
        return 'file';
    }

    public function index(string $indexName, array $data): bool
    {
        return $this->indexWithResult($indexName, $data)['success'];
    }

    public function delete(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return true;
    }

    public function clearIndex(string $indexName): bool
    {
        return true;
    }

    public function documentExists(string $indexName, int $elementId, ?int $siteId = null): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }

    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls++;

        return parent::search($indexName, $query, $options);
    }
}

/**
 * @since 5.54.0
 */
final class Pr170BackendService extends BackendService
{
    /** @var list<list<array<string, mixed>>> */
    public array $batchCalls = [];
    public int $clearSearchCacheCalls = 0;
    public int $documentCountCalls = 0;

    public function __construct(private readonly Pr170LocalBackend $backend)
    {
        parent::__construct();
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        return $this->backend;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        $this->batchCalls[] = $items;

        return parent::batchIndex($indexName, $items);
    }

    public function clearSearchCache(string $indexName): void
    {
        $this->clearSearchCacheCalls++;
    }

    public function getDocumentCount(string $indexName, ?int $siteId = null): ?int
    {
        $this->documentCountCalls++;

        return count($this->batchCalls[array_key_last($this->batchCalls)] ?? []);
    }

    public function getDistinctParentCount(string $indexName, ?int $siteId = null): ?int
    {
        return $this->getDocumentCount($indexName, $siteId);
    }
}

/**
 * @since 5.54.0
 */
final class Pr170RecordingArrayCache extends ArrayCache
{
    /** @var list<string> */
    public array $setKeys = [];

    public function set($key, $value, $duration = null, $dependency = null)
    {
        $this->setKeys[] = (string)$key;

        return parent::set($key, $value, $duration, $dependency);
    }

    public function searchCacheWriteCount(): int
    {
        $prefix = PluginHelper::getCacheKeyPrefix(SearchManager::$plugin->id, 'search');

        return count(array_filter(
            $this->setKeys,
            static fn(string $key): bool => str_starts_with($key, $prefix),
        ));
    }
}

/**
 * Disposable stream-wrapper faults for lock and partial-write primitives.
 *
 * @since 5.54.0
 */
final class Pr170FaultStreamWrapper
{
    /** @var resource|null */
    public $context;

    /** @var array<string, string> */
    public static array $modes = [];

    private string $name = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->name = (string)basename(parse_url($path, PHP_URL_PATH) ?: '');
        $this->position = 0;

        return (self::$modes[$this->name] ?? '') !== 'open-fail';
    }

    public function stream_lock(int $operation): bool
    {
        return (self::$modes[$this->name] ?? '') !== 'lock-fail';
    }

    public function stream_read(int $count): string|false
    {
        if ((self::$modes[$this->name] ?? '') === 'read-fail') {
            return false;
        }

        $contents = '{}';
        $chunk = substr($contents, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        return match (self::$modes[$this->name] ?? '') {
            'zero-write' => 0,
            'short-write' => max(0, strlen($data) - 1),
            default => strlen($data),
        };
    }

    public function stream_eof(): bool
    {
        return $this->position >= 2;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ((self::$modes[$this->name] ?? '') === 'rewind-fail') {
            return false;
        }

        $this->position = $offset;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_truncate(int $newSize): bool
    {
        return (self::$modes[$this->name] ?? '') !== 'truncate-fail';
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return $this->stat(false);
    }

    /**
     * @return array<int|string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        $name = (string)basename(parse_url($path, PHP_URL_PATH) ?: '');

        return $this->stat($name === 'root');
    }

    /**
     * @return array<int|string, int>
     */
    private function stat(bool $directory): array
    {
        $mode = $directory ? 0040777 : 0100666;
        $size = $directory ? 0 : 2;

        return [
            2 => $mode,
            7 => $size,
            'mode' => $mode,
            'size' => $size,
        ];
    }
}
