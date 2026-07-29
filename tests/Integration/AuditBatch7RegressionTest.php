<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\services\analytics\AnalyticsQueryTrait;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit Batch 7.
 *
 * @since 5.53.0
 */
final class AuditBatch7RegressionTest extends TestCase
{
    public function testSynonymSearchDeduplicatesWithStableIndexMapAndKeepsHighestScore(): void
    {
        $service = new BackendService();
        $backend = new Batch7SynonymBackend([
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'title' => 'Original title', 'score' => 2.0],
                    ['elementId' => 20, 'title' => 'Second title', 'score' => 5.0],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 10, 'title' => 'Duplicate title', 'score' => 9.0],
                    ['elementId' => 30, 'title' => 'Third title', 'score' => 1.0],
                ],
            ],
        ]);

        $method = new \ReflectionMethod(BackendService::class, '_searchWithSynonyms');
        $method->setAccessible(true);

        $results = $method->invoke($service, $backend, 'docs', ['original', 'synonym'], ['limit' => 50]);
        self::assertIsArray($results);

        self::assertSame(3, $results['total']);
        self::assertSame([10, 20, 30], array_column($results['hits'], 'elementId'));
        self::assertSame('Original title', $results['hits'][0]['title']);
        self::assertSame(9.0, $results['hits'][0]['score']);
    }

    public function testSynonymAggregateOwnsBoundedPaginationAcrossBackendStyles(): void
    {
        $responses = [
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'title' => 'Original title', 'score' => 10.0],
                    ['elementId' => 10, 'siteId' => 2, 'title' => 'Other site', 'score' => 9.5],
                    ['elementId' => 20, 'siteId' => 1, 'title' => 'Second title', 'score' => 8.0],
                    ['elementId' => 30, 'siteId' => 1, 'title' => 'Third title', 'score' => 6.0],
                ],
                'searchDebug' => [
                    'relaxedMatching' => false,
                    'resolvedTerms' => ['original' => [['term' => 'original']]],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'title' => 'Duplicate title', 'score' => 11.0],
                    ['elementId' => 40, 'siteId' => 1, 'title' => 'Fourth title', 'score' => 9.0],
                    ['elementId' => 50, 'siteId' => 1, 'title' => 'Fifth title', 'score' => 7.0],
                    ['elementId' => 60, 'siteId' => 1, 'title' => 'Sixth title', 'score' => 5.0],
                ],
                'searchDebug' => [
                    'relaxedMatching' => true,
                    'resolvedTerms' => ['synonym' => [['term' => 'synonym']]],
                ],
            ],
        ];

        foreach ([
            'local' => 'offset',
            'meilisearch' => 'offset',
            'algolia' => 'page',
            'typesense' => 'page',
        ] as $backendStyle => $paginationMode) {
            $backend = new Batch7SynonymBackend($responses, $paginationMode);
            $results = $this->invokeSynonymSearch($backend, [
                'limit' => 2,
                'offset' => 2,
                'page' => 1,
            ]);

            self::assertSame([40, 20], array_column($results['hits'], 'elementId'), $backendStyle);
            self::assertSame(7, $results['total'], $backendStyle);
            self::assertTrue($results['searchDebug']['relaxedMatching'], $backendStyle);
            self::assertSame(
                ['original', 'synonym'],
                array_keys($results['searchDebug']['resolvedTerms']),
                $backendStyle,
            );

            self::assertCount(2, $backend->searchCalls, $backendStyle);
            foreach ($backend->searchCalls as $call) {
                self::assertSame(4, $call['options']['limit'], $backendStyle);
                self::assertSame(0, $call['options']['offset'], $backendStyle);
                self::assertSame(0, $call['options']['page'], $backendStyle);
            }
        }
    }

    public function testSynonymAggregatePreservesUnboundedChildrenAndAppliesOffsetOnce(): void
    {
        $backend = new Batch7SynonymBackend([
            'original' => [
                'hits' => [
                    ['elementId' => 10, 'siteId' => 1, 'score' => 10.0],
                    ['elementId' => 20, 'siteId' => 1, 'score' => 8.0],
                ],
            ],
            'synonym' => [
                'hits' => [
                    ['elementId' => 30, 'siteId' => 1, 'score' => 9.0],
                    ['elementId' => 40, 'siteId' => 1, 'score' => 7.0],
                ],
            ],
        ], 'offset');

        $results = $this->invokeSynonymSearch($backend, [
            'limit' => 0,
            'offset' => 2,
            'page' => 3,
        ]);

        self::assertSame([20, 40], array_column($results['hits'], 'elementId'));
        self::assertSame(4, $results['total']);
        foreach ($backend->searchCalls as $call) {
            self::assertSame(0, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    public function testSynonymAggregatePageZeroUsesExactRequestedHeadroom(): void
    {
        $backend = new Batch7SynonymBackend([
            'original' => ['hits' => [['elementId' => 10, 'siteId' => 1, 'score' => 10.0]]],
            'synonym' => ['hits' => [['elementId' => 20, 'siteId' => 1, 'score' => 9.0]]],
        ], 'page');

        $results = $this->invokeSynonymSearch($backend, [
            'limit' => 2,
            'offset' => 0,
            'page' => 0,
        ]);

        self::assertSame([10, 20], array_column($results['hits'], 'elementId'));
        foreach ($backend->searchCalls as $call) {
            self::assertSame(2, $call['options']['limit']);
            self::assertSame(0, $call['options']['offset']);
            self::assertSame(0, $call['options']['page']);
        }
    }

    public function testSearchWithParsedQueryDoesNotAccumulateUnusedDocIds(): void
    {
        $body = $this->methodBody($this->readPluginFile('src/search/SearchEngine.php'), 'searchWithParsedQuery', 'public');

        self::assertStringNotContainsString('$allDocIds', $body);
        self::assertStringNotContainsString('array_unique(array_merge', $body);
    }

    public function testRawConfigDisplayFormatsActualClassAndGuardsMissingClasses(): void
    {
        $method = new \ReflectionMethod(SearchIndex::class, 'formatClassConfigValue');
        $method->setAccessible(true);

        self::assertSame('\\craft\\elements\\Entry::class', $method->invoke(null, \craft\elements\Entry::class));
        self::assertSame(
            '\'lindemannrock\\\\missing\\\\Element\'',
            $method->invoke(null, 'lindemannrock\\missing\\Element'),
        );
    }

    public function testRawConfigDisplayEscapesQuotedStringValuesThroughSharedFormatter(): void
    {
        $originalConfigCache = $this->configCache();

        try {
            $name = "Editor's \\ Picks";
            $transformer = "Vendor\\Editor'sTransformer";
            $language = "en'custom";
            $this->withConfigFileIndices([
                'quoted-config-index' => [
                    'name' => $name,
                    'elementType' => \craft\elements\Entry::class,
                    'transformer' => $transformer,
                    'language' => $language,
                    'enabled' => true,
                ],
            ]);

            $index = new SearchIndex();
            $index->handle = 'quoted-config-index';
            $index->source = 'config';
            $display = $index->getRawConfigDisplay();

            self::assertNotNull($display);
            self::assertStringContainsString("'name' => " . var_export($name, true), $display);
            self::assertStringContainsString("'transformer' => " . var_export($transformer, true), $display);
            self::assertStringContainsString("'language' => " . var_export($language, true), $display);
        } finally {
            $this->setConfigCache($originalConfigCache);
            SearchIndex::clearCache();
        }
    }

    public function testSimilarityThresholdInstallAndTemplateDefaultsUseCanonicalRuntimeValue(): void
    {
        $install = $this->readPluginFile('src/migrations/Install.php');
        $template = $this->readPluginFile('src/templates/settings/search.twig');
        $termResolver = $this->readPluginFile('src/search/TermResolver.php');

        self::assertStringContainsString("'similarityThreshold' => \$this->decimal(3, 2)->notNull()->defaultValue(0.25)", $install);
        self::assertStringContainsString("'similarityThreshold' => 0.25", $install);
        self::assertStringContainsString('value: settings.similarityThreshold ?? 0.25', $template);
        self::assertStringContainsString('Default: 0.25 (typo-tolerant). Lower = more typo tolerance but more false positives; higher = stricter matching.', $template);
        self::assertStringNotContainsString('Default: 0.50 (balanced)', $template);
        self::assertStringContainsString('Base fuzzy threshold (default 0.25)', $termResolver);
        self::assertStringContainsString("\$config['similarityThreshold'] ?? 0.25", $termResolver);
        self::assertStringNotContainsString("\$config['similarityThreshold'] ?? 0.50", $termResolver);
    }

    public function testOptionalAnalyticsColumnRejectsUnsupportedColumnsBeforeSqlInterpolation(): void
    {
        $service = new Batch7AnalyticsColumnProbe();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported optional analytics column: dateCreated');

        $service->exposeOptionalAnalyticsColumn('dateCreated');
    }

    public function testOptionalAnalyticsColumnAllowlistContainsOnlyCurrentOptionalCallers(): void
    {
        $source = $this->readPluginFile('src/services/analytics/AnalyticsQueryTrait.php');

        foreach (['trafficType', 'isSystemAgent', 'botCategory', 'botProducerName'] as $column) {
            self::assertStringContainsString("'{$column}' => true", $source);
        }
        // [[...]]-bracketed so the alias keeps its case on PostgreSQL (an
        // unquoted alias folds to lowercase, breaking $row['botCategory'] reads).
        self::assertStringContainsString('new Expression("NULL AS [[$column]]")', $source);
    }

    public function testAnalyticsGeoConfigDelegatesToSharedHelper(): void
    {
        $analyticsService = $this->methodBody($this->readPluginFile('src/services/AnalyticsService.php'), 'getGeoConfig', 'protected');
        $breakdownService = $this->methodBody($this->readPluginFile('src/services/analytics/AnalyticsBreakdownService.php'), 'getGeoConfig', 'protected');

        self::assertStringContainsString('return AnalyticsGeoConfigHelper::config();', $analyticsService);
        self::assertStringContainsString('return AnalyticsGeoConfigHelper::config();', $breakdownService);
    }

    public function testAutocompleteStorageFailureLoggingOmitsTraceStrings(): void
    {
        // Phase C (#383/#384) replaced getAllTerms with the shared-core
        // buildTokenSuggestions; the batch-7 invariant (graceful degrade, no
        // trace strings in the failure log) carries over to it.
        $body = $this->methodBody($this->readPluginFile('src/services/AutocompleteService.php'), 'buildTokenSuggestions');

        self::assertStringContainsString("'exception' => get_class(\$e)", $body);
        self::assertStringNotContainsString('getTraceAsString()', $body);
        self::assertStringNotContainsString("'trace'", $body);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'private'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        $body = $matches[0] ?? '';
        self::assertNotSame('', $body, $method . ' source should be captured.');

        return $body;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function invokeSynonymSearch(Batch7SynonymBackend $backend, array $options): array
    {
        $method = new \ReflectionMethod(BackendService::class, '_searchWithSynonyms');
        $method->setAccessible(true);
        $results = $method->invoke(new BackendService(), $backend, 'docs', ['original', 'synonym'], $options);
        self::assertIsArray($results);

        return $results;
    }
}

/**
 * @since 5.53.0
 */
final class Batch7AnalyticsColumnProbe
{
    use AnalyticsQueryTrait {
        optionalAnalyticsColumn as public exposeOptionalAnalyticsColumn;
    }
}

/**
 * @since 5.53.0
 */
final class Batch7SynonymBackend implements BackendInterface
{
    /** @var list<array{indexName: string, query: string, options: array<string, mixed>}> */
    public array $searchCalls = [];

    /**
     * @param array<string, array<string, mixed>> $responsesByQuery
     */
    public function __construct(
        private array $responsesByQuery,
        private readonly string $paginationMode = 'none',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function index(string $indexName, array $data): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{success: bool, wasCreated: bool|null}
     */
    public function indexWithResult(string $indexName, array $data): array
    {
        return ['success' => true, 'wasCreated' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function batchIndex(string $indexName, array $items): bool
    {
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
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

    /**
     * @return array{success: bool, existed: bool|null}
     */
    public function deleteWithResult(string $indexName, int $elementId, ?int $siteId = null): array
    {
        return ['success' => true, 'existed' => true];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function search(string $indexName, string $query, array $options = []): array
    {
        $this->searchCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'options' => $options,
        ];

        $response = $this->responsesByQuery[$query] ?? ['hits' => []];
        $hits = is_array($response['hits'] ?? null) ? $response['hits'] : [];
        $response['total'] = $response['total'] ?? count($hits);

        $limit = (int)($options['limit'] ?? 0);
        $offset = (int)($options['offset'] ?? 0);
        if ($this->paginationMode === 'page' && $limit > 0) {
            $offset = (int)($options['page'] ?? 0) * $limit;
        }

        if ($limit > 0) {
            $response['hits'] = array_slice($hits, $offset, $limit);
        } elseif ($offset > 0) {
            $response['hits'] = array_slice($hits, $offset);
        }

        return $response;
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

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'batch7';
    }

    /**
     * @param array<string, mixed> $parameters
     * @return iterable<int, array<string, mixed>>
     */
    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $queries
     * @return array<string, mixed>
     */
    public function multipleQueries(array $queries = []): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $filters
     */
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listIndices(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setConfiguredSettings(array $settings): void
    {
    }

    public function setBackendHandle(string $handle): void
    {
    }
}
