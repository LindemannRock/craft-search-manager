/**
 * ResultRenderer - Search result rendering utilities
 *
 * Renders search results with support for grouping, highlighting,
 * promoted results badges, recent searches display, and empty states.
 *
 * @module ResultRenderer
 * @author Search Manager
 * @since 5.x
 */

import { highlightMatches, escapeHtml } from './Highlighter.js';
import { groupResultsByType, groupResultsByField } from './SearchService.js';
import { getOptionId } from './A11yUtils.js';

/**
 * @typedef {Object} RenderOptions
 * @property {string} listboxId - ARIA listbox ID for accessibility
 * @property {boolean} groupResults - Whether to group by type/section
 * @property {boolean} enableHighlighting - Whether to highlight matches
 * @property {string} highlightTag - HTML tag for highlights (default: 'mark')
 * @property {string} highlightClass - Additional CSS class for highlights
 * @property {PromotionConfig} promotions - Promoted results configuration
 */

/**
 * @typedef {Object} PromotionConfig
 * @property {boolean} showBadge - Whether to show promoted badge
 * @property {string} badgeText - Text for the badge (default: 'Featured')
 * @property {string} badgePosition - Badge position: 'top-right', 'top-left', 'inline'
 */

/**
 * @typedef {Object} SearchResult
 * @property {string|number} id - Result ID
 * @property {string} title - Result title
 * @property {string} [description] - Result description/excerpt
 * @property {string} [url] - Result URL
 * @property {string} [section] - Section/type for grouping
 * @property {string} [type] - Element type
 * @property {boolean} [promoted] - Whether result is promoted
 * @property {number} [position] - Promotion position
 */

/**
 * Render a list of search results
 *
 * Handles both grouped and ungrouped rendering modes.
 *
 * @param {SearchResult[]} results - Search results array
 * @param {string} query - The search query (for highlighting)
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string of rendered results
 *
 * @example
 * const html = renderResults(results, 'search query', {
 *   listboxId: 'sm-results',
 *   groupResults: true,
 *   enableHighlighting: true,
 *   highlightTag: 'mark',
 * });
 */
export function renderResults(results, query, options = {}) {
    const { groupResults = false, resultLayout = 'default', listboxId } = options;

    if (!results || results.length === 0) {
        return '';
    }

    // Hierarchical layout (Algolia DocSearch-style)
    if (resultLayout === 'hierarchical') {
        return renderHierarchicalResults(results, query, options);
    }

    if (groupResults) {
        const groups = groupResultsByType(results);
        let globalIndex = 0;

        return Object.entries(groups).map(([type, items]) => `
            <div class="sm-section" role="group" aria-label="${escapeHtml(type)}">
                <div class="sm-section-header">${escapeHtml(type)}</div>
                ${items.map((result) => renderResultItem(result, globalIndex++, query, options)).join('')}
            </div>
        `).join('');
    }

    return results.map((result, i) => renderResultItem(result, i, query, options)).join('');
}

/**
 * Render a single result item
 *
 * @param {SearchResult} result - Result object
 * @param {number} index - Item index for ARIA
 * @param {string} query - Search query for highlighting
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string of result item
 *
 * @example
 * const itemHtml = renderResultItem(result, 0, 'query', {
 *   listboxId: 'sm-results',
 *   enableHighlighting: true,
 *   promotions: { showBadge: true, badgeText: 'Featured' },
 * });
 */
export function renderResultItem(result, index, query, options = {}) {
    const {
        listboxId,
        enableHighlighting = true,
        highlightTag = 'mark',
        highlightClass = '',
        groupResults = false,
        promotions = {},
        debug = false,
    } = options;

    const title = result.title || result.name || 'Untitled';
    const description = result.description || result.excerpt || result.snippet || '';
    const url = result.url || result.href || '#';
    const type = result.section || result.type || '';
    const optionId = getOptionId(listboxId, index);
    const isPromoted = result.promoted === true;

    // Build highlight options
    const highlightOptions = {
        enabled: enableHighlighting,
        tag: highlightTag,
        className: highlightClass,
    };

    const highlightedTitle = highlightMatches(title, query, {
        ...highlightOptions,
        terms: getHighlightTerms(result, 'title'),
    });
    const highlightedDesc = description ? highlightMatches(description, query, {
        ...highlightOptions,
        terms: getHighlightTerms(result, 'description'),
    }) : '';

    // Build promoted badge HTML
    const promotedBadge = renderPromotedBadge(result, promotions);
    const promotedClass = isPromoted ? ' sm-promoted' : '';

    // Build type badge (only if not grouping)
    const typeBadge = type && !groupResults
        ? `<span class="sm-result-type">${escapeHtml(type)}</span>`
        : '';

    // Build debug info (only if debug mode enabled)
    const debugInfo = debug ? renderDebugInfo(result) : '';

    // When debug is enabled, wrap main content so debug-info can be full-width sibling
    if (debug) {
        return `
            <a class="sm-result-item sm-debug-enabled${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${escapeHtml(title)}">
                <div class="sm-result-main">
                    ${promotedBadge}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${highlightedTitle}</span>
                        ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                    </div>
                    ${typeBadge}
                    <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
                ${debugInfo}
            </a>
        `;
    }

    return `
        <a class="sm-result-item${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${escapeHtml(title)}">
            ${promotedBadge}
            <div class="sm-result-content">
                <span class="sm-result-title">${highlightedTitle}</span>
                ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
            </div>
            ${typeBadge}
            <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </a>
    `;
}

