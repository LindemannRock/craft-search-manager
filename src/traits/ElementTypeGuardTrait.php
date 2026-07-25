<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\traits;

use lindemannrock\searchmanager\SearchManager;

/**
 * Element Type Guard Trait
 *
 * Shared guard logic for element type availability checks.
 *
 * @since 5.39.0
 */
trait ElementTypeGuardTrait
{
    protected function isElementTypeAvailable(string $elementType, string $context): bool
    {
        $availability = SearchManager::$plugin->dependencies->getClassAvailability(
            $elementType,
            \craft\base\ElementInterface::class,
        );
        if ($availability['available']) {
            return true;
        }

        $this->logWarning('Element type dependency unavailable; skipping', [
            'elementType' => $elementType,
            'plugin' => $availability['providerHandle'],
            'reason' => $availability['reason'],
            'context' => $context,
        ]);

        return false;
    }
}
