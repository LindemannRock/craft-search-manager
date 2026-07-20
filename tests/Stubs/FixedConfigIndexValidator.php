<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Stubs;

use lindemannrock\searchmanager\models\ConfigIndexValidationResult;
use lindemannrock\searchmanager\services\ConfigIndexValidator;

/**
 * Returns a fixed config-index validation result for consumer tests.
 *
 * @since 5.54.0
 */
final class FixedConfigIndexValidator extends ConfigIndexValidator
{
    public function __construct(private readonly ConfigIndexValidationResult $result, array $config = [])
    {
        parent::__construct($config);
    }

    public function validate(): ConfigIndexValidationResult
    {
        return $this->result;
    }
}
