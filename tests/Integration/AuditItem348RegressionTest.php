<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Guards audit item #348.
 *
 * @since 5.53.0
 */
final class AuditItem348RegressionTest extends TestCase
{
    public function testDeleteOrphanDocumentsUsesElementScopedBrowseAndKeepsBackendIdSemantics(): void
    {
        $backend = new AuditItem348Backend([
            ['backendId' => 'other-before-cap', 'elementId' => 10, 'siteId' => 1],
            ['backendId' => 'other-second-before-cap', 'elementId' => 11, 'siteId' => 1],
            ['backendId' => 'target-keep', 'elementId' => 42, 'siteId' => 1],
            ['backendId' => 'target-orphan', 'elementId' => 42, 'siteId' => 1],
            ['backendId' => 'other-site', 'elementId' => 42, 'siteId' => 2],
        ]);

        self::assertTrue($backend->deleteOrphanDocuments('docs', 42, 1, ['target-keep']));

        self::assertSame([
            ['indexName' => 'docs', 'elementId' => 42, 'siteId' => 1],
        ], $backend->elementScopedBrowseCalls);
        self::assertSame([], $backend->unfilteredBrowseCalls);
        self::assertSame([
            ['indexName' => 'docs', 'backendId' => 'target-orphan'],
        ], $backend->deletedBackendIds);
    }

    public function testExternalBackendsPassElementAndSiteFiltersToBrowse(): void
    {
        $base = $this->readPluginSource('src/backends/BaseBackend.php');
        $baseBody = $this->methodBody($base, 'deleteOrphanDocuments');

        self::assertStringContainsString('$this->browseDocumentsForElement($indexName, $elementId, $siteId)', $baseBody);
        self::assertStringNotContainsString('$this->browse($indexName)', $baseBody);

        $algoliaBody = $this->methodBody($this->readPluginSource('src/backends/AlgoliaBackend.php'), 'browseDocumentsForElement', 'protected');
        self::assertStringContainsString('$filters = $this->elementIdFilter([$elementId]);', $algoliaBody);
        self::assertStringContainsString('$filters = self::siteIdFilter($siteId, $filters);', $algoliaBody);
        self::assertStringContainsString("return \$this->browse(\$indexName, '', ['filters' => \$filters]);", $algoliaBody);

        $meilisearchBody = $this->methodBody($this->readPluginSource('src/backends/MeilisearchBackend.php'), 'browseDocumentsForElement', 'protected');
        self::assertStringContainsString('$filter = self::siteIdFilter($siteId, $this->elementIdFilter([$elementId]));', $meilisearchBody);
        self::assertStringContainsString("return \$this->browse(\$indexName, '', ['filter' => \$filter]);", $meilisearchBody);

        $typesenseBody = $this->methodBody($this->readPluginSource('src/backends/TypesenseBackend.php'), 'browseDocumentsForElement', 'protected');
        self::assertStringContainsString('$filter = self::siteIdFilter($siteId, $this->elementIdFilter([$elementId]));', $typesenseBody);
        self::assertStringContainsString("return \$this->browse(\$indexName, '', ['filter_by' => \$filter]);", $typesenseBody);
    }

    public function testMeilisearchBrowseAppliesDocumentFilterAndPaginatesUntilExhausted(): void
    {
        $body = $this->methodBody($this->readPluginSource('src/backends/MeilisearchBackend.php'), 'browse');

        self::assertStringContainsString("\$filter = \$parameters['filter'] ?? null;", $body);
        self::assertStringContainsString('$query->setFilter([$filter]);', $body);
        self::assertStringContainsString('$offset += $limit;', $body);
        self::assertStringContainsString('} while ($resultCount === $limit);', $body);
    }

    private function readPluginSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function methodBody(string $source, string $method, string $visibility = 'public'): string
    {
        preg_match(
            '/' . preg_quote($visibility, '/') . ' function ' . preg_quote($method, '/') . '\(.*?^    \}/ms',
            $source,
            $matches,
        );

        self::assertNotEmpty($matches, sprintf('Could not find %s::%s body.', $visibility, $method));

        return $matches[0];
    }
}

final class AuditItem348Backend extends BaseBackend
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $documents;

    /**
     * @var list<array{indexName: string, elementId: int, siteId: int|null}>
     */
    public array $elementScopedBrowseCalls = [];

    /**
     * @var list<array{indexName: string, query: string, parameters: array<string, mixed>}>
     */
    public array $unfilteredBrowseCalls = [];

    /**
     * @var list<array{indexName: string, backendId: string}>
     */
    public array $deletedBackendIds = [];

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function __construct(array $documents)
    {
        parent::__construct();
        $this->documents = $documents;
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

    public function search(string $indexName, string $query, array $options = []): array
    {
        return ['hits' => [], 'total' => 0];
    }

    public function getDocumentsByElementIds(string $indexName, array $elementIds, ?int $siteId = null): array
    {
        return [];
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

    public function getName(): string
    {
        return 'audit-item-348';
    }

    public function browse(string $indexName, string $query = '', array $parameters = []): iterable
    {
        $this->unfilteredBrowseCalls[] = [
            'indexName' => $indexName,
            'query' => $query,
            'parameters' => $parameters,
        ];

        return array_slice($this->documents, 0, 2);
    }

    protected function browseDocumentsForElement(string $indexName, int $elementId, ?int $siteId): iterable
    {
        $this->elementScopedBrowseCalls[] = [
            'indexName' => $indexName,
            'elementId' => $elementId,
            'siteId' => $siteId,
        ];

        return array_values(array_filter(
            $this->documents,
            static fn(array $document): bool => (int)($document['elementId'] ?? 0) === $elementId
                && ($siteId === null || (int)($document['siteId'] ?? 0) === $siteId),
        ));
    }

    protected function deleteByBackendId(string $indexName, string $backendId): bool
    {
        $this->deletedBackendIds[] = [
            'indexName' => $indexName,
            'backendId' => $backendId,
        ];

        return true;
    }
}
