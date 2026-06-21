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
