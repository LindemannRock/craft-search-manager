<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use lindemannrock\searchmanager\models\Settings;

/**
 * Recording Search Manager settings model with an injectable persistence
 * failure used by controller lifecycle tests.
 *
 * @since 5.54.0
 */
final class ControlledSettings extends Settings
{
    public bool $failWrites = false;

    /**
     * @var list<list<string>|null>
     */
    public array $saveCalls = [];

    public function saveToDatabase(?array $attributesToValidate = null): bool
    {
        $this->saveCalls[] = $attributesToValidate;

        return !$this->failWrites && parent::saveToDatabase($attributesToValidate);
    }
}
