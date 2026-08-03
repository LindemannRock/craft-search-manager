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
use craft\base\Plugin;
use craft\db\Connection;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\Settings;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\tests\Stubs\SearchManagerConfigServiceStub;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\db\Exception as DbException;

/**
 * Secret-safe persistence-failure regressions for A4 Fix Session 4.
 *
 * @since 5.54.0
 */
#[CoversClass(ConfiguredBackend::class)]
#[CoversClass(ApiKey::class)]
final class PersistenceFailureConfidentialityTest extends TestCase
{
    private const PREFIX = 'sm-a4-pr153-';
    private const SENTINELS = [
        'exception-message' => '__SM_PR153_EXCEPTION_MESSAGE__',
        'interpolated-sql' => '__SM_PR153_INTERPOLATED_SQL__',
        'bound-value' => '__SM_PR153_BOUND_VALUE__',
        'error-detail' => '__SM_PR153_ERROR_INFO_DETAIL__',
        'previous-message' => '__SM_PR153_PREVIOUS_EXCEPTION__',
        'plaintext-credential' => '__SM_PR153_PASSWORD_PLAINTEXT__',
        'credential-hash' => '__SM_PR153_HASH_8F72D7E8__',
        'ciphertext' => '__SM_PR153_CIPHERTEXT_Q2lwaGVy__',
        'json-settings' => '__SM_PR153_JSON_SETTINGS_VALUE__',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeFixtures();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * @return iterable<string, array{string, array<int, mixed>|string}>
     */
    public static function persistenceFailureCases(): iterable
    {
        foreach (['backend', 'apiKey'] as $family) {
            yield $family . ' MySQL-shaped exception' => [
                $family,
                ['23000', 1062, self::SENTINELS['error-detail']],
            ];
            yield $family . ' PostgreSQL-shaped exception' => [
                $family,
                ['23505', '7', self::SENTINELS['error-detail']],
            ];
            yield $family . ' missing errorInfo' => [
                $family,
                [],
            ];
            yield $family . ' malformed errorInfo' => [
                $family,
                '__SM_PR153_MALFORMED_ERROR_INFO__',
            ];
        }
    }

    /**
     * @param array<int, mixed>|string $errorInfo
     */
    #[DataProvider('persistenceFailureCases')]
    public function testPersistenceFailureLogsExcludeSensitiveExceptionData(
        string $family,
        array|string $errorInfo,
    ): void {
        $handle = $this->nextTestMarker(self::PREFIX, $family);
        $model = $family === 'backend'
            ? $this->backend($handle)
            : $this->apiKey($handle);
        $exception = $this->sentinelException($errorInfo);

        [$saved, $boundary] = $this->withFailingWriteBoundary(
            $exception,
            static fn(): bool => $model->save(),
        );

        self::assertFalse($saved);
        self::assertSame(1, $boundary->writeCommandRequests);
        self::assertSame(
            [
                $family === 'backend'
                    ? Craft::t('search-manager', 'Could not save backend')
                    : Craft::t('search-manager', 'Couldn’t save API key'),
            ],
            $model->getErrors('id'),
        );
        self::assertNull($model->id);
        self::assertSame(0, $this->countRows(
            $family === 'backend'
                ? '{{%searchmanager_backends}}'
                : '{{%searchmanager_api_keys}}',
            ['handle' => $handle],
        ));
        self::assertSame([], $model->infoLogs);
        self::assertCount(1, $model->errorLogs);

        $logged = serialize($model->errorLogs);
        $this->assertNoSensitiveSentinels($logged);
        self::assertSame([
            'message' => $family === 'backend'
                ? 'Failed to save backend'
                : 'Failed to save API key',
            'params' => [
                'operation' => 'save',
                'resource' => $family === 'backend' ? 'backend' : 'api-key',
                'handle' => $handle,
                'exception' => DbException::class,
            ],
        ], $model->errorLogs[0]);
    }

    public function testBackendDefaultTransitionRollsBackBeforeFailureLog(): void
    {
        $oldHandle = $this->nextTestMarker(self::PREFIX, 'default-old');
        $newHandle = $this->nextTestMarker(self::PREFIX, 'default-new');
        $backendId = $this->insertBackend($oldHandle);
        $this->setDefaultBackendHandle($oldHandle);
        $backend = ConfiguredBackend::findById($backendId);
        self::assertNotNull($backend);
        self::assertSame($oldHandle, $backend->handle);
        $backend->handle = $newHandle;
        $logOffset = count(\Yii::getLogger()->messages);

        [[$saved, $boundary], $settings] = $this->withUnconfiguredDefaultSettings(
            $oldHandle,
            fn(): array => $this->withFailingWriteBoundary(
                $this->sentinelException(['40001', 1213, self::SENTINELS['error-detail']]),
                static fn(): bool => $backend->saveWithDefaultHandleTransition(),
            ),
        );

        self::assertFalse($saved);
        self::assertSame(1, $boundary->writeCommandRequests, json_encode($backend->getErrors()));
        self::assertSame(
            [Craft::t('search-manager', 'Could not save backend')],
            $backend->getErrors('id'),
        );
        self::assertSame(
            $oldHandle,
            (new Query())
                ->select('handle')
                ->from('{{%searchmanager_backends}}')
                ->where(['id' => $backendId])
                ->scalar(),
        );
        self::assertSame(
            $oldHandle,
            (new Query())
                ->select('defaultBackendHandle')
                ->from('{{%searchmanager_settings}}')
                ->where(['id' => 1])
                ->scalar(),
        );
        self::assertSame($oldHandle, $settings->defaultBackendHandle);
        $logged = serialize(array_slice(\Yii::getLogger()->messages, $logOffset));
        self::assertStringNotContainsString('Backend saved', $logged);
        $this->assertNoSensitiveSentinels($logged);
    }

    public function testValidationFailuresOccurBeforeCredentialWriteBoundary(): void
    {
        $backend = $this->backend($this->nextTestMarker(self::PREFIX, 'invalid-backend'));
        $backend->name = '';
        $apiKey = $this->apiKey($this->nextTestMarker(self::PREFIX, 'invalid-api-key'));
        $apiKey->name = '';

        foreach ([$backend, $apiKey] as $model) {
            [$saved, $boundary] = $this->withFailingWriteBoundary(
                $this->sentinelException(['HY000', 1045, self::SENTINELS['error-detail']]),
                static fn(): bool => $model->save(),
            );

            self::assertFalse($saved);
            self::assertSame(0, $boundary->writeCommandRequests);
            self::assertNotEmpty($model->getErrors('name'));
            self::assertSame([], $model->infoLogs);
            self::assertCount(1, $model->errorLogs);
            self::assertStringContainsString('validation failed', strtolower($model->errorLogs[0]['message']));
            self::assertStringNotContainsString('Failed to save', $model->errorLogs[0]['message']);
        }
    }

    public function testSuccessfulSavesAndSuccessLogsRemainOperational(): void
    {
        $backendHandle = $this->nextTestMarker(self::PREFIX, 'success-backend');
        $backend = $this->backend($backendHandle);
        $apiKeyHandle = $this->nextTestMarker(self::PREFIX, 'success-api-key');
        $apiKey = $this->apiKey($apiKeyHandle);

        self::assertTrue($backend->save());
        self::assertNotNull($backend->id);
        self::assertSame([], $backend->errorLogs);
        self::assertSame([[
            'message' => 'Backend saved',
            'params' => ['handle' => $backendHandle],
        ]], $backend->infoLogs);
        $storedSettings = (new Query())
            ->select('settings')
            ->from('{{%searchmanager_backends}}')
            ->where(['id' => $backend->id])
            ->scalar();
        if (is_string($storedSettings)) {
            $storedSettings = json_decode($storedSettings, true, flags: JSON_THROW_ON_ERROR);
        }
        self::assertSame(
            self::SENTINELS['plaintext-credential'],
            is_array($storedSettings) ? $storedSettings['password'] : null,
        );

        self::assertTrue($apiKey->save());
        self::assertNotNull($apiKey->id);
        self::assertNotNull($apiKey->uid);
        self::assertSame([], $apiKey->errorLogs);
        self::assertSame([[
            'message' => 'API key saved',
            'params' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'handle' => $apiKeyHandle,
                'type' => ApiKey::TYPE_PUBLIC,
                'keyPrefix' => $apiKey->keyPrefix,
            ],
        ]], $apiKey->infoLogs);
        self::assertSame(
            self::SENTINELS['credential-hash'],
            (new Query())
                ->select('keyHash')
                ->from('{{%searchmanager_api_keys}}')
                ->where(['id' => $apiKey->id])
                ->scalar(),
        );
        self::assertSame(
            self::SENTINELS['ciphertext'],
            (new Query())
                ->select('encryptedKey')
                ->from('{{%searchmanager_api_keys}}')
                ->where(['id' => $apiKey->id])
                ->scalar(),
        );
    }

