<?php

namespace lindemannrock\searchmanager\traits;

use lindemannrock\base\helpers\PluginHelper;

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
        if (class_exists($elementType)) {
            return true;
        }

        $pluginHandle = $this->getPluginHandleForElementType($elementType);
        if ($pluginHandle && !PluginHelper::isPluginEnabled($pluginHandle)) {
            $this->logWarning('Element type plugin disabled; skipping', [
                'elementType' => $elementType,
                'plugin' => $pluginHandle,
                'context' => $context,
            ]);
            return false;
        }

        $this->logWarning('Element type class not found; skipping', [
            'elementType' => $elementType,
            'context' => $context,
        ]);

        return false;
    }

    protected function getPluginHandleForElementType(string $elementType): ?string
    {
        return match ($elementType) {
            'lindemannrock\\plugindocs\\elements\\PluginDoc' => 'plugin-docs',
            'lindemannrock\\smartlinkmanager\\elements\\SmartLink' => 'smartlink-manager',
            'lindemannrock\\shortlinkmanager\\elements\\ShortLink' => 'shortlink-manager',
            default => null,
        };
    }
}
