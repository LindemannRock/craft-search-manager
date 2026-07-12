<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\interfaces;

use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * Backend capability contract for direct local storage access.
 *
 * @since 5.53.0
 */
interface StorageBackedBackendInterface
{
    public function getStorage(string $indexHandle): StorageInterface;
}
