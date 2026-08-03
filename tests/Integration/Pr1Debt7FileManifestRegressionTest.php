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
use craft\helpers\StringHelper;
use craft\web\View;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\TermNormalizer;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression coverage for the authoritative File index manifest.
 *
 * @since 5.54.0
 */
final class Pr1Debt7FileManifestRegressionTest extends TestCase
{
    private const INDEX_HANDLE = 'pr1-debt-7-file-manifest';

    private ?string $basePath = null;

    public function testNewEmptyFileIndexStartsWithReadyAuthoritativeManifest(): void
    {
        $this->makeStorage();

        self::assertSame([
            'format' => 'search-manager-file-index-manifest',
            'version' => 1,
            'readiness' => 'ready',
            'elements' => [],
            'documents' => [],
        ], $this->readManifest());
    }

    public function testColdSuggestionAndMetadataLookupsUseManifestWithoutCandidateFiles(): void
    {
        $storage = $this->makeStorage();
        $storage->storeElement(1, 101, 'Café_pro-file', 'entry');
        $storage->storeElementByKey(2, 202, '202_2_section', 'Café_pro guide', 'asset');
        $storage->storeDocument(1, 101, ['café' => 2], 7, 'fr-CA');
        $storage->storeDocumentByKey(2, 202, '202_2_section', ['guide' => 3], 11, 'pt-BR');

        $this->writeManifest([
            'format' => 'search-manager-file-index-manifest',
            'version' => 1,
            'readiness' => 'ready',
            'elements' => [
                '1_101' => [
                    'title' => 'Café_pro-file',
                    'elementType' => 'entry',
                    'searchText' => TermNormalizer::normalizeSearchText('Café_pro-file'),
                    'elementId' => 101,
                    'siteId' => 1,
                ],
                '2_202' => [
                    'title' => 'Café_pro guide',
                    'elementType' => 'asset',
                    'searchText' => TermNormalizer::normalizeSearchText('Café_pro guide'),
                    'elementId' => 202,
                    'siteId' => 2,
                ],
            ],
            'documents' => [
                '1:101_1' => [
                    'siteId' => 1,
                    'elementId' => 101,
                    'documentKey' => '101_1',
                    'length' => 7,
                    'language' => 'fr',
                ],
                '2:202_2_section' => [
                    'siteId' => 2,
                    'elementId' => 202,
                    'documentKey' => '202_2_section',
                    'length' => 11,
                    'language' => 'pt',
                ],
            ],
        ]);

        @unlink($this->indexPath() . '/elements/1_101.dat');
        @unlink($this->indexPath() . '/elements/2_202.dat');
        @unlink($this->indexPath() . '/docs/1_101.dat');
        @unlink($this->indexPath() . '/docs/2_202_2_section.dat');

        self::assertSame([
            [
                'title' => 'Café_pro-file',
                'elementType' => 'entry',
                'elementId' => 101,
                'siteId' => 1,
            ],
            [
                'title' => 'Café_pro guide',
                'elementType' => 'asset',
                'elementId' => 202,
                'siteId' => 2,
            ],
        ], $storage->getElementSuggestions('café_pro', null, 10));
        self::assertSame([
            '101_1' => 'fr',
        ], $storage->getDocumentLanguagesBatchByKeys(1, ['101_1']));
        self::assertSame([
            '202_2_section' => 11,
        ], $storage->getDocumentLengthsBatchByKeys(2, ['202_2_section']));
    }

    public function testSuggestionAndMetadataReadersHaveNoLegacyDiscoveryFallback(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        $suggestions = $this->methodBody($source, 'getElementSuggestions');
        self::assertStringNotContainsString('glob(', $suggestions);
        self::assertStringNotContainsString('readFile(', $suggestions);
        self::assertStringContainsString('readManifest(', $suggestions);

        foreach ([
            'getDocumentLanguagesBatch',
            'getDocumentLanguagesBatchByKeys',
            'getDocumentLengthsBatch',
            'getDocumentLengthsBatchByKeys',
        ] as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringNotContainsString('readFile(', $body, $method);
            self::assertStringContainsString('readManifest(', $body, $method);
        }
    }

