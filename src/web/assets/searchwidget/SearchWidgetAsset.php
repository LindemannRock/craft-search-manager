<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\searchwidget;

use craft\web\AssetBundle;

/**
 * Frontend search widget asset bundle.
 *
 * @since 5.54.3
 */
class SearchWidgetAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@lindemannrock/searchmanager/web/assets/searchwidget/dist';

        $this->js = [
            'SearchModalWidget.js',
        ];

        parent::init();
    }
}
