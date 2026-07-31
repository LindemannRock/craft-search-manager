<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use lindemannrock\searchmanager\models\ConfiguredBackend;
use lindemannrock\searchmanager\services\RedisNativeConnectionFactory;

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

    /**
     * Resolve the effective Redis connection for a configured backend.
     *
     * @return array<string, mixed>
     */
    public static function resolveForBackend(ConfiguredBackend $backend): array
    {
        $factory = self::factory();

        return $factory->compatibilityProjection($factory->resolveForBackend($backend));
    }

    /**
     * Resolve the effective Redis connection from raw backend settings.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function resolve(array $settings): array
    {
        $factory = self::factory();

        return $factory->compatibilityProjection($factory->resolve($settings));
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
        return self::factory()->databaseLabel($database, $craftDatabase);
    }

    /**
     * Resolve an environment-variable backed setting.
     */
    public static function resolveEnvValue(mixed $value, mixed $default): mixed
    {
        return self::factory()->resolveEnvValue($value, $default);
    }

    private static function factory(): RedisNativeConnectionFactory
    {
        return new RedisNativeConnectionFactory();
    }
}
