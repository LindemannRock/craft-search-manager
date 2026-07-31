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
use lindemannrock\searchmanager\helpers\RedisConnectionHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use lindemannrock\searchmanager\services\RedisNativeConnectionFactory;
use lindemannrock\searchmanager\tests\TestCase;
use yii\redis\Cache;
use yii\redis\Connection;

/**
 * Pins Redis backend connection/database resolution.
 *
 * @since 5.52.0
 */
final class RedisConnectionHelperTest extends TestCase
{
    private const ENV_HOST = 'SEARCH_MANAGER_REDIS_TEST_HOST';
    private const ENV_DATABASE = 'SEARCH_MANAGER_REDIS_TEST_DATABASE';

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

    public function testExplicitSettingsWinOverCraftRedisFallback(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'host' => 'redis.internal',
            'port' => 6380,
            'database' => 2,
            'password' => 'secret',
        ]);

        self::assertSame('redis.internal', $info['host']);
        self::assertSame(6380, $info['port']);
        self::assertSame(2, $info['database']);
        self::assertSame('DB 2', $info['databaseLabel']);
        self::assertSame(RedisConnectionHelper::SOURCE_EXPLICIT, $info['source']);
        self::assertFalse($info['usesCraftCache']);
        self::assertFalse($info['isAutoDatabase']);
        self::assertTrue($info['passwordConfigured']);
    }

    public function testEnvSettingsResolveForExplicitConnection(): void
    {
        putenv(self::ENV_HOST . '=redis.env');
        $_ENV[self::ENV_HOST] = 'redis.env';
        $_SERVER[self::ENV_HOST] = 'redis.env';
        putenv(self::ENV_DATABASE . '=7');
        $_ENV[self::ENV_DATABASE] = '7';
        $_SERVER[self::ENV_DATABASE] = '7';

        $info = RedisConnectionHelper::resolve([
            'host' => '$' . self::ENV_HOST,
            'database' => '$' . self::ENV_DATABASE,
        ]);

        self::assertSame('redis.env', $info['host']);
        self::assertSame(7, $info['database']);
        self::assertSame('DB 7', $info['databaseLabel']);
        self::assertSame(RedisConnectionHelper::SOURCE_EXPLICIT, $info['source']);
    }

    public function testExplicitHostWithoutDatabaseUsesCraftDatabasePlusOne(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'host' => 'redis2.internal',
            'port' => 6380,
            'password' => 'secret',
        ]);

        self::assertSame('redis2.internal', $info['host']);
        self::assertSame(6380, $info['port']);
        self::assertSame(6, $info['database']);
        self::assertSame(5, $info['craftDatabase']);
        self::assertSame('DB 6 (5 + 1)', $info['databaseLabel']);
        self::assertSame(RedisConnectionHelper::SOURCE_EXPLICIT, $info['source']);
        self::assertFalse($info['usesCraftCache']);
        self::assertTrue($info['isAutoDatabase']);
    }

    public function testMissingDatabaseEnvFailsClosedWithoutCraftFallback(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'host' => 'redis2.internal',
            'database' => '$' . self::ENV_DATABASE,
        ]);

        self::assertSame(0, $info['database']);
        self::assertSame('DB 0', $info['databaseLabel']);
        self::assertFalse($info['isConfigured']);
        self::assertFalse($info['usesCraftCache']);
    }

    public function testUnresolvedHostEnvIsNotReportedConfigured(): void
    {
        Craft::$app->set('cache', $this->originalCache);

        $info = RedisConnectionHelper::resolve([
            'host' => '$' . self::ENV_HOST,
        ]);

        self::assertNull($info['host']);
        self::assertFalse($info['isConfigured']);
        self::assertSame(RedisConnectionHelper::SOURCE_EXPLICIT, $info['source']);
    }

    public function testCraftRedisFallbackUsesCraftDatabasePlusOne(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([]);

        self::assertSame('craft-redis.local', $info['host']);
        self::assertSame(6379, $info['port']);
        self::assertSame(6, $info['database']);
        self::assertSame(5, $info['craftDatabase']);
        self::assertSame('DB 6 (5 + 1)', $info['databaseLabel']);
        self::assertSame(RedisConnectionHelper::SOURCE_CRAFT_CACHE_FALLBACK, $info['source']);
        self::assertTrue($info['usesCraftCache']);
        self::assertTrue($info['isAutoDatabase']);
        self::assertTrue($info['isConfigured']);
    }

    public function testExplicitDatabaseOverridesCraftRedisFallbackDatabaseOnly(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'database' => 9,
        ]);

        self::assertSame('craft-redis.local', $info['host']);
        self::assertSame(9, $info['database']);
        self::assertSame(5, $info['craftDatabase']);
        self::assertSame('DB 9', $info['databaseLabel']);
        self::assertSame(RedisConnectionHelper::SOURCE_CRAFT_CACHE_FALLBACK, $info['source']);
        self::assertTrue($info['usesCraftCache']);
        self::assertFalse($info['isAutoDatabase']);
    }

    public function testConfiguredBackendExposesRedisInfo(): void
    {
        $this->installCraftRedisCache(database: 3);

        $backend = new ConfiguredBackend();
        $backend->backendType = 'redis';
        $backend->settings = [];

        $backendInfo = $backend->getRedisConnectionInfo();
        self::assertSame(4, $backendInfo['database']);
    }

    public function testPresentationConnectionInfoNeverContainsRawPassword(): void
    {
        $backend = new ConfiguredBackend();
        $backend->backendType = 'redis';
        $backend->settings = [
            'host' => 'redis.internal',
            'port' => 6379,
            'password' => 'presentation-secret',
            'database' => 3,
        ];

        $info = $backend->getRedisConnectionInfo();

        self::assertIsArray($info);
        self::assertSame([
            'host',
            'port',
            'passwordConfigured',
            'database',
            'databaseLabel',
            'source',
            'craftDatabase',
            'isAutoDatabase',
            'isConfigured',
            'usesCraftCache',
        ], array_keys($info));
        self::assertArrayNotHasKey('password', $info);
        self::assertStringNotContainsString('presentation-secret', json_encode($info, JSON_THROW_ON_ERROR));
    }

    public function testUnresolvedDatabaseEnvironmentReferenceFailsClosed(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'host' => 'redis.internal',
            'database' => '$' . self::ENV_DATABASE,
        ]);

        self::assertFalse($info['isConfigured']);
        self::assertFalse($info['usesCraftCache']);
        self::assertNull($info['host']);
    }

    public function testInvalidSupportedPortDoesNotCastToAConnectionTarget(): void
    {
        $info = RedisConnectionHelper::resolve([
            'host' => 'redis.internal',
            'port' => ' 6379',
            'database' => 1,
        ]);

        self::assertFalse($info['isConfigured']);
        self::assertNull($info['host']);
    }

    public function testBackendSidebarRendersResolvedDatabase(): void
    {
        $template = file_get_contents(__DIR__ . '/../../src/templates/backends/edit.twig');
        self::assertIsString($template);
        self::assertStringContainsString('{% set redisConnectionInfo = backend.redisSafePresentation %}', $template);
        self::assertStringContainsString('redisConnectionInfo.databaseLabel', $template);
    }

    public function testUnsupportedCraftDerivedPresentationKeepsItsFactualSource(): void
    {
        Craft::$app->set('cache', new Cache([
            'redis' => new Connection([
                'hostname' => 'craft-redis.local',
                'password' => 'craft-presentation-secret',
                'dataTimeout' => 0,
            ]),
        ]));
        $backend = new ConfiguredBackend([
            'backendType' => 'redis',
            'settings' => [],
        ]);
        $connectionInfo = $backend->getRedisConnectionInfo();
        self::assertIsArray($connectionInfo);
        self::assertSame(RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK, $connectionInfo['source']);
        self::assertTrue($connectionInfo['usesCraftCache']);

        foreach ([
            SearchManager::$plugin->getRedisSafePresentation(),
            $backend->getRedisSafePresentation(),
        ] as $presentation) {
            self::assertIsArray($presentation);
            self::assertSame(RedisNativeConnectionFactory::SOURCE_CRAFT_CACHE_FALLBACK, $presentation['source']);
            self::assertSame(RedisNativeConnectionFactory::STATUS_UNSUPPORTED_CONFIGURATION, $presentation['status']);
            self::assertTrue($presentation['usesCraftCache']);
            self::assertNull($presentation['endpoint']);
            self::assertStringNotContainsString(
                'craft-presentation-secret',
                json_encode($presentation, JSON_THROW_ON_ERROR),
            );
        }

        $template = file_get_contents(__DIR__ . '/../../src/templates/backends/edit.twig');
        self::assertIsString($template);
        self::assertStringContainsString(
            "redisConnectionInfo.source == 'craft-cache-fallback' ? 'Derived from Craft Redis cache configuration' : 'Search Manager settings'",
            $template,
        );
    }

    public function testThreePublicPresentationMethodsExposeExactlyTheTenApprovedKeys(): void
    {
        $settings = [
            'host' => 'redis.internal',
            'port' => 6380,
            'password' => 'presentation-secret',
            'database' => 4,
        ];
        $backend = new ConfiguredBackend([
            'backendType' => 'redis',
            'settings' => $settings,
        ]);
        $index = new class($backend) extends SearchIndex {
            public function __construct(private readonly ConfiguredBackend $redisBackend)
            {
                parent::__construct();
            }

            public function getEffectiveBackendType(): ?string
            {
                return 'redis';
            }

            public function getConfiguredBackend(): ?ConfiguredBackend
            {
                return $this->redisBackend;
            }
        };
        $expectedKeys = [
            'host',
            'port',
            'passwordConfigured',
            'database',
            'databaseLabel',
            'source',
            'craftDatabase',
            'isAutoDatabase',
            'isConfigured',
            'usesCraftCache',
        ];

        $presentations = [
            SearchManager::$plugin->getRedisConnectionInfo($settings),
            $backend->getRedisConnectionInfo(),
            $index->getRedisConnectionInfo(),
        ];
        foreach ($presentations as $presentation) {
            self::assertIsArray($presentation);
            self::assertSame($expectedKeys, array_keys($presentation));
            self::assertTrue($presentation['passwordConfigured']);
            self::assertStringNotContainsString('presentation-secret', json_encode($presentation, JSON_THROW_ON_ERROR));
        }
    }

    private function installCraftRedisCache(int $database): void
    {
        $connection = new Connection([
            'hostname' => 'craft-redis.local',
            'port' => 6379,
            'database' => $database,
            'password' => 'craft-secret',
        ]);

        Craft::$app->set('cache', new Cache([
            'redis' => $connection,
        ]));
    }

    private function clearEnv(): void
    {
        putenv(self::ENV_HOST);
        unset($_ENV[self::ENV_HOST], $_SERVER[self::ENV_HOST]);
        putenv(self::ENV_DATABASE);
        unset($_ENV[self::ENV_DATABASE], $_SERVER[self::ENV_DATABASE]);
    }
}