/**
 * Render debug information for a result
 *
 * Developer tools panel showing key:value pairs with labels.
 * Extensible design - easy to add more debug info later.
 *
 * @param {SearchResult} result - Result object with debug fields
 * @returns {string} HTML string of debug info
 */
function renderDebugInfo(result) {
    const debugItems = [];
    const backendValue = result.backend ? result.backend.toLowerCase() : '';

    // Index handle
    if (result._index || result.index) {
        debugItems.push(debugItem('index', result._index || result.index, 'index'));
    }

    // Backend - color-coded
    if (result.backend) {
        debugItems.push(debugItem('backend', backendValue, 'backend', backendValue));
    }

    // Element ID
    if (result.id) {
        debugItems.push(debugItem('id', result.id, 'generic'));
    }

    // Score - highlighted
    if (result.score !== undefined && result.score !== null) {
        const scoreDisplay = typeof result.score === 'number' ? result.score.toFixed(2) : result.score;
        debugItems.push(debugItem('score', scoreDisplay, 'score'));
    }

    // Site
    if (result.site) {
        debugItems.push(debugItem('site', result.site, 'generic'));
    }

    // Language
    if (result.language) {
        debugItems.push(debugItem('lang', result.language, 'generic'));
    }

    // Matched fields - which fields contained the search query
    if (result.matchedIn && Array.isArray(result.matchedIn) && result.matchedIn.length > 0) {
        const matchedDisplay = result.matchedIn.join(', ');
        debugItems.push(debugItem('matched', matchedDisplay, 'matched'));
    }

    // Promoted flag - result was injected via promotion
    if (result.promoted) {
        debugItems.push(debugItem('promoted', 'yes', 'promoted'));
    }

    // Boosted flag - result score was boosted via query rule
    if (result.boosted) {
        debugItems.push(debugItem('boosted', 'yes', 'boosted'));
    }

    if (debugItems.length === 0) {
        return '';
    }

    return `<div class="sm-debug-info">${debugItems.join('')}</div>`;
}

function getHighlightTerms(result, area) {
    const matchedTerms = result.matchedTerms;
    if (!matchedTerms) {
        return null;
    }

    if (area === 'title') {
        if (Array.isArray(matchedTerms.title) && matchedTerms.title.length > 0) {
            return matchedTerms.title;
        }
    }

    if (area === 'description') {
        if (Array.isArray(matchedTerms.content) && matchedTerms.content.length > 0) {
            return matchedTerms.content;
        }
    }

    const combined = [
        ...(Array.isArray(matchedTerms.title) ? matchedTerms.title : []),
        ...(Array.isArray(matchedTerms.content) ? matchedTerms.content : []),
    ];

    return combined.length > 0 ? combined : null;
}

/**
 * Create a debug item with label and value
 *
 * @param {string} label - The label text
 * @param {string|number} value - The value to display
 * @param {string} type - Value type for styling (index, backend, score, generic)
 * @param {string} [backendType] - Backend type for color coding
 * @returns {string} HTML string
 */
function debugItem(label, value, type, backendType = '') {
    const backendAttr = backendType ? ` data-backend="${escapeHtml(backendType)}"` : '';
    return `<span class="sm-debug-item"><span class="sm-debug-label">${escapeHtml(label)}</span><span class="sm-debug-value" data-type="${escapeHtml(type)}"${backendAttr}>${escapeHtml(String(value))}</span></span>`;
}

/**
 * Render promoted badge for a result
 *
 * @param {SearchResult} result - Result with potential promoted flag
 * @param {PromotionConfig} config - Promotion display config
 * @returns {string} HTML string of badge (or empty string)
 *
 * @example
 * const badge = renderPromotedBadge({ promoted: true }, {
 *   showBadge: true,
 *   badgeText: 'Featured',
 *   badgePosition: 'top-right',
 * });
 */
export function renderPromotedBadge(result, config = {}) {
    const {
        showBadge = true,
        badgeText = 'Featured',
        badgePosition = 'top-right',
    } = config;

    if (!result.promoted || !showBadge) {
        return '';
    }

    const positionClass = `sm-promoted-badge--${badgePosition}`;

    return `<span class="sm-promoted-badge ${positionClass}">${escapeHtml(badgeText)}</span>`;
}

