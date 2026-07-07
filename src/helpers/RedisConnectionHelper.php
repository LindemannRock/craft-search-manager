<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use Craft;
use craft\helpers\App;
use lindemannrock\searchmanager\models\ConfiguredBackend;

/**
 * Resolves Redis backend connection settings consistently across runtime and CP surfaces.
 *
 * @since 5.52.0
 */
class RedisConnectionHelper
{
    public const SOURCE_EXPLICIT = 'explicit';
    public const SOURCE_CRAFT_CACHE_FALLBACK = 'craft-cache-fallback';
    public const SOURCE_DEFAULT = 'default';

    private const SEARCH_DATABASE_OFFSET = 1;

    /**
     * Resolve the effective Redis connection for a configured backend.
     *
     * @return array<string, mixed>
     */
    public static function resolveForBackend(ConfiguredBackend $backend): array
    {
        return self::resolve($backend->settings ?? []);
    }

    /**
     * Resolve the effective Redis connection from raw backend settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function resolve(array $settings): array
    {
        $configuredHost = self::resolveEnvValue($settings['host'] ?? null, null);
        $configuredDatabase = self::resolveEnvValue($settings['database'] ?? null, null);
        $hasExplicitDatabase = $configuredDatabase !== null && $configuredDatabase !== '';
        $craftDatabase = self::craftRedisDatabase();
        $autoDatabase = $craftDatabase !== null
            ? $craftDatabase + self::SEARCH_DATABASE_OFFSET
            : 0;

        if (!empty($configuredHost)) {
            $database = $hasExplicitDatabase ? (int) $configuredDatabase : $autoDatabase;

            return [
                'host' => $configuredHost,
                'port' => (int) self::resolveEnvValue($settings['port'] ?? null, 6379),
                'password' => self::resolveEnvValue($settings['password'] ?? null, null),
                'passwordConfigured' => self::resolveEnvValue($settings['password'] ?? null, null) !== null,
                'database' => $database,
                'databaseLabel' => self::databaseLabel($database, $hasExplicitDatabase ? null : $craftDatabase),
                'source' => self::SOURCE_EXPLICIT,
                'craftDatabase' => $craftDatabase,
                'isAutoDatabase' => !$hasExplicitDatabase,
                'isConfigured' => true,
                'usesCraftCache' => false,
            ];
        }

        if (Craft::$app->cache instanceof \yii\redis\Cache) {
            $redisConnection = Craft::$app->cache->redis;
            $database = $hasExplicitDatabase
                ? (int) $configuredDatabase
                : $autoDatabase;

            return [
                'host' => $redisConnection->hostname ?? 'localhost',
                'port' => (int) ($redisConnection->port ?? 6379),
                'password' => $redisConnection->password ?? null,
                'passwordConfigured' => ($redisConnection->password ?? null) !== null,
                'database' => $database,
                'databaseLabel' => self::databaseLabel($database, $hasExplicitDatabase ? null : $craftDatabase),
                'source' => self::SOURCE_CRAFT_CACHE_FALLBACK,
                'craftDatabase' => $craftDatabase,
                'isAutoDatabase' => !$hasExplicitDatabase,
                'isConfigured' => true,
                'usesCraftCache' => true,
            ];
        }

        $database = $hasExplicitDatabase ? (int) $configuredDatabase : $autoDatabase;

        return [
            'host' => self::resolveEnvValue($settings['host'] ?? null, null),
            'port' => (int) self::resolveEnvValue($settings['port'] ?? null, 6379),
            'password' => self::resolveEnvValue($settings['password'] ?? null, null),
            'passwordConfigured' => self::resolveEnvValue($settings['password'] ?? null, null) !== null,
            'database' => $database,
            'databaseLabel' => self::databaseLabel($database),
            'source' => self::SOURCE_DEFAULT,
            'craftDatabase' => null,
            'isAutoDatabase' => !$hasExplicitDatabase,
            'isConfigured' => !empty($configuredHost),
            'usesCraftCache' => false,
        ];
    }

    /**
     * Return resolved settings in the shape expected by RedisStorage.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function storageSettings(array $settings): array
    {
        $resolved = self::resolve($settings);

        return [
            'host' => $resolved['host'],
            'port' => $resolved['port'],
            'password' => $resolved['password'],
            'database' => $resolved['database'],
        ];
    }

    /**
     * Return a compact technical display value, e.g. `DB 6 (5 + 1)`.
     */
    public static function databaseLabel(int $database, ?int $craftDatabase = null): string
    {
        $label = 'DB ' . $database;

        if ($craftDatabase !== null) {
            $label .= ' (' . $craftDatabase . ' + 1)';
        }

        return $label;
    }

    /**
     * Return Craft's Redis cache database when Craft cache uses Redis.
     */
    private static function craftRedisDatabase(): ?int
    {
        if (!Craft::$app->cache instanceof \yii\redis\Cache) {
            return null;
        }

        return (int) (Craft::$app->cache->redis->database ?? 0);
    }

    /**
     * Resolve an environment-variable backed setting.
     */
    public static function resolveEnvValue(mixed $value, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            return App::env(ltrim($value, '$')) ?? $default;
        }

        return $value;
    }
}