    public function testMixedPhysicalManifestMutationsUseOneLockOrderedTransactionAuthority(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        foreach ([
            'storeDocument',
            'storeDocumentByKey',
            'deleteDocument',
            'deleteDocumentByKey',
            'storeElement',
            'storeElementByKey',
            'deleteElement',
            'clearSite',
        ] as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringContainsString('mutateStorageAndManifest(', $body, $method);
            self::assertStringNotContainsString('readManifest(', $body, $method);
        }

        $transaction = $this->methodBody($source, 'mutateStorageAndManifest');
        self::assertStringContainsString('withManifestLock(', $transaction);
        self::assertStringContainsString('LOCK_EX,', $transaction);
        self::assertStringContainsString('MANIFEST_READINESS_UPDATING', $transaction);
        self::assertStringContainsString('$mutatePhysicalStorage()', $transaction);
        self::assertLessThan(
            strpos($transaction, '$mutatePhysicalStorage()'),
            strpos($transaction, 'MANIFEST_READINESS_UPDATING'),
        );
        self::assertLessThan(
            strrpos($transaction, 'writeManifestUnlocked($finalManifest)'),
            strpos($transaction, '$mutatePhysicalStorage()'),
        );

        $clearAll = $this->methodBody($source, 'clearAll');
        self::assertStringContainsString('withManifestLock(LOCK_EX', $clearAll);
        self::assertStringContainsString('MANIFEST_READINESS_UPDATING', $clearAll);
        self::assertStringContainsString('deleteDirectoryOrFail(', $clearAll);
    }

    public function testSuggestionContractPreservesFiltersOrderShapeAndUpdates(): void
    {
        $storage = $this->makeStorage();
        $storage->storeElement(2, 20, 'Café_beta-two', 'asset');
        $storage->storeElement(1, 11, 'Café_beta-one', 'entry');
        $storage->storeElement(1, 3, 'Café_beta-three', 'asset');
        $storage->storeElement(1, 4, 'Other title', 'entry');

        self::assertSame([
            [
                'title' => 'Café_beta-one',
                'elementType' => 'entry',
                'elementId' => 11,
                'siteId' => 1,
            ],
            [
                'title' => 'Café_beta-three',
                'elementType' => 'asset',
                'elementId' => 3,
                'siteId' => 1,
            ],
            [
                'title' => 'Café_beta-two',
                'elementType' => 'asset',
                'elementId' => 20,
                'siteId' => 2,
            ],
        ], $storage->getElementSuggestions('CAFÉ_BETA', null, 10));
        self::assertSame([11], array_column($storage->getElementSuggestions('café_beta', 1, 10, 'entry'), 'elementId'));
        self::assertSame([3], array_column($storage->getElementSuggestions('café_beta', 1, 1, 'asset'), 'elementId'));

        $storage->storeElement(1, 11, 'Renamed.value_name', 'asset');

        self::assertSame([], $storage->getElementSuggestions('café_beta', 1, 10, 'entry'));
        self::assertSame([[
            'title' => 'Renamed.value_name',
            'elementType' => 'asset',
            'elementId' => 11,
            'siteId' => 1,
        ]], $storage->getElementSuggestions('renamed.value_', 1, 10, 'asset'));
    }

