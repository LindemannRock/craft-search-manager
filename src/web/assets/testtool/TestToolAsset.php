<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\testtool;

use craft\web\AssetBundle;
use lindemannrock\searchmanager\web\assets\highlighter\SearchHighlighterAsset;

/**
 * Settings test tool asset bundle.
 *
 * @since 5.53.0
 */
class TestToolAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            SearchHighlighterAsset::class,
        ];

        $this->css = [
            'test-tool.css',
        ];

        $this->js = [
            'test-tool.js',
        ];

        parent::init();
    }
}
