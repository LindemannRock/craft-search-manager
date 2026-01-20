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
 * - Recent searches
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
 * @since 5.x
 */

import { parseConfig } from './ConfigParser.js';
import { createStateManager, DEFAULT_STATE } from './StateManager.js';
import { performSearch, trackClick } from '../modules/SearchService.js';
import { loadRecentSearches, saveRecentSearch, clearRecentSearches } from '../modules/RecentSearches.js';
import { applyStylesToElement } from '../modules/StyleUtils.js';
import {
    renderResults,
    renderRecentSearches,
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
    getRecentSearchesAnnouncement,
    updateComboboxAria,
} from '../modules/A11yUtils.js';

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

        // Abort controller for cancelling in-flight searches
        this.abortController = null;

        // Debounce timer for search input
        this.debounceTimer = null;

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

        // Load recent searches from localStorage
        this.state.set({
            recentSearches: loadRecentSearches(this.config.index),
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
    }

    /**
     * Called when element is removed from the DOM
     *
     * Subclasses should call super.disconnectedCallback() to ensure cleanup.
     */
    disconnectedCallback() {
        // Cancel any pending search
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }

        // Clear debounce timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = null;
        }
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
        // Update results display when results, query, or recent searches changes
        if (changedKeys.includes('results') || changedKeys.includes('query') || changedKeys.includes('recentSearches')) {
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

        // Update state
        this.state.set({
            query,
            selectedIndex: -1,
        });

        // Clear pending search
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        // If query is empty, just re-render (shows recent or empty state)
        if (!query.trim()) {
            this.state.set({ results: [] });
            return;
        }

        // Don't search if below minimum characters
        if (query.length < this.config.minChars) {
            return;
        }

        // Debounced search
        this.debounceTimer = setTimeout(() => {
            this.executeSearch(query);
        }, this.config.debounce);
    }

    /**
     * Execute a search query
     *
     * @param {string} query - Search query
     */
    async executeSearch(query) {
        // Cancel any in-flight search
        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        // Set loading state
        this.state.set({ loading: true, error: null });

        // Announce loading for screen readers
        if (this.liveRegion) {
            announce(this.liveRegion, getLoadingAnnouncement());
        }

        try {
            const { results, meta } = await performSearch({
                query,
                endpoint: this.config.endpoint,
                indices: this.config.indices,
                siteId: this.config.siteId,
                maxResults: this.config.maxResults,
                hideResultsWithoutUrl: this.config.hideResultsWithoutUrl,
                debug: this.config.debug,
                signal: this.abortController.signal,
            });

            // Update state with results and debug meta
            this.state.set({
                results,
                meta,
                loading: false,
                selectedIndex: results.length > 0 ? 0 : -1,
            });

            // Announce results for screen readers
            if (this.liveRegion) {
                announce(this.liveRegion, getResultsAnnouncement(results.length, query));
            }

            // Dispatch search event
            this.dispatchWidgetEvent('search', { query, results, meta });

        } catch (error) {
            // Ignore abort errors (expected when search is cancelled)
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
        const { showRecent, groupResults, enableHighlighting, highlightTag, highlightClass, showLoadingIndicator, debug } = this.config;

        // Get appropriate content based on state
        const { html, hasResults, showListbox } = getContentToRender(
            {
                query: state.query,
                results: state.results,
                recentSearches: state.recentSearches,
                loading: state.loading,
                showRecent,
            },
            {
                listboxId: this.listboxId,
                groupResults,
                enableHighlighting,
                highlightTag,
                highlightClass,
                showLoadingIndicator,
                debug,
                promotions: this.config.promotions,
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
                announce(this.liveRegion, getResultsAnnouncement(0, state.query));
            } else if (!state.query && state.recentSearches.length > 0 && showRecent) {
                announce(this.liveRegion, getRecentSearchesAnnouncement(state.recentSearches.length));
            }
        }

        // Attach event handlers to new elements
        this.attachResultHandlers();

        // Handle clear recent button
        const clearBtn = container.querySelector('.sm-clear-recent');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                clearRecentSearches(this.config.index);
                this.state.set({ recentSearches: [] });
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
     * Handles navigation, recent search saving, and analytics tracking.
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
        const query = item.dataset.query || this.state.get('query');
        const isRecentItem = item.classList.contains('sm-recent-item');

        // Save to recent searches (for regular results, not re-clicking recent items)
        if (!isRecentItem && query) {
            const updatedRecent = saveRecentSearch(
                this.config.index,
                query,
                { title, url },
                this.config.maxRecentSearches
            );
            this.state.set({ recentSearches: updatedRecent });
        }

        // Track analytics for search results (not recent items)
        if (id && this.config.index) {
            trackClick({
                endpoint: this.config.analyticsEndpoint,
                elementId: id,
                query,
                index: this.config.index,
            });
        }

        // Dispatch result-click event
        this.dispatchWidgetEvent('result-click', {
            id,
            title,
            url,
            query,
            isRecent: isRecentItem,
        });

        // Handle navigation/action
        if (url && url !== '#') {
            // For <a> elements, browser handles navigation naturally
            // For recent items (<div> elements), navigate explicitly
            if (isRecentItem) {
                e.preventDefault();
                window.location.href = url;
            }
            // Let subclass know a result was selected (for closing modal, etc.)
            this.onResultSelected(url, title, id);
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

    // =========================================================================
    // LOADING STATE
    // =========================================================================

    /**
     * Update loading indicator visibility
     *
     * Respects showLoadingIndicator config - if disabled, spinner stays hidden.
     */
    updateLoadingVisual() {
        const loading = this.getLoadingElement();
        if (loading) {
            const isLoading = this.state.get('loading');
            const showIndicator = this.config?.showLoadingIndicator !== false;
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

        const { debug } = this.config;
        const state = this.state.getAll();

        // Hide toolbar if debug is off or no results
        if (!debug || !state.meta || state.results.length === 0) {
            toolbar.hidden = true;
            return;
        }

        // Check if toolbar is collapsed (preserve state across updates)
        const isCollapsed = toolbar.classList.contains('sm-collapsed');

        // Render and show toolbar
        toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed);
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
        toolbar.innerHTML = renderDebugToolbarContent(state.meta, state.results.length, isCollapsed);

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
        const { theme, styles } = this.config;

        // Apply styles from config (theme-aware)
        applyStylesToElement(host, styles, theme);
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
