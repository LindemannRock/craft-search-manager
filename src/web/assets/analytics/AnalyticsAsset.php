<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\analytics;

use craft\web\AssetBundle;

/**
 * Search Analytics Asset Bundle
 *
 * Provides Search Manager analytics wiring for cp-analytics pages.
 *
 * @since 5.29.0
 */
class AnalyticsAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            \lindemannrock\base\web\assets\analytics\AnalyticsAsset::class,
        ];

        $this->js = [
            'analytics.js',
        ];

        parent::init();
    }
}
