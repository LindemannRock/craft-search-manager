<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use lindemannrock\searchmanager\models\ConfiguredBackend;
use yii\redis\Cache;
use yii\redis\Connection;

/**
 * Owns Search Manager's normalized native phpredis connection policy.
 *
 * @internal
 * @since 5.54.0
 */
class RedisNativeConnectionFactory extends Component
{
    public const RESOLUTION_SUPPORTED = 'supported';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_EXTENSION_UNAVAILABLE = 'extension-unavailable';
    public const STATUS_NOT_CONFIGURED = 'not-configured';
    public const STATUS_UNSUPPORTED_CONFIGURATION = 'unsupported-configuration';
    public const STATUS_CONNECTION_FAILED = 'connection-failed';
    public const STATUS_AUTHENTICATION_FAILED = 'authentication-failed';
    public const STATUS_DATABASE_SELECTION_FAILED = 'database-selection-failed';
    public const STATUS_PING_FAILED = 'ping-failed';

    public const SOURCE_EXPLICIT = 'explicit';
    public const SOURCE_CRAFT_CACHE_FALLBACK = 'craft-cache-fallback';
    public const SOURCE_DEFAULT = 'default';

    private const SEARCH_DATABASE_OFFSET = 1;

    /**
     * @param array<string, mixed> $settings
     */
    public function resolve(array $settings): RedisConnectionConfiguration
    {
        $hostValue = $this->resolveSetting($settings['host'] ?? null);
        $portValue = $this->resolveSetting($settings['port'] ?? null);
        $passwordValue = $this->resolveSetting($settings['password'] ?? null, true);
        $databaseValue = $this->resolveSetting($settings['database'] ?? null);

        if (!$hostValue['valid'] || !$portValue['valid'] || !$passwordValue['valid'] || !$databaseValue['valid']) {
            return $this->unsupported(self::SOURCE_EXPLICIT);
        }

        $host = $this->normalizeHost($hostValue['value']);
        if ($hostValue['present'] && $host === null) {
            return $this->unsupported(self::SOURCE_EXPLICIT);
        }

        $password = $this->normalizePassword($passwordValue['value']);
        if ($passwordValue['present'] && $passwordValue['value'] !== '' && $password === null) {
            return $this->unsupported(self::SOURCE_EXPLICIT);
        }

        $hasExplicitHost = $host !== null;
        if (!$hasExplicitHost && ($portValue['present'] || ($password !== null && $password !== ''))) {
            return $this->unsupported(self::SOURCE_EXPLICIT);
        }

        $hasExplicitDatabase = $databaseValue['present'];
        $explicitDatabase = null;
        if ($hasExplicitDatabase) {
            $explicitDatabase = $this->normalizeInteger($databaseValue['value'], 0, PHP_INT_MAX);
            if ($explicitDatabase === null) {
                return $this->unsupported(self::SOURCE_EXPLICIT);
            }
        }

        $cache = Craft::$app->getCache();
        $craftCache = $cache instanceof Cache ? $cache : null;
        $craftConnection = $craftCache?->redis;
        $standardCraftConnection = $craftConnection instanceof Connection ? $craftConnection : null;

        if (!$hasExplicitHost && $craftCache !== null && $standardCraftConnection === null) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $craftDatabase = null;
        if ($standardCraftConnection !== null) {
            $craftDatabase = $this->normalizeNullableDatabase($standardCraftConnection->database);
            if ($craftDatabase === false) {
                return $this->unsupported(
                    $hasExplicitHost ? self::SOURCE_EXPLICIT : self::SOURCE_CRAFT_CACHE_FALLBACK,
                );
            }
        }

        if ($hasExplicitHost) {
            if (!$hasExplicitDatabase && $craftCache !== null && $standardCraftConnection === null) {
                return $this->unsupported(self::SOURCE_EXPLICIT);
            }

            $port = $portValue['present']
                ? $this->normalizeInteger($portValue['value'], 1, 65535)
                : 6379;
            if ($port === null) {
                return $this->unsupported(self::SOURCE_EXPLICIT);
            }

            $database = $hasExplicitDatabase
                ? $explicitDatabase
                : $this->automaticDatabase($standardCraftConnection, $craftDatabase);
            if ($database === null) {
                return $this->unsupported(self::SOURCE_EXPLICIT);
            }

            return new RedisConnectionConfiguration(
                self::RESOLUTION_SUPPORTED,
                self::SOURCE_EXPLICIT,
                'tcp',
                $host,
                $port,
                null,
                $password,
                $database,
                is_int($craftDatabase) ? $craftDatabase : null,
                !$hasExplicitDatabase,
                false,
            );
        }

        if ($standardCraftConnection === null) {
            if ($hasExplicitDatabase) {
                return $this->notConfigured($explicitDatabase, false);
            }

            return $this->notConfigured(0, true);
        }

        return $this->resolveCraftConnection(
            $standardCraftConnection,
            $hasExplicitDatabase ? $explicitDatabase : null,
            !$hasExplicitDatabase,
            is_int($craftDatabase) ? $craftDatabase : null,
        );
    }

