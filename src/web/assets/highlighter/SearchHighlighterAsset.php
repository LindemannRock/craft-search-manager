<?php

/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\web\assets\highlighter;

use craft\web\AssetBundle;

/**
 * Search Highlighter Asset Bundle
 *
 * Standalone client-side text highlighter for use in custom search UIs.
 * Exposes `window.SearchManagerHighlighter` with `highlight()`, `escapeHtml()`,
 * `escapeRegex()`, and `create()` methods.
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since 5.39.0
 */
class SearchHighlighterAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->js = [
            'SearchManagerHighlighter.js',
        ];

        parent::init();
    }
}