    public function testMetadataPreservesNormalSplitUpdateMissingAndSingleBatchParity(): void
    {
        $storage = $this->makeStorage();
        $storage->storeDocument(1, 101, ['alpha' => 1], 7, 'en-US');
        $storage->storeDocumentByKey(1, 202, '202_1_intro', ['beta' => 1], 11, 'fr-CA');
        $storage->storeDocumentByKey(1, 202, '202_1_details', ['gamma' => 1], 13, 'de');

        self::assertSame('en-US', $storage->getDocumentLanguage(1, 101));
        self::assertSame(7, $storage->getDocumentLength(1, 101));
        self::assertSame([
            101 => 'en-US',
            999 => 'en',
        ], $storage->getDocumentLanguagesBatch(1, [101, 999, 101]));
        self::assertSame([
            '101_1' => 7,
            '202_1_intro' => 11,
            '202_1_details' => 13,
            'missing' => 0,
        ], $storage->getDocumentLengthsBatchByKeys(1, [
            '101_1',
            '202_1_intro',
            '202_1_details',
            'missing',
        ]));
        self::assertSame([
            '101_1' => 'en-US',
            '202_1_intro' => 'fr-CA',
            '202_1_details' => 'de',
            'missing' => 'en',
        ], $storage->getDocumentLanguagesBatchByKeys(1, [
            '101_1',
            '202_1_intro',
            '202_1_details',
            'missing',
        ]));
        self::assertSame(['101_1'], $storage->getDocumentKeysByParent(1, 101));
        self::assertSame(['202_1_intro', '202_1_details'], $storage->getDocumentKeysByParent(1, 202));
        self::assertSame(2, $storage->getDistinctParentCount(1));

        $storage->storeDocumentByKey(1, 202, '202_1_intro', ['updated' => 1], 19, 'pt');

        self::assertSame(19, $storage->getDocumentLengthByKey(1, '202_1_intro'));
        self::assertSame(['202_1_intro' => 'pt'], $storage->getDocumentLanguagesBatchByKeys(1, ['202_1_intro']));
    }

    public function testDeletesAndClearsRemoveOnlyApplicableManifestRows(): void
    {
        $storage = $this->makeStorage();
        $storage->storeDocument(1, 101, ['one' => 1], 1, 'en');
        $storage->storeElement(1, 101, 'One', 'entry');
        $storage->storeDocumentByKey(1, 202, '202_1_intro', ['two' => 1], 2, 'en');
        $storage->storeDocumentByKey(1, 202, '202_1_details', ['three' => 1], 3, 'en');
        $storage->storeElementByKey(1, 202, '202_1_intro', 'Two', 'entry');
        $storage->storeElementByKey(1, 202, '202_1_details', 'Two', 'entry');
        $storage->storeDocument(2, 303, ['four' => 1], 4, 'fr');
        $storage->storeElement(2, 303, 'Three', 'asset');

        $storage->deleteDocumentByKey(1, '202_1_intro');
        $storage->deleteDocumentByKey(1, '202_1_intro');
        self::assertSame(['202_1_details'], $storage->getDocumentKeysByParent(1, 202));
        self::assertSame([202], array_column($storage->getElementSuggestions('two', 1), 'elementId'));

        $storage->deleteDocumentByKey(1, '202_1_details');
        self::assertSame([], $storage->getDocumentKeysByParent(1, 202));
        self::assertSame([], $storage->getElementSuggestions('two', 1));

        $storage->deleteDocument(1, 101);
        $storage->deleteDocument(1, 101);
        self::assertSame([], $storage->getDocumentKeysByParent(1, 101));
        self::assertSame([], $storage->getElementSuggestions('one', 1));
        self::assertSame([303], array_column($storage->getElementSuggestions('three', 2), 'elementId'));

        $storage->clearSite(2);
        self::assertSame([], $storage->getDocumentKeysByParent(2, 303));
        self::assertSame([], $storage->getElementSuggestions('', 2));

        $storage->clearAll();
        self::assertSame($this->emptyManifest(), $this->readManifest());
        self::assertSame([], $storage->getElementSuggestions('', null));
        self::assertSame(['missing' => 0], $storage->getDocumentLengthsBatchByKeys(1, ['missing']));
    }