    private function backend(string $handle): PersistenceFailureConfiguredBackend
    {
        $backend = new PersistenceFailureConfiguredBackend();
        $backend->name = 'persistence failure Backend';
        $backend->handle = $handle;
        $backend->backendType = $this->databaseBackendType();
        $backend->settings = [
            'password' => self::SENTINELS['plaintext-credential'],
            'value' => self::SENTINELS['json-settings'],
        ];

        return $backend;
    }

    private function apiKey(string $handle): PersistenceFailureApiKey
    {
        $apiKey = new PersistenceFailureApiKey();
        $apiKey->name = 'persistence failure API Key';
        $apiKey->handle = $handle;
        $apiKey->type = ApiKey::TYPE_PUBLIC;
        $apiKey->keyHash = self::SENTINELS['credential-hash'];
        $apiKey->encryptedKey = self::SENTINELS['ciphertext'];
        $apiKey->keyPrefix = 'sm_pub_' . substr(hash('sha256', $handle), 0, 8);
        $apiKey->allowedIndices = [ApiKey::ALL_INDICES];

        return $apiKey;
    }

    /**
     * @param array<int, mixed>|string $errorInfo
     */
    private function sentinelException(array|string $errorInfo): DbException
    {
        $message = implode(' ', [
            self::SENTINELS['exception-message'],
            'SQL=' . self::SENTINELS['interpolated-sql'],
            'bound=' . self::SENTINELS['bound-value'],
            'password=' . self::SENTINELS['plaintext-credential'],
            'hash=' . self::SENTINELS['credential-hash'],
            'ciphertext=' . self::SENTINELS['ciphertext'],
            'settings={"value":"' . self::SENTINELS['json-settings'] . '"}',
        ]);
        $exception = new DbException(
            $message,
            is_array($errorInfo) ? $errorInfo : [],
            is_array($errorInfo) && is_string($errorInfo[0] ?? null) ? $errorInfo[0] : null,
            new \RuntimeException(self::SENTINELS['previous-message']),
        );
        $exception->errorInfo = $errorInfo;

        return $exception;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return array{T, PersistenceFailureFailingWriteConnection}
     */
    private function withFailingWriteBoundary(DbException $exception, callable $callback): array
    {
        $database = Craft::$app->getDb();
        $boundary = new PersistenceFailureFailingWriteConnection($database, $exception);
        Craft::$app->set('db', $boundary);

        try {
            $result = $callback();
        } finally {
            Craft::$app->set('db', $database);
        }

        return [$result, $boundary];
    }

    private function assertNoSensitiveSentinels(string $logged): void
    {
        foreach (self::SENTINELS as $name => $sentinel) {
            self::assertStringNotContainsString($sentinel, $logged, "Logged $name sentinel.");
        }
        self::assertStringNotContainsString('__SM_PR153_MALFORMED_ERROR_INFO__', $logged);
    }

    private function insertBackend(string $handle): int
    {
        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        Craft::$app->getDb()->createCommand()->insert('{{%searchmanager_backends}}', [
            'name' => 'persistence failure Default Backend',
            'handle' => $handle,
            'backendType' => $this->databaseBackendType(),
            'settings' => json_encode(['password' => self::SENTINELS['plaintext-credential']]),
            'enabled' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function databaseBackendType(): string
    {
        return Craft::$app->getDb()->getDriverName() === 'pgsql' ? 'pgsql' : 'mysql';
    }

    private function setDefaultBackendHandle(string $handle): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%searchmanager_settings}}', ['defaultBackendHandle' => $handle], ['id' => 1])
            ->execute();
        SearchManager::$plugin->getSettings()->defaultBackendHandle = $handle;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return array{T, PersistenceFailureSettings}
     */
    private function withUnconfiguredDefaultSettings(string $handle, callable $callback): array
    {
        $settingsProperty = new \ReflectionProperty(Plugin::class, '_settings');
        $settingsProperty->setAccessible(true);
        $originalSettings = $settingsProperty->getValue(SearchManager::$plugin);
        $originalConfig = Craft::$app->getConfig();
        $settings = new PersistenceFailureSettings();
        $settings->setAttributes(Settings::loadFromDatabase()->getAttributes(), false);
        $settings->defaultBackendHandle = $handle;
        Craft::$app->set('config', new SearchManagerConfigServiceStub($originalConfig));
        $settingsProperty->setValue(SearchManager::$plugin, $settings);
        self::assertSame($settings, SearchManager::$plugin->getSettings());
        self::assertSame($handle, SearchManager::$plugin->getSettings()->defaultBackendHandle);
        self::assertFalse($settings->isOverriddenByConfig('defaultBackendHandle'));

        try {
            $result = $callback();
        } finally {
            $settingsProperty->setValue(SearchManager::$plugin, $originalSettings);
            Craft::$app->set('config', $originalConfig);
        }

        return [$result, $settings];
    }

    private function purgeFixtures(): void
    {
        $this->purgeRowsByMarker(
            '{{%searchmanager_api_keys}}',
            'handle',
            self::PREFIX,
        );
        $this->purgeRowsByMarker(
            '{{%searchmanager_backends}}',
            'handle',
            self::PREFIX,
        );
    }
}

/**
 * Captures Backend logs without writing synthetic credential fixtures to disk.
 *
 * @since 5.54.0
 */
final class PersistenceFailureConfiguredBackend extends ConfiguredBackend
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $errorLogs = [];

    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $infoLogs = [];

    protected function logError(string $message, array $params = []): void
    {
        $this->errorLogs[] = compact('message', 'params');
    }

    protected function logInfo(string $message, array $params = []): void
    {
        $this->infoLogs[] = compact('message', 'params');
    }
}

/**
 * Captures API-key logs without writing synthetic protected material to disk.
 *
 * @since 5.54.0
 */
final class PersistenceFailureApiKey extends ApiKey
{
    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $errorLogs = [];

