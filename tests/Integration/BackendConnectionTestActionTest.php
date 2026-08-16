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
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\searchmanager\backends\BaseBackend;
use lindemannrock\searchmanager\controllers\BackendsController;
use lindemannrock\searchmanager\interfaces\BackendInterface;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\BackendService;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;

/**
 * @since 5.54.0
 */
#[CoversClass(BackendsController::class)]
final class BackendConnectionTestActionTest extends TestCase
{
    private const PREFIX = 'sm-pr1-26';

    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeRows();
        $user = $this->createTestUser(self::PREFIX);
        $this->grantPermissions($user, ['accessCp', 'searchManager:manageBackends']);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        $this->restoreRequestResponse();
        $this->purgeRows();
        parent::tearDown();
    }

    public function testManageOnlyUserTestsStoredBackendWithStoredSettings(): void
    {
        $backendId = $this->insertBackend('stored', [
            'host' => 'stored.example.test',
            'apiKey' => 'stored-secret',
        ]);
        $adapter = new Pr126RecordingBackend();
        $service = new Pr126RecordingBackendService($adapter);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->withRequest('POST', 'application/json', [
            'backendId' => $backendId,
            'backendType' => 'typesense',
            'settings' => [
                'host' => 'submitted.example.test',
                'apiKey' => 'submitted-secret',
            ],
        ]);

        self::assertFalse(Craft::$app->getUser()->checkPermission('searchManager:editBackends'));
        $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();

        self::assertSame([
            'success' => true,
            'message' => 'Connection successful',
        ], $response->data);
        self::assertSame(['file'], $service->requestedTypes);
        self::assertSame([[
            'host' => 'stored.example.test',
            'apiKey' => 'stored-secret',
        ]], $adapter->configuredSettings);
        self::assertSame(1, $adapter->availabilityCalls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unresolvedBackendIds(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'invalid shape' => [['id' => 1]];
        yield 'unknown handle' => [self::PREFIX . '-missing'];
        yield 'unknown id' => [999999999];
    }

    #[DataProvider('unresolvedBackendIds')]
    public function testUnresolvedBackendIdNeverConstructsAdapter(mixed $backendId): void
    {
        $adapter = new Pr126RecordingBackend();
        $service = new Pr126RecordingBackendService($adapter);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->withRequest('POST', 'application/json', [
            'backendId' => $backendId,
            'backendType' => 'typesense',
            'settings' => [
                'host' => 'private.internal',
                'apiKey' => 'submitted-secret',
            ],
        ]);

        $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();

        self::assertSame([
            'success' => false,
            'error' => 'Backend not found',
        ], $response->data);
        self::assertSame([], $service->requestedTypes);
        self::assertSame([], $adapter->configuredSettings);
        self::assertSame(0, $adapter->availabilityCalls);
    }

    public function testStoredBackendUnavailableResponseIsPreserved(): void
    {
        $backendId = $this->insertBackend('unavailable', ['path' => '/stored/path']);
        $adapter = new Pr126RecordingBackend();
        $adapter->available = false;
        $this->swapPluginComponent(
            'search-manager',
            'backend',
            new Pr126RecordingBackendService($adapter),
        );
        $this->withRequest('POST', 'application/json', ['backendId' => $backendId]);

        $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();

        self::assertSame([
            'success' => false,
            'error' => 'Backend is not available. Check your settings.',
        ], $response->data);
        self::assertSame(1, $adapter->availabilityCalls);
    }

    public function testEphemeralFilesystemConnectionTestReportsUnavailableWithoutCreatingStorage(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('ephemeral-file-connection') . '/indices';
        $backendId = $this->insertBackend('ephemeral-file', ['storagePath' => $candidatePath]);
        $this->withRequest('POST', 'application/json', ['backendId' => $backendId]);
        $hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = true;

        try {
            $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();

            self::assertSame([
                'success' => false,
                'error' => 'Backend is not available. Check your settings.',
            ], $response->data);
            self::assertDirectoryDoesNotExist($candidatePath);
        } finally {
            if ($hadEphemeralSetting) {
                $_SERVER['CRAFT_EPHEMERAL'] = $originalEphemeralSetting;
            } else {
                unset($_SERVER['CRAFT_EPHEMERAL']);
            }
        }
    }

    public function testEphemeralFilesystemBackendInfoSkipsFileEnumeration(): void
    {
        $candidatePath = $this->createOwnedStorageDirectory('ephemeral-file-info') . '/indices';
        $backendId = $this->insertBackend('ephemeral-file-info', ['storagePath' => $candidatePath]);
        $this->withRequest('POST', 'application/json', ['backendId' => $backendId]);
        $hadEphemeralSetting = array_key_exists('CRAFT_EPHEMERAL', $_SERVER);
        $originalEphemeralSetting = $_SERVER['CRAFT_EPHEMERAL'] ?? null;
        $_SERVER['CRAFT_EPHEMERAL'] = true;

        try {
            $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionInfo();

            self::assertTrue($response->data['success']);
            self::assertFalse($response->data['available']);
            self::assertSame([], $response->data['indices']);
            self::assertDirectoryDoesNotExist($candidatePath);
        } finally {
            if ($hadEphemeralSetting) {
                $_SERVER['CRAFT_EPHEMERAL'] = $originalEphemeralSetting;
            } else {
                unset($_SERVER['CRAFT_EPHEMERAL']);
            }
        }
    }

    public function testStoredBackendWithoutAvailableAdapterResponseIsPreserved(): void
    {
        $backendId = $this->insertBackend('unavailable-adapter', [], 'file');
        $service = new Pr126RecordingBackendService(null);
        $this->swapPluginComponent('search-manager', 'backend', $service);
        $this->withRequest('POST', 'application/json', ['backendId' => $backendId]);

        $response = (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();

        self::assertSame([
            'success' => false,
            'error' => 'Unknown backend type: file',
        ], $response->data);
        self::assertSame(['file'], $service->requestedTypes);
    }

    public function testThrownExceptionNeverDisclosesProviderDetailOrSecrets(): void
    {
        $backendId = $this->insertBackend('throws', ['apiKey' => 'stored-secret']);
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $originalDevMode = $generalConfig->devMode;

        try {
            foreach ([true, false] as $devMode) {
                $adapter = new Pr126RecordingBackend();
                $adapter->exception = new \RuntimeException('adapter detail with stored-secret');
                $this->swapPluginComponent(
                    'search-manager',
                    'backend',
                    new Pr126RecordingBackendService($adapter),
                );
                $this->withRequest('POST', 'application/json', ['backendId' => $backendId]);
                $generalConfig->devMode = $devMode;
                $controller = new Pr126BackendsController('backends', SearchManager::$plugin);

                $response = $controller->actionTest();

                self::assertFalse($response->data['success']);
                self::assertSame('connection-failed', $response->data['status']);
                self::assertSame('Connection test failed.', $response->data['error']);
                self::assertStringNotContainsString('stored-secret', $response->data['error']);
                self::assertSame([[
                    'message' => 'Backend connection test failed',
                    'params' => [
                        'operation' => 'backend-connection-test',
                        'classification' => 'connection-failed',
                    ],
                ]], $controller->errors);
            }
        } finally {
            $generalConfig->devMode = $originalDevMode;
        }
    }

    public function testPostGateIsPreserved(): void
    {
        $this->withRequest('GET', 'application/json', []);

        $this->expectException(MethodNotAllowedHttpException::class);
        (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();
    }

    public function testJsonGateIsPreserved(): void
    {
        $this->withRequest('POST', 'text/html', []);

        $this->expectException(BadRequestHttpException::class);
        (new Pr126BackendsController('backends', SearchManager::$plugin))->actionTest();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function insertBackend(string $suffix, array $settings, string $backendType = 'file'): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());

        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'PR1.26 Backend',
            'handle' => self::PREFIX . '-' . $suffix,
            'backendType' => $backendType,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function withRequest(string $method, string $accept, array $body): void
    {
        if ($this->originalRequest === null) {
            $this->originalRequest = Craft::$app->getRequest();
        }
        if ($this->originalResponse === null) {
            $this->originalResponse = Craft::$app->getResponse();
        }
        if ($this->originalRequestMethod === null) {
            $this->originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        }

        $request = new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
        ]);
        $request->setBodyParams($body);
        $request->getHeaders()->set('Accept', $accept);
        Craft::$app->set('request', $request);
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = $method;
    }

    private function restoreRequestResponse(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }
    }

    private function purgeRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%searchmanager_backends}}', ['like', 'handle', self::PREFIX . '%', false])
            ->execute();
    }
}

final class Pr126BackendsController extends BackendsController
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $errors = [];

    protected function logError(string $message, array $params = []): void
    {
        $this->errors[] = compact('message', 'params');
    }
}

final class Pr126RecordingBackendService extends BackendService
{
    /**
     * @var list<string>
     */
    public array $requestedTypes = [];

    public function __construct(private readonly ?BackendInterface $adapter)
    {
        parent::__construct();
    }

    public function getBackend(string $name): ?BackendInterface
    {
        $this->requestedTypes[] = $name;
        return $this->adapter;
    }
}

final class Pr126RecordingBackend extends BaseBackend
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $configuredSettings = [];

    public int $availabilityCalls = 0;
    public bool $available = true;
    public ?\Throwable $exception = null;

    public function setConfiguredSettings(array $settings): void
    {
        $this->configuredSettings[] = $settings;
        parent::setConfiguredSettings($settings);
    }

    public function isAvailable(): bool
    {
        $this->availabilityCalls++;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->available;
    }

    public function getName(): string
    {
        return 'pr1-26-recording';
    }

    public function getStatus(): array
    {
        return ['available' => $this->available];
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
}
