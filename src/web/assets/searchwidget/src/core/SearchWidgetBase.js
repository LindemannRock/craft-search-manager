/**
 * SearchWidgetBase - Abstract base class for search widgets
 *
 * Provides common functionality for all search widget types:
 * - Configuration parsing
 * - State management
 * - Search execution with debouncing
 * - Result rendering
 * - Keyboard navigation
 * - Accessibility (ARIA, live region announcements)
 * - Analytics tracking
 * - Recently viewed items
 * - Event dispatching
 *
 * Subclasses implement widget-specific behavior:
 * - UI rendering (modal, page, inline)
 * - Open/close behavior
 * - Layout-specific interactions
 *
 * @abstract
 * @extends HTMLElement
 * @author Search Manager
 * @since 5.32.0
 */

import { getSearchScopeKey, getSingleConfiguredIndex, parseConfig } from './ConfigParser.js';
import { createStateManager, DEFAULT_STATE } from './StateManager.js';
import { performSearch, trackClick, trackSearch } from '../modules/SearchService.js';
import { loadRecentlyViewed, saveRecentlyViewed, clearRecentlyViewed } from '../modules/RecentlyViewed.js';
import { applyStylesToElement } from '../modules/StyleUtils.js';
import { parseQueryTerms, escapeRegex } from '../modules/Highlighter.js';
import { appendQueryParam } from '../modules/UrlUtils.js';
import {
    renderResults,
    renderRecentlyViewed,
    renderEmptyState,
    getContentToRender,
} from '../modules/ResultRenderer.js';
import { renderDebugToolbarContent } from '../modules/DebugToolbar.js';
import {
    createKeyboardNavigator,
    updateSelectionState,
    attachHoverHandlers,
} from '../modules/KeyboardNavigator.js';
import {
    generateId,
    createLiveRegion,
    announce,
    getResultsAnnouncement,
    getLoadingAnnouncement,
    getRecentlyViewedAnnouncement,
    updateComboboxAria,
} from '../modules/A11yUtils.js';

const PAGE_HIGHLIGHT_STYLE_ID = 'sm-page-highlight-style';
const PAGE_HIGHLIGHT_REGISTRY = '__smPageHighlightRegistry';
const HOTKEY_HANDLED_FLAG = '__searchManagerHotkeyHandled';

let activeOpenWidget = null;

/**
 * Abstract base class for search widgets
 *
 * @abstract
 */