    public function testClearThenReindexPopulatesReadyManifestLikeFullRebuildBoundary(): void
    {
        $storage = $this->makeStorage();
        $storage->storeDocument(1, 101, ['stale' => 1], 1, 'en');
        $storage->storeElement(1, 101, 'Stale', 'entry');

        $storage->clearAll();
        $storage->storeDocumentByKey(1, 202, '202_1_intro', ['fresh' => 2], 8, 'fr');
        $storage->storeElementByKey(1, 202, '202_1_intro', 'Fresh Guide', 'entry');

        $manifest = $this->readManifest();
        self::assertSame('ready', $manifest['readiness']);
        self::assertSame(['1_202'], array_keys($manifest['elements']));
        self::assertSame(['1:202_1_intro'], array_keys($manifest['documents']));
        self::assertSame([], $storage->getElementSuggestions('stale', 1));
        self::assertSame([202], array_column($storage->getElementSuggestions('fresh', 1), 'elementId'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unavailableManifestProvider(): iterable
    {
        yield 'legacy populated index' => ['legacy', 'legacy populated index has no manifest'];
        yield 'missing sidecar' => ['missing', 'manifest is missing'];
        yield 'corrupt sidecar' => ['corrupt', 'manifest is corrupt'];
        yield 'incomplete sidecar' => ['incomplete', 'manifest is incomplete'];
        yield 'unsupported version' => ['unsupported', 'manifest version is unsupported'];
    }

    #[DataProvider('unavailableManifestProvider')]
    public function testUnavailableManifestStatesFailClosedAndLogOneRebuildAction(string $state, string $reason): void
    {
        if ($state === 'legacy') {
            $this->assignBasePath();
            $legacyIndexPath = $this->indexPath();
            mkdir($legacyIndexPath . '/docs', 0755, true);
            file_put_contents($legacyIndexPath . '/docs/1_101.dat', '{"_length":7,"_language":"en"}');
            $storage = new FileStorage(self::INDEX_HANDLE, $this->basePath);
        } else {
            $storage = $this->makeStorage();
            if ($state === 'missing') {
                @unlink($this->indexPath() . '/manifest.json');
            } elseif ($state === 'corrupt') {
                file_put_contents($this->indexPath() . '/manifest.json', '{"format":');
            } elseif ($state === 'incomplete') {
                $manifest = $this->emptyManifest();
                $manifest['readiness'] = 'building';
                $this->writeManifest($manifest);
            } else {
                $manifest = $this->emptyManifest();
                $manifest['version'] = 99;
                $this->writeManifest($manifest);
            }
        }

        $logger = Craft::getLogger();
        $before = count($logger->messages);

        self::assertSame([], $storage->getElementSuggestions('', null));
        self::assertSame([], $storage->getElementSuggestions('', null));

        foreach ([1, 2] as $attempt) {
            try {
                $storage->getDocumentLengthsBatchByKeys(1, ['101_1']);
                self::fail("Metadata lookup attempt {$attempt} should fail closed.");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Rebuild the File index', $e->getMessage());
                self::assertStringContainsString($reason, $e->getMessage());
            }
        }

        $messages = array_values(array_filter(
            array_slice($logger->messages, $before),
            static fn(array $message): bool => str_contains((string)($message[0] ?? ''), $reason),
        ));
        self::assertCount(1, $messages);
        self::assertStringContainsString('Rebuild the File index', (string)$messages[0][0]);
        self::assertFileDoesNotExist($this->indexPath() . '/manifest.json.tmp');
    }

    public function testMultipleStorageInstancesPreserveIndependentManifestUpdates(): void
    {
        $first = $this->makeStorage();
        self::assertIsString($this->basePath);
        $second = new FileStorage(self::INDEX_HANDLE, $this->basePath);

        $first->storeDocument(1, 101, ['one' => 1], 5, 'en');
        $second->storeDocumentByKey(1, 202, '202_1_intro', ['two' => 1], 9, 'fr');
        $first->storeElement(1, 101, 'One', 'entry');
        $second->storeElementByKey(1, 202, '202_1_intro', 'Two', 'asset');

        self::assertSame([
            '101_1' => 5,
            '202_1_intro' => 9,
        ], $first->getDocumentLengthsBatchByKeys(1, ['101_1', '202_1_intro']));
        self::assertSame([101, 202], array_column($second->getElementSuggestions('', 1), 'elementId'));

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);
        self::assertStringContainsString('withManifestLock(LOCK_EX', $source);
        self::assertStringContainsString('LOCK_SH,', $source);
        self::assertStringContainsString('@rename($temporaryPath, $this->getManifestPath())', $source);
        self::assertStringNotContainsString('manifestCache', $source);
    }

    public function testIncompleteManifestBlocksIncrementalWritesUntilFullClearBoundary(): void
    {
        $storage = $this->makeStorage();
        $manifest = $this->emptyManifest();
        $manifest['readiness'] = 'building';
        $this->writeManifest($manifest);
        $before = (string)file_get_contents($this->indexPath() . '/manifest.json');

        try {
            $storage->storeDocument(1, 101, ['blocked' => 1], 1, 'en');
            self::fail('An incomplete manifest must block incremental File document writes.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('manifest is incomplete', $e->getMessage());
            self::assertStringContainsString('Rebuild the File index', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->indexPath() . '/docs/1_101.dat');
        self::assertSame($before, (string)file_get_contents($this->indexPath() . '/manifest.json'));

        $storage->clearAll();
        $storage->storeDocument(1, 101, ['ready' => 1], 2, 'en');

        self::assertSame(['101_1' => 2], $storage->getDocumentLengthsBatchByKeys(1, ['101_1']));
        self::assertSame('ready', $this->readManifest()['readiness']);
    }

    public function testSearchLanguageAndScoringUseColdManifestMetadataForNormalAndSplitKeys(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, self::INDEX_HANDLE, [
            'disableStopWords' => true,
        ]);

        self::assertTrue($engine->indexDocument(1, 101, 'Protein', 'protein powder', 'en'));
        self::assertTrue($engine->indexDocumentWithKeyResult(
            1,
            202,
            '202_1_intro',
            'Protéine',
            'protein français protein',
            'fr',
        )['success']);

        @unlink($this->indexPath() . '/docs/1_101.dat');
        @unlink($this->indexPath() . '/docs/1_202_1_intro.dat');

        $all = $engine->search('protein', 1, 0, ['returnDocumentKeys' => true]);
        self::assertSame(['101_1', '202_1_intro'], array_keys($all));
        self::assertSame(['101_1'], array_keys($engine->search('protein', 1, 0, [
            'language' => 'en-US',
            'returnDocumentKeys' => true,
        ])));
        self::assertSame(['202_1_intro'], array_keys($engine->search('protein', 1, 0, [
            'language' => 'fr-CA',
            'returnDocumentKeys' => true,
        ])));
    }

    public function testInterruptedDocumentStoreLeavesManifestNonReadyUntilFullClear(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for File manifest crash regression coverage.');
        }

        $storage = $this->makeStorage();
        $documentPath = $this->indexPath() . '/docs/1_101.dat';
        $documentHandle = fopen($documentPath, 'c+');
        self::assertIsResource($documentHandle);
        $this->registerOwnedStream($documentHandle);
        self::assertTrue(flock($documentHandle, LOCK_EX));

        [$process, $pipes, $script] = $this->startFileStorageWorker('store-document', [
            '1',
            '101',
            '7',
            'en',
        ]);

        $sawInProgress = $this->waitForManifestReadiness('updating');
        proc_terminate($process);
        flock($documentHandle, LOCK_UN);
        fclose($documentHandle);
        $this->finishTerminatedProcess($process, $pipes);
        @unlink($script);

        self::assertTrue($sawInProgress, 'The non-ready manifest marker must be durable before the document write can block.');
        $this->assertManifestLookupFailsClosed($storage, 'manifest is incomplete');

        $storage->clearAll();
        $storage->storeDocument(1, 101, ['ready' => 1], 2, 'en');

        self::assertSame('ready', $this->readManifest()['readiness']);
        self::assertSame(['101_1' => 2], $storage->getDocumentLengthsBatchByKeys(1, ['101_1']));
    }

    public function testStoreAndSiteClearCannotInterleaveIntoReadyDivergence(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for File manifest interleaving regression coverage.');
        }

        $storage = $this->makeStorage();
        $documentPath = $this->indexPath() . '/docs/1_101.dat';
        $documentHandle = fopen($documentPath, 'c+');
        self::assertIsResource($documentHandle);
        $this->registerOwnedStream($documentHandle);
        self::assertTrue(flock($documentHandle, LOCK_EX));

        [$storeProcess, $storePipes, $storeScript] = $this->startFileStorageWorker('store-document', [
            '1',
            '101',
            '7',
            'en',
        ]);
        $sawInProgress = $this->waitForManifestReadiness('updating');

        $clearDonePath = $this->basePath . '/clear-site-done-' . StringHelper::UUID() . '.tmp';
        $this->trackOwnedTempPath($clearDonePath);
        [$clearProcess, $clearPipes, $clearScript] = $this->startFileStorageWorker('clear-site', [
            '1',
            $clearDonePath,
        ]);

        usleep(200000);
        $clearFinishedWhileStoreBlocked = file_exists($clearDonePath);

        flock($documentHandle, LOCK_UN);
        fclose($documentHandle);
        $this->finishPhpProcess($storeProcess, $storePipes, 'File document store worker failed.');
        $this->finishPhpProcess($clearProcess, $clearPipes, 'File site-clear worker failed.');

        @unlink($storeScript);
        @unlink($clearScript);
        @unlink($clearDonePath);

        self::assertTrue($sawInProgress, 'The store must publish its non-ready marker before touching the document file.');
        self::assertFalse($clearFinishedWhileStoreBlocked, 'Site clear must wait for the store transaction manifest lock.');
        self::assertSame('ready', $this->readManifest()['readiness']);
        self::assertSame([], $this->readManifest()['documents']);
        self::assertSame(['101_1' => 0], $storage->getDocumentLengthsBatchByKeys(1, ['101_1']));
        self::assertFileDoesNotExist($documentPath);
    }

    public function testPhysicalAndFinalManifestFailuresCannotLeaveOldReadyAuthority(): void
    {
        $storage = $this->makeStorage();
        $documentPath = $this->indexPath() . '/docs/1_101.dat';
        mkdir($documentPath);

        $physicalFailure = null;
        try {
            $storage->storeDocument(1, 101, ['blocked' => 1], 1, 'en');
        } catch (\RuntimeException $e) {
            $physicalFailure = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $physicalFailure);
        self::assertStringContainsString('Rebuild the File index', $physicalFailure->getMessage());
        self::assertSame('updating', $this->readManifest()['readiness']);
        $this->assertManifestLookupFailsClosed($storage, 'manifest is incomplete');

        $storage->clearAll();
        $documentPath = $this->indexPath() . '/docs/1_101.dat';
        $documentHandle = fopen($documentPath, 'c+');
        self::assertIsResource($documentHandle);
        $this->registerOwnedStream($documentHandle);
        self::assertTrue(flock($documentHandle, LOCK_EX));

        [$process, $pipes, $script] = $this->startFileStorageWorker('store-document', [
            '1',
            '101',
            '7',
            'en',
        ]);
        $sawInProgress = $this->waitForManifestReadiness('updating');
        self::assertTrue($sawInProgress, 'The final-write failure proof requires the durable non-ready marker.');

        $movedIndexPath = $this->indexPath() . '.moved';
        self::assertTrue(rename($this->indexPath(), $movedIndexPath));
        self::assertNotFalse(file_put_contents($this->indexPath(), 'blocked'));

        flock($documentHandle, LOCK_UN);
        fclose($documentHandle);
        $exitCode = $this->finishPhpProcessWithExitCode($process, $pipes);

        self::assertTrue(unlink($this->indexPath()));
        self::assertTrue(rename($movedIndexPath, $this->indexPath()));
        @unlink($script);

        self::assertNotSame(0, $exitCode, 'The worker must report the failed final manifest replacement.');
        self::assertSame('updating', $this->readManifest()['readiness']);
        $this->assertManifestLookupFailsClosed($storage, 'manifest is incomplete');

        $storage->clearAll();
        self::assertSame($this->emptyManifest(), $this->readManifest());
    }

    public function testDeleteFailureCannotLeaveReadyManifestPointingAtMissingPhysicalState(): void
    {
        $storage = $this->makeStorage();
        $storage->storeDocument(1, 101, ['existing' => 1], 4, 'en');
        $storage->storeElement(1, 101, 'Existing', 'entry');

        $documentPath = $this->indexPath() . '/docs/1_101.dat';
        self::assertTrue(unlink($documentPath));
        self::assertTrue(mkdir($documentPath));

        $deleteFailure = null;
        try {
            $storage->deleteDocumentByKey(1, '101_1');
        } catch (\RuntimeException $e) {
            $deleteFailure = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $deleteFailure);
        self::assertSame('updating', $this->readManifest()['readiness']);
        $this->assertManifestLookupFailsClosed($storage, 'manifest is incomplete');

        $storage->clearAll();
        self::assertSame($this->emptyManifest(), $this->readManifest());
        self::assertSame(['101_1' => 0], $storage->getDocumentLengthsBatchByKeys(1, ['101_1']));
    }

    public function testFileEnvironmentWarningRendersOnlyForFileAndIsWiredToBothSurfaces(): void
    {
        $fileHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_file-environment-warning',
            ['backendType' => 'file'],
            View::TEMPLATE_MODE_CP,
        );
        $fileWithoutMarginHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_file-environment-warning',
            ['backendType' => 'file', 'margin' => 'none'],
            View::TEMPLATE_MODE_CP,
        );
        $redisHtml = Craft::$app->getView()->renderTemplate(
            'search-manager/_components/_file-environment-warning',
            ['backendType' => 'redis'],
            View::TEMPLATE_MODE_CP,
        );

