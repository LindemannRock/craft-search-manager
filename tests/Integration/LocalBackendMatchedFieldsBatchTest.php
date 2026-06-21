<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\backends\AbstractSearchEngineBackend;
use lindemannrock\searchmanager\search\SearchEngine;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for local-backend matched-field term preloading.
 */
final class LocalBackendMatchedFieldsBatchTest extends TestCase
{
    public function testMatchedFieldsPreloadsTitleAndDocumentTermsInBatches(): void
    {
        $hits = [];
        $termDocs = [];
        $titleByElement = [];
        $documentTermsById = [];
        $documentLengthsById = [];
        $docLengths = [];

        for ($i = 1; $i <= 50; $i++) {
            $docId = '1:' . $i;
            $hits[] = [
                'elementId' => $i,
                'siteId' => 1,
                'score' => 100 - $i,
            ];
            $termDocs['coffee'][$docId] = 1;
            $titleByElement[$i] = $i === 1 ? ['coffee'] : ['other'];
            $documentTermsById[$docId] = ['coffee' => 1, 'body' => 1];
            $documentLengthsById[$docId] = 2;
            $docLengths[$docId] = 2;
        }

        $storage = new RecordingStorage(
            $termDocs,
            $titleByElement,
            $docLengths,
            50,
            2.0,
            documentTermsById: $documentTermsById,
            documentLengthsById: $documentLengthsById,
        );
        $backend = new LocalBackendMatchedFieldsTestBackend($storage);

        $decorated = $backend->decorate($hits, 'coffee', 'test-index', 1, 50);

        self::assertSame(0, $storage->getTitleTermsCalls);
        self::assertSame(1, $storage->getTitleTermsBatchCalls);
        self::assertSame([50], $storage->getTitleTermsBatchSizes);
        self::assertSame(0, $storage->getDocumentTermsCalls);
        self::assertSame(1, $storage->getDocumentTermsBatchCalls);
        self::assertSame([50], $storage->getDocumentTermsBatchSizes);
        self::assertSame(0, $storage->getTermDocumentsCalls);
        self::assertSame(1, $storage->getTermDocumentsBatchCalls);
        self::assertSame([1], $storage->getTermDocumentsBatchSizes);
        self::assertSame(['title'], $decorated[0]['matchedIn']);
        self::assertSame(['coffee'], $decorated[0]['matchedTerms']['title']);
        self::assertSame(['content'], $decorated[1]['matchedIn']);
        self::assertSame(['coffee'], $decorated[1]['matchedTerms']['content']);
    }

    public function testAllSitesSearchBatchesElementLookupsBySite(): void
    {
        $siteIds = \Craft::$app->getSites()->getAllSiteIds();
        self::assertNotEmpty($siteIds);

        $termDocs = [];
        $titleByElement = [];
        $documentTermsById = [];
        $documentLengthsById = [];
        $docLengths = [];
        $elementsById = [];
        $expectedBatchSizes = [];

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;
            $expectedBatchSizes[] = 3;
            for ($i = 1; $i <= 3; $i++) {
                $elementId = ($siteId * 1000) + $i;
                $docId = $siteId . ':' . $elementId;
                $termDocs['coffee'][$docId] = 1;
                $titleByElement[$elementId] = ['coffee'];
                $documentTermsById[$docId] = ['coffee' => 1];
                $documentLengthsById[$docId] = 1;
                $docLengths[$docId] = 1;
                $elementsById[$elementId] = [
                    'elementType' => 'entry',
                    'documentData' => [
                        'title' => 'Coffee ' . $elementId,
                        'url' => 'https://example.test/' . $siteId . '/' . $elementId,
                    ],
                ];
            }
        }

        $storage = new RecordingStorage(
            $termDocs,
            $titleByElement,
            $docLengths,
            max(1, count($docLengths)),
            1.0,
            documentTermsById: $documentTermsById,
            documentLengthsById: $documentLengthsById,
            elementsById: $elementsById,
        );
        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);
        $backend = new LocalBackendMatchedFieldsTestBackend($storage);

        $result = $backend->searchEverySite($engine, 'coffee', 'test-index', 2, 1);

        self::assertSame(count($siteIds), $storage->getElementsByIdsCalls);
        self::assertSame($expectedBatchSizes, $storage->getElementsByIdsBatchSizes);
        self::assertSame(count($siteIds) * 3, $result['total']);
        self::assertCount(2, $result['hits']);
        self::assertSame(['Coffee ' . ($siteIds[0] * 1000 + 2), 'Coffee ' . ($siteIds[0] * 1000 + 3)], array_column($result['hits'], 'title'));
        self::assertSame([$siteIds[0], $siteIds[0]], array_column($result['hits'], 'siteId'));
        self::assertSame(['title'], $result['hits'][0]['matchedIn']);
    }
}

final class LocalBackendMatchedFieldsTestBackend extends AbstractSearchEngineBackend
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     * @return array<int, array<string, mixed>>
     */
    public function decorate(array $hits, string $query, string $indexName, int $siteId, int $maxHits): array
    {
        return $this->addMatchedFieldsToHits($hits, $query, $indexName, $siteId, $this->storage, $maxHits);
    }

    /**
     * @return array{hits: array<int, array<string, mixed>>, total: int}
     */
    public function searchEverySite(SearchEngine $engine, string $query, string $indexName, int $limit, int $offset): array
    {
        return $this->searchAllSites($engine, $this->storage, $indexName, $query, $limit, $offset, null, []);
    }

    protected function createStorage(string $fullIndexName): StorageInterface
    {
        return $this->storage;
    }

    protected function getBackendLabel(): string
    {
        return 'Test';
    }

    public function getName(): string
    {
        return 'test';
    }

    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
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
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }
}