    /**
     * @var list<array{message: string, params: array<string, mixed>}>
     */
    public array $infoLogs = [];

    protected function logError(string $message, array $params = []): void
    {
        $this->errorLogs[] = compact('message', 'params');
    }

    protected function logInfo(string $message, array $params = []): void
    {
        $this->infoLogs[] = compact('message', 'params');
    }
}

/**
 * Removes config override state from the default-transition rollback fixture.
 *
 * @since 5.54.0
 */
final class PersistenceFailureSettings extends Settings
{
    public function isOverriddenByConfig(string $attribute): bool
    {
        return false;
    }
}

/**
 * Delegates reads/transactions to the real database and fails only builder writes.
 *
 * @since 5.54.0
 */
final class PersistenceFailureFailingWriteConnection extends Connection
{
    public int $writeCommandRequests = 0;

    public function __construct(
        private readonly Connection $database,
        private readonly DbException $exception,
    ) {
    }

    public function getSchema()
    {
        return $this->database->getSchema();
    }

    public function getDriverName()
    {
        return $this->database->getDriverName();
    }

    public function beginTransaction($isolationLevel = null)
    {
        return $this->database->beginTransaction($isolationLevel);
    }

    public function createCommand($sql = null, $params = [])
    {
        if ($sql === null) {
            $this->writeCommandRequests++;
            throw $this->exception;
        }

        return $this->database->createCommand($sql, $params);
    }
}
