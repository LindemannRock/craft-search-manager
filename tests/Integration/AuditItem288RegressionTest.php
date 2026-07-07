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
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\MeilisearchBackend;
use lindemannrock\searchmanager\backends\TypesenseBackend;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\search\storage\StorageInterface;
use lindemannrock\searchmanager\tests\Stubs\RecordingStorage;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Focused regression coverage for audit #288.
 *
 * @since 5.53.0
 */
final class AuditItem288RegressionTest extends TestCase
{
    public function testSiteScopeNormalizationKeepsSelectedSetsAndDoesNotCollapseToFirstSite(): void
    {
        self::assertSame('*', SearchSiteScopeHelper::normalize(null));
        self::assertSame('*', SearchSiteScopeHelper::normalize('*'));
        self::assertSame('*', SearchSiteScopeHelper::normalize([]));
        self::assertSame(7, SearchSiteScopeHelper::normalize('7'));
        self::assertSame([1, 2, 3, 7], SearchSiteScopeHelper::normalize([1, '2', 2, 0, -5, '3', 7]));

        self::assertNull(SearchSiteScopeHelper::scopedSiteId([1, 2, 3, 7]));
        self::assertSame(7, SearchSiteScopeHelper::scopedSiteId([7]));
    }

    public function testLocalBackendSelectedSiteSetMergesRequestedSitesOnly(): void
    {
        $storage = new RecordingStorage(
            [
                'coffee' => [
                    '1:101' => 1,
                    '2:202' => 1,
                    '3:303' => 1,
                ],
            ],
            [
                101 => ['coffee'],
                202 => ['coffee'],
                303 => ['coffee'],
            ],
            [
                '1:101' => 1,
                '2:202' => 1,
                '3:303' => 1,
            ],
            3,
            1.0,
            documentTermsById: [
                '1:101' => ['coffee' => 1],
                '2:202' => ['coffee' => 1],
                '3:303' => ['coffee' => 1],
            ],
            documentLengthsById: [
                '1:101' => 1,
                '2:202' => 1,
                '3:303' => 1,
            ],
            elementsById: [
                101 => ['elementType' => 'entry', 'documentData' => ['title' => 'English coffee']],
                202 => ['elementType' => 'entry', 'documentData' => ['title' => 'Arabic coffee']],
                303 => ['elementType' => 'entry', 'documentData' => ['title' => 'French coffee']],
            ],
        );
        $backend = new AuditItem288Backend($storage);

        $result = $backend->search('docs', 'coffee', ['siteId' => [1, 3], 'limit' => 10]);

        self::assertSame(2, $result['total']);
        self::assertSame([1, 3], array_values(array_unique(array_column($result['hits'], 'siteId'))));
        self::assertSame([101, 303], array_column($result['hits'], 'elementId'));
    }

    public function testExternalBackendFiltersSupportSelectedSiteSetsAndExistingFilters(): void
    {
        self::assertSame('(siteId:1 OR siteId:2 OR siteId:7)', AlgoliaBackend::siteIdFilter([2, 1, 7, 2]));
        self::assertSame('(type:doc) AND (siteId:1 OR siteId:2)', AlgoliaBackend::siteIdFilter([2, 1], 'type:doc'));

        self::assertSame('(siteId = 1 OR siteId = 2 OR siteId = 7)', MeilisearchBackend::siteIdFilter([2, 1, 7, 2]));
        self::assertSame('(type = doc) AND (siteId = 1 OR siteId = 2)', MeilisearchBackend::siteIdFilter([2, 1], 'type = doc'));

        self::assertSame('siteId:=[1,2,7]', TypesenseBackend::siteIdFilter([2, 1, 7, 2]));
        self::assertSame('(type:=doc) && siteId:=[1,2]', TypesenseBackend::siteIdFilter([2, 1], 'type:=doc'));
    }

    public function testCacheKeysUseNormalizedSelectedSiteSetIdentity(): void
    {
        $service = new \lindemannrock\searchmanager\services\BackendService();
        $method = new \ReflectionMethod($service, '_generateCacheKey');
        $method->setAccessible(true);

        $enOnly = $method->invoke($service, 'docs', 'coffee', ['siteId' => [1], 'limit' => 10]);
        $enAr = $method->invoke($service, 'docs', 'coffee', ['siteId' => [1, 2], 'limit' => 10]);
        $arEn = $method->invoke($service, 'docs', 'coffee', ['siteId' => [2, 1, 1], 'limit' => 10]);
        $allSites = $method->invoke($service, 'docs', 'coffee', ['siteId' => '*', 'limit' => 10]);

        self::assertNotSame($enOnly, $enAr);
        self::assertSame($enAr, $arEn);
        self::assertNotSame($enAr, $allSites);
    }

    public function testSettingsControllerTestSearchUsesResolvedSelectedSiteSet(): void
    {
        $source = $this->readPluginFile('src/controllers/SettingsController.php');

        self::assertStringContainsString('$indexSiteIds = $index ? $index->getSiteIds() : null;', $source);
        self::assertStringContainsString("\$searchOptions['siteId'] = count(\$indexSiteIds) === 1 ? \$indexSiteIds[0] : \$indexSiteIds;", $source);
        self::assertStringContainsString("'siteId' => \$searchOptions['siteId'] ?? null,", $source);
    }

    public function testLocalRuntimeBackendsShareSelectedSiteSetContract(): void
    {
        foreach (['MySqlBackend', 'PostgreSqlBackend', 'FileBackend', 'RedisBackend'] as $class) {
            $source = $this->readPluginFile('src/backends/' . $class . '.php');
            self::assertStringContainsString('extends AbstractSearchEngineBackend', $source, $class);
        }
    }

    public function testRawSiteIdArraysAreNotDirectlyIntCastInSearchPaths(): void
    {
        $backendService = $this->readPluginFile('src/services/BackendService.php');
        $abstractBackend = $this->readPluginFile('src/backends/AbstractSearchEngineBackend.php');
        $enrichmentService = $this->readPluginFile('src/services/EnrichmentService.php');

        self::assertStringNotContainsString('(int) $options[\'siteId\']', $backendService);
        self::assertStringNotContainsString('(int) $options[\'siteId\']', $enrichmentService);
        self::assertStringNotContainsString('(int)$rawSiteId', $backendService);
        self::assertStringNotContainsString('(int)$siteIdOption', $abstractBackend);
        self::assertStringContainsString('SearchSiteScopeHelper::normalize($options[\'siteId\'] ?? null)', $backendService);
        self::assertStringContainsString('SearchSiteScopeHelper::normalize($options[\'siteId\'] ?? null)', $abstractBackend);
        self::assertStringContainsString('SearchSiteScopeHelper::scopedSiteId($options[\'siteId\'] ?? null)', $enrichmentService);
    }

    private function readPluginFile(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source);

        return $source;
    }
}

final class AuditItem288Backend extends AbstractSearchEngineBackend
{
    public function __construct(private readonly StorageInterface $storage)
    {
        parent::__construct();
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

    public function isAvailable(): bool
    {
        return true;
    }

    public function getStatus(): array
    {
        return ['available' => true];
    }
}
