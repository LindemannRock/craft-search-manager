<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Algolia\AlgoliaSearch\Api\SearchClient;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;

/**
 * Hosted-provider pagination request coverage for PR1.74.
 *
 * @since 5.54.0
 */
final class HostedExactOffsetTest extends TestCase
{
    private const INDEX_HANDLE = 'docs';

    public function testAlgoliaForwardsMutuallyExclusivePageAndExactOffsetModes(): void
    {
        $requests = [];
        $client = $this->createMock(SearchClient::class);
        $client->expects(self::exactly(10))
            ->method('searchSingleIndex')
            ->willReturnCallback(static function(string $indexName, array $params) use (&$requests): array {
                $requests[] = $params;

                return ['hits' => [], 'nbHits' => 37];
            });

        $backend = new AlgoliaBackend();
        $this->setPrivateProperty($backend, AlgoliaBackend::class, '_searchClient', $client);

        foreach ($this->paginationCases() as $options) {
            self::assertSame(['hits' => [], 'total' => 37], $backend->search(self::INDEX_HANDLE, 'needle', $options));
        }

        self::assertSame([
            ['page' => 2],
            ['hitsPerPage' => 10, 'page' => 2],
            ['hitsPerPage' => 10, 'page' => 0],
            ['hitsPerPage' => 10, 'page' => 3],
            ['offset' => 15, 'length' => 10],
            ['offset' => 20, 'length' => 10],
            ['offset' => 0, 'length' => 10],
            ['hitsPerPage' => 10],
            [],
            [],
        ], array_map(
            static fn(array $request): array => array_intersect_key(
                $request,
                array_flip(['hitsPerPage', 'page', 'offset', 'length']),
            ),
            $requests,
        ));
    }

    public function testTypesenseForwardsMutuallyExclusivePageAndExactOffsetModes(): void
    {
        $requests = [];
        $documents = $this->createMock(Documents::class);
        $documents->expects(self::exactly(10))
            ->method('search')
            ->willReturnCallback(static function(array $params) use (&$requests): array {
                $requests[] = $params;

                return ['hits' => [], 'found' => 37];
            });

        $collection = $this->createMock(Collection::class);
        $collection->documents = $documents;
        $collections = $this->createMock(Collections::class);
        $collections->expects(self::exactly(10))->method('offsetGet')->willReturn($collection);
        $client = $this->createMock(Client::class);
        $client->collections = $collections;

        $backend = new TypesenseBackend();
        $this->setPrivateProperty($backend, TypesenseBackend::class, '_searchClient', $client);

        foreach ($this->paginationCases() as $options) {
            self::assertSame(['hits' => [], 'total' => 37], $backend->search(self::INDEX_HANDLE, 'needle', $options));
        }

        self::assertSame([
            ['page' => 3],
            ['per_page' => 10, 'page' => 3],
            ['per_page' => 10, 'page' => 1],
            ['per_page' => 10, 'page' => 4],
            ['offset' => 15, 'limit' => 10],
            ['offset' => 20, 'limit' => 10],
            ['offset' => 0, 'limit' => 10],
            ['per_page' => 10],
            [],
            [],
        ], array_map(
            static fn(array $request): array => array_intersect_key(
                $request,
                array_flip(['per_page', 'page', 'offset', 'limit']),
            ),
            $requests,
        ));
    }

    /** @return list<array<string, int>> */
    private function paginationCases(): array
    {
        return [
            ['page' => 2],
            ['limit' => 10, 'offset' => 15, 'page' => 2],
            ['limit' => 10, 'page' => 0],
            ['limit' => 10, 'page' => 3],
            ['limit' => 10, 'offset' => 15],
            ['limit' => 10, 'offset' => 20],
            ['limit' => 10, 'offset' => 0],
            ['limit' => 10],
            [],
            ['limit' => 0, 'offset' => 15],
        ];
    }

    private function setPrivateProperty(object $target, string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue($target, $value);
    }
}
