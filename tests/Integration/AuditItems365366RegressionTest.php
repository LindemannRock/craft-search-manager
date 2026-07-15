<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit #365 and #366.
 *
 * @since 5.53.0
 */
final class AuditItems365366RegressionTest extends TestCase
{
    public function testQuoteDslParseFiltersEscapeBackslashesBeforeQuotes(): void
    {
        self::assertSame(
            '(category:"ends\\\\") AND (title:"a\\"b")',
            (new AlgoliaBackend())->parseFilters([
                'category' => 'ends\\',
                'title' => 'a"b',
            ]),
        );

        self::assertSame(
            'category = "ends\\\\" AND title = "a\\"b"',
            (new MeilisearchBackend())->parseFilters([
                'category' => 'ends\\',
                'title' => 'a"b',
            ]),
        );

        self::assertSame(
            'category = "ends\\\\" AND title = "a\\"b"',
            (new AuditItems365366Backend())->parseFilters([
                'category' => 'ends\\',
                'title' => 'a"b',
            ]),
        );
    }

    public function testTypesenseParseFiltersEscapesBackslashesBeforeBackticks(): void
    {
        self::assertSame(
            'category:=`ends\\\\` && title:=`a\\`b`',
            (new TypesenseBackend())->parseFilters([
                'category' => 'ends\\',
                'title' => 'a`b',
            ]),
        );
    }

    public function testSiteFiltersMergeValidExpressionsWithQuotedParentheses(): void
    {
        self::assertSame('(title:"ACME ) Demo") AND siteId:5', AlgoliaBackend::siteIdFilter(5, 'title:"ACME ) Demo"'));
        self::assertSame('(title = "ACME ) Demo") AND siteId = 5', MeilisearchBackend::siteIdFilter(5, 'title = "ACME ) Demo"'));
        self::assertSame('(title:=`ACME ) Demo`) && siteId:=5', TypesenseBackend::siteIdFilter(5, 'title:=`ACME ) Demo`'));
    }

    public function testSiteFiltersDeclineMalformedExistingExpressionsAndKeepSiteScope(): void
    {
        self::assertNull(AlgoliaBackend::siteIdFilter(null, 'type:doc)'));
        self::assertSame('siteId:5', AlgoliaBackend::siteIdFilter(5, 'type:doc) OR siteId:1'));
        self::assertSame('siteId:5', AlgoliaBackend::siteIdFilter(5, '(type:doc'));

        self::assertNull(MeilisearchBackend::siteIdFilter(null, 'type = doc)'));
        self::assertSame('siteId = 5', MeilisearchBackend::siteIdFilter(5, 'type = doc) OR siteId = 1'));
        self::assertSame('siteId = 5', MeilisearchBackend::siteIdFilter(5, '(type = doc'));

        self::assertNull(TypesenseBackend::siteIdFilter(null, 'type:=doc)'));
        self::assertSame('siteId:=5', TypesenseBackend::siteIdFilter(5, 'type:=doc) || siteId:=1'));
        self::assertSame('siteId:=5', TypesenseBackend::siteIdFilter(5, '(type:=doc'));
    }

    public function testBackendSourcesUseSharedFilterEscapingAndMergeHelpers(): void
    {
        foreach (['BaseBackend', 'AlgoliaBackend', 'MeilisearchBackend', 'TypesenseBackend'] as $class) {
            $source = $this->readPluginFile('src/backends/' . $class . '.php');
            self::assertStringNotContainsString("str_replace('\"', '\\\\\"'", $source, $class);
            self::assertStringNotContainsString("str_replace('`', '\\\\`'", $source, $class);
        }

        foreach (['AlgoliaBackend', 'MeilisearchBackend', 'TypesenseBackend'] as $class) {
            $source = $this->readPluginFile('src/backends/' . $class . '.php');
            self::assertStringContainsString('SearchFilterExpressionHelper::mergeWithRequiredFilter', $source, $class);
        }
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}

final class AuditItems365366Backend extends BaseBackend
{
    public function getName(): string
    {
        return 'audit-items-365-366';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return [];
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

    public function listIndices(): array
    {
        return [];
    }
}
