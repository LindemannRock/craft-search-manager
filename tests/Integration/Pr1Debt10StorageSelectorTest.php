<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use craft\helpers\FileHelper;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use lindemannrock\searchmanager\controllers\UtilitiesController;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\StorageMaintenanceService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @since 5.54.0
 */
final class Pr1Debt10StorageSelectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool, int, bool}>
     */
    public static function eligibilityProvider(): iterable
    {
        yield 'database available configured empty' => ['database', true, true, 0, true];
        yield 'database available unconfigured with rows' => ['database', true, false, 3, true];
        yield 'database available unconfigured empty' => ['database', true, false, 0, false];
        yield 'redis available configured empty' => ['redis', true, true, 0, true];
        yield 'redis available unconfigured with keys' => ['redis', true, false, 4, true];
        yield 'redis available unconfigured empty' => ['redis', true, false, 0, false];
        yield 'redis unavailable configured' => ['redis', false, true, 0, false];
        yield 'redis unavailable with stale key metadata' => ['redis', false, false, 9, false];
        yield 'file available configured empty' => ['file', true, true, 0, true];
        yield 'file available unconfigured with files' => ['file', true, false, 5, true];
        yield 'file available unconfigured empty' => ['file', true, false, 0, false];
    }

    #[DataProvider('eligibilityProvider')]
    public function testEligibilityMatrix(
        string $type,
        bool $available,
        bool $configured,
        int $count,
        bool $expectedVisible,
    ): void {
        $stats = $this->emptyStats();
        $stats[$type]['available'] = $available;
        $stats[$type]['configured'] = $configured;
        $stats[$type][$this->countKey($type)] = $count;

        $options = SearchManager::$plugin->storageMaintenance->buildEligibleOptions($stats);
        $values = array_column($options, 'value');

        self::assertSame($expectedVisible, in_array($type, $values, true));
        if ($expectedVisible) {
            self::assertStringNotContainsString('Loading...', $options[0]['label']);
            self::assertStringContainsString((string)$count, $options[0]['label']);
        }
    }

    public function testNoEligibleStorageTypesRenderWithoutLoadingOptionsOrClearAction(): void
    {
        $html = $this->renderUtilities([]);

        self::assertSame([], $this->storageOptionValues($html));
        self::assertStringNotContainsString('Loading...', $html);
        self::assertStringNotContainsString('id="clear-storage-by-type"', $html);
        self::assertStringContainsString('No data available', $html);
    }

    public function testRenderedSelectorContainsOnlyEligibleFinalLabelsInDeterministicOrder(): void
    {
        $stats = $this->emptyStats();
        $stats['database'] = [
            'available' => true,
            'configured' => true,
            'driverLabel' => 'PostgreSQL',
            'totalRows' => 12,
        ];
        $stats['redis'] = [
            'available' => false,
            'configured' => true,
            'keyCount' => 7,
        ];
        $stats['file'] = [
            'available' => true,
            'configured' => false,
            'fileCount' => 2,
        ];

        $options = SearchManager::$plugin->storageMaintenance->buildEligibleOptions($stats);
        $html = $this->renderUtilities($options);

        self::assertSame(['database', 'file'], $this->storageOptionValues($html));
        self::assertStringContainsString('PostgreSQL (12 rows)', $html);
        self::assertStringContainsString('File (2 files)', $html);
        self::assertStringNotContainsString('value="redis"', $html);
        self::assertStringNotContainsString('Loading...', $html);
        self::assertStringContainsString('id="clear-storage-by-type"', $html);
    }

    public function testConfiguredUsageMapsDisabledLocalBackendsAndIgnoresHostedBackends(): void
    {
        $backends = [
            new ConfiguredBackend(['backendType' => 'mysql', 'enabled' => false]),
            new ConfiguredBackend(['backendType' => 'pgsql', 'enabled' => true]),
            new ConfiguredBackend(['backendType' => 'redis', 'enabled' => false]),
            new ConfiguredBackend(['backendType' => 'file', 'enabled' => false]),
            new ConfiguredBackend(['backendType' => 'algolia', 'enabled' => true]),
            new ConfiguredBackend(['backendType' => 'meilisearch', 'enabled' => true]),
            new ConfiguredBackend(['backendType' => 'typesense', 'enabled' => true]),
        ];

        self::assertSame(
            ['database' => true, 'redis' => true, 'file' => true],
            SearchManager::$plugin->storageMaintenance->buildConfiguredUsage($backends),
        );
    }

    public function testDisabledRedisBackendStillEstablishesTheClearTarget(): void
    {
        $backend = new ConfiguredBackend([
            'backendType' => 'redis',
            'enabled' => false,
            'settings' => [
                'host' => 'redis.internal',
                'port' => 6380,
                'database' => 9,
            ],
        ]);

        $config = SearchManager::$plugin->storageMaintenance->getRedisConfig([$backend]);

        self::assertSame('redis.internal', $config['host']);
        self::assertSame(6380, $config['port']);
        self::assertSame(9, $config['database']);
    }

    public function testFileCountingUsesAnIsolatedTemporaryPath(): void
    {
        $path = Craft::$app->getPath()->getTempPath() . '/search-manager-pr1-debt-10-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($path . '/nested');

        try {
            file_put_contents($path . '/one.dat', 'one');
            file_put_contents($path . '/nested/two.dat', 'two');

            self::assertSame(2, SearchManager::$plugin->storageMaintenance->countFilesInDirectory($path));
        } finally {
            FileHelper::removeDirectory($path);
        }
    }

    public function testStatsEndpointReturnsTheSharedFilteredProjection(): void
    {
        $projection = [
            'stats' => $this->emptyStats(),
            'storageOptions' => [
                ['label' => 'PostgreSQL (3 rows)', 'value' => 'database'],
            ],
        ];
        $service = new class extends StorageMaintenanceService {
            /**
             * @var array{
             *   stats: array<string, array<string, mixed>>,
             *   storageOptions: list<array{label: string, value: string}>
             * }
             */
            public array $projection = [];

            /**
             * @inheritdoc
             */
            public function getProjection(): array
            {
                return $this->projection;
            }
        };
        $service->projection = $projection;
        $this->swapPluginComponent('search-manager', 'storageMaintenance', $service);

        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');

        try {
            $response = (new UtilitiesController('utilities', SearchManager::$plugin))->actionGetStorageStats();

            self::assertSame([
                'success' => true,
                'stats' => $projection['stats'],
                'storageOptions' => $projection['storageOptions'],
            ], $response->data);
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
        }
    }

    public function testUtilitiesUseOneServerProjectionWithoutClientEligibilityLogic(): void
    {
        $pluginRoot = dirname(__DIR__, 2);
        $utility = file_get_contents($pluginRoot . '/src/utilities/ClearSearchCache.php');
        $controller = file_get_contents($pluginRoot . '/src/controllers/UtilitiesController.php');
        $template = file_get_contents($pluginRoot . '/src/templates/utilities/index.twig');
        $service = file_get_contents($pluginRoot . '/src/services/StorageMaintenanceService.php');
        self::assertIsString($utility);
        self::assertIsString($controller);
        self::assertIsString($template);
        self::assertIsString($service);

        self::assertStringContainsString("storageMaintenance->getProjection()['storageOptions']", $utility);
        self::assertStringContainsString('storageMaintenance->getProjection()', $controller);
        self::assertStringContainsString("'storageOptions' => \$projection['storageOptions']", $controller);
        self::assertStringContainsString('applyStorageOptions(response.storageOptions);', $template);
        self::assertStringNotContainsString('response.stats.', $template);
        self::assertStringNotContainsString('storageStrings', $template);
        self::assertStringNotContainsString("'Loading...'|t('search-manager')", $template);
        self::assertStringNotContainsString('$(document).ready(function()', $template);
        self::assertStringNotContainsString('getDatabaseStats', $controller);
        self::assertStringNotContainsString('getRedisStats', $controller);
        self::assertStringNotContainsString('getFileStats', $controller);
        self::assertStringContainsString('ConfiguredBackend::findAll()', $service);
        self::assertStringContainsString("'mysql', 'pgsql' => 'database'", $service);
        self::assertStringNotContainsString('getConfigFromFile', $service);
        self::assertStringNotContainsString('searchmanager_backends', $service);
    }

    public function testStorageStatsAndClearActionsRetainTheRebuildPermissionGate(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/UtilitiesController.php');
        self::assertIsString($controller);

        preg_match('/public function beforeAction\(.*?^    }$/ms', $controller, $matches);
        self::assertNotEmpty($matches);
        self::assertStringContainsString("case 'clear-storage-by-type':", $matches[0]);
        self::assertStringContainsString("case 'get-storage-stats':", $matches[0]);
        self::assertStringContainsString("\$this->requirePermission('searchManager:rebuildIndices');", $matches[0]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function emptyStats(): array
    {
        return [
            'database' => [
                'available' => false,
                'configured' => false,
                'driverLabel' => 'MySQL',
                'totalRows' => 0,
            ],
            'redis' => [
                'available' => false,
                'configured' => false,
                'keyCount' => 0,
            ],
            'file' => [
                'available' => false,
                'configured' => false,
                'fileCount' => 0,
            ],
        ];
    }

    private function countKey(string $type): string
    {
        return match ($type) {
            'database' => 'totalRows',
            'redis' => 'keyCount',
            'file' => 'fileCount',
        };
    }

    /**
     * @param list<array{label: string, value: string}> $storageOptions
     */
    private function renderUtilities(array $storageOptions): string
    {
        $originalRequest = Craft::$app->getRequest();
        $originalResponse = Craft::$app->getResponse();
        $originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            return Craft::$app->getView()->renderTemplate(
                'search-manager/utilities/index',
                [
                    'indexCount' => 0,
                    'totalDocuments' => 0,
                    'backendDistribution' => [],
                    'defaultBackendName' => null,
                    'indices' => [],
                    'deviceCacheFiles' => 0,
                    'searchCacheFiles' => 0,
                    'autocompleteCacheFiles' => 0,
                    'storageMethod' => 'file',
                    'analyticsCount' => 0,
                    'settings' => SearchManager::$plugin->getSettings(),
                    'storageOptions' => $storageOptions,
                    'currentUser' => new class {
                        public function can(string $permission): bool
                        {
                            return true;
                        }
                    },
                ],
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('response', $originalResponse);
            $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
        }
    }

    /**
     * @return list<string>
     */
    private function storageOptionValues(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//select[@id="storage-type-select"]/option/@value');
        self::assertNotFalse($nodes);

        $values = [];
        foreach ($nodes as $node) {
            $values[] = $node->nodeValue;
        }

        return $values;
    }
}
