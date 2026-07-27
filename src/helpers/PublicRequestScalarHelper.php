<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

use craft\console\Request as ConsoleRequest;
use craft\web\Request as WebRequest;
use yii\web\BadRequestHttpException;

/**
 * Normalizes public HTTP parameters to a single scalar boundary.
 *
 * @since 5.54.0
 */
final class PublicRequestScalarHelper
{
    /**
     * @param array<string, scalar|null> $defaults
     * @return array<string, string|null>
     * @throws BadRequestHttpException if a supplied parameter is not scalar
     */
    public static function normalize(ConsoleRequest|WebRequest $request, array $defaults): array
    {
        $normalized = [];

        foreach ($defaults as $name => $default) {
            $value = $request instanceof WebRequest || method_exists($request, 'getParam')
                ? $request->getParam($name, $default)
                : $default;
            if ($value === null) {
                $normalized[$name] = null;
                continue;
            }

            if (!is_scalar($value)) {
                throw new BadRequestHttpException();
            }

            $normalized[$name] = (string)$value;
        }

        return $normalized;
    }
}
