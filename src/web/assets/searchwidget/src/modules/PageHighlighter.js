/**
 * PageHighlighter - Destination-page highlighting orchestration
 *
 * Reads a search query from the current URL and applies simple substring
 * highlights to configured page-content scopes. This is intentionally separate
 * from the word-aware result highlighter in Highlighter.js.
 *
 * @module PageHighlighter
 * @author Search Manager
 * @since 5.55.0
 */

import { escapeRegex, parseQueryTerms } from './Highlighter.js';

const DEFAULT_QUERY_PARAM = 'smq';
const DEFAULT_CONTENT_SELECTOR = 'main, article, [data-search-content]';
const MAX_QUERY_LENGTH = 256;
const PAGE_HIGHLIGHT_STYLE_ID = 'sm-page-highlight-style';
const PAGE_HIGHLIGHT_REGISTRY = '__smPageHighlightRegistry';

/**
 * @typedef {'applied'|'no-query'|'no-scopes'|'no-terms'|'duplicate'|'superseded'|'invalid-selector'|'query-too-long'|'unsupported-environment'} PageHighlightStatus
 */

/**
 * @typedef {Object} PageHighlightResult
 * @property {PageHighlightStatus} status - Outcome of the requested run
 * @property {string} param - Effective URL query-parameter name
 * @property {string} selector - Effective content selector
 * @property {string} query - Effective trimmed URL query, when available
 * @property {string} language - Normalized page language, when available
 * @property {number} scopeCount - Number of matched content scopes
 * @property {number} markCount - Number of marks added by this call
 * @property {number} removedMarkCount - Number of prior channel-owned marks removed by this call
 * @property {string} [reason] - Optional diagnostic detail
 */

/**
 * Highlight destination-page content from a URL query parameter.
 *
 * Calls are explicit and asynchronous. A window-level registry coordinates
 * separately built widget and standalone bundles by parameter/selector channel.
 * Identical pending or completed runs are duplicates. A changed query or page
 * language supersedes pending work, removes only marks owned by that channel,
 * and applies the new run. Pass `force: true` to scan an already-applied scope
 * again after dynamic content is added; existing highlight elements are always
 * skipped.
 *
 * @param {Object} [options]
 * @param {string} [options.param='smq'] - URL query-parameter name
 * @param {string} [options.selector='main, article, [data-search-content]'] - Content scope selector
 * @param {boolean} [options.force=false] - Re-scan after a completed identical call
 * @returns {Promise<PageHighlightResult>} Inspectable asynchronous outcome
 * @since 5.55.0
 */
export async function highlightFromUrl(options = {}) {
    const param = normalizeOption(options?.param, DEFAULT_QUERY_PARAM);
    const selector = normalizeOption(options?.selector, DEFAULT_CONTENT_SELECTOR);
    const force = options?.force === true;
    const unsupported = unsupportedEnvironmentResult(param, selector);
    if (unsupported) {
        return unsupported;
    }

    const win = window;
    const doc = document;
    const registry = getPageHighlightRegistry(win);
    const channelKey = JSON.stringify([param, selector]);
    const query = new URLSearchParams(win.location.search).get(param)?.trim() || '';
    if (!query) {
        return createResult('no-query', param, selector, {
            removedMarkCount: clearChannel(registry, channelKey),
        });
    }

    const language = getPageLanguage(doc);
    if (Array.from(query).length > MAX_QUERY_LENGTH) {
        return createResult('query-too-long', param, selector, {
            query,
            language,
            removedMarkCount: clearChannel(registry, channelKey),
            reason: `Queries are limited to ${MAX_QUERY_LENGTH} characters.`,
        });
    }

    const runKey = JSON.stringify([param, selector, query, language]);
    const existing = registry.get(channelKey);
    const sameRun = existing?.runKey === runKey;
    if (sameRun && (existing.state === 'pending' || !force)) {
        return createResult('duplicate', param, selector, {
            query,
            language,
            reason: existing.state,
        });
    }

    const removedMarkCount = sameRun ? 0 : removeOwnedMarks(existing?.marks);
    const entry = {
        state: 'pending',
        runKey,
        marks: sameRun && existing?.marks instanceof Set ? existing.marks : new Set(),
        previousResult: sameRun && existing?.state === 'applied' ? existing.result : null,
        promise: null,
    };
    const work = scheduleHighlight(doc, win, () => {
        if (registry.get(channelKey) !== entry) {
            return createResult('superseded', param, selector, {
                query,
                language,
                removedMarkCount,
                reason: 'newer-run',
            });
        }

        return applyPageHighlights({
            doc,
            param,
            selector,
            query,
            language,
            ownedMarks: entry.marks,
            removedMarkCount,
        });
    });
    entry.promise = work;
    registry.set(channelKey, entry);

    const result = await work;
    if (registry.get(channelKey) === entry) {
        if (result.status === 'applied') {
            entry.state = 'applied';
            entry.result = result;
            entry.previousResult = null;
            entry.promise = null;
        } else if (entry.previousResult) {
            entry.state = 'applied';
            entry.result = entry.previousResult;
            entry.previousResult = null;
            entry.promise = null;
        } else {
            registry.delete(channelKey);
        }
    }

    return result;
}

function normalizeOption(value, fallback) {
    return typeof value === 'string' && value.trim() ? value.trim() : fallback;
}

function unsupportedEnvironmentResult(param, selector) {
    if (typeof window === 'undefined' || typeof document === 'undefined'
        || typeof URLSearchParams === 'undefined'
        || typeof document.querySelectorAll !== 'function'
        || typeof document.createTreeWalker !== 'function'
        || typeof document.defaultView?.NodeFilter === 'undefined') {
        return createResult('unsupported-environment', param, selector);
    }

    return null;
}

