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
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\FileStorage;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * @since 5.53.0
 */
final class FileStorageRegressionTest extends TestCase
{
    private ?string $basePath = null;

    protected function tearDown(): void
    {
        if ($this->basePath !== null) {
            $this->deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    public function testElementSuggestionsPreserveStoredSiteIdOnAllSitesSearch(): void
    {
        $storage = $this->makeStorage();

        $storage->storeElement(7, 101, 'Protein Powder', 'entry');

        $suggestions = $storage->getElementSuggestions('protein', null, 10);

        self::assertSame([7], array_column($suggestions, 'siteId'));
        self::assertSame([101], array_column($suggestions, 'elementId'));
    }

    public function testMetadataUpdatesUseLockedReadModifyWriteHelper(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        preg_match('/public function updateMetadata\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, 'updateMetadata source should be found.');
        self::assertStringContainsString('updateJsonFile(', $matches[0]);
        self::assertStringNotContainsString('readFile(', $matches[0]);

        preg_match('/private function updateJsonFile\(.*?^    }$/ms', $source, $matches);
        self::assertNotEmpty($matches, 'updateJsonFile source should be found.');
        self::assertStringContainsString('flock($handle, LOCK_EX)', $matches[0]);
        self::assertStringContainsString('ftruncate($handle, 0)', $matches[0]);
    }

    public function testFileReadsUseSharedLockAndStillRoundTripJsonData(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        $body = $this->methodBody($source, 'readFile');
        self::assertStringContainsString('@fopen($path, \'rb\')', $body);
        self::assertStringContainsString('flock($handle, LOCK_SH)', $body);
        self::assertStringContainsString('stream_get_contents($handle)', $body);
        self::assertStringContainsString('flock($handle, LOCK_UN)', $body);
        self::assertStringNotContainsString('file_get_contents(', $body);

        $storage = $this->makeStorage();
        $storage->storeTermDocument('shared-lock', 1, 101, 3);

        self::assertSame(['1:101' => 3], $storage->getTermDocuments('shared-lock', 1));
    }

    public function testConcurrentTermDocumentStoresPreserveBothPostings(): void
    {
        $storage = $this->makeStorage();
        $termPath = $this->indexPath() . '/terms/shared_1.dat';
        $this->writeJsonFile($termPath, []);

        $this->runMutationWhileFileLockIsHeld(
            $termPath,
            fn() => $this->writeJsonFile($termPath, [
                '1:101' => 1,
            ]),
            ['store-term-document', $this->basePath, 'shared', '1', '102', '2'],
        );

        self::assertSame([
            '1:101' => 1,
            '1:102' => 2,
        ], $storage->getTermDocuments('shared', 1));
    }

    public function testConcurrentTermDocumentRemovePreservesCompetingPosting(): void
    {
        $storage = $this->makeStorage();
        $termPath = $this->indexPath() . '/terms/shared_1.dat';
        $this->writeJsonFile($termPath, [
            '1:101' => 1,
        ]);

        $this->runMutationWhileFileLockIsHeld(
            $termPath,
            fn() => $this->writeJsonFile($termPath, [
                '1:101' => 1,
                '1:102' => 2,
            ]),
            ['remove-term-document', $this->basePath, 'shared', '1', '101'],
        );

        self::assertSame([
            '1:102' => 2,
        ], $storage->getTermDocuments('shared', 1));
    }

    public function testConcurrentTermDocumentRemoveByKeyPreservesCompetingPosting(): void
    {
        $storage = $this->makeStorage();
        $termPath = $this->indexPath() . '/terms/shared_1.dat';
        $this->writeJsonFile($termPath, [
            '1:301_1_intro' => 1,
        ]);

        $this->runMutationWhileFileLockIsHeld(
            $termPath,
            fn() => $this->writeJsonFile($termPath, [
                '1:301_1_intro' => 1,
                '1:301_1_install' => 2,
            ]),
            ['remove-term-document-by-key', $this->basePath, 'shared', '1', '301_1_intro'],
        );

        self::assertSame([
            '1:301_1_install' => 2,
        ], $storage->getTermDocuments('shared', 1));
    }

    public function testConcurrentParentDocumentKeyRemovalPreservesCompetingKey(): void
    {
        $storage = $this->makeStorage();
        $parentPath = $this->indexPath() . '/parents/1_301.dat';
        $this->writeJsonFile($parentPath, [
            '301_1_intro',
        ]);

        $this->runMutationWhileFileLockIsHeld(
            $parentPath,
            fn() => $this->writeJsonFile($parentPath, [
                '301_1_intro',
                '301_1_install',
            ]),
            ['delete-document-by-key', $this->basePath, '1', '301_1_intro'],
        );

        self::assertSame(['301_1_install'], $storage->getDocumentKeysByParent(1, 301));
    }

    public function testRemovePathsUseLockedJsonUpdateHelper(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/search/storage/FileStorage.php');
        self::assertIsString($source);

        foreach ([
            'removeTermDocument',
            'removeTermDocumentByKey',
            'removeDocumentKeyForParent',
        ] as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringContainsString('updateJsonFile(', $body, $method);
            self::assertStringNotContainsString('readFile(', $body, $method);
            self::assertStringNotContainsString('writeFile(', $body, $method);
        }
    }

    public function testConcurrentNgramBucketRemovalsPreserveBothRemovals(): void
    {
        $storage = $this->makeStorage();
        $indexPath = $this->indexPath();
        $sharedBucketPath = $indexPath . '/ngrams-index/site1/sh.dat';

        $this->writeJsonFile($indexPath . '/ngrams/site1/alpha.dat', ['sh']);
        $this->writeJsonFile($indexPath . '/ngrams/site1/beta.dat', ['sh']);
        $this->writeJsonFile($sharedBucketPath, [
            'alpha' => 1,
            'beta' => 1,
        ]);

        $this->runMutationWhileFileLockIsHeld(
            $sharedBucketPath,
            fn() => $this->writeJsonFile($sharedBucketPath, [
                'beta' => 1,
            ]),
            ['store-term-ngrams', $this->basePath, 'beta', '1', json_encode(['be'], JSON_THROW_ON_ERROR)],
        );

        self::assertFileDoesNotExist($sharedBucketPath);
        self::assertSame(['beta'], array_keys($storage->getTermsByNgramSimilarity(['be'], 1, 1.0)));
    }

    public function testAllSitesAutocompleteRoundTripsUnderscoreTermsWithoutSiteSuffix(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermDocument('foo_bar', 1, 101, 1);
        $storage->storeTermDocument('foo_bar', 2, 201, 1);
        $storage->storeTermDocument('foo_baz', 1, 102, 1);
        $storage->storeTermDocument('other_term', 1, 103, 1);

        $terms = $storage->getTermsForAutocomplete(null, null, 10);

        self::assertSame(2, $terms['foo_bar'] ?? null);
        self::assertSame(1, $terms['foo_baz'] ?? null);
        self::assertSame(1, $terms['other_term'] ?? null);
        self::assertArrayNotHasKey('foo_bar_1', $terms);
        self::assertArrayNotHasKey('foo_bar_2', $terms);
        self::assertArrayNotHasKey('foo_baz_1', $terms);
    }

    public function testAutocompletePrefixFilterPreservesRankingAfterScanningMatchingFiles(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermDocument('alpha', 1, 101, 1);
        $storage->storeTermDocument('foo_product', 1, 102, 1);
        $storage->storeTermDocument('foo_product', 1, 103, 1);
        $storage->storeTermDocument('foo_protein', 1, 104, 1);
        $storage->storeTermDocument('foo_protein', 2, 204, 1);
        $storage->storeTermDocument('foo_profile', 1, 105, 1);

        $terms = $storage->getTermsForAutocomplete(null, null, 2, 'foo_pro');

        self::assertSame(['foo_product' => 2, 'foo_protein' => 2], $terms);
        self::assertArrayNotHasKey('alpha', $terms);
        self::assertArrayNotHasKey('foo_profile', $terms);
        self::assertArrayNotHasKey('foo_product_1', $terms);
        self::assertArrayNotHasKey('foo_protein_2', $terms);
    }

    public function testUnicodeTermsUseDistinctSafeFilenamesAndSearchIndependently(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'file-storage-regression', [
            'disableStopWords' => true,
        ]);

        self::assertTrue($engine->indexDocument(1, 101, 'بيت', 'بيت عربي', 'ar'));
        self::assertTrue($engine->indexDocument(1, 102, 'نور', 'نور عربي', 'ar'));

        self::assertSame(['1:101' => 2], $storage->getTermDocuments('بيت', 1));
        self::assertSame(['1:102' => 2], $storage->getTermDocuments('نور', 1));
        self::assertSame([101], array_keys($engine->search('بيت', 1)));
        self::assertSame([102], array_keys($engine->search('نور', 1)));

        $termFiles = glob($this->basePath . '/file-storage-regression/terms/*.dat');
        self::assertIsArray($termFiles);
        self::assertCount(3, $termFiles);
        self::assertCount(3, array_unique(array_map('basename', $termFiles)));
    }

    public function testUnicodeAutocompleteAndPrefixScansRecoverOriginalTerms(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermDocument('東京', 1, 101, 1);
        $storage->storeTermDocument('東京', 2, 201, 1);
        $storage->storeTermDocument('京都', 1, 102, 1);
        $storage->storeTermDocument('Москва', 1, 103, 1);

        self::assertSame(['東京' => 2], $storage->getTermsForAutocomplete(null, null, 10, '東'));
        self::assertSame(['京都'], $storage->getTermsByPrefix('京', 1));
        self::assertArrayHasKey('Москва', $storage->getTermsForAutocomplete(1, null, 10, 'Мос'));
    }

    public function testUnicodeNgramScansRecoverOriginalTerms(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermNgrams('東京', ['東京'], 1);
        $storage->storeTermNgrams('京都', ['京都'], 1);

        self::assertSame(['東京'], array_keys($storage->getTermsByNgramSimilarity(['東京'], 1, 1.0)));
        self::assertSame(['京都'], array_keys($storage->getTermsByNgramSimilarity(['京都'], 1, 1.0)));
    }

    public function testIndexedNgramLookupDoesNotReadUnrelatedLegacyTermFiles(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermNgrams('alpha', ['al', 'lp'], 1);

        $indexPath = $this->basePath . '/file-storage-regression';
        file_put_contents($indexPath . '/ngrams/site1/beta.dat', json_encode([
            'be',
            'et',
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $storage->getTermsByNgramSimilarity(['be', 'et'], 1, 1.0));
        self::assertSame(['alpha'], array_keys($storage->getTermsByNgramSimilarity(['al', 'lp'], 1, 1.0)));
    }

    public function testNgramLookupRequiresIndexedSiteDirectory(): void
    {
        $storage = $this->makeStorage();
        $indexPath = $this->basePath . '/file-storage-regression';

        mkdir($indexPath . '/ngrams/site1', 0755, true);
        file_put_contents($indexPath . '/ngrams/site1/legacy.dat', json_encode([
            'le',
            'eg',
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], $storage->getTermsByNgramSimilarity(['le', 'eg'], 1, 1.0));
    }

    public function testIndexedNgramStaleUpdateRemovesOldBuckets(): void
    {
        $storage = $this->makeStorage();
        $storage->storeTermNgrams('alpha', ['al', 'lp'], 1);
        $storage->storeTermNgrams('alpha', ['be', 'et'], 1);

        self::assertSame([], $storage->getTermsByNgramSimilarity(['al', 'lp'], 1, 1.0));
        self::assertSame(['alpha'], array_keys($storage->getTermsByNgramSimilarity(['be', 'et'], 1, 1.0)));

        $indexPath = $this->basePath . '/file-storage-regression';
        self::assertFileDoesNotExist($indexPath . '/ngrams-index/site1/al.dat');
    }

    public function testIndexedNgramLookupRecoversHashedSidecarTerm(): void
    {
        $storage = $this->makeStorage();
        $encodedNgram = '__utf8_5p2x5Lqs';
        $originalTerm = '東京';
        $indexPath = $this->basePath . '/file-storage-regression';

        mkdir($indexPath . '/ngrams-index/site1', 0755, true);
        file_put_contents($indexPath . '/ngrams-index/site1/' . $encodedNgram . '.dat', json_encode([
            $originalTerm => 1,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        self::assertSame(
            [$originalTerm],
            array_keys($storage->getTermsByNgramSimilarity(['東京'], 1, 1.0)),
        );
    }

    public function testCompoundSuggestionsAggregateByPrefixAndDeletePerDocumentRows(): void
    {
        $storage = $this->makeStorage();

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
        self::assertSame(['redirect.twig' => 8], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', null, 'en', 10));

        $storage->deleteCompoundSuggestions(1, 101);

        self::assertSame(['redirect.twig' => 1], $storage->getCompoundSuggestionsForAutocomplete('redirect.tw', 1, 'en', 10));
    }

    public function testCompoundSuggestionsUpdateAggregatesAndDisplayTieBreaks(): void
    {
        $storage = $this->makeStorage();

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

        $storage->deleteCompoundSuggestions(1, 101);

        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('readme', 1, 'en', 10));
        self::assertSame(['readme.twig' => 3], $storage->getCompoundSuggestionsForAutocomplete('readme', null, 'en', 10));
    }

    public function testCompoundLookupRequiresAggregateScope(): void
    {
        $storage = $this->makeStorage();
        $indexPath = $this->basePath . '/file-storage-regression';

        file_put_contents($indexPath . '/compounds/1_101.dat', json_encode([[
            'suggestion' => 'legacy.twig',
            'normalizedSuggestion' => 'legacy.twig',
            'tokenKey' => 'legacy twig',
            'frequency' => 5,
            'language' => 'en',
        ]], JSON_THROW_ON_ERROR));

        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('legacy', 1, 'en', 10));
        self::assertSame([], $storage->getCompoundSuggestionsForAutocomplete('legacy', null, 'en', 10));
    }

    public function testSplitSectionDocumentsAreSearchableAndHydratedByDocumentKey(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'file-storage-regression', [
            'disableStopWords' => true,
        ]);

        $this->indexSection($engine, $storage, '301_1_intro', 'Install Guide', 'overview landing', [
            'sectionType' => 'intro',
            'sectionTitle' => 'Install Guide',
            'sectionIndex' => 0,
        ]);
        $this->indexSection($engine, $storage, '301_1_install', 'Install Guide', 'composer install package', [
            'sectionType' => 'heading',
            'sectionTitle' => 'Install',
            'sectionLevel' => 2,
            'sectionAnchor' => 'install',
            'sectionUrl' => '/docs/install#install',
            'sectionIndex' => 1,
        ]);
        $this->indexSection($engine, $storage, '301_1_configure', 'Install Guide', 'configure settings', [
            'sectionType' => 'heading',
            'sectionTitle' => 'Configure',
            'sectionLevel' => 2,
            'sectionAnchor' => 'configure',
            'sectionUrl' => '/docs/install#configure',
            'sectionIndex' => 2,
        ]);

        self::assertTrue($storage->supportsDocumentKeys());
        self::assertSame(['301_1_intro', '301_1_install', '301_1_configure'], $storage->getDocumentKeysByParent(1, 301));
        self::assertSame(['301_1_install'], array_keys($engine->search('composer', 1, 0, ['returnDocumentKeys' => true])));
        self::assertSame(['1:301_1_install'], array_keys($storage->getTermDocuments('composer', 1)));

        $elements = $storage->getElementsByDocumentKeys(1, ['301_1_intro', '301_1_install', '301_1_configure']);
        self::assertSame('Install Guide', $elements['301_1_intro']['documentData']['sectionTitle'] ?? null);
        self::assertSame('Install', $elements['301_1_install']['documentData']['sectionTitle'] ?? null);
        self::assertSame('Configure', $elements['301_1_configure']['documentData']['sectionTitle'] ?? null);
    }

    public function testDeleteByParentRemovesAllSplitSectionDocuments(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'file-storage-regression', [
            'disableStopWords' => true,
        ]);

        $this->indexSection($engine, $storage, '301_1_intro', 'Install Guide', 'overview landing', ['sectionType' => 'intro']);
        $this->indexSection($engine, $storage, '301_1_install', 'Install Guide', 'composer install package', ['sectionType' => 'heading']);
        $this->indexSection($engine, $storage, '301_1_configure', 'Install Guide', 'configure settings', ['sectionType' => 'heading']);

        self::assertTrue($engine->deleteDocument(1, 301));

        self::assertSame([], $storage->getDocumentKeysByParent(1, 301));
        self::assertSame([], $engine->search('composer', 1, 0, ['returnDocumentKeys' => true]));
        self::assertSame([], $storage->getTermDocuments('composer', 1));
        self::assertSame([], $storage->getElementsByIds(1, [301]));
    }

    public function testDocumentKeyKeepSetCleanupRemovesOnlyOrphanedSections(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'file-storage-regression', [
            'disableStopWords' => true,
        ]);

        $this->indexSection($engine, $storage, '301_1_intro', 'Install Guide', 'overview landing', ['sectionType' => 'intro']);
        $this->indexSection($engine, $storage, '301_1_install', 'Install Guide', 'composer install package', ['sectionType' => 'heading']);
        $this->indexSection($engine, $storage, '301_1_configure', 'Install Guide', 'configure settings', ['sectionType' => 'heading']);

        $keep = array_flip(['301_1_intro', '301_1_configure']);
        foreach ($storage->getDocumentKeysByParent(1, 301) as $documentKey) {
            if (!isset($keep[$documentKey])) {
                self::assertTrue($engine->deleteDocumentByKey(1, 301, $documentKey));
            }
        }

        self::assertSame(['301_1_intro', '301_1_configure'], $storage->getDocumentKeysByParent(1, 301));
        self::assertSame([], $engine->search('composer', 1, 0, ['returnDocumentKeys' => true]));
        self::assertSame(['301_1_configure'], array_keys($engine->search('configure', 1, 0, ['returnDocumentKeys' => true])));
    }

    public function testLegacyPageModeDocumentKeyReadsRemainCompatible(): void
    {
        $storage = $this->makeStorage();
        $engine = new SearchEngine($storage, 'file-storage-regression', [
            'disableStopWords' => true,
        ]);

        self::assertTrue($engine->indexDocument(1, 401, 'Legacy Page', 'legacy body', 'en'));
        $storage->storeElement(1, 401, 'Legacy Page', 'entry', json_encode([
            'title' => 'Legacy Page',
            'url' => '/legacy',
        ], JSON_THROW_ON_ERROR));

        self::assertSame($storage->getDocumentTerms(1, 401), $storage->getDocumentTermsByKey(1, '401_1'));
        self::assertSame(['401_1'], $storage->getDocumentKeysByParent(1, 401));
        self::assertSame([401], array_keys($engine->search('legacy', 1, 0, ['returnDocumentKeys' => true])));
        self::assertSame('Legacy Page', $storage->getElementsByDocumentKeys(1, ['401_1'])['401_1']['title'] ?? null);
    }

    public function testNonDocumentKeyStorageFailsLoudlyForSplitDocumentKey(): void
    {
        $storage = new RecordingStorage([], [], [], 0, 1.0);
        $engine = new SearchEngine($storage, 'recording-storage', [
            'disableStopWords' => true,
        ]);

        $result = $engine->indexDocumentWithKeyResult(1, 301, '301_1_install', 'Install Guide', 'composer install package', 'en');

        self::assertFalse($result['success']);
        self::assertNull($result['wasCreated']);
        self::assertSame([], $storage->getDocumentTerms(1, 301));
    }

    /**
     * @param array<string, mixed> $documentData
     */
    private function indexSection(SearchEngine $engine, FileStorage $storage, string $documentKey, string $title, string $content, array $documentData): void
    {
        self::assertTrue($engine->indexDocumentWithKeyResult(1, 301, $documentKey, $title, $content, 'en')['success']);

        $storage->storeElementByKey(1, 301, $documentKey, $title, 'source-doc', json_encode(array_merge([
            'title' => $title,
            'url' => '/docs/install',
            'elementId' => 301,
            'siteId' => 1,
            'backendId' => $documentKey,
            'content' => $content,
        ], $documentData), JSON_THROW_ON_ERROR));
    }

    private function makeStorage(): FileStorage
    {
        $this->basePath = Craft::getAlias('@storage/search-manager-test-' . StringHelper::UUID());

        return new FileStorage('file-storage-regression', $this->basePath);
    }

    private function indexPath(): string
    {
        self::assertIsString($this->basePath);

        return $this->basePath . '/file-storage-regression';
    }

    /**
     * @param callable(): void $competingWrite
     * @param array<int, string|null> $workerArguments
     */
    private function runMutationWhileFileLockIsHeld(string $lockedPath, callable $competingWrite, array $workerArguments): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for FileStorage lock interleaving regression coverage.');
        }

        $readyPath = $this->indexPath() . '/lock-ready-' . StringHelper::UUID() . '.tmp';
        $releasePath = $this->indexPath() . '/lock-release-' . StringHelper::UUID() . '.tmp';
        $lockScript = $this->writePhpScript('lock-holder', <<<'PHP'
<?php
[$script, $lockedPath, $readyPath, $releasePath] = $argv;
$handle = fopen($lockedPath, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    exit(1);
}

touch($readyPath);
$started = microtime(true);
while (!file_exists($releasePath)) {
    if (microtime(true) - $started > 5.0) {
        flock($handle, LOCK_UN);
        fclose($handle);
        exit(1);
    }

    usleep(10000);
}

flock($handle, LOCK_UN);
fclose($handle);
exit(0);
PHP);
        $workerScript = $this->writePhpScript('file-storage-worker', <<<'PHP'
<?php
declare(strict_types=1);

use lindemannrock\searchmanager\search\storage\FileStorage;

require $argv[1];

$operation = $argv[2];
$basePath = $argv[3];
$storage = new FileStorage('file-storage-regression', $basePath);

if ($operation === 'store-term-document') {
    $storage->storeTermDocument($argv[4], (int)$argv[5], (int)$argv[6], (int)$argv[7]);
    exit(0);
}

if ($operation === 'remove-term-document') {
    $storage->removeTermDocument($argv[4], (int)$argv[5], (int)$argv[6]);
    exit(0);
}

if ($operation === 'remove-term-document-by-key') {
    $storage->removeTermDocumentByKey($argv[4], (int)$argv[5], $argv[6]);
    exit(0);
}

if ($operation === 'delete-document-by-key') {
    $storage->deleteDocumentByKey((int)$argv[4], $argv[5]);
    exit(0);
}

if ($operation === 'store-term-ngrams') {
    $ngrams = json_decode($argv[6], true, 512, JSON_THROW_ON_ERROR);
    $storage->storeTermNgrams($argv[4], is_array($ngrams) ? $ngrams : [], (int)$argv[5]);
    exit(0);
}

exit(1);
PHP);

        [$lockProcess, $lockPipes] = $this->startPhpScript($lockScript, [$lockedPath, $readyPath, $releasePath]);
        $this->waitForFile($readyPath, 'FileStorage lock holder did not acquire the lock.');

        [$workerProcess, $workerPipes] = $this->startPhpScript(
            $workerScript,
            array_map(static fn(?string $argument): string => (string)$argument, array_merge([
                dirname(__DIR__) . '/bootstrap.php',
            ], $workerArguments)),
        );

        usleep(200000);
        $competingWrite();
        touch($releasePath);

        $this->finishPhpProcess($lockProcess, $lockPipes, 'FileStorage lock holder failed.');
        $this->finishPhpProcess($workerProcess, $workerPipes, 'FileStorage worker failed.');

        @unlink($readyPath);
        @unlink($releasePath);
        @unlink($lockScript);
        @unlink($workerScript);
    }

    private function writePhpScript(string $name, string $source): string
    {
        $path = $this->indexPath() . '/' . $name . '-' . StringHelper::UUID() . '.php';
        $this->writeFile($path, $source);

        return $path;
    }

    private function waitForFile(string $path, string $message): void
    {
        $started = microtime(true);
        while (!file_exists($path)) {
            if (microtime(true) - $started > 5.0) {
                self::fail($message);
            }

            usleep(10000);
        }
    }

    /**
     * @param array<int, string> $arguments
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startPhpScript(string $scriptPath, array $arguments): array
    {
        $command = array_merge([PHP_BINARY, $scriptPath], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        self::assertIsResource($process, 'Unable to start FileStorage worker process.');

        return [$process, $pipes];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function finishPhpProcess($process, array $pipes, string $message): void
    {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), trim($message . ' ' . $output . ' ' . $error));
    }

    /**
     * @param mixed $data
     */
    private function writeJsonFile(string $path, $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);
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

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
