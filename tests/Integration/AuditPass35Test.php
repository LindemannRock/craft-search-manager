<?php
/**
 * Search Manager plugin for Craft CMS 5.x
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
 * Pins audit Pass 35 fixes #193-#195.
 */
final class AuditPass35Test extends TestCase
{
    public function testSingleSiteTypeFilterRunsBeforePaginationAndTotal(): void
    {
        $termDocs = [];
        $titleByElement = [];
        $documentTermsById = [];
        $documentLengthsById = [];
        $docLengths = [];
        $elementsById = [];

        for ($i = 1; $i <= 6; $i++) {
            $docId = '1:' . $i;
            $termDocs['coffee'][$docId] = 1;
            $titleByElement[$i] = ['coffee'];
            $documentTermsById[$docId] = ['coffee' => 1];
            $documentLengthsById[$docId] = 1;
            $docLengths[$docId] = 1;
            $elementsById[$i] = [
                'elementType' => $i % 2 === 0 ? 'asset' : 'entry',
                'documentData' => [
                    'title' => 'Coffee ' . $i,
                ],
            ];
        }

        $storage = new RecordingStorage(
            $termDocs,
            $titleByElement,
            $docLengths,
            6,
            1.0,
            documentTermsById: $documentTermsById,
            documentLengthsById: $documentLengthsById,
            elementsById: $elementsById,
        );
        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);
        $backend = new AuditPass35Backend($storage);

        $result = $backend->searchOneSite($engine, 'coffee', 'test-index', 1, 2, 0, 'asset');

        self::assertSame(3, $result['total']);
        self::assertCount(2, $result['hits']);
        self::assertSame(['asset', 'asset'], array_column($result['hits'], 'type'));
        self::assertSame([2, 4], array_column($result['hits'], 'elementId'));
    }

    public function testSingleSiteWithoutTypeFilterOnlyLoadsPageMetadata(): void
    {
        $termDocs = [];
        $titleByElement = [];
        $documentTermsById = [];
        $documentLengthsById = [];
        $docLengths = [];
        $elementsById = [];

        for ($i = 1; $i <= 6; $i++) {
            $docId = '1:' . $i;
            $termDocs['coffee'][$docId] = 1;
            $titleByElement[$i] = ['coffee'];
            $documentTermsById[$docId] = ['coffee' => 1];
            $documentLengthsById[$docId] = 1;
            $docLengths[$docId] = 1;
            $elementsById[$i] = [
                'elementType' => 'entry',
                'documentData' => [
                    'title' => 'Coffee ' . $i,
                ],
            ];
        }

        $storage = new RecordingStorage(
            $termDocs,
            $titleByElement,
            $docLengths,
            6,
            1.0,
            documentTermsById: $documentTermsById,
            documentLengthsById: $documentLengthsById,
            elementsById: $elementsById,
        );
        $engine = new SearchEngine($storage, 'test-index', ['enableStopWords' => false]);
        $backend = new AuditPass35Backend($storage);

        $result = $backend->searchOneSite($engine, 'coffee', 'test-index', 1, 2, 1, null);

        self::assertSame(6, $result['total']);
        self::assertCount(2, $result['hits']);
        self::assertSame([2, 3], array_column($result['hits'], 'elementId'));
        self::assertSame(1, $storage->getElementsByIdsCalls);
        self::assertSame([2], $storage->getElementsByIdsBatchSizes);
    }

    public function testSearchControllerUsesSharedTrackingSourceNormalizer(): void
    {
        $source = $this->readPluginFile('src/controllers/SearchController.php');

        self::assertStringContainsString('use lindemannrock\searchmanager\helpers\TrackingMetadataHelper;', $source);
        self::assertStringContainsString("\$source = TrackingMetadataHelper::source(\$source) ?? 'frontend-widget';", $source);
        self::assertStringNotContainsString('substr($source, 0, 64)', $source);
    }

    public function testDiagnosticsUnknownFallbackIsTranslatedForInlineJs(): void
    {
        $source = $this->readPluginFile('src/templates/backends/_partials/diagnostics.twig');

        self::assertStringContainsString("const unknownLabel = {{ 'Unknown'|t('search-manager')|json_encode|raw }};", $source);
        self::assertStringContainsString('const name = index.name || index.uid || unknownLabel;', $source);
        self::assertStringNotContainsString("index.name || index.uid || 'Unknown'", $source);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        $this->assertIsString($source);

        return $source;
    }
}

final class AuditPass35Backend extends AbstractSearchEngineBackend
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
    }

    /**
     * @return array{hits: array<int, array<string, mixed>>, total: int}
     */
    public function searchOneSite(SearchEngine $engine, string $query, string $indexName, int $siteId, int $limit, int $offset, ?string $type): array
    {
        return $this->searchSingleSite($engine, $this->storage, $indexName, $query, $siteId, $limit, $offset, $type, []);
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
