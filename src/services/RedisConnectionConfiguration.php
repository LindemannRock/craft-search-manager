<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\services;

/**
 * Normalized internal Redis connection configuration.
 *
 * Secret-bearing configuration deliberately has no JSON or string projection.
 *
 * @internal
 * @since 5.54.0
 */
final readonly class RedisConnectionConfiguration
{
    public function __construct(
        public string $resolutionStatus,
        public string $source,
        public string $transport,
        public ?string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public ?int $database,
        public ?int $craftDatabase,
        public bool $isAutoDatabase,
        public bool $usesCraftCache,
        public ?float $connectionTimeout = null,
        public ?float $readTimeout = null,
        public ?array $context = null,
    ) {
    }

    public function isSupported(): bool
    {
        return $this->resolutionStatus === RedisNativeConnectionFactory::RESOLUTION_SUPPORTED;
    }

    public function isUnsupported(): bool
    {
        return $this->resolutionStatus === RedisNativeConnectionFactory::STATUS_UNSUPPORTED_CONFIGURATION;
    }

    public function isConfigured(): bool
    {
        return $this->isSupported() && $this->host !== null && $this->database !== null;
    }

    public function authenticationMode(): string
    {
        if ($this->username !== null) {
            return 'acl';
        }

        return $this->password !== null && $this->password !== '' ? 'password' : 'none';
    }

    public function endpointLabel(): ?string
    {
        if ($this->host === null) {
            return null;
        }

        return $this->transport === 'unix' ? $this->host : $this->host . ':' . $this->port;
    }

    public function targetIdentity(): string
    {
        $identity = [
            'transport' => $this->transport,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'authenticationMode' => $this->authenticationMode(),
            'usernameDigest' => $this->username === null ? null : hash('sha256', $this->username),
            'secretDigest' => $this->password === null ? null : hash('sha256', $this->password),
            'contextDigest' => $this->context === null ? null : hash('sha256', serialize($this->context)),
            'connectionTimeout' => $this->connectionTimeout,
            'readTimeout' => $this->readTimeout,
        ];

        return hash('sha256', serialize($identity));
    }
}
