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
final class AnalyticsSqlContractTest extends TestCase
{
    public function testOptionalAnalyticsColumnRejectsUnsupportedColumnsBeforeSqlInterpolation(): void
    {
        $service = new AnalyticsSqlColumnProbe();

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
}

/**
 * @since 5.53.0
 */
final class AnalyticsSqlColumnProbe
{
    use AnalyticsQueryTrait {
        optionalAnalyticsColumn as public exposeOptionalAnalyticsColumn;
    }
}

/**
 * @since 5.53.0
 */
final class AnalyticsSqlSynonymBackend implements BackendInterface
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
