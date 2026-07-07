<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\widgetconfig;

use craft\web\AssetBundle;

/**
 * Widget config CP interactions.
 *
 * @since 5.53.0
 */
class WidgetConfigAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            'widget-config.js',
        ];

        parent::init();
    }
}
