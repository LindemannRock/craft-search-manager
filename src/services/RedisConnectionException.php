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
 * Fixed, credential-safe Redis connection failure.
 *
 * @internal
 * @since 5.54.0
 */
final class RedisConnectionException extends \RuntimeException
{
}
