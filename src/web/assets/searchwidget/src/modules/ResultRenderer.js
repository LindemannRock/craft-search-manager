/**
 * ResultRenderer - Search result rendering utilities
 *
 * Renders search results with support for grouping, highlighting,
 * promoted results badges, recent searches display, and empty states.
 *
 * @module ResultRenderer
 * @author Search Manager
 * @since 5.32.0
 */

import { highlightMatches, escapeHtml } from './Highlighter.js';
import { groupResultsByType, groupResultsByField } from './SearchService.js';
import { getOptionId } from './A11yUtils.js';
import { appendQueryParam } from './UrlUtils.js';

/**
 * @typedef {Object} RenderOptions
 * @property {string} listboxId - ARIA listbox ID for accessibility
 * @property {boolean} groupResults - Whether to group flat results by source, Entry section, or type
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
 * @property {string} [backendId] - Unique backend record ID
 * @property {number} [elementId] - Craft element ID
 * @property {string} title - Result title
 * @property {string} [snippet] - Result match snippet
 * @property {string} [url] - Result URL
 * @property {string} [source] - Source name for grouping
 * @property {string} [entrySection] - Entry section name for grouping
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

    // Hierarchical layout can group split section hits back under their page.
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
        persistQueryInUrl = false,
        queryParamName = 'smq',
    } = options;

    const sectionHit = isSectionHit(result);
    const title = sectionHit
        ? (result.sectionTitle || result.title || result.name || 'Untitled')
        : (result.title || result.name || 'Untitled');
    const snippet = result.snippet || '';
    const rawUrl = sectionHit
        ? (result.sectionUrl || result.url || result.href || '#')
        : (result.url || result.href || '#');
    const url = appendQueryParam(rawUrl, query, persistQueryInUrl ? queryParamName : '');
    const type = result.source || result.entrySection || result.type || '';
    const optionId = getOptionId(listboxId, index);
    const isPromoted = result.promoted === true;
    const sourceIndex = result._index || result.index || '';
    const identityAttrs = renderIdentityAttrs(result);

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
    const highlightedDesc = snippet ? renderSnippetHtml(result, snippet, query, {
        ...highlightOptions,
        terms: getHighlightTerms(result, 'snippet'),
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
            <a class="sm-result-item sm-debug-enabled${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(title)}">
                <div class="sm-result-main">
                    ${promotedBadge}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${highlightedTitle}</span>
                        ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                    </div>
                    ${typeBadge}
                    ${arrowSvg()}
                </div>
                ${debugInfo}
            </a>
        `;
    }

    return `
        <a class="sm-result-item${promotedClass}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(title)}">
            ${promotedBadge}
            <div class="sm-result-content">
                <span class="sm-result-title">${highlightedTitle}</span>
                ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
            </div>
            ${typeBadge}
            ${arrowSvg()}
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

    if (result.elementId) {
        debugItems.push(debugItem('element', result.elementId, 'generic'));
    }

    if (result.backendId) {
        debugItems.push(debugItem('hit', result.backendId, 'generic'));
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
    const phrases = Array.isArray(result.matchedPhrases) ? result.matchedPhrases : [];
    const matchedTerms = result.matchedTerms;

    // Collect individual terms from matchedTerms
    let terms = [];
    if (matchedTerms) {
        if (area === 'title' && Array.isArray(matchedTerms.title) && matchedTerms.title.length > 0) {
            terms = matchedTerms.title;
        } else if (area === 'snippet' && Array.isArray(matchedTerms.content) && matchedTerms.content.length > 0) {
            terms = matchedTerms.content;
        } else {
            terms = [
                ...(Array.isArray(matchedTerms.title) ? matchedTerms.title : []),
                ...(Array.isArray(matchedTerms.content) ? matchedTerms.content : []),
            ];
        }
    }

    // Combine: full phrases (longest match first via normalizeTerms), then explicit terms
    const combined = [...phrases, ...terms];
    return combined.length > 0 ? combined : null;
}

function renderSnippetHtml(result, snippet, query, highlightOptions) {
    return highlightMatches(snippet, query, highlightOptions);
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

function isSectionHit(result) {
    return Boolean(result && typeof result === 'object' && ['heading', 'intro', 'promoted-page'].includes(String(result.sectionType || '')));
}

function hasSectionHits(results) {
    return Array.isArray(results) && results.some(isSectionHit);
}

function normalizeSectionHitsForHierarchy(results, maxHeadingsPerResult) {
    const sectionGroups = new Map();
    const entries = [];

    results.forEach((result, order) => {
        if (!isSectionHit(result)) {
            entries.push({
                type: 'single',
                item: result,
                order,
                score: numericScore(result),
            });
            return;
        }

        const key = sectionGroupKey(result);
        if (!sectionGroups.has(key)) {
            const group = {
                hits: [],
                order,
                score: numericScore(result),
            };
            sectionGroups.set(key, group);
            entries.push({
                type: 'section-group',
                key,
                order,
                score: group.score,
            });
        }

        const group = sectionGroups.get(key);
        group.hits.push(result);
        group.score = Math.max(group.score, numericScore(result));

        const entry = entries.find(candidate => candidate.type === 'section-group' && candidate.key === key);
        if (entry) {
            entry.score = group.score;
        }
    });

    return entries
        .map(entry => {
            if (entry.type === 'section-group') {
                const group = sectionGroups.get(entry.key);
                return {
                    ...entry,
                    item: sectionGroupToPageResult(group.hits, maxHeadingsPerResult),
                };
            }

            return entry;
        })
        .sort((a, b) => {
            const score = compareScores(b.score, a.score);
            return score !== 0 ? score : a.order - b.order;
        })
        .map(entry => entry.item);
}

function sectionGroupToPageResult(hits, maxHeadingsPerResult) {
    const sortedHits = [...hits].sort((a, b) => sectionIndex(a) - sectionIndex(b));
    const introHit = sortedHits.find(hit => hit.sectionType === 'intro') || null;
    const bestHit = [...hits].sort((a, b) => {
        const score = compareScores(numericScore(b), numericScore(a));
        return score !== 0 ? score : sectionIndex(a) - sectionIndex(b);
    })[0] || sortedHits[0] || {};
    const pageHit = introHit || bestHit;
    const elementId = elementIdentity(pageHit);
    const siteId = pageHit.siteId ?? '';
    const headingLimit = Number.isFinite(maxHeadingsPerResult) && maxHeadingsPerResult > 0
        ? maxHeadingsPerResult
        : 3;

    const headings = sortedHits
        .filter(hit => hit.sectionType === 'heading')
        .sort((a, b) => {
            const score = compareScores(numericScore(b), numericScore(a));
            return score !== 0 ? score : sectionIndex(a) - sectionIndex(b);
        })
        .slice(0, headingLimit)
        .sort((a, b) => sectionIndex(a) - sectionIndex(b))
        .map(sectionHitToHeading);

    return {
        ...pageHit,
        elementId: elementId || pageHit.elementId,
        backendId: introHit?.backendId || pageHit.backendId || syntheticPageBackendId(elementId, siteId),
        title: pageHit.title || pageHit.sectionTitle || pageHit.name || 'Untitled',
        url: pageHit.url || '#',
        snippet: introHit ? (introHit.snippet || null) : null,
        score: numericScore(bestHit),
        headings,
        __sectionHitGroup: true,
        __useBackendDomId: true,
    };
}

function sectionHitToHeading(hit) {
    const parsedLevel = Number.parseInt(hit.sectionLevel, 10);
    const level = Number.isFinite(parsedLevel) ? parsedLevel : 2;

    return {
        title: hit.sectionTitle || hit.title || '',
        text: hit.sectionTitle || hit.title || '',
        id: hit.sectionAnchor || hit.sectionId || '',
        level,
        url: hit.sectionUrl || hit.url || null,
        snippet: hit.snippet || null,
        backendId: hit.backendId || '',
        elementId: elementIdentity(hit),
        sectionType: hit.sectionType,
        _index: hit._index,
        index: hit.index,
        matchedTerms: hit.matchedTerms,
        matchedPhrases: hit.matchedPhrases,
        __useBackendDomId: true,
    };
}

function sectionGroupKey(result) {
    return [
        elementIdentity(result) || backendIdentity(result) || '',
        result.siteId ?? '',
    ].join(':');
}

function sectionIndex(result) {
    const index = Number.parseInt(result.sectionIndex, 10);
    return Number.isFinite(index) ? index : Number.MAX_SAFE_INTEGER;
}

function numericScore(result) {
    const score = Number(result?.score);
    return Number.isFinite(score) ? score : Number.NEGATIVE_INFINITY;
}

function compareScores(a, b) {
    if (a === b) {
        return 0;
    }

    return a > b ? 1 : -1;
}

function syntheticPageBackendId(elementId, siteId) {
    const element = elementId || 'unknown';
    return siteId !== null && siteId !== undefined && String(siteId) !== ''
        ? `${element}_${siteId}`
        : String(element);
}

function backendIdentity(result, parent = null) {
    return result?.backendId || parent?.backendId || '';
}

function elementIdentity(result, parent = null) {
    return result?.elementId || parent?.elementId || '';
}

function renderIdentityAttrs(result, parent = null) {
    const backendId = backendIdentity(result, parent) || elementIdentity(result, parent);
    const elementId = elementIdentity(result, parent);

    return ` data-id="${escapeHtml(backendId)}" data-element-id="${escapeHtml(elementId)}"`;
}

// =========================================================================
// HIERARCHICAL RESULT RENDERING
// =========================================================================

/**
 * Arrow icon SVG for result items (reused across all layouts)
 * @returns {string} SVG markup
 */