class SearchWidgetBase extends HTMLElement {
    /**
     * Initialize the base widget
     *
     * Sets up shadow DOM, state management, unique IDs, and binds methods.
     * Subclasses should call super() in their constructor.
     */
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });

        // Configuration (set in connectedCallback)
        this.config = null;

        // State management with change notifications
        this.state = createStateManager(
            { ...DEFAULT_STATE },
            this.handleStateChange.bind(this)
        );

        // Monotonic search sequence for stale-response protection. Responses
        // only apply when still current; superseded requests are discarded
        // instead of aborted so Chrome's Network panel shows no (canceled) rows.
        this.searchSequence = 0;

        // Debounce timer for search input
        this.debounceTimer = null;

        // Analytics idle timeout timer (for "browsing" behavior tracking)
        this.analyticsIdleTimer = null;

        // Last query that was tracked for analytics (prevent double tracking)
        this.lastTrackedQuery = null;

        // Cache state of the most recent search response — forwarded with the
        // intent ping so the server can record an accurate executionTime for
        // dashboard cache stats. {cached: bool, took: number} | null
        this.lastSearchCacheState = null;

        // Unique IDs for ARIA accessibility
        this.listboxId = generateId('sm-listbox');
        this.inputId = generateId('sm-input');

        // Live region for screen reader announcements
        this.liveRegion = null;

        // Keyboard navigator instance
        this.keyboardNavigator = null;

        // Cached DOM element references (set by subclass in render())
        this.elements = {};

        // Bind methods to preserve context
        this.handleInput = this.handleInput.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleResultClick = this.handleResultClick.bind(this);
    }

    // =========================================================================
    // ABSTRACT METHODS - Must be implemented by subclasses
    // =========================================================================

    /**
     * Get the widget type identifier
     *
     * @abstract
     * @returns {string} Widget type: 'modal', 'page', or 'inline'
     * @throws {Error} If not implemented by subclass
     *
     * @example
     * get widgetType() {
     *   return 'modal';
     * }
     */
    get widgetType() {
        throw new Error('Subclass must implement widgetType getter');
    }

    /**
     * Render the widget HTML structure
     *
     * Subclasses must implement this to render their specific UI.
     * Should set this.elements with references to key DOM elements.
     *
     * @abstract
     * @throws {Error} If not implemented by subclass
     *
     * @example
     * render() {
     *   this.shadowRoot.innerHTML = `<style>${styles}</style><div>...</div>`;
     *   this.elements = {
     *     input: this.shadowRoot.querySelector('.sm-input'),
     *     results: this.shadowRoot.querySelector('.sm-results'),
     *   };
     * }
     */
    render() {
        throw new Error('Subclass must implement render()');
    }

    /**
     * Get the results container element
     *
     * @abstract
     * @returns {HTMLElement} Results container
     * @throws {Error} If not implemented by subclass
     */
    getResultsContainer() {
        throw new Error('Subclass must implement getResultsContainer()');
    }

    /**
     * Get the search input element
     *
     * @abstract
     * @returns {HTMLInputElement} Search input
     * @throws {Error} If not implemented by subclass
     */
    getInputElement() {
        throw new Error('Subclass must implement getInputElement()');
    }

    /**
     * Get the loading indicator element (optional)
     *
     * @returns {HTMLElement|null} Loading element or null
     */
    getLoadingElement() {
        return this.elements.loading || null;
    }

    /**
     * Get the debug toolbar element (optional)
     *
     * Subclasses can override to provide a dedicated debug toolbar container.
     *
     * @returns {HTMLElement|null} Debug toolbar element or null
     */
    getDebugToolbarElement() {
        return this.elements.debugToolbar || null;
    }

    // =========================================================================
    // LIFECYCLE METHODS
    // =========================================================================

    /**
     * Called when element is added to the DOM
     *
     * Subclasses should call super.connectedCallback() first,
     * then perform their own initialization.
     */
    connectedCallback() {
        // Parse configuration from attributes
        this.config = parseConfig(this, this.widgetType);

        // Load recently viewed items from localStorage
        this.state.set({
            recentlyViewed: loadRecentlyViewed(getSearchScopeKey(this.config)),
        });

        // Create keyboard navigator
        this.keyboardNavigator = createKeyboardNavigator(
            {
                onSelect: (index) => this.selectResultAtIndex(index),
                onIndexChange: (index) => this.state.set({ selectedIndex: index }),
                onEscape: () => this.handleEscape(),
            },
            { listboxId: this.listboxId }
        );

        this.applyDestinationPageHighlight();
    }

    /**
     * Called when element is removed from the DOM
     *
     * Subclasses should call super.disconnectedCallback() to ensure cleanup.
     */
    disconnectedCallback() {
        this.unregisterOpenWidget();

        // Invalidate any in-flight search (its response is discarded as stale)
        this.searchSequence++;

        // Clear debounce timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = null;
        }
    }

    // =========================================================================
    // OPEN WIDGET REGISTRY
    // =========================================================================

    /**
     * Register this widget as the only open widget instance on the page.
     *
     * @protected
     */
    registerOpenWidget() {
        if (activeOpenWidget && activeOpenWidget !== this && typeof activeOpenWidget.close === 'function') {
            activeOpenWidget.close({
                reason: 'replace',
                replacedBy: this,
                source: 'replace',
            });
        }

        activeOpenWidget = this;
    }

    /**
     * Clear this widget from the shared open-widget registry.
     *
     * @protected
     */
    unregisterOpenWidget() {
        if (activeOpenWidget === this) {
            activeOpenWidget = null;
        }
    }

    /**
     * Claim a global hotkey event once per keypress.
     *
     * If the currently open widget shares this hotkey, it owns the press so
     * repeated shared hotkeys close the active widget instead of opening a
     * later instance in the same bubbling pass.
     *
     * @protected
     * @param {KeyboardEvent} event
     * @param {string} hotkey
     * @returns {boolean}
     */
    claimHotkeyEvent(event, hotkey) {
        if (event[HOTKEY_HANDLED_FLAG]) {
            return false;
        }

        if (
            activeOpenWidget
            && activeOpenWidget !== this
            && activeOpenWidget.state?.get('isOpen')
            && activeOpenWidget.config?.triggerHotkey?.toLowerCase() === hotkey
        ) {
            return false;
        }

        event[HOTKEY_HANDLED_FLAG] = true;
        return true;
    }

    /**
     * Called when an observed attribute changes
     *
     * Triggers re-render if the widget has been rendered.
     *
     * @param {string} name - Attribute name
     * @param {string|null} oldValue - Previous value
     * @param {string|null} newValue - New value
     */
    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue !== newValue && this.shadowRoot.children.length > 0) {
            // Re-parse config
            this.config = parseConfig(this, this.widgetType);
            // Re-render
            this.render();
            // Re-apply custom styles
            this.applyCustomStyles();
        }
    }

    // =========================================================================
    // STATE CHANGE HANDLING
    // =========================================================================

    /**
     * Handle state changes
     *
     * Called automatically when state.set() is used.
     * Subclasses can override to handle additional state changes.
     *
     * @protected
     * @param {Object} newState - The new state object
     * @param {Array<string>} changedKeys - Keys that changed
     */
    handleStateChange(newState, changedKeys) {
        // Update results display when results, query, or recently viewed items change
        if (changedKeys.includes('results') || changedKeys.includes('query') || changedKeys.includes('recentlyViewed') || changedKeys.includes('error')) {
            this.renderResultsContent();
        }

        // Update debug toolbar when results or meta changes
        if (changedKeys.includes('results') || changedKeys.includes('meta')) {
            this.updateDebugToolbar();
        }

        // Update selection visual state
        if (changedKeys.includes('selectedIndex')) {
            this.updateSelectionVisual();
        }

        // Update loading indicator
        if (changedKeys.includes('loading')) {
            this.updateLoadingVisual();
        }
    }

    // =========================================================================
    // SEARCH FUNCTIONALITY
    // =========================================================================

    /**
     * Handle input change with debouncing
     *
     * @param {Event} e - Input event
     */
    handleInput(e) {
        const query = e.target.value;

        // Sync the clear button (present in the modal widget)
        if (this.elements && this.elements.clear) {
            this.elements.clear.hidden = !query;
        }

        // Update state
        this.state.set({
            query,
            selectedIndex: -1,
        });

        // Clear pending search
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        // Cancel analytics idle timer (user is still typing)
        if (this.analyticsIdleTimer) {
            clearTimeout(this.analyticsIdleTimer);
            this.analyticsIdleTimer = null;
        }

        // If query is empty, just re-render (shows recently viewed or empty state)
        if (!query.trim()) {
            this.state.set({ results: [] });
            return;
        }

        // Don't search if below minimum characters
        if (query.length < this.config.searchMinChars) {
            return;
        }

        // Debounced search
        this.debounceTimer = setTimeout(() => {
            this.executeSearch(query);
        }, this.config.searchDebounceMs);
    }

    /**
     * Execute a search query
     *
     * @param {string} query - Search query
     */
    async executeSearch(query) {
        // Supersede any in-flight search — its response becomes stale and is
        // discarded on arrival (no abort, so no red canceled Network rows)
        const requestId = ++this.searchSequence;

        // Set loading state
        this.state.set({ loading: true, error: null });

        // Announce loading for screen readers
        if (this.liveRegion) {
            announce(this.liveRegion, getLoadingAnnouncement(this.config.translations));
        }

        try {
            const { results, meta } = await performSearch({
                query,
                endpoint: this.config.searchEndpoint,
                indexHandles: this.config.indexHandles,
                siteId: this.config.siteId,
                resultsLimit: this.config.resultsLimit,
                resultsRequireUrl: this.config.resultsRequireUrl,
                snippetIncludeCodeBlocks: this.config.snippetIncludeCodeBlocks,
                snippetMode: this.config.snippetMode,
                snippetMaxLength: this.config.snippetMaxLength,
                snippetCleanMarkdown: this.config.snippetCleanMarkdown,
                debugEnabled: this.config.debugEnabled,
                apiKey: this.config.apiKey,
                translations: this.config.translations,
            });

            // Stale response — a newer search owns the UI state
            if (requestId !== this.searchSequence) {
                return;
            }

            // Update state with results and debug meta
            this.state.set({
                results,
                meta,
                loading: false,
                selectedIndex: results.length > 0 ? 0 : -1,
            });

            // Capture cache state for the eventual intent ping. Forwarding this
            // to /search/track-search lets the server record an accurate
            // executionTime so dashboard cache stats reflect widget usage.
            if (meta && typeof meta.cached === 'boolean') {
                this.lastSearchCacheState = {
                    cached: meta.cached,
                    took: typeof meta.took === 'number' ? meta.took : null,
                };
            } else {
                this.lastSearchCacheState = null;
            }

            // Announce results for screen readers
            if (this.liveRegion) {
                announce(this.liveRegion, getResultsAnnouncement(results.length, query, this.config.translations));
            }

            // Dispatch search event
            this.dispatchWidgetEvent('search', { query, results, meta });

            // Start analytics idle timer (track "browsing" behavior)
            this.startAnalyticsIdleTimer(query, results.length);

        } catch (error) {
            // Stale failure — a newer search owns the UI state
            if (requestId !== this.searchSequence) {
                return;
            }

            // Ignore abort errors (browser can cancel fetches on page unload)
            if (error.name === 'AbortError') {
                return;
            }

            console.error('Search error:', error);

            this.state.set({
                results: [],
                loading: false,
                error: error.message,
            });

            // Dispatch error event
            this.dispatchWidgetEvent('error', { query, error: error.message });
        }
    }

    // =========================================================================
    // RESULT RENDERING
    // =========================================================================

    /**
     * Render results content into the results container
     *
     * Determines what to show based on current state and renders it.
     */
    renderResultsContent() {
        const container = this.getResultsContainer();
        if (!container) return;

        const state = this.state.getAll();
        const {
            recentlyViewedEnabled,
            resultsGroupingEnabled,
            highlightResultsEnabled,
            highlightTag,
            highlightClass,
            loadingIndicatorEnabled,
            debugEnabled,
        } = this.config;

        // Get appropriate content based on state
        const { html, hasResults, showListbox } = getContentToRender(
            {
                query: state.query,
                results: state.results,
                recentlyViewed: state.recentlyViewed,
                loading: state.loading,
                error: state.error,
                recentlyViewedEnabled,
            },
            {
                listboxId: this.listboxId,
                resultsGroupingEnabled,
                highlightResultsEnabled,
                highlightTag,
                highlightClass,
                loadingIndicatorEnabled,
                debugEnabled,
                translations: this.config.translations,
                highlightDestinationPersistQuery: this.config.highlightDestinationEnabled && this.config.highlightDestinationPersistQuery,
                highlightDestinationQueryParam: this.config.highlightDestinationQueryParam,
                promotionBadge: this.config.promotionBadge,
                // Hierarchical display options
                resultsLayout: this.config.resultsLayout,
                hierarchyGroupBy: this.config.hierarchyGroupBy,
                hierarchyStyle: this.config.hierarchyStyle,
                hierarchyDisplay: this.config.hierarchyDisplay,
                hierarchyMaxHeadings: this.config.hierarchyMaxHeadings,
            }
        );

        // Update container content
        container.innerHTML = html;

        // Update ARIA role based on whether we have list items
        if (showListbox) {
            container.setAttribute('role', 'listbox');
        } else {
            container.removeAttribute('role');
        }

        // Update combobox ARIA state
        const input = this.getInputElement();
        if (input) {
            updateComboboxAria(input, {
                expanded: hasResults,
                activeDescendant: null,
                listboxId: this.listboxId,
            });
        }

        // Announce for screen readers
        if (this.liveRegion && !state.loading) {
            if (state.query && state.results.length === 0) {
                announce(this.liveRegion, getResultsAnnouncement(0, state.query, this.config.translations));
            } else if (!state.query && state.recentlyViewed.length > 0 && recentlyViewedEnabled) {
                announce(this.liveRegion, getRecentlyViewedAnnouncement(state.recentlyViewed.length, this.config.translations));
            }
        }

        // Attach event handlers to new elements
        this.attachResultHandlers();

        // Handle recently-viewed clear button
        const clearBtn = container.querySelector('.sm-recently-viewed-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                clearRecentlyViewed(getSearchScopeKey(this.config));
                this.state.set({ recentlyViewed: [] });
            });
        }

        // Auto-select first result if we have results
        if (hasResults && state.results.length > 0) {
            this.state.set({ selectedIndex: 0 });
        }
    }

    /**
     * Attach event handlers to result items
     *
     * Called after rendering results to wire up click and hover handlers.
     */
    attachResultHandlers() {
        const container = this.getResultsContainer();
        if (!container) return;

        const items = container.querySelectorAll('.sm-result-item');

        // Attach click handlers
        items.forEach(item => {
            item.addEventListener('click', (e) => this.handleResultClick(e, item));
        });

        // Attach hover handlers for selection sync
        attachHoverHandlers(items, (index) => {
            this.state.set({ selectedIndex: index });
        });
    }

    // =========================================================================
    // SELECTION AND NAVIGATION
    // =========================================================================

    /**
     * Update visual selection state
     *
     * Highlights the selected item and updates ARIA attributes.
     */
    updateSelectionVisual() {
        const container = this.getResultsContainer();
        const input = this.getInputElement();
        if (!container) return;

        const items = container.querySelectorAll('.sm-result-item');
        const selectedIndex = this.state.get('selectedIndex');

        updateSelectionState(items, selectedIndex, {
            scrollContainer: container,
            inputElement: input,
            listboxId: this.listboxId,
        });
    }

    /**
     * Handle keyboard navigation
     *
     * @param {KeyboardEvent} e - Keyboard event
     */
    handleKeydown(e) {
        const container = this.getResultsContainer();
        if (!container) return;

        const items = container.querySelectorAll('.sm-result-item');
        const currentIndex = this.state.get('selectedIndex');

        // Track search analytics on Enter key (explicit intent signal)
        // This fires before selection so we track even if no item is selected
        if (e.key === 'Enter') {
            const query = this.state.get('query');
            const results = this.state.get('results') || [];
            if (query && results.length > 0) {
                this.trackSearchAnalytics(query, results.length, 'enter');
            }
        }

        // Delegate to keyboard navigator
        this.keyboardNavigator.handleKeydown(e, items.length, currentIndex);
    }

    /**
     * Select and activate the result at the given index
     *
     * @param {number} index - Index of result to select
     */
    selectResultAtIndex(index) {
        const container = this.getResultsContainer();
        if (!container) return;

        const items = container.querySelectorAll('.sm-result-item');
        if (index >= 0 && items[index]) {
            items[index].click();
        }
    }

    /**
     * Handle Escape key press
     *
     * Subclasses should override to implement close behavior.
     * @protected
     */
    handleEscape() {
        // Default: do nothing. Modal will override to close.
    }

    // =========================================================================
    // RESULT CLICK HANDLING
    // =========================================================================

    /**
     * Handle result item click
     *
     * Handles navigation, recently-viewed saving, and analytics tracking.
     *
     * @param {Event} e - Click event
     * @param {HTMLElement} item - Clicked item element
     */
    handleResultClick(e, item) {
        const href = item.getAttribute('href');
        const dataUrl = item.dataset.url;
        const url = href || dataUrl;
        const title = item.dataset.title || item.querySelector('.sm-result-title')?.textContent;
        const id = item.dataset.id;
        const elementId = item.dataset.elementId || id;
        const query = item.dataset.query || this.state.get('query');
        const isRecentlyViewedItem = item.classList.contains('sm-recently-viewed-item');
        const destinationUrl = appendQueryParam(
            url,
            query,
            (this.config.highlightDestinationEnabled && this.config.highlightDestinationPersistQuery) ? this.config.highlightDestinationQueryParam : ''
        );

        // Save to recently viewed (for regular results, not re-clicking recently viewed items)
        if (!isRecentlyViewedItem && query) {
            const updatedRecent = saveRecentlyViewed(
                getSearchScopeKey(this.config),
                query,
                { title, url },
                this.config.recentlyViewedLimit
            );
            this.state.set({ recentlyViewed: updatedRecent });
        }

        // Track analytics for search results (not recently viewed items)
        const sourceIndex = item.dataset.sourceIndex || getSingleConfiguredIndex(this.config);
        if (elementId && sourceIndex) {
            trackClick({
                endpoint: this.config.trackClickEndpoint,
                elementId,
                query,
                index: sourceIndex,
                apiKey: this.config.apiKey,
            });
        }

        // Track search analytics on click (explicit intent signal)
        if (!isRecentlyViewedItem && query) {
            this.trackSearchAnalytics(query, this.state.get('results')?.length || 0, 'click');
        }

        // Dispatch result-click event
        this.dispatchWidgetEvent('result-click', {
            id,
            elementId,
            title,
            url: destinationUrl,
            query,
            isRecentlyViewed: isRecentlyViewedItem,
        });

        // Handle navigation/action
        if (url && url !== '#') {
            // For <a> elements, browser handles navigation naturally
            // For recently viewed items (<div> elements), navigate explicitly
            if (isRecentlyViewedItem) {
                e.preventDefault();
                window.location.href = destinationUrl;
            }
            // Let subclass know a result was selected (for closing modal, etc.)
            this.onResultSelected(destinationUrl, title, id);
        } else if (query) {
            // No URL - populate search and trigger new search
            e.preventDefault();
            const input = this.getInputElement();
            if (input) {
                input.value = query;
                this.state.set({ query });
                this.executeSearch(query);
            }
        }
    }

    /**
     * Called when a result with a URL is selected
     *
     * Subclasses can override to perform actions like closing the modal.
     *
     * @protected
     * @param {string} url - Result URL
     * @param {string} title - Result title
     * @param {string|number} id - Result ID
     */
    onResultSelected(url, title, id) {
        // Default: do nothing. Modal will override to close.
    }

    /**
     * Apply destination-page highlights once per page load when a query
     * parameter is present (for example, ?smq=redis).
     */
    applyDestinationPageHighlight() {
        if (!this.config.highlightDestinationEnabled || typeof window === 'undefined' || typeof document === 'undefined') {
            return;
        }

        const queryParamName = this.config.highlightDestinationQueryParam || 'smq';
        const selector = this.config.highlightDestinationContentSelector || 'main, article, [data-search-content]';
        const query = new URLSearchParams(window.location.search).get(queryParamName);

        if (!query || !query.trim()) {
            return;
        }

        const registry = this.getPageHighlightRegistry();
        const key = `${queryParamName}::${selector}`;
        if (registry.has(key)) {
            return;
        }
        registry.add(key);

        const run = () => {
            this.ensurePageHighlightStyles();
            this.highlightDestinationNodes(query.trim(), selector, key);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            window.requestAnimationFrame(run);
        }
    }

    /**
     * Inject global styles for page-level highlights.
     */
    ensurePageHighlightStyles() {
        if (document.getElementById(PAGE_HIGHLIGHT_STYLE_ID)) {
            return;
        }

        const style = document.createElement('style');
        style.id = PAGE_HIGHLIGHT_STYLE_ID;
        style.textContent = `
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `;

        document.head.appendChild(style);
    }

    /**
     * Highlight matching text in configured destination content areas.
     *
     * @param {string} query - Search query to highlight
     * @param {string} selector - CSS selector for content scopes
     * @param {string} key - De-duplication key for this highlight run
     */
    highlightDestinationNodes(query, selector, key) {
        const scopes = Array.from(document.querySelectorAll(selector));
        if (scopes.length === 0) {
            return;
        }

        const terms = [...new Set(parseQueryTerms(query).map(t => t.trim()).filter(t => t.length >= 2))];
        if (terms.length === 0) {
            return;
        }

        const pattern = terms
            .map(term => escapeRegex(term))
            .filter(Boolean)
            .sort((a, b) => b.length - a.length)
            .join('|');

        if (!pattern) {
            return;
        }

        const regex = new RegExp(`(${pattern})`, 'gi');
        scopes.forEach((scope) => {
            if (scope.getAttribute('data-sm-highlighted') === key) {
                return;
            }
            this.highlightTextNodesInScope(scope, regex);
            scope.setAttribute('data-sm-highlighted', key);
        });
    }

    /**
     * Wrap matching text nodes with <mark class="sm-page-highlight">.
     *
     * @param {Element} scope - Root element to process
     * @param {RegExp} regex - Global, case-insensitive regex
     */
    highlightTextNodesInScope(scope, regex) {
        const walker = document.createTreeWalker(
            scope,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: (node) => {
                    const text = node.nodeValue;
                    if (!text || !text.trim()) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    const parent = node.parentElement;
                    if (!parent) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    if (parent.closest('script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal')) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    return NodeFilter.FILTER_ACCEPT;
                },
            }
        );

        const textNodes = [];
        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        textNodes.forEach((node) => {
            const text = node.nodeValue || '';
            regex.lastIndex = 0;
            if (!regex.test(text)) {
                return;
            }

            const fragment = document.createDocumentFragment();
            let cursor = 0;

            regex.lastIndex = 0;
            const matches = text.matchAll(regex);
            for (const match of matches) {
                const matchText = match[0];
                const matchIndex = match.index ?? -1;

                if (matchIndex < 0) {
                    continue;
                }

                if (matchIndex > cursor) {
                    fragment.appendChild(document.createTextNode(text.slice(cursor, matchIndex)));
                }

                const mark = document.createElement('mark');
                mark.className = 'sm-highlight sm-page-highlight';
                mark.textContent = matchText;
                fragment.appendChild(mark);

                cursor = matchIndex + matchText.length;
            }

            if (cursor < text.length) {
                fragment.appendChild(document.createTextNode(text.slice(cursor)));
            }

            node.parentNode?.replaceChild(fragment, node);
        });
    }

    /**
     * Get or initialize the global page-highlight registry.
     *
     * @returns {Set<string>} Registry of applied highlight keys
     */
    getPageHighlightRegistry() {
        const existing = window[PAGE_HIGHLIGHT_REGISTRY];
        if (existing instanceof Set) {
            return existing;
        }

        const registry = new Set();
        window[PAGE_HIGHLIGHT_REGISTRY] = registry;
        return registry;
    }

    // =========================================================================
    // LOADING STATE
    // =========================================================================

    /**
     * Update loading indicator visibility
     *
     * Respects loadingIndicatorEnabled config - if disabled, spinner stays hidden.
     */
    updateLoadingVisual() {
        const loading = this.getLoadingElement();
        if (loading) {
            const isLoading = this.state.get('loading');
            const showIndicator = this.config?.loadingIndicatorEnabled !== false;
            loading.hidden = !isLoading || !showIndicator;
        }
    }

    /**
     * Update the debug toolbar
     *
     * Shows/hides and populates the debug toolbar based on state.
     */
    updateDebugToolbar() {
        const toolbar = this.getDebugToolbarElement();
        if (!toolbar) return;

        const { debugEnabled } = this.config;
        const state = this.state.getAll();

        // Hide toolbar if debug is off or no results
        if (!debugEnabled || !state.meta || state.results.length === 0) {
            toolbar.hidden = true;
            return;
        }

        // Check if toolbar is collapsed (preserve state across updates)
        const isCollapsed = toolbar.classList.contains('sm-collapsed');

        // Render and show toolbar
        toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed, this.config.translations);
        toolbar.hidden = false;

        // Maintain collapsed class
        if (isCollapsed) {
            toolbar.classList.add('sm-collapsed');
        }

        // Attach toggle handlers
        this.attachDebugToolbarHandlers(toolbar);
    }

    /**
     * Attach click handlers to debug toolbar elements
     */
    attachDebugToolbarHandlers(toolbar) {
        // Toggle button (when expanded)
        const toggleBtn = toolbar.querySelector('.sm-toolbar-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleDebugToolbar();
            });
        }

        // Collapsed bar (entire bar is clickable when collapsed)
        const collapsedBar = toolbar.querySelector('.sm-toolbar-collapsed-bar');
        if (collapsedBar) {
            collapsedBar.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.toggleDebugToolbar();
            });
        }
    }

    /**
     * Toggle debug toolbar collapsed state
     */
    toggleDebugToolbar() {
        const toolbar = this.getDebugToolbarElement();
        if (!toolbar) return;

        const isCollapsed = toolbar.classList.toggle('sm-collapsed');
        const state = this.state.getAll();

        // Re-render with new state to update content
        toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed, this.config.translations);

        // Maintain collapsed class after re-render
        if (isCollapsed) {
            toolbar.classList.add('sm-collapsed');
        }

        // Re-attach handlers
        this.attachDebugToolbarHandlers(toolbar);
    }

    // =========================================================================
    // STYLING
    // =========================================================================

    /**
     * Apply custom styles from config
     *
     * Applies CSS custom properties to the host element.
     * Subclasses can override to add additional styling logic.
     */
    applyCustomStyles() {
        if (!this.config) return;

        const host = this.shadowRoot.host;
        const { theme, styles, resultsTitleLines, resultsDescriptionLines } = this.config;

        // Apply styles from config (theme-aware)
        applyStylesToElement(host, styles, theme);

        if (resultsTitleLines) {
            host.style.setProperty('--sm-result-title-lines', String(resultsTitleLines));
        }
        if (resultsDescriptionLines) {
            host.style.setProperty('--sm-result-desc-lines', String(resultsDescriptionLines));
        }
    }

    // =========================================================================
    // ACCESSIBILITY
    // =========================================================================

    /**
     * Initialize the live region for screen reader announcements
     *
     * Call this from subclass render() after setting up the DOM.
     */
    initializeLiveRegion() {
        this.liveRegion = createLiveRegion(this.shadowRoot);
    }

    // =========================================================================
    // ANALYTICS TRACKING
    // =========================================================================

    /**
     * Start analytics idle timer
     *
     * Tracks search when user stops typing for analyticsIdleTimeout ms
     * (captures "browsing" behavior - user reads results without clicking).
     *
     * @param {string} query - The search query
     * @param {number} resultsCount - Number of results returned
     */
    startAnalyticsIdleTimer(query, resultsCount) {
        // Cancel any existing timer
        if (this.analyticsIdleTimer) {
            clearTimeout(this.analyticsIdleTimer);
        }

        // Check if idle tracking is enabled
        const idleTimeout = this.config.analyticsIdleTimeoutMs;
        if (!idleTimeout || idleTimeout <= 0) {
            return;
        }

        // Start timer
        this.analyticsIdleTimer = setTimeout(() => {
            this.trackSearchAnalytics(query, resultsCount, 'idle');
        }, idleTimeout);
    }

    /**
     * Track search analytics (explicit tracking)
     *
     * Called when user shows intent:
     * - Clicks a result (trigger='click')
     * - Presses Enter (trigger='enter')
     * - Stops typing for idle timeout (trigger='idle')
     *
     * Prevents double tracking of the same query.
     *
     * @param {string} query - The search query
     * @param {number} resultsCount - Number of results
     * @param {string} trigger - What triggered tracking ('click', 'enter', 'idle')
     */
    trackSearchAnalytics(query, resultsCount, trigger) {
        // Skip if no query or already tracked this query
        if (!query || query === this.lastTrackedQuery) {
            return;
        }

        // Mark as tracked
        this.lastTrackedQuery = query;

        // Cancel idle timer (if tracking via click or enter)
        if (this.analyticsIdleTimer) {
            clearTimeout(this.analyticsIdleTimer);
            this.analyticsIdleTimer = null;
        }

        // Track via endpoint
        trackSearch({
            endpoint: this.config.trackSearchEndpoint,
            query,
            indexHandles: this.config.indexHandles,
            resultsCount,
            trigger,
            analyticsSource: this.config.analyticsSource,
            siteId: this.config.siteId,
            cached: this.lastSearchCacheState?.cached,
            took: this.lastSearchCacheState?.took,
            apiKey: this.config.apiKey,
        });
    }

    /**
     * Reset analytics tracking state
     *
     * Call when modal closes or search context changes.
     */
    resetAnalyticsTracking() {
        this.lastTrackedQuery = null;
        this.lastSearchCacheState = null;
        if (this.analyticsIdleTimer) {
            clearTimeout(this.analyticsIdleTimer);
            this.analyticsIdleTimer = null;
        }
    }

    // =========================================================================
    // EVENT DISPATCHING
    // =========================================================================

    /**
     * Dispatch a custom widget event
     *
     * Events are prefixed with 'search-' and bubble up the DOM.
     *
     * @param {string} name - Event name (without 'search-' prefix)
     * @param {Object} detail - Event detail data
     *
     * @example
     * this.dispatchWidgetEvent('open', { source: 'hotkey' });
     * // Dispatches 'search-open' event
     */
    dispatchWidgetEvent(name, detail = {}) {
        this.dispatchEvent(new CustomEvent(`search-${name}`, {
            bubbles: true,
            composed: true,
            detail,
        }));
    }
}

export default SearchWidgetBase;