// =========================================================================
// HIERARCHICAL RESULT RENDERING
// =========================================================================

/**
 * Document icon SVG for parent items in hierarchical layout
 * @returns {string} SVG markup
 */
function documentIcon() {
    return `<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
    </svg>`;
}

/**
 * Hash icon SVG for heading children in hierarchical layout
 * @returns {string} SVG markup
 */
function hashIcon() {
    return `<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`;
}

/**
 * Render results in hierarchical layout (Algolia DocSearch-style)
 *
 * Groups results by a configurable field and shows matched headings
 * as indented child items with connecting lines.
 *
 * @param {SearchResult[]} results - Search results array
 * @param {string} query - The search query (for highlighting)
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string
 */
function renderHierarchicalResults(results, query, options = {}) {
    const {
        hierarchyGroupBy = 'section',
        showMatchedHeadings = true,
        maxHeadingsPerResult = 3,
        listboxId,
    } = options;

    const groupField = hierarchyGroupBy || 'section';
    const groups = groupResultsByField(results, groupField);
    let globalIndex = 0;

    return Object.entries(groups).map(([groupName, items]) => {
        const itemsHtml = items.map((result) => {
            // Render parent item
            const parentIndex = globalIndex++;
            const parentHtml = renderHierarchyParent(result, parentIndex, query, options);

            // Render matched heading children
            let childrenHtml = '';
            if (showMatchedHeadings) {
                const headings = result._matchedHeadings || [];
                const limitedHeadings = headings.slice(0, maxHeadingsPerResult);
                if (limitedHeadings.length > 0) {
                    // Normalize levels: shallowest heading = depth 0
                    const minLevel = Math.min(...limitedHeadings.map(h => h.level || 2));
                    // Mark each heading as "last" if no later heading shares its level
                    childrenHtml = limitedHeadings.map((heading, headingIndex) => {
                        const level = heading.level || 2;
                        const depth = level - minLevel;
                        const isLastAtLevel = !limitedHeadings.slice(headingIndex + 1).some(h => (h.level || 2) === level);
                        return renderHeadingChild(result, heading, globalIndex++, query, options, isLastAtLevel, depth);
                    }).join('');
                }
            }

            const hasChildren = Boolean(childrenHtml);

            return `
                <div class="sm-hierarchy-block${hasChildren ? ' sm-hierarchy-block--has-children' : ''}">
                    ${hasChildren
                        ? parentHtml.replace('sm-hierarchy-parent"', 'sm-hierarchy-parent sm-hierarchy-parent--has-children"')
                        : parentHtml}
                    ${hasChildren ? `<div class="sm-hierarchy-children">${childrenHtml}</div>` : ''}
                </div>
            `;
        }).join('');

        return `
            <div class="sm-hierarchy-group" role="group" aria-label="${escapeHtml(groupName)}">
                <div class="sm-hierarchy-group-header">${escapeHtml(groupName)}</div>
                ${itemsHtml}
            </div>
        `;
    }).join('');
}

/**
 * Render a parent item in hierarchical layout (document-level result)
 *
 * @param {SearchResult} result - Result object
 * @param {number} index - Item index for ARIA
 * @param {string} query - Search query for highlighting
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string
 */
function renderHierarchyParent(result, index, query, options = {}) {
    const {
        listboxId,
        enableHighlighting = true,
        highlightTag = 'mark',
        highlightClass = '',
    } = options;

    const title = result.title || result.name || 'Untitled';
    const description = result.description || result.excerpt || '';
    const url = result.url || '#';
    const optionId = getOptionId(listboxId, index);

    const highlightOptions = {
        enabled: enableHighlighting,
        tag: highlightTag,
        className: highlightClass,
    };

    const highlightedTitle = highlightMatches(title, query, highlightOptions);
    const highlightedDesc = description ? highlightMatches(description, query, highlightOptions) : '';

    return `
        <a class="sm-result-item sm-hierarchy-parent" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${escapeHtml(title)}">
            ${documentIcon()}
            <div class="sm-result-content">
                <span class="sm-result-title">${highlightedTitle}</span>
                ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
            </div>
            <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
        </a>
    `;
}

/**
 * Render a heading child item in hierarchical layout
 *
 * @param {SearchResult} result - Parent result (for URL base)
 * @param {Object} heading - Heading object with text, id, level
 * @param {number} index - Item index for ARIA
 * @param {string} query - Search query for highlighting
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string
 */