function arrowSvg() {
    return `<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`;
}

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
 * Content icon SVG for parent items without matched headings (body text match)
 * Three horizontal lines representing text content.
 * @returns {string} SVG markup
 */
function contentIcon() {
    return `<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="14" y2="17"/>
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
 * Render results in hierarchical layout.
 *
 * Groups results by a configurable public field and can show split section
 * heading hits as child items.
 *
 * @param {SearchResult[]} results - Search results array
 * @param {string} query - The search query (for highlighting)
 * @param {RenderOptions} options - Rendering options
 * @returns {string} HTML string
 */
function renderHierarchicalResults(results, query, options = {}) {
    const {
        hierarchyGroupBy = '',
        hierarchyStyle = 'tree',
        hierarchyDisplay = 'individual',
        maxHeadingsPerResult = 3,
        listboxId,
    } = options;

    const useTree = hierarchyStyle === 'tree';
    const useConnectors = hierarchyStyle !== 'none';

    const hierarchicalResults = hasSectionHits(results)
        ? normalizeSectionHitsForHierarchy(results, maxHeadingsPerResult)
        : results;
    const groupField = hierarchyGroupBy || '';
    const groups = groupResultsByField(hierarchicalResults, groupField);
    let globalIndex = 0;

    return Object.entries(groups).map(([groupName, items]) => {
        const itemsHtml = items.map((result) => {
            // Render parent item
            const parentIndex = globalIndex++;
            const parentHtml = renderHierarchyParent(result, parentIndex, query, options);

            // Render matched heading children
            let childrenHtml = '';
            const headings = result.headings || [];
            const limitedHeadings = result.__sectionHitGroup ? headings : headings.slice(0, maxHeadingsPerResult);
            if (limitedHeadings.length > 0) {
                // Normalize levels: shallowest heading = depth 0
                const minLevel = Math.min(...limitedHeadings.map(h => h.level || 2));
                // Pre-compute depths: tree mode uses heading levels, flat/none use depth 0
                const depths = limitedHeadings.map(h => useTree ? (h.level || 2) - minLevel : 0);
                childrenHtml = limitedHeadings.map((heading, headingIndex) => {
                    const depth = depths[headingIndex];
                    // In flat/none mode all children are at depth 0, so isLast = last child overall
                    const isLastAtLevel = !depths.slice(headingIndex + 1).some(d => d === depth);
                    // Compute ancestor guide lines (only needed in tree mode)
                    const activeGuides = [];
                    if (useTree) {
                        const remainingDepths = depths.slice(headingIndex + 1);
                        for (let dl = 0; dl < depth; dl++) {
                            if (remainingDepths.some(d => d === dl)) {
                                activeGuides.push(dl);
                            }
                        }
                    }
                    return renderHeadingChild(result, heading, globalIndex++, query, options, isLastAtLevel, depth, activeGuides);
                }).join('');
            }

            const hasChildren = Boolean(childrenHtml);

            const unifiedClass = hierarchyDisplay === 'unified' ? ' sm-hierarchy-block--unified' : '';

            return `
                <div class="sm-hierarchy-block${hasChildren ? ' sm-hierarchy-block--has-children' : ''}${unifiedClass}">
                    ${hasChildren
                        ? parentHtml.replace('sm-result-item sm-hierarchy-parent', 'sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children')
                        : parentHtml}
                    ${hasChildren ? `<div class="sm-hierarchy-children${!useConnectors ? ' sm-hierarchy-children--no-connectors' : ''}">${childrenHtml}</div>` : ''}
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
        debug = false,
        persistQueryInUrl = false,
        queryParamName = 'smq',
    } = options;

    const title = result.title || result.name || 'Untitled';
    const snippet = result.snippet || '';
    const rawUrl = result.url || '#';
    const url = appendQueryParam(rawUrl, query, persistQueryInUrl ? queryParamName : '');
    const optionId = getOptionId(listboxId, index);
    const sourceIndex = result._index || result.index || '';
    const identityAttrs = renderIdentityAttrs(result);

    const highlightOptions = {
        enabled: enableHighlighting,
        tag: highlightTag,
        className: highlightClass,
    };

    const highlightedTitle = highlightMatches(title, query, {
        ...highlightOptions,
        terms: getHighlightTerms(result, 'title'),
    });
    const highlightedDesc = snippet ? renderSnippetHtml(result, snippet, query, {
        ...highlightOptions,
        terms: getHighlightTerms(result, 'snippet'),
    }) : '';

    const debugInfo = debug ? renderDebugInfo(result) : '';
    const hasHeadings = result.headings && result.headings.length > 0;
    const icon = hasHeadings ? documentIcon() : contentIcon();

    if (debug) {
        return `
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(title)}">
                <div class="sm-result-main">
                    ${icon}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${highlightedTitle}</span>
                        ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                    </div>
                    ${arrowSvg()}
                </div>
                ${debugInfo}
            </a>
        `;
    }

    return `
        <a class="sm-result-item sm-hierarchy-parent" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(title)}">
            ${icon}
            <div class="sm-result-content">
                <span class="sm-result-title">${highlightedTitle}</span>
                ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
            </div>
            ${arrowSvg()}
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
function renderHeadingChild(result, heading, index, query, options = {}, isLast = false, depth = 0, activeGuides = []) {
    const {
        listboxId,
        enableHighlighting = true,
        highlightTag = 'mark',
        highlightClass = '',
        debug = false,
        persistQueryInUrl = false,
        queryParamName = 'smq',
    } = options;

    const rawText = heading.title || heading.text || '';
    const text = rawText.replace(/^#+\s*/, '');
    const snippet = heading.snippet || '';
    const parsedLevel = Number.parseInt(heading.level, 10);
    const level = Number.isFinite(parsedLevel) ? Math.min(Math.max(parsedLevel, 1), 6) : 2;
    const anchorId = heading.id || (text ? slugifyHeading(text) : '');
    const baseUrl = result.url || '#';
    const rawUrl = heading.url || (anchorId ? `${baseUrl}#${anchorId}` : baseUrl);
    const url = appendQueryParam(rawUrl, query, persistQueryInUrl ? queryParamName : '');
    const optionId = getOptionId(listboxId, index);
    const sourceIndex = heading._index || heading.index || result._index || result.index || '';
    const identityAttrs = renderIdentityAttrs(heading, result);

    const highlightOptions = {
        enabled: enableHighlighting,
        tag: highlightTag,
        className: highlightClass,
    };

    const highlightedText = highlightMatches(text, query, {
        ...highlightOptions,
        terms: getHighlightTerms(heading, 'title') || getHighlightTerms(result, 'title'),
    });
    const highlightedDesc = snippet ? renderSnippetHtml(result, snippet, query, {
        ...highlightOptions,
        terms: getHighlightTerms(heading, 'snippet') || getHighlightTerms(result, 'snippet'),
    }) : '';

    const rowClass = isLast ? ' sm-hierarchy-child-row-last' : '';

    // Guide elements for ancestor depth vertical continuation lines
    const guidesHtml = activeGuides.map(dl =>
        `<div class="sm-hierarchy-guide" style="--sm-guide-depth:${dl}" aria-hidden="true"></div>`
    ).join('');

    // Build debug info for heading child
    let debugInfo = '';
    if (debug) {
        const childDebugItems = [];
        childDebugItems.push(debugItem('h', level, 'generic'));
        if (anchorId) {
            childDebugItems.push(debugItem('anchor', anchorId, 'generic'));
        }
        const elementId = elementIdentity(heading, result);
        if (elementId) {
            childDebugItems.push(debugItem('parent', elementId, 'generic'));
        }
        debugInfo = `<div class="sm-debug-info">${childDebugItems.join('')}</div>`;
    }

    if (debug) {
        return `
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${level} sm-hierarchy-depth-${depth}${rowClass}" style="--sm-hierarchy-depth:${depth}">
                ${guidesHtml}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${level} sm-debug-enabled" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(text)}">
                    <div class="sm-result-main">
                        ${hashIcon()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${highlightedText}</span>
                            ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                        </div>
                        ${arrowSvg()}
                    </div>
                    ${debugInfo}
                </a>
            </div>
        `;
    }

    return `
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${level} sm-hierarchy-depth-${depth}${rowClass}" style="--sm-hierarchy-depth:${depth}">
            ${guidesHtml}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${level}" id="${optionId}" role="option" aria-selected="false" href="${escapeHtml(url)}" data-index="${index}" data-source-index="${escapeHtml(sourceIndex)}"${identityAttrs} data-title="${escapeHtml(text)}">
                ${hashIcon()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${highlightedText}</span>
                    ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                </div>
                ${arrowSvg()}
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
                <div class="sm-result-item sm-recent-item" id="${getOptionId(listboxId, i)}" role="option" aria-selected="false" data-index="${i}" data-url="${escapeHtml(item.url || '')}" data-query="${escapeHtml(item.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${escapeHtml(item.title || item.query)}</span>
                    ${arrowSvg()}
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
 * Render search error state.
 *
 * @param {string} message - Error message to show
 * @returns {string} HTML string of error state
 */
export function renderErrorState(message) {
    return `
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${escapeHtml(message || 'Search failed.')}</p>
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
    const { query, results, recentSearches, loading, showRecent, error } = state;
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

    if (error) {
        return {
            html: renderErrorState(error),
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
