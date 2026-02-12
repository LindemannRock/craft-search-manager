<?php

/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\widgetpreview;

use craft\web\AssetBundle;

/**
 * Widget Preview Asset Bundle
 *
 * Shared preview engine for widget and style edit pages.
 *
 * @since 5.39.0
 */
class WidgetPreviewAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->js = [
            'widget-preview.js',
        ];

        parent::init();
    }
}
