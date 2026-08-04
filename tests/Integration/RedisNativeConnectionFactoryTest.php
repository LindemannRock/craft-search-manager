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
use lindemannrock\searchmanager\services\RedisNativeConnectionFactory;
use lindemannrock\searchmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\redis\Cache;
use yii\redis\Connection;

/**
 * Pins Search Manager's independently owned native Redis connection authority.
 *
 * @since 5.54.0
 */
final class RedisNativeConnectionFactoryTest extends TestCase
{
    private const ENV_HOST = 'SEARCH_MANAGER_NATIVE_REDIS_HOST';

    private mixed $originalCache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCache = Craft::$app->getCache();
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        Craft::$app->set('cache', $this->originalCache);
        $this->clearEnv();

        parent::tearDown();
    }

    #[DataProvider('validScalarProvider')]
    public function testExplicitScalarNormalization(mixed $port, mixed $database, int $expectedPort, int $expectedDatabase): void
    {
        $configuration = (new RedisNativeConnectionFactory())->resolve([
            'host' => 'redis.internal',
            'port' => $port,
            'password' => '0',
            'database' => $database,
        ]);

        self::assertTrue($configuration->isSupported());
        self::assertSame($expectedPort, $configuration->port);
        self::assertSame($expectedDatabase, $configuration->database);
        self::assertSame('0', $configuration->password);
    }

    /**
     * @return iterable<string, array{mixed, mixed, int, int}>
     */
    public static function validScalarProvider(): iterable
    {
        yield 'integers' => [6380, 3, 6380, 3];
        yield 'digits-only strings' => ['6381', '4', 6381, 4];
    }

    #[DataProvider('invalidScalarProvider')]
    public function testInvalidSupportedScalarsFailClosed(string $field, mixed $value): void
    {
        $settings = [
            'host' => 'redis.internal',
            'port' => 6379,
            'database' => 1,
        ];
        $settings[$field] = $value;

        $configuration = (new RedisNativeConnectionFactory())->resolve($settings);

        self::assertTrue($configuration->isUnsupported());
        self::assertSame(RedisNativeConnectionFactory::SOURCE_EXPLICIT, $configuration->source);
        self::assertNull($configuration->host);
        self::assertNull($configuration->password);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidScalarProvider(): iterable
    {
        yield 'boolean port' => ['port', true];
        yield 'float port' => ['port', 6379.0];
        yield 'whitespace port' => ['port', ' 6379'];
        yield 'signed port' => ['port', '+6379'];
        yield 'zero port' => ['port', 0];
        yield 'port above range' => ['port', 65536];
        yield 'negative database' => ['database', -1];
        yield 'signed database' => ['database', '+1'];
        yield 'overflow database' => ['database', str_repeat('9', 40)];
    }

    public function testUnresolvedEnvironmentValuesNeverFallBack(): void
    {
        $this->installCraftRedisCache(new Connection([
            'hostname' => 'craft-redis.internal',
            'database' => 5,
        ]));

        foreach (['host', 'port', 'password', 'database'] as $field) {
            $configuration = (new RedisNativeConnectionFactory())->resolve([
                'host' => $field === 'host' ? '$' . self::ENV_HOST : 'redis.internal',
                $field => '$' . self::ENV_HOST,
            ]);

            self::assertTrue($configuration->isUnsupported(), $field);
            self::assertSame(RedisNativeConnectionFactory::SOURCE_EXPLICIT, $configuration->source, $field);
            self::assertFalse($configuration->usesCraftCache, $field);
            self::assertNull($configuration->host, $field);
            self::assertNull($configuration->password, $field);
        }
    }

    public function testPortOrPasswordWithoutHostIsUnsupportedButDatabaseOnlyOverrideRemainsSupported(): void
    {
        $this->installCraftRedisCache(new Connection([
            'hostname' => 'craft-redis.internal',
            'database' => 5,
        ]));
        $factory = new RedisNativeConnectionFactory();

        self::assertTrue($factory->resolve(['port' => 6380])->isUnsupported());
        self::assertTrue($factory->resolve(['password' => 'secret'])->isUnsupported());

        $databaseOnly = $factory->resolve(['database' => 9]);
        self::assertTrue($databaseOnly->isSupported());
        self::assertSame(9, $databaseOnly->database);
        self::assertTrue($databaseOnly->usesCraftCache);
    }

    public function testExplicitHostWithoutDatabaseDerivesOnlyTheCraftDatabase(): void
    {
        $source = new Connection([
            'hostname' => 'craft-redis.internal',
            'password' => 'craft-secret',
            'database' => '5',
        ]);
        $this->installCraftRedisCache($source);

        $configuration = (new RedisNativeConnectionFactory())->resolve([
            'host' => 'search-redis.internal',
            'password' => 'search-secret',
        ]);

        self::assertSame('search-redis.internal', $configuration->host);
        self::assertSame('search-secret', $configuration->password);
        self::assertSame(6, $configuration->database);
        self::assertSame(5, $configuration->craftDatabase);
        self::assertFalse($configuration->usesCraftCache);
        self::assertFalse($source->isActive);
    }

    public function testCraftNullDatabaseUsesSearchDatabaseOneAndOverflowFailsClosed(): void
    {
        $this->installCraftRedisCache(new Connection([
            'hostname' => 'craft-redis.internal',
            'database' => null,
        ]));
        self::assertSame(1, (new RedisNativeConnectionFactory())->resolve([])->database);

        $this->installCraftRedisCache(new Connection([
            'hostname' => 'craft-redis.internal',
            'database' => PHP_INT_MAX,
        ]));
        self::assertTrue((new RedisNativeConnectionFactory())->resolve([])->isUnsupported());
    }

    public function testCraftTlsAclTimeoutMappingPreservesSourceState(): void
    {
        $source = new Connection([
            'hostname' => 'secure.redis.internal',
            'scheme' => 'tls',
            'port' => 6380,
            'username' => 'acl-user',
            'password' => 'acl-secret',
            'database' => 5,
            'connectionTimeout' => 1.25,
            'dataTimeout' => 2.5,
            'useSSL' => true,
            'contextOptions' => ['ssl' => ['verify_peer' => true, 'peer_name' => 'secure.redis.internal']],
            'socketClientFlags' => STREAM_CLIENT_PERSISTENT,
            'retries' => 4,
            'retryInterval' => 25000,
            'redisCommands' => ['PING'],
        ]);
        $this->installCraftRedisCache($source);

        $configuration = (new RedisNativeConnectionFactory())->resolve([]);

        self::assertTrue($configuration->isSupported());
        self::assertSame('tls', $configuration->transport);
        self::assertSame('secure.redis.internal', $configuration->host);
        self::assertSame(6380, $configuration->port);
        self::assertSame('acl-user', $configuration->username);
        self::assertSame('acl-secret', $configuration->password);
        self::assertSame(6, $configuration->database);
        self::assertSame(1.25, $configuration->connectionTimeout);
        self::assertSame(2.5, $configuration->readTimeout);
        self::assertSame([
            'stream' => ['verify_peer' => true, 'peer_name' => 'secure.redis.internal'],
        ], $configuration->context);
        self::assertFalse($source->isActive);
        self::assertSame(STREAM_CLIENT_PERSISTENT, $source->socketClientFlags);
        self::assertSame(4, $source->retries);
        self::assertSame(25000, $source->retryInterval);
        self::assertSame(['PING'], $source->redisCommands);
    }

    public function testCraftUnixSocketMapsToNativePortZero(): void
    {
        $source = new Connection([
            'unixSocket' => '/run/redis/search.sock',
            'database' => null,
        ]);
        $this->installCraftRedisCache($source);

        $configuration = (new RedisNativeConnectionFactory())->resolve([]);

        self::assertTrue($configuration->isSupported());
        self::assertSame('unix', $configuration->transport);
        self::assertSame('/run/redis/search.sock', $configuration->host);
        self::assertSame(0, $configuration->port);
        self::assertSame(1, $configuration->database);
        self::assertFalse($source->isActive);
    }

    public function testTlsUseSslAndUnsupportedTransportCombinationsFailClosedWithoutDowngrade(): void
    {
        $this->installCraftRedisCache(new Connection([
            'hostname' => 'secure.redis.internal',
            'useSSL' => true,
            'contextOptions' => ['ssl' => ['verify_peer' => true]],
        ]));
        self::assertSame('tls', (new RedisNativeConnectionFactory())->resolve([])->transport);

        $unsupportedConnections = [
            new Connection([
                'hostname' => 'redis.internal',
                'scheme' => 'udp',
            ]),
            new Connection([
                'hostname' => 'redis.internal',
                'contextOptions' => ['ssl' => ['verify_peer' => true]],
            ]),
            new Connection([
                'unixSocket' => '/run/redis/search.sock',
                'useSSL' => true,
            ]),
        ];

        foreach ($unsupportedConnections as $connection) {
            $this->installCraftRedisCache($connection);
            $configuration = (new RedisNativeConnectionFactory())->resolve([]);
            self::assertTrue($configuration->isUnsupported());
            self::assertSame(RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK, $configuration->source);
            self::assertTrue($configuration->usesCraftCache);
            self::assertNull($configuration->host);
        }
    }

    public function testCraftConnectionTimeoutZeroUsesDefaultWhileDataTimeoutZeroRemainsUnsupported(): void
    {
        $this->installCraftRedisCache(new Connection([
            'hostname' => 'redis.internal',
            'connectionTimeout' => 0,
            'dataTimeout' => null,
        ]));

        $connectionDefault = (new RedisNativeConnectionFactory())->resolve([]);

        self::assertTrue($connectionDefault->isSupported());
        self::assertNull($connectionDefault->connectionTimeout);
        self::assertNull($connectionDefault->readTimeout);

        $this->installCraftRedisCache(new Connection([
            'hostname' => 'redis.internal',
            'connectionTimeout' => null,
            'dataTimeout' => 0,
        ]));

        $unsupportedReadTimeout = (new RedisNativeConnectionFactory())->resolve([]);

        self::assertTrue($unsupportedReadTimeout->isUnsupported());
        self::assertSame(
            RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK,
            $unsupportedReadTimeout->source,
        );
        self::assertTrue($unsupportedReadTimeout->usesCraftCache);
    }

    public function testUnsupportedResolutionPreservesSourceWithoutRejectedValuesOrSecrets(): void
    {
        $factory = new RedisNativeConnectionFactory();
        $explicit = $factory->resolve([
            'host' => 'redis.internal',
            'port' => ' 6379',
            'password' => 'rejected-explicit-secret',
        ]);

        self::assertTrue($explicit->isUnsupported());
        self::assertSame(RedisNativeConnectionFactory::SOURCE_EXPLICIT, $explicit->source);
        self::assertFalse($explicit->usesCraftCache);
        self::assertNull($explicit->host);
        self::assertNull($explicit->password);
        self::assertStringNotContainsString(
            'rejected-explicit-secret',
            json_encode($factory->safePresentation($explicit), JSON_THROW_ON_ERROR),
        );

        $this->installCraftRedisCache(new Connection([
            'hostname' => 'craft-redis.internal',
            'dataTimeout' => 0,
        ]));
        $craftDerived = $factory->resolve([]);

        self::assertTrue($craftDerived->isUnsupported());
        self::assertSame(RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK, $craftDerived->source);
        self::assertTrue($craftDerived->usesCraftCache);
        self::assertNull($craftDerived->host);
        self::assertNull($craftDerived->password);
    }

    public function testUnsupportedCraftConnectionInterfaceFailsClosed(): void
    {
        $cache = new Cache(['redis' => new UnsupportedRedisConnection()]);
        Craft::$app->set('cache', $cache);

        $configuration = (new RedisNativeConnectionFactory())->resolve([]);

        self::assertTrue($configuration->isUnsupported());
        self::assertSame(RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK, $configuration->source);
        self::assertTrue($configuration->usesCraftCache);
    }

    public function testConnectionChecksEveryStageAndAuthenticatesPasswordZero(): void
    {
        $client = new RecordingNativeRedis();
        $factory = new TestRedisNativeConnectionFactory([$client]);
        $configuration = $factory->resolve([
            'host' => 'redis.internal',
            'port' => 6379,
            'password' => '0',
            'database' => 3,
        ]);

        self::assertSame($client, $factory->connect($configuration));
        self::assertSame([
            ['connect', 'redis.internal', 6379, 0.0, null, 0, 0.0, null],
            ['auth', '0'],
            ['select', 3],
        ], $client->calls);
        self::assertSame(0, $client->pconnectCalls);
    }

    #[DataProvider('connectionFailureProvider')]
    public function testConnectionFailuresClosePartialClientAndExposeOnlyFixedClassification(
        string $stage,
        bool $throw,
        string $expectedClassification,
    ): void {
        $client = new RecordingNativeRedis();
        $client->failureStage = $stage;
        $client->throwOnFailure = $throw;
        $factory = new TestRedisNativeConnectionFactory([$client]);
        $configuration = $factory->resolve([
            'host' => 'redis.internal',
            'password' => 'secret',
            'database' => 3,
        ]);

        try {
            $factory->connect($configuration);
            self::fail('Expected fixed Redis connection failure.');
        } catch (\Throwable $exception) {
            self::assertSame($expectedClassification, $exception->getMessage());
            self::assertStringNotContainsString(RecordingNativeRedis::INJECTED_SECRET, $exception->getMessage());
        }

        self::assertSame(1, $client->closeCalls);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function connectionFailureProvider(): iterable
    {
        foreach ([false, true] as $throw) {
            $suffix = $throw ? ' exception' : ' false';
            yield 'connect' . $suffix => ['connect', $throw, 'connection-failed'];
            yield 'auth' . $suffix => ['auth', $throw, 'authentication-failed'];
            yield 'select' . $suffix => ['select', $throw, 'database-selection-failed'];
        }
    }

    #[DataProvider('pingFailureProvider')]
    public function testPingStatusIsValidatedAndTemporaryClientAlwaysCloses(mixed $result, bool $throw): void
    {
        $client = new RecordingNativeRedis();
        $client->pingResult = $result;
        $client->throwOnPing = $throw;
        $factory = new TestRedisNativeConnectionFactory([$client]);
        $configuration = $factory->resolve([
            'host' => 'redis.internal',
            'database' => 3,
        ]);

        self::assertSame('ping-failed', $factory->probe($configuration));
        self::assertSame(1, $client->closeCalls);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function pingFailureProvider(): iterable
    {
        yield 'false' => [false, false];
        yield 'invalid text' => ['not-pong', false];
        yield 'exception' => [null, true];
    }

    public function testSafePresentationAndTargetIdentityNeverExposeSecrets(): void
    {
        $factory = new RedisNativeConnectionFactory();
        $first = $factory->resolve([
            'host' => 'redis.internal',
            'password' => 'first-secret',
            'database' => 3,
        ]);
        $same = $factory->resolve([
            'host' => 'redis.internal',
            'password' => 'first-secret',
            'database' => '3',
        ]);
        $different = $factory->resolve([
            'host' => 'redis.internal',
            'password' => 'second-secret',
            'database' => 3,
        ]);

        self::assertSame($first->targetIdentity(), $same->targetIdentity());
        self::assertNotSame($first->targetIdentity(), $different->targetIdentity());

        $presentation = $factory->safePresentation($first, 'connected');
        $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('first-secret', $encoded);
        self::assertStringNotContainsString($first->targetIdentity(), $encoded);
        self::assertArrayNotHasKey('username', $presentation);
        self::assertArrayNotHasKey('password', $presentation);
        self::assertSame('password', $presentation['authenticationMode']);
        self::assertSame('connected', $presentation['status']);
    }

    public function testNativeConstructionPolicyExistsOnlyInTheSharedAuthority(): void
    {
        $sourceRoot = dirname(__DIR__, 2) . '/src';
        $nativeConstructionFiles = [];
        $legacyStorageSettingsCallers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            if (str_contains($source, 'new \\Redis(')) {
                $nativeConstructionFiles[] = $file->getFilename();
            }
            if ($file->getFilename() !== 'RedisConnectionHelper.php' && str_contains($source, 'storageSettings(')) {
                $legacyStorageSettingsCallers[] = $file->getPathname();
            }
        }

        self::assertSame(['RedisNativeConnectionFactory.php'], $nativeConstructionFiles);
        self::assertSame([], $legacyStorageSettingsCallers);
    }

    private function installCraftRedisCache(Connection $connection): void
    {
        Craft::$app->set('cache', new Cache(['redis' => $connection]));
    }

    private function clearEnv(): void
    {
        putenv(self::ENV_HOST);
        unset($_ENV[self::ENV_HOST], $_SERVER[self::ENV_HOST]);
    }
}

final class TestRedisNativeConnectionFactory extends RedisNativeConnectionFactory
{
    /** @param list<\Redis> $clients */
    public function __construct(private array $clients)
    {
        parent::__construct();
    }

    protected function createClient(): \Redis
    {
        $client = array_shift($this->clients);
        if (!$client instanceof \Redis) {
            throw new \LogicException('No native Redis test client is available.');
        }

        return $client;
    }
}

final class RecordingNativeRedis extends \Redis
{
    public const INJECTED_SECRET = 'provider-secret-injected-error';

    /** @var list<array<int, mixed>> */
    public array $calls = [];
    public ?string $failureStage = null;
    public bool $throwOnFailure = false;
    public mixed $pingResult = true;
    public bool $throwOnPing = false;
    public int $closeCalls = 0;
    public int $pconnectCalls = 0;

    public function connect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null,
    ): bool {
        $this->calls[] = ['connect', $host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout, $context];
        return $this->stageResult('connect');
    }

    public function pconnect(
        string $host,
        int $port = 6379,
        float $timeout = 0,
        ?string $persistent_id = null,
        int $retry_interval = 0,
        float $read_timeout = 0,
        ?array $context = null,
    ): bool {
        $this->pconnectCalls++;
        return true;
    }

    public function auth(mixed $credentials): \Redis|bool
    {
        $this->calls[] = ['auth', $credentials];
        return $this->stageResult('auth');
    }

    public function select(int $db): \Redis|bool
    {
        $this->calls[] = ['select', $db];
        return $this->stageResult('select');
    }

    public function ping(?string $message = null): \Redis|string|bool
    {
        $this->calls[] = ['ping'];
        if ($this->throwOnPing) {
            throw new \RedisException(self::INJECTED_SECRET);
        }
        return $this->pingResult;
    }

    public function close(): bool
    {
        $this->calls[] = ['close'];
        $this->closeCalls++;
        return true;
    }

    private function stageResult(string $stage): bool
    {
        if ($this->failureStage !== $stage) {
            return true;
        }
        if ($this->throwOnFailure) {
            throw new \RedisException(self::INJECTED_SECRET);
        }
        return false;
    }
}

final class UnsupportedRedisConnection implements \yii\redis\ConnectionInterface
{
    public function open(): void
    {
    }
    public function close(): void
    {
    }
    public function getIsActive(): bool
    {
        return false;
    }
    public function executeCommand(string $name, array $params = []): mixed
    {
        return null;
    }
}
