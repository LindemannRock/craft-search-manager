<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\traits;

use craft\base\Model;
use lindemannrock\searchmanager\SearchManager;

/**
 * Delegates nullable index-reference validation to the dependency authority.
 *
 * @mixin Model
 * @since 5.54.0
 */
trait IndexReferenceValidationTrait
{
    public function validateIndexReference(string $attribute): void
    {
        SearchManager::$plugin->dependencies->validateIndexReference($this, $attribute);
    }
}
