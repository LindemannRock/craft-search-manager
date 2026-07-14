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
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\backends\AlgoliaBackend;
use lindemannrock\searchmanager\backends\FileBackend;
use lindemannrock\searchmanager\controllers\IndicesController;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\services\IndexingService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use yii\web\ForbiddenHttpException;

/**
 * @since 5.53.0
 */
#[CoversClass(IndicesController::class)]
final class CatchAllIndexControllerTest extends TestCase
{
    private const PREFIX = 'sm-catch-all-controller-test';

    /**
     * @var list<int>
     */
    private array $createdIndexIds = [];

    /**
     * @var list<string>
     */
    private array $createdBackendHandles = [];

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeMarkedRows();
    }

    protected function tearDown(): void
    {
        if ($this->createdIndexIds !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => $this->createdIndexIds])
                ->execute();
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => $this->createdIndexIds])
                ->execute();
        }

        if ($this->createdBackendHandles !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_backends}}', ['handle' => $this->createdBackendHandles])
                ->execute();
        }

        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }

        $this->purgeMarkedRows();
        SearchIndex::clearCache();
        parent::tearDown();
    }

    public function testCreateCatchAllCreatesConformingLocalIndexAndCoversType(): void
    {
        $localBackendHandle = $this->insertBackend('local-file', 'file');
        $backend = new CatchAllIndexBackendService(new FileBackend(), [
            $localBackendHandle => new FileBackend(),
        ]);
        $indexing = new CatchAllIndexRecordingIndexingService();

        $this->swapPluginComponent('search-manager', 'backend', $backend);
        $this->swapPluginComponent('search-manager', 'indexing', $indexing);
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:editIndices']);
        $this->withPostJson([
            'elementType' => Entry::class,
            'backend' => $localBackendHandle,
            'confirmed' => '1',
        ]);

        $beforeCoverage = $this->withOnlySearchIndices([], fn(): ?array => $this->coverageRowFor(Entry::class));
        self::assertNotNull($beforeCoverage);
        self::assertSame(false, $beforeCoverage['covered']);

        $response = $this->withOnlySearchIndices(
            [],
            fn(): Response => (new IndicesController('indices', SearchManager::$plugin))->actionCreateCatchAll(),
        );
        $data = $response->data;

        self::assertSame(true, $data['success'] ?? null, json_encode($data));
        self::assertIsString($data['indexHandle'] ?? null);
        self::assertSame([$data['indexHandle']], $indexing->rebuilds);

        $row = $this->fetchIndexRow((string)$data['indexHandle']);
        self::assertNotNull($row);
        $this->createdIndexIds[] = (int)$row['id'];

        self::assertSame('Entries (All)', $row['name']);
        self::assertStringStartsWith('entry-all', (string)$row['handle']);
        self::assertSame(Entry::class, $row['elementType']);
        self::assertNull($row['siteId']);
        self::assertSame([], json_decode((string)$row['criteria'], true));
        self::assertSame($localBackendHandle, $row['backend']);
        self::assertSame(1, (int)$row['enabled']);
        self::assertSame(0, (int)$row['splitSections']);
        self::assertSame(['*'], json_decode((string)$row['retrievableFields'], true));

        SearchIndex::clearCache();
        $createdIndex = SearchIndex::findByHandle((string)$row['handle']);
        self::assertNotNull($createdIndex);

        $entryCoverage = $this->withOnlySearchIndices([$createdIndex], fn(): ?array => $this->coverageRowFor(Entry::class));
        self::assertNotNull($entryCoverage);
        self::assertSame(true, $entryCoverage['covered']);
        self::assertSame($row['handle'], $entryCoverage['indexHandle']);
    }

    public function testCreateCatchAllRejectsNonLocalBackend(): void
    {
        $externalBackendHandle = $this->insertBackend('external-algolia', 'algolia');
        $this->swapPluginComponent('search-manager', 'backend', new CatchAllIndexBackendService(new FileBackend(), [
            $externalBackendHandle => new AlgoliaBackend(),
        ]));
        $this->swapPluginComponent('search-manager', 'indexing', new CatchAllIndexRecordingIndexingService());
        $this->actWithPermissions(['searchManager:manageIndices', 'searchManager:editIndices']);
        $this->withPostJson([
            'elementType' => Entry::class,
            'backend' => $externalBackendHandle,
            'confirmed' => '1',
        ]);

        $before = $this->countRows('{{%searchmanager_indices}}', ['backend' => $externalBackendHandle]);
        $response = (new IndicesController('indices', SearchManager::$plugin))->actionCreateCatchAll();

        self::assertSame(false, $response->data['success'] ?? true);
        self::assertSame($before, $this->countRows('{{%searchmanager_indices}}', ['backend' => $externalBackendHandle]));
    }

    public function testCreateCatchAllRequiresEditIndicesPermission(): void
    {
        $localBackendHandle = $this->insertBackend('permission-file', 'file');
        $this->swapPluginComponent('search-manager', 'backend', new CatchAllIndexBackendService(new FileBackend(), [
            $localBackendHandle => new FileBackend(),
        ]));
        $this->swapPluginComponent('search-manager', 'indexing', new CatchAllIndexRecordingIndexingService());
        $this->actWithPermissions(['searchManager:manageIndices']);
        $this->withPostJson([
            'elementType' => Entry::class,
            'backend' => $localBackendHandle,
            'confirmed' => '1',
        ]);

        $this->expectException(ForbiddenHttpException::class);

        (new IndicesController('indices', SearchManager::$plugin))->actionCreateCatchAll();
    }

    /**
     * @param list<string> $permissions
     */
    private function actWithPermissions(array $permissions): void
    {
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, array_values(array_unique(array_merge(['accessCp'], $permissions))));
        $this->actingAs($user);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function withPostJson(array $params): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
            Craft::$app->set('request', new Request([
                'enableCookieValidation' => false,
                'enableCsrfValidation' => false,
            ]));
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
            Craft::$app->set('response', new Response());
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->getRequest()->setBodyParams($params);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
    }

    private function insertBackend(string $suffix, string $backendType): string
    {
        $handle = self::PREFIX . '-' . $suffix;
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'Catch-all Test ' . $suffix,
            'handle' => $handle,
            'backendType' => $backendType,
            'settings' => '{}',
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $this->createdBackendHandles[] = $handle;

        return $handle;
    }

    private function purgeMarkedRows(): void
    {
        $indexIds = (new Query())
            ->select(['id'])
            ->from('{{%searchmanager_indices}}')
            ->where(['like', 'backend', self::PREFIX . '%', false])
            ->column();

        if ($indexIds !== []) {
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_index_sites}}', ['indexId' => array_map('intval', $indexIds)])
                ->execute();
            Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%searchmanager_indices}}', ['id' => array_map('intval', $indexIds)])
                ->execute();
        }

        Craft::$app->getDb()
            ->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchIndexRow(string $handle): ?array
    {
        $row = (new Query())
            ->from('{{%searchmanager_indices}}')
            ->where(['handle' => $handle])
            ->one();

        return $row === false ? null : $row;
    }

    /**
     * @return array{type: string, label: string, covered: bool, indexHandle: string|null}|null
     */
    private function coverageRowFor(string $elementType): ?array
    {
        foreach (SearchManager::$plugin->nativeSearchCoverage->getReport() as $row) {
            if ($row['type'] === $elementType) {
                return $row;
            }
        }

        return null;
    }
}

final class CatchAllIndexBackendService extends BackendService
{
    /**
     * @param array<string, BackendInterface> $backendsByHandle
     */
    public function __construct(
        private readonly BackendInterface $activeBackend,
        private readonly array $backendsByHandle,
    ) {
        parent::__construct();
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->activeBackend;
    }

    public function getBackendForIndex(string $indexName): ?BackendInterface
    {
        $index = SearchIndex::findByHandle($indexName);

        return $this->backendsByHandle[$index?->backend] ?? $this->activeBackend;
    }
}

final class CatchAllIndexRecordingIndexingService extends IndexingService
{
    /**
     * @var list<string>
     */
    public array $rebuilds = [];

    public function rebuildIndex(string $indexHandle): bool
    {
        $this->rebuilds[] = $indexHandle;

        return true;
    }
}
