<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\cache;

use lindemannrock\base\cache\CacheBackendStatus;
use yii\caching\CacheInterface;

/**
 * Immutable disposable-cache storage decision for the current host.
 *
 * @since 5.55.0
 */
final readonly class CacheStorageDecision
{
    public const EFFECTIVE_APPLICATION = 'application';
    public const EFFECTIVE_FILE = 'file';
    public const EFFECTIVE_DISABLED = 'disabled';

    public const PERSISTENCE_CONFIRMED = 'confirmed';
    public const PERSISTENCE_UNKNOWN = 'unknown';
    public const PERSISTENCE_UNSUITABLE = 'unsuitable';

    public const REASON_APPLICATION = 'application';
    public const REASON_DURABLE_FILE = 'durable-file';
    public const REASON_EPHEMERAL_FILE_APPLICATION = 'ephemeral-file-application';
    public const REASON_EPHEMERAL_FILE_UNSUITABLE = 'ephemeral-file-unsuitable';
    public const REASON_APPLICATION_UNSUITABLE = 'application-unsuitable';
    public const REASON_UNKNOWN_TOKEN = 'unknown-token';

    public function __construct(
        public string $configuredStorage,
        public string $effectiveStorage,
        public bool $ephemeral,
        public CacheBackendStatus $backendStatus,
        public ?CacheInterface $applicationCache,
        public string $persistence,
        public bool $fileStorageBypassed,
        public bool $canResolveFilePath,
        public string $reasonCode,
    ) {
    }

    public function usesApplicationCache(): bool
    {
        return $this->effectiveStorage === self::EFFECTIVE_APPLICATION;
    }

    public function usesFileCache(): bool
    {
        return $this->effectiveStorage === self::EFFECTIVE_FILE;
    }

    public function isDisabled(): bool
    {
        return $this->effectiveStorage === self::EFFECTIVE_DISABLED;
    }
}