    public function resolveForBackend(ConfiguredBackend $backend): RedisConnectionConfiguration
    {
        return $this->resolve($backend->settings ?? []);
    }

    public function connect(RedisConnectionConfiguration $configuration): \Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new RedisConnectionException(self::STATUS_EXTENSION_UNAVAILABLE);
        }
        if (!$configuration->isConfigured()) {
            throw new RedisConnectionException(
                $configuration->isUnsupported()
                    ? self::STATUS_UNSUPPORTED_CONFIGURATION
                    : self::STATUS_NOT_CONFIGURED,
            );
        }

        $client = $this->createClient();
        try {
            $endpoint = $configuration->transport === 'tls'
                ? 'tls://' . $configuration->host
                : (string)$configuration->host;
            $connected = $client->connect(
                $endpoint,
                $configuration->port,
                $configuration->connectionTimeout ?? 0.0,
                null,
                0,
                $configuration->readTimeout ?? 0.0,
                $configuration->context,
            );
            if ($connected === false) {
                throw new RedisConnectionException(self::STATUS_CONNECTION_FAILED);
            }
        } catch (RedisConnectionException $exception) {
            $this->close($client);
            throw $exception;
        } catch (\Throwable) {
            $this->close($client);
            throw new RedisConnectionException(self::STATUS_CONNECTION_FAILED);
        }

        try {
            if ($configuration->username !== null) {
                if ($client->auth([$configuration->username, (string)$configuration->password]) === false) {
                    throw new RedisConnectionException(self::STATUS_AUTHENTICATION_FAILED);
                }
            } elseif ($configuration->password !== null && $configuration->password !== '') {
                if ($client->auth($configuration->password) === false) {
                    throw new RedisConnectionException(self::STATUS_AUTHENTICATION_FAILED);
                }
            }
        } catch (RedisConnectionException $exception) {
            $this->close($client);
            throw $exception;
        } catch (\Throwable) {
            $this->close($client);
            throw new RedisConnectionException(self::STATUS_AUTHENTICATION_FAILED);
        }

        try {
            if ($client->select((int)$configuration->database) === false) {
                throw new RedisConnectionException(self::STATUS_DATABASE_SELECTION_FAILED);
            }
        } catch (RedisConnectionException $exception) {
            $this->close($client);
            throw $exception;
        } catch (\Throwable) {
            $this->close($client);
            throw new RedisConnectionException(self::STATUS_DATABASE_SELECTION_FAILED);
        }

        return $client;
    }

    public function probe(RedisConnectionConfiguration $configuration): string
    {
        $client = null;
        try {
            $client = $this->connect($configuration);
            $ping = $client->ping();
            if (!$this->validPing($ping)) {
                return self::STATUS_PING_FAILED;
            }

            return self::STATUS_CONNECTED;
        } catch (RedisConnectionException $exception) {
            return $exception->getMessage();
        } catch (\Throwable) {
            return self::STATUS_PING_FAILED;
        } finally {
            if ($client instanceof \Redis) {
                $this->close($client);
            }
        }
    }

    public function close(?\Redis $client): void
    {
        if ($client === null) {
            return;
        }

        try {
            $client->close();
        } catch (\Throwable) {
            // Closing is best effort and must never hide the primary outcome.
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function compatibilityProjection(
        RedisConnectionConfiguration $configuration,
        bool $includePassword = true,
    ): array {
        $configured = $configuration->isConfigured();
        $database = $configured ? (int)$configuration->database : 0;
        $projection = [
            'host' => $configured ? $configuration->host : null,
            'port' => $configured ? $configuration->port : 6379,
            'passwordConfigured' => $configured && $configuration->password !== null && $configuration->password !== '',
            'database' => $database,
            'databaseLabel' => $this->databaseLabel(
                $database,
                $configured && $configuration->isAutoDatabase ? $configuration->craftDatabase : null,
            ),
            'source' => $configuration->source,
            'craftDatabase' => $configured ? $configuration->craftDatabase : null,
            'isAutoDatabase' => $configured && $configuration->isAutoDatabase,
            'isConfigured' => $configured,
            'usesCraftCache' => $configuration->usesCraftCache,
        ];

        if ($includePassword) {
            $projection = array_merge(array_slice($projection, 0, 2, true), [
                'password' => $configured ? $configuration->password : null,
            ], array_slice($projection, 2, null, true));
        }

        return $projection;
    }

    /**
     * @return array<string, mixed>
     */
    public function safePresentation(
        RedisConnectionConfiguration $configuration,
        ?string $status = null,
    ): array {
        return [
            'source' => $configuration->source,
            'transport' => $configuration->transport,
            'endpoint' => $configuration->endpointLabel(),
            'host' => $configuration->host,
            'port' => $configuration->port,
            'database' => $configuration->database,
            'databaseLabel' => $configuration->database === null
                ? null
                : $this->databaseLabel(
                    $configuration->database,
                    $configuration->isAutoDatabase ? $configuration->craftDatabase : null,
                ),
            'authenticationMode' => $configuration->authenticationMode(),
            'connectionTimeout' => $configuration->connectionTimeout,
            'readTimeout' => $configuration->readTimeout,
            'status' => $status ?? $configuration->resolutionStatus,
            'isConfigured' => $configuration->isConfigured(),
            'usesCraftCache' => $configuration->usesCraftCache,
            'craftDatabase' => $configuration->craftDatabase,
            'isAutoDatabase' => $configuration->isAutoDatabase,
        ];
    }

    public function databaseLabel(int $database, ?int $craftDatabase = null): string
    {
        $label = 'DB ' . $database;
        if ($craftDatabase !== null) {
            $label .= ' (' . $craftDatabase . ' + 1)';
        }

        return $label;
    }

    public function resolveEnvValue(mixed $value, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            return App::env(ltrim($value, '$')) ?? $default;
        }

        return $value;
    }

    protected function createClient(): \Redis
    {
        return new \Redis();
    }

    private function resolveCraftConnection(
        Connection $connection,
        ?int $explicitDatabase,
        bool $autoDatabase,
        ?int $craftDatabase,
    ): RedisConnectionConfiguration {
        $database = $autoDatabase
            ? $this->automaticDatabase($connection, $craftDatabase)
            : $explicitDatabase;
        if ($database === null) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $username = $this->normalizeOptionalString($connection->username);
        $password = $this->normalizePassword($connection->password);
        if (($connection->username !== null && $connection->username !== '' && $username === null)
            || ($connection->password !== null && $connection->password !== '' && $password === null)
            || ($username !== null && ($password === null || $password === ''))
        ) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $connectionTimeout = $this->normalizeTimeout($connection->connectionTimeout, true);
        $readTimeout = $this->normalizeTimeout($connection->dataTimeout);
        if ($connectionTimeout === false || $readTimeout === false) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $scheme = strtolower((string)$connection->scheme);
        $contextOptions = $connection->contextOptions;
        if (!is_array($contextOptions)) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $unixSocket = $this->normalizeOptionalString($connection->unixSocket);
        if ($connection->unixSocket !== null && $connection->unixSocket !== '' && $unixSocket === null) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        if ($unixSocket !== null) {
            if (!in_array($scheme, ['', 'tcp'], true) || $connection->useSSL === true || $contextOptions !== []) {
                return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
            }

            return new RedisConnectionConfiguration(
                self::RESOLUTION_SUPPORTED,
                self::SOURCE_CRAFT_CACHE_FALLBACK,
                'unix',
                $unixSocket,
                0,
                $username,
                $password,
                $database,
                $craftDatabase,
                $autoDatabase,
                true,
                $connectionTimeout,
                $readTimeout,
            );
        }

        if (!in_array($scheme, ['', 'tcp', 'tls'], true)) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }
        $tls = $scheme === 'tls' || $connection->useSSL === true;
        if (!$tls && $contextOptions !== []) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        $context = null;
        if ($contextOptions !== []) {
            if (array_keys($contextOptions) !== ['ssl'] || !is_array($contextOptions['ssl'])) {
                return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
            }
            $context = ['stream' => $contextOptions['ssl']];
        }

        $host = $this->normalizeHost($connection->hostname);
        $port = $this->normalizeInteger($connection->port, 1, 65535);
        if ($host === null || $port === null) {
            return $this->unsupported(self::SOURCE_CRAFT_CACHE_FALLBACK);
        }

        return new RedisConnectionConfiguration(
            self::RESOLUTION_SUPPORTED,
            self::SOURCE_CRAFT_CACHE_FALLBACK,
            $tls ? 'tls' : 'tcp',
            $host,
            $port,
            $username,
            $password,
            $database,
            $craftDatabase,
            $autoDatabase,
            true,
            $connectionTimeout,
            $readTimeout,
            $context,
        );
    }

    /**
     * @return array{valid: bool, present: bool, value: mixed}
     */
    private function resolveSetting(mixed $value, bool $emptyEnvironmentAllowed = false): array
    {
        if ($value === null || $value === '') {
            return ['valid' => true, 'present' => false, 'value' => null];
        }
        if (!is_string($value) || !str_starts_with($value, '$')) {
            return ['valid' => true, 'present' => true, 'value' => $value];
        }
        if (preg_match('/^\$([A-Z_][A-Z0-9_]*)$/i', $value, $matches) !== 1) {
            return ['valid' => false, 'present' => true, 'value' => null];
        }

        $resolved = App::env($matches[1]);
        if ($resolved === null || (!$emptyEnvironmentAllowed && $resolved === '')) {
            return ['valid' => false, 'present' => true, 'value' => null];
        }

        return ['valid' => true, 'present' => true, 'value' => $resolved];
    }

    private function normalizeHost(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }

        return $value;
    }

    private function normalizePassword(mixed $value): ?string
    {
        return $this->normalizeOptionalString($value);
    }

    private function normalizeInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        if (is_int($value)) {
            return $value >= $minimum && $value <= $maximum ? $value : null;
        }
        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximumString = (string)$maximum;
        if (strlen($normalized) > strlen($maximumString)
            || (strlen($normalized) === strlen($maximumString) && strcmp($normalized, $maximumString) > 0)
        ) {
            return null;
        }
        $integer = (int)$normalized;

        return $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    private function normalizeNullableDatabase(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        return $this->normalizeInteger($value, 0, PHP_INT_MAX) ?? false;
    }

    private function automaticDatabase(?Connection $connection, int|false|null $craftDatabase): ?int
    {
        if ($connection === null) {
            return 0;
        }
        if ($craftDatabase === false) {
            return null;
        }
        $sourceDatabase = $craftDatabase ?? 0;
        if ($sourceDatabase === PHP_INT_MAX) {
            return null;
        }

        return $sourceDatabase + self::SEARCH_DATABASE_OFFSET;
    }

    private function normalizeTimeout(mixed $value, bool $zeroIsDefault = false): float|false|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value)) {
            return false;
        }
        $timeout = (float)$value;
        if (!is_finite($timeout)) {
            return false;
        }

        if ($timeout === 0.0 && $zeroIsDefault) {
            return null;
        }
        if ($timeout <= 0) {
            return false;
        }

        return $timeout;
    }

    private function validPing(mixed $ping): bool
    {
        return $ping === true
            || $ping instanceof \Redis
            || (is_string($ping) && in_array(strtoupper($ping), ['PONG', '+PONG'], true));
    }

    private function unsupported(string $source): RedisConnectionConfiguration
    {
        return new RedisConnectionConfiguration(
            self::STATUS_UNSUPPORTED_CONFIGURATION,
            $source,
            'tcp',
            null,
            6379,
            null,
            null,
            null,
            null,
            false,
            $source === self::SOURCE_CRAFT_CACHE_FALLBACK,
        );
    }

    private function notConfigured(int $database, bool $autoDatabase): RedisConnectionConfiguration
    {
        return new RedisConnectionConfiguration(
            self::STATUS_NOT_CONFIGURED,
            self::SOURCE_DEFAULT,
            'tcp',
            null,
            6379,
            null,
            null,
            $database,
            null,
            $autoDatabase,
            false,
        );
    }
}