function renderHeadingChild(result, heading, index, query, options = {}, isLast = false, depth = 0) {
    const {
        listboxId,
        enableHighlighting = true,
        highlightTag = 'mark',
        highlightClass = '',
    } = options;

    const rawText = heading.text || '';
    const text = rawText.replace(/^#+\s*/, '');
    const level = heading.level || 2;
    const anchorId = heading.id || (text ? slugifyHeading(text) : '');
    const baseUrl = result.url || '#';
    const url = anchorId ? `${baseUrl}#${anchorId}` : baseUrl;
    const optionId = getOptionId(listboxId, index);

    const highlightOptions = {
        enabled: enableHighlighting,
        tag: highlightTag,
        className: highlightClass,
    };

    const highlightedText = highlightMatches(text, query, highlightOptions);

    const rowClass = isLast ? ' sm-hierarchy-child-row-last' : '';

    return `
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${level} sm-hierarchy-depth-${depth}${rowClass}" style="--sm-hierarchy-depth:${depth}">
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${level}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${escapeHtml(text)}">
                ${hashIcon()}
                <span class="sm-result-title">${highlightedText}</span>
                <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    `;
}

function slugifyHeading(text) {
    const normalized = text.normalize('NFKD').toLowerCase();
    try {
        return normalized
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-+|-+$/g, '');
    } catch (err) {
        return normalized
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
}

/**
 * Render recent searches section
 *
 * @param {Array} recentSearches - Recent search items
 * @param {string} listboxId - ARIA listbox ID
 * @returns {string} HTML string of recent searches
 *
 * @example
 * const html = renderRecentSearches([
 *   { query: 'test', title: 'Test Result', url: '/test' },
 * ], 'sm-results');
 */
export function renderRecentSearches(recentSearches, listboxId) {
    if (!recentSearches || recentSearches.length === 0) {
        return '';
    }

    return `
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${listboxId}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${recentSearches.map((item, i) => `
                <div class="sm-result-item sm-recent-item" id="${getOptionId(listboxId, i)}" role="option" aria-selected="false" data-index="${i}" data-url="${item.url || ''}" data-query="${escapeHtml(item.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${escapeHtml(item.title || item.query)}</span>
                    <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            `).join('')}
        </div>
    `;
}

/**
 * Render empty state (no query or no results)
 *
 * @param {string} query - Current query (empty = start typing, has value = no results)
 * @returns {string} HTML string of empty state
 *
 * @example
 * // No query yet
 * renderEmptyState(''); // "Start typing to search"
 *
 * // No results found
 * renderEmptyState('search term'); // "No results for 'search term'"
 */
export function renderEmptyState(query) {
    if (!query || !query.trim()) {
        // No query - show "start typing" message
        return `
            <div class="sm-empty" part="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <p>Start typing to search</p>
            </div>
        `;
    }

    // Has query but no results
    return `
        <div class="sm-empty" part="empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <path d="m15 9-6 6M9 9l6 6"/>
            </svg>
            <p>No results for "<strong>${escapeHtml(query)}</strong>"</p>
        </div>
    `;
}

/**
 * Render loading state
 *
 * @returns {string} HTML string of loading indicator
 */
export function renderLoadingState() {
    return `
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `;
}

/**
 * Determine what content to render based on state
 *
 * Helper function that decides which render function to call
 * based on the current search state.
 *
 * @param {Object} state - Current search state
 * @param {string} state.query - Current search query
 * @param {Array} state.results - Search results
 * @param {Array} state.recentSearches - Recent searches
 * @param {boolean} state.loading - Loading state
 * @param {boolean} state.showRecent - Whether to show recent searches
 * @param {RenderOptions} options - Rendering options
 * @returns {Object} Object with { html, hasResults, showListbox }
 *
 * @example
 * const { html, hasResults, showListbox } = getContentToRender({
 *   query: '',
 *   results: [],
 *   recentSearches: [...],
 *   loading: false,
 *   showRecent: true,
 * }, options);
 */
export function getContentToRender(state, options) {
    const { query, results, recentSearches, loading, showRecent } = state;
    const { showLoadingIndicator = true } = options;
    const hasQuery = query && query.trim();

    // Loading state (only if showLoadingIndicator is enabled)
    if (loading && showLoadingIndicator) {
        return {
            html: renderLoadingState(),
            hasResults: false,
            showListbox: false,
        };
    }

    // No query - show recent searches or empty state
    if (!hasQuery) {
        if (showRecent && recentSearches && recentSearches.length > 0) {
            return {
                html: renderRecentSearches(recentSearches, options.listboxId),
                hasResults: true,
                showListbox: true,
            };
        }
        return {
            html: renderEmptyState(''),
            hasResults: false,
            showListbox: false,
        };
    }

    // Has query but no results
    if (!results || results.length === 0) {
        return {
            html: renderEmptyState(query),
            hasResults: false,
            showListbox: false,
        };
    }

    // Has results
    return {
        html: renderResults(results, query, options),
        hasResults: true,
        showListbox: true,
    };
}
