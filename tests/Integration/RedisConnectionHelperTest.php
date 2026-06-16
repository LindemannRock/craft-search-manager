<?php
/**
 * LindemannRock Search Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Integration;

use Craft;
use lindemannrock\searchmanager\helpers\RedisConnectionHelper;
use lindemannrock\searchmanager\models\ConfiguredBackend;
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

    public function testMissingDatabaseEnvUsesCraftDatabasePlusOne(): void
    {
        $this->installCraftRedisCache(database: 5);

        $info = RedisConnectionHelper::resolve([
            'host' => 'redis2.internal',
            'database' => '$' . self::ENV_DATABASE,
        ]);

        self::assertSame(6, $info['database']);
        self::assertSame('DB 6 (5 + 1)', $info['databaseLabel']);
        self::assertTrue($info['isAutoDatabase']);
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

    public function testBackendSidebarRendersResolvedDatabase(): void
    {
        $template = file_get_contents(__DIR__ . '/../../src/templates/backends/edit.twig');
        self::assertIsString($template);
        self::assertStringContainsString('{% set redisConnectionInfo = backend.redisConnectionInfo %}', $template);
        self::assertStringContainsString('{{ redisConnectionInfo.databaseLabel }}', $template);
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