function scheduleHighlight(doc, win, callback) {
    return new Promise((resolve) => {
        const run = () => resolve(callback());
        if (doc.readyState === 'loading') {
            doc.addEventListener('DOMContentLoaded', run, { once: true });
            return;
        }

        if (typeof win.requestAnimationFrame === 'function') {
            win.requestAnimationFrame(run);
        } else {
            win.setTimeout(run, 0);
        }
    });
}

function applyPageHighlights({ doc, param, selector, query, language, ownedMarks, removedMarkCount }) {
    let scopes;
    try {
        scopes = Array.from(doc.querySelectorAll(selector));
    } catch (error) {
        return createResult('invalid-selector', param, selector, {
            query,
            language,
            removedMarkCount,
            reason: error instanceof Error ? error.message : String(error),
        });
    }

    if (scopes.length === 0) {
        return createResult('no-scopes', param, selector, {
            query,
            language,
            removedMarkCount,
        });
    }

    const terms = [...new Set(
        parseQueryTerms(query, null, language)
            .map(term => term.trim())
            .filter(term => term.length >= 2)
    )];
    const pattern = terms
        .map(term => escapeRegex(term))
        .filter(Boolean)
        .sort((left, right) => right.length - left.length)
        .join('|');
    if (!pattern) {
        return createResult('no-terms', param, selector, {
            query,
            language,
            scopeCount: scopes.length,
            removedMarkCount,
        });
    }

    ensurePageHighlightStyles(doc);
    const regex = new RegExp(`(${pattern})`, 'gi');
    let markCount = 0;
    scopes.forEach((scope) => {
        markCount += highlightTextNodesInScope(doc, scope, regex, ownedMarks);
    });

    return createResult('applied', param, selector, {
        query,
        language,
        scopeCount: scopes.length,
        markCount,
        removedMarkCount,
    });
}

function ensurePageHighlightStyles(doc) {
    if (doc.getElementById(PAGE_HIGHLIGHT_STYLE_ID)) {
        return;
    }

    const style = doc.createElement('style');
    style.id = PAGE_HIGHLIGHT_STYLE_ID;
    style.textContent = `
        .sm-page-highlight {
            background: var(--sm-highlight-bg, #fef08a);
            color: var(--sm-highlight-color, #854d0e);
            border-radius: 0.15em;
            padding: 0 0.08em;
        }
    `;
    (doc.head || doc.documentElement).appendChild(style);
}

function highlightTextNodesInScope(doc, scope, regex, ownedMarks) {
    const nodeFilter = doc.defaultView.NodeFilter;

    const walker = doc.createTreeWalker(
        scope,
        nodeFilter.SHOW_TEXT,
        {
            acceptNode: (node) => {
                const text = node.nodeValue;
                if (!text || !text.trim()) {
                    return nodeFilter.FILTER_REJECT;
                }

                const parent = node.parentElement;
                if (!parent || parent.closest('script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal')) {
                    return nodeFilter.FILTER_REJECT;
                }

                return nodeFilter.FILTER_ACCEPT;
            },
        }
    );

    const textNodes = [];
    while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
    }

    let markCount = 0;
    textNodes.forEach((node) => {
        const text = node.nodeValue || '';
        regex.lastIndex = 0;
        if (!regex.test(text)) {
            return;
        }

        const fragment = doc.createDocumentFragment();
        let cursor = 0;
        regex.lastIndex = 0;
        for (const match of text.matchAll(regex)) {
            const matchText = match[0];
            const matchIndex = match.index ?? -1;
            if (matchIndex < 0) {
                continue;
            }
            if (matchIndex > cursor) {
                fragment.appendChild(doc.createTextNode(text.slice(cursor, matchIndex)));
            }

            const mark = doc.createElement('mark');
            mark.className = 'sm-highlight sm-page-highlight';
            mark.textContent = matchText;
            fragment.appendChild(mark);
            ownedMarks.add(mark);
            markCount++;
            cursor = matchIndex + matchText.length;
        }

        if (cursor < text.length) {
            fragment.appendChild(doc.createTextNode(text.slice(cursor)));
        }
        node.parentNode?.replaceChild(fragment, node);
    });

    return markCount;
}

function getPageLanguage(doc) {
    return String(doc.documentElement.lang || 'en')
        .trim()
        .toLowerCase()
        .replace(/_/g, '-') || 'en';
}

function getPageHighlightRegistry(win) {
    const existing = win[PAGE_HIGHLIGHT_REGISTRY];
    if (existing instanceof Map) {
        return existing;
    }

    const registry = new Map();
    win[PAGE_HIGHLIGHT_REGISTRY] = registry;
    return registry;
}

function clearChannel(registry, channelKey) {
    const existing = registry.get(channelKey);
    if (!existing) {
        return 0;
    }

    const removedMarkCount = removeOwnedMarks(existing.marks);
    if (registry.get(channelKey) === existing) {
        registry.delete(channelKey);
    }

    return removedMarkCount;
}

function removeOwnedMarks(marks) {
    if (!(marks instanceof Set)) {
        return 0;
    }

    const affectedParents = new Set();
    let removedMarkCount = 0;
    marks.forEach((mark) => {
        const parent = mark?.parentNode;
        if (!parent) {
            return;
        }

        parent.replaceChild(mark.ownerDocument.createTextNode(mark.textContent || ''), mark);
        affectedParents.add(parent);
        removedMarkCount++;
    });
    marks.clear();
    affectedParents.forEach(parent => parent.normalize());

    return removedMarkCount;
}

function createResult(status, param, selector, overrides = {}) {
    return {
        status,
        param,
        selector,
        query: '',
        language: '',
        scopeCount: 0,
        markCount: 0,
        removedMarkCount: 0,
        ...overrides,
    };
}
