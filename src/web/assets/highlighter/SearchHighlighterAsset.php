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
 * `escapeRegex()`, `create()`, `parseQuery()`, `getHitTerms()`, and
 * `highlightFromUrl()` methods. Destination-page orchestration through
 * `highlightFromUrl()` is available since 5.55.0.
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
        $this->sourcePath = '@lindemannrock/searchmanager/web/assets/highlighter/dist';

        $this->js = [
            'SearchManagerHighlighter.js',
        ];

        parent::init();
    }
}