        self::assertStringContainsString('lr-info-box--warning', $fileHtml);
        self::assertStringContainsString('lr-info-box--margin-top', $fileHtml);
        self::assertStringContainsString('lr-info-box--margin-none', $fileWithoutMarginHtml);
        self::assertStringContainsString('small, persistent, single-node installations', $fileHtml);
        self::assertStringContainsString('edge, ephemeral, shared-volume, multi-server, or larger environments', $fileHtml);
        self::assertSame('', trim($redisHtml));

        $key = 'File is intended for small, persistent, single-node installations. Prefer MySQL or Redis for edge, ephemeral, shared-volume, multi-server, or larger environments.';
        foreach (['en', 'de', 'fr', 'nl', 'es', 'ar', 'it', 'pt', 'ja', 'sv', 'da', 'no'] as $language) {
            $translations = require dirname(__DIR__, 2) . '/src/translations/' . $language . '/search-manager.php';
            self::assertArrayHasKey($key, $translations, $language);
            self::assertNotSame('', trim((string)$translations[$key]), $language);
        }

        $edit = file_get_contents(dirname(__DIR__, 2) . '/src/templates/backends/edit.twig');
        self::assertIsString($edit);
        self::assertSame(2, substr_count($edit, '_components/_file-environment-warning'));
        self::assertStringContainsString('backendType: type', $edit);
        self::assertStringContainsString('backendType: backend.backendType', $edit);
        self::assertSame(1, substr_count($edit, "margin: 'none'"));
    }

    private function makeStorage(): FileStorage
    {
        $this->assignBasePath();

        return new FileStorage(self::INDEX_HANDLE, $this->basePath);
    }

    private function assignBasePath(): void
    {
        if ($this->basePath !== null) {
            throw new \LogicException('This test already owns a File storage root.');
        }

        $path = $this->createOwnedStorageDirectory('file-manifest');
        $this->basePath = $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        $data = json_decode(
            (string)file_get_contents($this->indexPath() . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return array{
     *     format: string,
     *     version: int,
     *     readiness: string,
     *     elements: array<string, mixed>,
     *     documents: array<string, mixed>
     * }
     */
    private function emptyManifest(): array
    {
        return [
            'format' => 'search-manager-file-index-manifest',
            'version' => 1,
            'readiness' => 'ready',
            'elements' => [],
            'documents' => [],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        file_put_contents(
            $this->indexPath() . '/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0: resource, 1: array<int, resource>, 2: string}
     */
    private function startFileStorageWorker(string $operation, array $arguments): array
    {
        self::assertIsString($this->basePath);
        $script = $this->basePath . '/file-storage-worker-' . StringHelper::UUID() . '.php';
        $this->trackOwnedTempPath($script);
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);

use lindemannrock\searchmanager\search\storage\FileStorage;

require $argv[1];

$operation = $argv[2];
$basePath = $argv[3];
$storage = new FileStorage('pr1-debt-7-file-manifest', $basePath);

if ($operation === 'store-document') {
    $storage->storeDocument((int)$argv[4], (int)$argv[5], ['worker' => 1], (int)$argv[6], $argv[7]);
    exit(0);
}

if ($operation === 'clear-site') {
    $storage->clearSite((int)$argv[4]);
    touch($argv[5]);
    exit(0);
}

exit(1);
PHP);

        $command = array_merge([
            PHP_BINARY,
            $script,
            dirname(__DIR__) . '/bootstrap.php',
            $operation,
            $this->basePath,
        ], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process, 'Unable to start FileStorage worker process.');
        $this->registerOwnedProcess($process, $pipes);

        return [$process, $pipes, $script];
    }

    private function waitForManifestReadiness(string $readiness): bool
    {
        self::assertIsString($this->basePath);
        $manifestPath = $this->basePath . '/' . self::INDEX_HANDLE . '/manifest.json';
        $started = microtime(true);
        while (microtime(true) - $started <= 5.0) {
            $contents = @file_get_contents($manifestPath);
            if (is_string($contents)) {
                $manifest = json_decode($contents, true);
                if (is_array($manifest) && ($manifest['readiness'] ?? null) === $readiness) {
                    return true;
                }
            }

            usleep(10000);
        }

        return false;
    }

    private function assertManifestLookupFailsClosed(FileStorage $storage, string $reason): void
    {
        try {
            $storage->getDocumentLengthsBatchByKeys(1, ['101_1']);
            self::fail('A non-ready File manifest must fail metadata reads closed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringContainsString('Rebuild the File index', $e->getMessage());
        }
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function finishTerminatedProcess($process, array $pipes): void
    {
        $this->finishOwnedProcess($process, $pipes, true);
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function finishPhpProcess($process, array $pipes, string $message): void
    {
        $exitCode = $this->finishPhpProcessWithExitCode($process, $pipes);
        self::assertSame(0, $exitCode, $message);
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function finishPhpProcessWithExitCode($process, array $pipes): int
    {
        return $this->finishOwnedProcess($process, $pipes)['exitCode'];
    }

    private function indexPath(): string
    {
        self::assertIsString($this->basePath);

        return $this->basePath . '/' . self::INDEX_HANDLE;
    }

    private function methodBody(string $source, string $method): string
    {
        preg_match(
            '/function ' . preg_quote($method, '/') . '\(.*?^    }$/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }

}
