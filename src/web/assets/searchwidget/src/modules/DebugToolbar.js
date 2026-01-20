/**
 * DebugToolbar - Floating debug panel for search metadata
 *
 * Displays aggregate search information like timing, cache status,
 * indices searched, and query expansion details.
 *
 * @module DebugToolbar
 * @author Search Manager
 * @since 5.x
 */

import { escapeHtml } from './Highlighter.js';

/**
 * @typedef {Object} DebugMeta
 * @property {boolean} cached - Whether results came from cache
 * @property {number} took - Search time in milliseconds
 * @property {boolean} cacheEnabled - Whether caching is enabled
 * @property {string} backend - Backend type used
 * @property {boolean} synonymsExpanded - Whether synonyms were expanded
 * @property {Array<string>} expandedQueries - Expanded query terms
 * @property {Array} rulesMatched - Query rules that matched
 * @property {Array} promotionsMatched - Promotions that matched
 * @property {Array<string>} indices - Indices that were searched
 */

/**
 * Render the debug toolbar inner content (items only)
 *
 * @param {DebugMeta} meta - Search metadata
 * @param {number} totalResults - Total results count
 * @param {boolean} collapsed - Whether toolbar is collapsed
 * @returns {string} HTML string of toolbar items with toggle
 */
export function renderDebugToolbarContent(meta, totalResults, collapsed = false) {
    if (!meta) {
        return '';
    }

    const items = [];

    // Results count
    items.push(toolbarItem('results', totalResults, 'generic'));

    // Timing
    if (meta.took !== undefined) {
        const timeDisplay = meta.took < 1 ? '<1ms' : `${Math.round(meta.took)}ms`;
        items.push(toolbarItem('time', timeDisplay, 'time'));
    }

    // Cache status
    if (meta.cacheEnabled !== undefined) {
        if (!meta.cacheEnabled) {
            items.push(toolbarItem('cache', 'off', 'cache-off'));
        } else if (meta.cached) {
            items.push(toolbarItem('cache', 'hit', 'cache-hit'));
        } else {
            items.push(toolbarItem('cache', 'miss', 'cache-miss'));
        }
    }

    // Cache driver (file, redis, memcached, etc.)
    if (meta.cacheDriver) {
        items.push(toolbarItem('storage', meta.cacheDriver, 'cache-driver', meta.cacheDriver));
    }

    // Indices
    if (meta.indices && meta.indices.length > 0) {
        const indicesDisplay = meta.indices.length > 2
            ? `${meta.indices.length} indices`
            : meta.indices.join(', ');
        items.push(toolbarItem('indices', indicesDisplay, 'generic'));
    }

    // Synonyms (only show if expanded)
    if (meta.synonymsExpanded) {
        const synonymCount = meta.expandedQueries ? meta.expandedQueries.length - 1 : 0;
        items.push(toolbarItem('synonyms', `+${synonymCount}`, 'synonyms'));
    }

    // Rules matched (always show)
    const rulesCount = meta.rulesMatched?.length || 0;
    items.push(toolbarItem('rules', rulesCount, rulesCount > 0 ? 'rules' : 'generic'));

    // Promotions (always show)
    const promotedCount = meta.promotionsMatched?.length || 0;
    items.push(toolbarItem('promoted', promotedCount, promotedCount > 0 ? 'promotions' : 'generic'));

    // Toggle icon (chevron)
    const toggleIcon = collapsed
        ? '<path d="M6 9l6 6 6-6"/>' // chevron down (click to expand)
        : '<path d="M18 15l-6-6-6 6"/>'; // chevron up (click to collapse)

    const toggleSvg = `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${toggleIcon}</svg>`;

    // When collapsed, show label; when expanded, show items
    if (collapsed) {
        return `<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${toggleSvg}</div>`;
    }

    return `<div class="sm-toolbar-content">${items.join('')}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${toggleSvg}</button>`;
}

/**
 * Render the debug toolbar with wrapper
 *
 * @param {DebugMeta} meta - Search metadata
 * @param {number} totalResults - Total results count
 * @returns {string} HTML string of the toolbar with wrapper div
 */
export function renderDebugToolbar(meta, totalResults) {
    const content = renderDebugToolbarContent(meta, totalResults);
    if (!content) return '';
    return `<div class="sm-debug-toolbar">${content}</div>`;
}

/**
 * Create a toolbar item with label and value
 *
 * @param {string} label - The label text
 * @param {string|number} value - The value to display
 * @param {string} type - Value type for styling
 * @param {string} [backendType] - Backend type for color coding
 * @returns {string} HTML string
 */
function toolbarItem(label, value, type, backendType = '') {
    const backendAttr = backendType ? ` data-backend="${escapeHtml(backendType)}"` : '';
    return `<span class="sm-toolbar-item"><span class="sm-toolbar-label">${escapeHtml(label)}</span><span class="sm-toolbar-value" data-type="${escapeHtml(type)}"${backendAttr}>${escapeHtml(String(value))}</span></span>`;
}

/**
 * Update the toolbar with new meta
 *
 * @param {HTMLElement} container - Container element
 * @param {DebugMeta} meta - Search metadata
 * @param {number} totalResults - Total results count
 */
export function updateDebugToolbar(container, meta, totalResults) {
    if (!container) return;

    const html = renderDebugToolbar(meta, totalResults);
    container.innerHTML = html;
}
