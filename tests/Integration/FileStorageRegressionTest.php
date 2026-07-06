<?php
/**
 * LindemannRock Search Manager
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

    public function testNgramScanDoesNotStripNumericSuffixFromEncodedFilenameSegment(): void
    {
        $storage = $this->makeStorage();
        $encodedTerm = '__utf8_sha256_fixture_3';
        $originalTerm = '東京';
        $indexPath = $this->basePath . '/file-storage-regression';

        mkdir($indexPath . '/ngrams/site1', 0755, true);
        file_put_contents($indexPath . '/keys/' . $encodedTerm . '.dat', json_encode([
            'value' => $originalTerm,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents($indexPath . '/ngrams/site1/' . $encodedTerm . '.dat', json_encode([
            '東京',
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

    private function makeStorage(): FileStorage
    {
        $this->basePath = Craft::getAlias('@storage/search-manager-test-' . StringHelper::UUID());

        return new FileStorage('file-storage-regression', $this->basePath);
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
