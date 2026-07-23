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
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request as WebRequest;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\searchmanager\adapters\CraftSearchAdapter;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\jobs\RebuildIndexJob;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\ConfigIndexValidator;
use lindemannrock\searchmanager\tests\TestCase;

/**
 * Regression coverage for audit findings #453 and #456.
 *
 * @since 5.54.0
 */
final class AuditBatch9RegressionTest extends TestCase
{
    private const BACKEND_HANDLE = '__sm_batch9_backend';
    private const GOOD_CONFIG_BACKEND_HANDLE = '__sm_batch9_good_backend';
    private const INDEX_HANDLE = 'sm-batch9-index';

    private mixed $originalConfigCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigCache = $this->configCache();
        $this->purgeRows();
        $this->insertDatabaseBackendAndIndex();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRows();
            $this->setConfigCache($this->originalConfigCache);
            SearchIndex::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    public function testMalformedConfigBackendIsIsolatedAcrossResolutionSurfaces(): void
    {
        $this->withConfigBackends([
            self::BACKEND_HANDLE => [
                'name' => 'Malformed Config Backend',
                'backendType' => 'file',
                'settings' => [],
                'enabled' => ['not', 'a', 'boolean'],
            ],
            self::GOOD_CONFIG_BACKEND_HANDLE => [
                'name' => 'Valid Config Sibling',
                'backendType' => 'file',
                'settings' => [],
                'enabled' => true,
            ],
        ]);

        $fallback = ConfiguredBackend::findByHandle(self::BACKEND_HANDLE);
        self::assertNotNull($fallback);
        self::assertSame('Database Fallback Backend', $fallback->name);
        self::assertFalse($fallback->isFromConfig());

        $validSibling = ConfiguredBackend::findByHandle(self::GOOD_CONFIG_BACKEND_HANDLE);
        self::assertNotNull($validSibling);
        self::assertSame('Valid Config Sibling', $validSibling->name);
        self::assertTrue($validSibling->isFromConfig());

        $allBackends = [];
        foreach (ConfiguredBackend::findAll() as $backend) {
            $allBackends[$backend->handle] = $backend;
        }
        self::assertSame('Database Fallback Backend', $allBackends[self::BACKEND_HANDLE]->name);
        self::assertFalse($allBackends[self::BACKEND_HANDLE]->isFromConfig());
        self::assertSame('Valid Config Sibling', $allBackends[self::GOOD_CONFIG_BACKEND_HANDLE]->name);

        $backendService = new BackendService();
        $this->swapPluginComponent('search-manager', 'backend', $backendService);
        self::assertInstanceOf(FileBackend::class, $backendService->getBackendForIndex(self::INDEX_HANDLE));

        $validation = (new ConfigIndexValidator())->validateConfig([
            'indices' => [
                self::INDEX_HANDLE => [
                    'name' => 'Batch 9 Index',
                    'elementType' => Entry::class,
                    'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
                    'backend' => self::BACKEND_HANDLE,
                    'enabled' => true,
                ],
            ],
        ]);
        self::assertFalse($validation->hasErrors(self::INDEX_HANDLE));

        $index = SearchIndex::findByHandle(self::INDEX_HANDLE);
        self::assertNotNull($index);
        $preflight = new \ReflectionMethod(RebuildIndexJob::class, 'preflightIndexRebuild');
        $preflight->setAccessible(true);
        $result = $preflight->invoke(new RebuildIndexJob(), self::INDEX_HANDLE, $index);
        self::assertSame(self::INDEX_HANDLE, $result['index']->handle);

        $request = Craft::$app->get('request');
        $siteRequest = new WebRequest();
        $siteRequest->setIsCpRequest(false);
        Craft::$app->set('request', $siteRequest);

        try {
            $scores = $this->withOnlySearchIndices([$index], function(): array {
                $query = Entry::find();
                $query->search = 'batch nine native search';
                $query->siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

                return (new CraftSearchAdapter())->searchElements($query);
            });
        } finally {
            Craft::$app->set('request', $request);
        }

        self::assertIsArray($scores);
    }

    public function testWidgetStyleValidationErrorsRouteEveryNonGeneralTab(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/templates/widgets/styles/edit.twig');
        self::assertIsString($source);

        foreach ([
            "'styles.modal'" => "'modal'",
            "'styles.backdrop'" => "'modal'",
            "'styles.header'" => "'modal'",
            "'styles.footer'" => "'modal'",
            "'styles.input'" => "'input'",
            "'styles.result'" => "'results'",
            "'styles.promoted'" => "'results'",
            "'styles.highlight'" => "'results'",
            "'styles.trigger'" => "'controls'",
            "'styles.kbd'" => "'controls'",
        ] as $prefix => $tab) {
            self::assertStringContainsString('k starts with ' . $prefix, $source);
            self::assertStringContainsString('{% set selectedTab = ' . $tab . ' %}', $source);
        }

        foreach (['general', 'modal', 'input', 'results', 'controls'] as $tab) {
            self::assertStringContainsString(
                '<div id="' . $tab . '"{% if selectedTab != \'' . $tab . '\' %} class="hidden"{% endif %}>',
                $source,
            );
        }
    }

    /**
     * @param array<string, mixed> $backends
     */
    private function withConfigBackends(array $backends): void
    {
        $cache = $this->configCache();
        if (!is_array($cache)) {
            $cache = [];
        }

        $pluginConfig = $cache['search-manager'] ?? [];
        $cache['search-manager'] = array_merge(
            is_array($pluginConfig) ? $pluginConfig : [],
            ['backends' => $backends],
        );
        $this->setConfigCache($cache);
        BaseConfigFileHelper::clearCache('search-manager');
        $this->setConfigCache($cache);
        SearchIndex::clearCache();
    }

    private function insertDatabaseBackendAndIndex(): void
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Database Fallback Backend',
            'handle' => self::BACKEND_HANDLE,
            'backendType' => 'file',
            'settings' => null,
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_indices}}', [
            'name' => 'Batch 9 Index',
            'handle' => self::INDEX_HANDLE,
            'elementType' => Entry::class,
            'siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id,
            'criteria' => '{}',
            'transformerClass' => '',
            'headingLevels' => null,
            'language' => null,
            'backend' => self::BACKEND_HANDLE,
            'enabled' => 1,
            'enableAnalytics' => 1,
            'disableStopWords' => 0,
            'skipEntriesWithoutUrl' => 0,
            'splitSections' => 0,
            'retrievableFields' => '["*"]',
            'source' => 'database',
            'lastIndexed' => null,
            'documentCount' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        SearchIndex::clearCache();
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_indices}}', ['handle' => self::INDEX_HANDLE])
            ->execute();
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['handle' => self::BACKEND_HANDLE])
            ->execute();
        SearchIndex::clearCache();
    }
}
