/**
 * Search Manager - Search Widget Web Component
 *
 * A CMD+K style search modal with instant results, keyboard navigation,
 * recent searches, and analytics integration.
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

import { applyStylesToElement } from './modules/StyleUtils.js';
import { loadRecentSearches, saveRecentSearch, clearRecentSearches } from './modules/RecentSearches.js';
import { performSearch, trackClick, groupResultsByType } from './modules/SearchService.js';
import {
    generateId,
    createLiveRegion,
    announce,
    getResultsAnnouncement,
    getLoadingAnnouncement,
    getRecentSearchesAnnouncement,
    updateComboboxAria,
    getOptionId,
    scrollIntoViewIfNeeded
} from './modules/A11yUtils.js';
import styles from './styles/SearchWidget.css';

class SearchWidget extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });

        // State
        this.isOpen = false;
        this.results = [];
        this.recentSearches = [];
        this.selectedIndex = -1;
        this.loading = false;
        this.query = '';
        this.debounceTimer = null;
        this.abortController = null;

        // Unique IDs for ARIA
        this.listboxId = generateId('sm-listbox');
        this.inputId = generateId('sm-input');
        this.liveRegion = null;

        // Bind methods
        this.open = this.open.bind(this);
        this.close = this.close.bind(this);
        this.toggle = this.toggle.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);
        this.handleInput = this.handleInput.bind(this);
        this.handleResultClick = this.handleResultClick.bind(this);
        this.handleBackdropClick = this.handleBackdropClick.bind(this);
    }

    // Observed attributes
    static get observedAttributes() {
        return [
            'indices', 'placeholder', 'endpoint', 'theme',
            'max-results', 'debounce', 'min-chars', 'show-recent',
            'group-results', 'hotkey', 'site-id', 'enable-highlighting',
            'highlight-tag', 'highlight-class', 'backdrop-opacity',
            'enable-backdrop-blur', 'prevent-body-scroll', 'show-trigger',
            'trigger-selector', 'styles', 'hide-results-without-url'
        ];
    }

    // Parse styles JSON attribute
    get styles() {
        const stylesAttr = this.getAttribute('styles');
        if (stylesAttr) {
            try {
                return JSON.parse(stylesAttr);
            } catch (e) {
                console.warn('SearchWidget: Invalid styles JSON', e);
            }
        }
        return {};
    }

    // Configuration from attributes
    get config() {
        const indicesAttr = this.getAttribute('indices') || '';
        const indices = indicesAttr ? indicesAttr.split(',').map(s => s.trim()).filter(Boolean) : [];

        return {
            indices,
            index: indices[0] || '',
            placeholder: this.getAttribute('placeholder') || 'Search...',
            endpoint: this.getAttribute('endpoint') || '/actions/search-manager/search/query',
            theme: this.getAttribute('theme') || 'light',
            maxResults: parseInt(this.getAttribute('max-results')) || 10,
            debounce: parseInt(this.getAttribute('debounce')) || 200,
            minChars: parseInt(this.getAttribute('min-chars')) || 2,
            showRecent: this.getAttribute('show-recent') !== 'false',
            maxRecentSearches: parseInt(this.getAttribute('max-recent-searches')) || 5,
            groupResults: this.getAttribute('group-results') !== 'false',
            hotkey: this.getAttribute('hotkey') || 'k',
            siteId: this.getAttribute('site-id') || '',
            analyticsEndpoint: this.getAttribute('analytics-endpoint') || '/actions/search-manager/search/track-click',
            enableHighlighting: this.getAttribute('enable-highlighting') !== 'false',
            highlightTag: this.getAttribute('highlight-tag') || 'mark',
            highlightClass: this.getAttribute('highlight-class') || '',
            backdropOpacity: parseInt(this.getAttribute('backdrop-opacity')) || 50,
            enableBackdropBlur: this.getAttribute('enable-backdrop-blur') !== 'false',
            preventBodyScroll: this.getAttribute('prevent-body-scroll') !== 'false',
            showTrigger: this.getAttribute('show-trigger') !== 'false',
            triggerSelector: this.getAttribute('trigger-selector') || '',
            hideResultsWithoutUrl: this.getAttribute('hide-results-without-url') === 'true',
        };
    }

    connectedCallback() {
        this.render();
        this.recentSearches = loadRecentSearches(this.config.index);
        this.attachEventListeners();
    }

    disconnectedCallback() {
        this.detachEventListeners();
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue !== newValue && this.shadowRoot.querySelector('.sm-modal')) {
            this.render();
        }
    }

    // Render the component
    render() {
        const { theme, placeholder, showTrigger } = this.config;

        this.shadowRoot.innerHTML = `
            <style>${styles}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="Open search" ${showTrigger ? '' : 'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${this.getHotkeyDisplay()}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="Search">
                    <!-- Search input -->
                    <div class="sm-header" part="header">
                        <svg class="sm-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            type="text"
                            id="${this.inputId}"
                            class="sm-input"
                            part="input"
                            placeholder="${placeholder}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-controls="${this.listboxId}"
                        />
                        <div class="sm-loading" part="loading" hidden>
                            <svg class="sm-spinner" width="20" height="20" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <button class="sm-close" part="close" aria-label="Close search">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="Search results"></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                            <span><kbd>↵</kbd> select</span>
                            <span><kbd>esc</kbd> close</span>
                        </div>
                        <div class="sm-footer-brand">
                            Powered by <strong>Search Manager</strong>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Cache DOM elements
        this.elements = {
            trigger: this.shadowRoot.querySelector('.sm-trigger'),
            backdrop: this.shadowRoot.querySelector('.sm-backdrop'),
            modal: this.shadowRoot.querySelector('.sm-modal'),
            input: this.shadowRoot.querySelector('.sm-input'),
            results: this.shadowRoot.querySelector('.sm-results'),
            loading: this.shadowRoot.querySelector('.sm-loading'),
            close: this.shadowRoot.querySelector('.sm-close'),
        };

        // Create live region for screen reader announcements
        this.liveRegion = createLiveRegion(this.shadowRoot);

        // Set theme
        this.shadowRoot.host.setAttribute('data-theme', theme);

        // Apply custom styles
        this.applyCustomStyles();
    }

    // Apply custom CSS properties
    applyCustomStyles() {
        const { backdropOpacity, enableBackdropBlur, theme } = this.config;
        const host = this.shadowRoot.host;

        // Backdrop settings
        host.style.setProperty('--sm-backdrop-opacity', backdropOpacity / 100);
        host.style.setProperty('--sm-backdrop-blur', enableBackdropBlur ? 'blur(4px)' : 'none');

        // Apply styles from config (theme-aware: only sets appropriate variables)
        applyStylesToElement(host, this.styles, theme);
    }

    // Get hotkey display
    getHotkeyDisplay() {
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const key = this.config.hotkey.toUpperCase();
        return isMac ? `⌘${key}` : `Ctrl+${key}`;
    }

    // Attach event listeners
    attachEventListeners() {
        this.elements.trigger.addEventListener('click', this.toggle);
        this.elements.close.addEventListener('click', this.close);
        this.elements.backdrop.addEventListener('click', this.handleBackdropClick);
        this.elements.input.addEventListener('input', this.handleInput);
        this.elements.input.addEventListener('keydown', this.handleKeydown);
        document.addEventListener('keydown', this.handleGlobalKeydown);

        // External trigger selector
        const { triggerSelector } = this.config;
        if (triggerSelector) {
            this.externalTrigger = document.querySelector(triggerSelector);
            if (this.externalTrigger) {
                this.externalTrigger.addEventListener('click', this.toggle);
            }
        }
    }

    // Detach event listeners
    detachEventListeners() {
        document.removeEventListener('keydown', this.handleGlobalKeydown);
        if (this.externalTrigger) {
            this.externalTrigger.removeEventListener('click', this.toggle);
            this.externalTrigger = null;
        }
    }

    // Handle global keyboard shortcuts
    handleGlobalKeydown(e) {
        const hotkey = this.config.hotkey.toLowerCase();
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modifier = isMac ? e.metaKey : e.ctrlKey;

        if (modifier && e.key.toLowerCase() === hotkey) {
            e.preventDefault();
            this.toggle();
        }

        if (e.key === 'Escape' && this.isOpen) {
            e.preventDefault();
            this.close();
        }
    }

    // Handle input keydown for navigation
    handleKeydown(e) {
        const items = this.shadowRoot.querySelectorAll('.sm-result-item');
        const count = items.length;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, count - 1);
                this.updateSelection();
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection();
                break;
            case 'Enter':
                e.preventDefault();
                if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                    items[this.selectedIndex].click();
                }
                break;
            case 'Escape':
                e.preventDefault();
                this.close();
                break;
        }
    }

    // Update selection highlight and ARIA state
    updateSelection() {
        const items = this.shadowRoot.querySelectorAll('.sm-result-item');
        const activeId = this.selectedIndex >= 0 ? getOptionId(this.listboxId, this.selectedIndex) : null;

        // Update input's aria-activedescendant
        updateComboboxAria(this.elements.input, {
            expanded: items.length > 0,
            activeDescendant: activeId,
            listboxId: this.listboxId
        });

        // Update visual and ARIA state for each item
        items.forEach((item, i) => {
            const isSelected = i === this.selectedIndex;
            item.classList.toggle('sm-selected', isSelected);
            item.setAttribute('aria-selected', String(isSelected));

            if (isSelected) {
                scrollIntoViewIfNeeded(item, this.elements.results);
            }
        });
    }

    // Handle backdrop click
    handleBackdropClick(e) {
        if (e.target === this.elements.backdrop) {
            this.close();
        }
    }

    // Handle input change
    handleInput(e) {
        this.query = e.target.value;
        this.selectedIndex = -1;

        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        if (!this.query.trim()) {
            this.results = [];
            this.renderResults();
            return;
        }

        if (this.query.length < this.config.minChars) {
            return;
        }

        this.debounceTimer = setTimeout(() => {
            this.search(this.query);
        }, this.config.debounce);
    }

    // Perform search
    async search(query) {
        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        this.loading = true;
        this.elements.loading.hidden = false;

        // Announce loading for screen readers
        announce(this.liveRegion, getLoadingAnnouncement());

        try {
            this.results = await performSearch({
                query,
                endpoint: this.config.endpoint,
                indices: this.config.indices,
                siteId: this.config.siteId,
                maxResults: this.config.maxResults,
                hideResultsWithoutUrl: this.config.hideResultsWithoutUrl,
                signal: this.abortController.signal,
            });
            this.renderResults();
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Search error:', error);
                this.results = [];
                this.renderResults();
            }
        } finally {
            this.loading = false;
            this.elements.loading.hidden = true;
        }
    }

    // Render results
    renderResults() {
        const container = this.elements.results;
        const { showRecent, groupResults } = this.config;

        // Empty state - show recent searches
        if (!this.query.trim() && showRecent && this.recentSearches.length > 0) {
            container.innerHTML = `
                <div class="sm-section">
                    <div class="sm-section-header">
                        <span id="${this.listboxId}-recent-label">Recent searches</span>
                        <button class="sm-clear-recent" part="clear-recent">Clear</button>
                    </div>
                    ${this.recentSearches.map((item, i) => `
                        <div class="sm-result-item sm-recent-item" id="${getOptionId(this.listboxId, i)}" role="option" aria-selected="false" data-index="${i}" data-url="${item.url || ''}" data-query="${this.escapeHtml(item.query)}">
                            <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <span class="sm-result-title">${this.escapeHtml(item.title || item.query)}</span>
                            <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </div>
                    `).join('')}
                </div>
            `;

            // Announce recent searches for screen readers
            announce(this.liveRegion, getRecentSearchesAnnouncement(this.recentSearches.length));

            const clearBtn = container.querySelector('.sm-clear-recent');
            if (clearBtn) {
                clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    clearRecentSearches(this.config.index);
                    this.recentSearches = [];
                    this.renderResults();
                });
            }

            this.attachResultHandlers();
            return;
        }

        // No query yet
        if (!this.query.trim()) {
            // Remove listbox role when empty (aria-required-children: listbox needs options)
            container.removeAttribute('role');
            container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <p>Start typing to search</p>
                </div>
            `;

            // Update ARIA state - no results
            updateComboboxAria(this.elements.input, {
                expanded: false,
                activeDescendant: null,
                listboxId: this.listboxId
            });
            return;
        }

        // No results
        if (this.results.length === 0) {
            // Remove listbox role when empty (aria-required-children: listbox needs options)
            container.removeAttribute('role');
            container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="m15 9-6 6M9 9l6 6"/>
                    </svg>
                    <p>No results for "<strong>${this.escapeHtml(this.query)}</strong>"</p>
                </div>
            `;

            // Announce no results for screen readers
            announce(this.liveRegion, getResultsAnnouncement(0, this.query));

            // Update ARIA state
            updateComboboxAria(this.elements.input, {
                expanded: false,
                activeDescendant: null,
                listboxId: this.listboxId
            });
            return;
        }

        // Re-add listbox role when we have options
        container.setAttribute('role', 'listbox');

        // Group results by type/section if enabled
        if (groupResults) {
            const groups = groupResultsByType(this.results);
            let globalIndex = 0;
            container.innerHTML = Object.entries(groups).map(([type, items]) => `
                <div class="sm-section" role="group" aria-label="${this.escapeHtml(type)}">
                    <div class="sm-section-header">${this.escapeHtml(type)}</div>
                    ${items.map((result) => this.renderResultItem(result, globalIndex++)).join('')}
                </div>
            `).join('');
        } else {
            container.innerHTML = this.results.map((result, i) => this.renderResultItem(result, i)).join('');
        }

        // Announce results for screen readers
        announce(this.liveRegion, getResultsAnnouncement(this.results.length, this.query));

        // Update ARIA state - listbox now has options
        updateComboboxAria(this.elements.input, {
            expanded: true,
            activeDescendant: null,
            listboxId: this.listboxId
        });

        // Auto-select first result for better keyboard navigation
        if (this.results.length > 0) {
            this.selectedIndex = 0;
            this.updateSelection();
        }

        this.attachResultHandlers();
    }

    // Render single result item
    renderResultItem(result, index) {
        const title = result.title || result.name || 'Untitled';
        const description = result.description || result.excerpt || result.snippet || '';
        const url = result.url || result.href || '#';
        const type = result.section || result.type || '';
        const optionId = getOptionId(this.listboxId, index);

        const highlightedTitle = this.highlightMatches(title, this.query);
        const highlightedDesc = description ? this.highlightMatches(description, this.query) : '';

        return `
            <a class="sm-result-item" id="${optionId}" role="option" aria-selected="false" href="${this.escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${this.escapeHtml(title)}">
                <div class="sm-result-content">
                    <span class="sm-result-title">${highlightedTitle}</span>
                    ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                </div>
                ${type && !this.config.groupResults ? `<span class="sm-result-type">${this.escapeHtml(type)}</span>` : ''}
                <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        `;
    }

    // Highlight matching text
    highlightMatches(text, query) {
        if (!query) return this.escapeHtml(text);

        const escaped = this.escapeHtml(text);

        if (!this.config.enableHighlighting) {
            return escaped;
        }

        const queryWords = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
        const { highlightTag, highlightClass } = this.config;
        const classes = ['sm-highlight'];
        if (highlightClass) {
            classes.push(highlightClass);
        }
        const classAttr = ` class="${classes.join(' ')}"`;

        let result = escaped;
        queryWords.forEach(word => {
            const regex = new RegExp(`\\b(${this.escapeRegex(word)})\\b`, 'gi');
            result = result.replace(regex, `<${highlightTag}${classAttr}>$1</${highlightTag}>`);
        });

        return result;
    }

    // Attach handlers to result items
    attachResultHandlers() {
        const items = this.shadowRoot.querySelectorAll('.sm-result-item');
        items.forEach(item => {
            item.addEventListener('click', (e) => this.handleResultClick(e, item));
            item.addEventListener('mouseenter', () => {
                this.selectedIndex = parseInt(item.dataset.index) || 0;
                this.updateSelection();
            });
        });
    }

    // Handle result click
    handleResultClick(e, item) {
        const href = item.getAttribute('href');
        const dataUrl = item.dataset.url;
        const url = href || dataUrl;
        const title = item.dataset.title || item.querySelector('.sm-result-title')?.textContent;
        const id = item.dataset.id;
        const query = item.dataset.query || this.query;
        const isRecentItem = item.classList.contains('sm-recent-item');

        // Save to recent searches (for regular results, not for re-clicking recent items)
        if (!isRecentItem) {
            this.recentSearches = saveRecentSearch(this.config.index, query, { title, url }, this.config.maxRecentSearches);
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

        // Handle navigation/action
        if (url && url !== '#') {
            // For <a> elements, browser handles navigation naturally
            // For recent items (<div> elements), navigate explicitly
            if (isRecentItem) {
                e.preventDefault();
                window.location.href = url;
            }
            this.close();
        } else if (query) {
            // No URL - populate search and trigger new search
            e.preventDefault();
            this.elements.input.value = query;
            this.query = query;
            this.search(query);
        }
    }

    // Open modal
    open() {
        this.isOpen = true;
        this.elements.backdrop.hidden = false;
        this.elements.input.value = '';
        this.query = '';
        this.results = [];
        this.selectedIndex = -1;
        this.renderResults();

        requestAnimationFrame(() => {
            this.elements.input.focus();
        });

        if (this.config.preventBodyScroll) {
            document.body.style.overflow = 'hidden';
        }

        this.dispatchEvent(new CustomEvent('search-open'));
    }

    // Close modal
    close() {
        this.isOpen = false;
        this.elements.backdrop.hidden = true;

        if (this.config.preventBodyScroll) {
            document.body.style.overflow = '';
        }

        this.dispatchEvent(new CustomEvent('search-close'));
    }

    // Toggle modal
    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    // Escape HTML
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Escape regex special characters
    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}

// Register the custom element
customElements.define('search-widget', SearchWidget);

export default SearchWidget;
