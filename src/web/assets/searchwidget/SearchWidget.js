/**
 * Search Manager - Search Widget Web Component
 *
 * A CMD+K style search modal with instant results, keyboard navigation,
 * recent searches, and analytics integration.
 *
 * Usage:
 * <search-widget
 *   index="main"
 *   placeholder="Search..."
 *   endpoint="/actions/search-manager/search/query"
 *   theme="light"
 *   max-results="10"
 *   debounce="200"
 *   min-chars="2"
 *   show-recent="true"
 *   group-results="true"
 * ></search-widget>
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

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
            'index',
            'placeholder',
            'endpoint',
            'theme',
            'max-results',
            'debounce',
            'min-chars',
            'show-recent',
            'group-results',
            'hotkey',
            'site-id',
            'enable-highlighting',
            'highlight-tag',
            'highlight-class'
        ];
    }

    // Default config
    get config() {
        return {
            index: this.getAttribute('index') || '',
            placeholder: this.getAttribute('placeholder') || 'Search...',
            endpoint: this.getAttribute('endpoint') || '/actions/search-manager/search/query',
            theme: this.getAttribute('theme') || 'light',
            maxResults: parseInt(this.getAttribute('max-results')) || 10,
            debounce: parseInt(this.getAttribute('debounce')) || 200,
            minChars: parseInt(this.getAttribute('min-chars')) || 2,
            showRecent: this.getAttribute('show-recent') !== 'false',
            groupResults: this.getAttribute('group-results') !== 'false',
            hotkey: this.getAttribute('hotkey') || 'k',
            siteId: this.getAttribute('site-id') || '',
            analyticsEndpoint: this.getAttribute('analytics-endpoint') || '/actions/search-manager/search/track-click',
            enableHighlighting: this.getAttribute('enable-highlighting') !== 'false',
            highlightTag: this.getAttribute('highlight-tag') || 'mark',
            highlightClass: this.getAttribute('highlight-class') || ''
        };
    }

    connectedCallback() {
        this.render();
        this.loadRecentSearches();
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

    // Load recent searches from localStorage
    loadRecentSearches() {
        try {
            const key = `sm-recent-${this.config.index || 'default'}`;
            const stored = localStorage.getItem(key);
            this.recentSearches = stored ? JSON.parse(stored) : [];
        } catch (e) {
            this.recentSearches = [];
        }
    }

    // Save recent search
    saveRecentSearch(query, result) {
        if (!query.trim()) return;

        const key = `sm-recent-${this.config.index || 'default'}`;
        const entry = {
            query: query.trim(),
            title: result?.title || query,
            url: result?.url || null,
            timestamp: Date.now()
        };

        // Remove duplicates and add to front
        this.recentSearches = this.recentSearches.filter(s => s.query !== entry.query);
        this.recentSearches.unshift(entry);
        this.recentSearches = this.recentSearches.slice(0, 5); // Keep last 5

        try {
            localStorage.setItem(key, JSON.stringify(this.recentSearches));
        } catch (e) {
            // localStorage full or unavailable
        }
    }

    // Clear recent searches
    clearRecentSearches() {
        const key = `sm-recent-${this.config.index || 'default'}`;
        this.recentSearches = [];
        try {
            localStorage.removeItem(key);
        } catch (e) {}
        this.renderResults();
    }

    // Render the component
    render() {
        const { theme, placeholder } = this.config;

        this.shadowRoot.innerHTML = `
            <style>${this.getStyles()}</style>

            <!-- Trigger button (optional, can be hidden) -->
            <button class="sm-trigger" part="trigger" aria-label="Open search">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd">${this.getHotkeyDisplay()}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <!-- Modal -->
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="Search">
                    <!-- Search input -->
                    <div class="sm-header" part="header">
                        <svg class="sm-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            type="text"
                            class="sm-input"
                            part="input"
                            placeholder="${placeholder}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
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
                    <div class="sm-results" part="results" role="listbox"></div>

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
            close: this.shadowRoot.querySelector('.sm-close')
        };

        // Set theme
        this.shadowRoot.host.setAttribute('data-theme', theme);
    }

    // Get hotkey display
    getHotkeyDisplay() {
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const key = this.config.hotkey.toUpperCase();
        return isMac ? `⌘${key}` : `Ctrl+${key}`;
    }

    // Attach event listeners
    attachEventListeners() {
        // Trigger button
        this.elements.trigger.addEventListener('click', this.toggle);

        // Close button
        this.elements.close.addEventListener('click', this.close);

        // Backdrop click
        this.elements.backdrop.addEventListener('click', this.handleBackdropClick);

        // Input
        this.elements.input.addEventListener('input', this.handleInput);
        this.elements.input.addEventListener('keydown', this.handleKeydown);

        // Global keyboard shortcut
        document.addEventListener('keydown', this.handleGlobalKeydown);
    }

    // Detach event listeners
    detachEventListeners() {
        document.removeEventListener('keydown', this.handleGlobalKeydown);
    }

    // Handle global keyboard shortcuts
    handleGlobalKeydown(e) {
        const hotkey = this.config.hotkey.toLowerCase();
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modifier = isMac ? e.metaKey : e.ctrlKey;

        // CMD/CTRL + K (or configured hotkey)
        if (modifier && e.key.toLowerCase() === hotkey) {
            e.preventDefault();
            this.toggle();
        }

        // Escape to close
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

    // Update selection highlight
    updateSelection() {
        const items = this.shadowRoot.querySelectorAll('.sm-result-item');
        items.forEach((item, i) => {
            item.classList.toggle('sm-selected', i === this.selectedIndex);
            if (i === this.selectedIndex) {
                item.scrollIntoView({ block: 'nearest' });
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

        // Clear existing timer
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        // Show recent if empty
        if (!this.query.trim()) {
            this.results = [];
            this.renderResults();
            return;
        }

        // Check minimum characters
        if (this.query.length < this.config.minChars) {
            return;
        }

        // Debounce search
        this.debounceTimer = setTimeout(() => {
            this.search(this.query);
        }, this.config.debounce);
    }

    // Perform search
    async search(query) {
        // Abort previous request
        if (this.abortController) {
            this.abortController.abort();
        }
        this.abortController = new AbortController();

        this.loading = true;
        this.elements.loading.hidden = false;

        try {
            const params = new URLSearchParams({
                q: query,
                limit: this.config.maxResults.toString()
            });

            if (this.config.index) {
                params.append('index', this.config.index);
            }

            if (this.config.siteId) {
                params.append('siteId', this.config.siteId);
            }

            // Check if endpoint already has query params (Craft's actionUrl includes ?p=...)
            const separator = this.config.endpoint.includes('?') ? '&' : '?';
            const response = await fetch(`${this.config.endpoint}${separator}${params}`, {
                signal: this.abortController.signal,
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Search failed');
            }

            const data = await response.json();
            this.results = data.results || data.hits || [];
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
                        <span>Recent searches</span>
                        <button class="sm-clear-recent" part="clear-recent">Clear</button>
                    </div>
                    ${this.recentSearches.map((item, i) => `
                        <div class="sm-result-item sm-recent-item" role="option" data-index="${i}" data-url="${item.url || ''}" data-query="${this.escapeHtml(item.query)}">
                            <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <span class="sm-result-title">${this.escapeHtml(item.title || item.query)}</span>
                        </div>
                    `).join('')}
                </div>
            `;

            // Attach clear handler
            const clearBtn = container.querySelector('.sm-clear-recent');
            if (clearBtn) {
                clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.clearRecentSearches();
                });
            }

            // Attach click handlers
            this.attachResultHandlers();
            return;
        }

        // No query yet
        if (!this.query.trim()) {
            container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <p>Start typing to search</p>
                </div>
            `;
            return;
        }

        // No results
        if (this.results.length === 0) {
            container.innerHTML = `
                <div class="sm-empty" part="empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="m15 9-6 6M9 9l6 6"/>
                    </svg>
                    <p>No results for "<strong>${this.escapeHtml(this.query)}</strong>"</p>
                </div>
            `;
            return;
        }

        // Group results by type/section if enabled
        if (groupResults) {
            const groups = this.groupResultsByType(this.results);
            let globalIndex = 0;
            container.innerHTML = Object.entries(groups).map(([type, items]) => `
                <div class="sm-section">
                    <div class="sm-section-header">${this.escapeHtml(type)}</div>
                    ${items.map((result) => this.renderResultItem(result, globalIndex++)).join('')}
                </div>
            `).join('');
        } else {
            container.innerHTML = this.results.map((result, i) => this.renderResultItem(result, i)).join('');
        }

        // Attach click handlers
        this.attachResultHandlers();
    }

    // Group results by type
    groupResultsByType(results) {
        const groups = {};
        results.forEach(result => {
            const type = result.section || result.type || 'Results';
            if (!groups[type]) {
                groups[type] = [];
            }
            groups[type].push(result);
        });
        return groups;
    }

    // Render single result item
    renderResultItem(result, index) {
        const title = result.title || result.name || 'Untitled';
        const description = result.description || result.excerpt || result.snippet || '';
        const url = result.url || result.href || '#';
        const type = result.section || result.type || '';

        // Highlight matching text
        const highlightedTitle = this.highlightMatches(title, this.query);
        const highlightedDesc = description ? this.highlightMatches(description, this.query) : '';

        return `
            <a class="sm-result-item" role="option" href="${this.escapeHtml(url)}" data-index="${index}" data-id="${result.id || ''}" data-title="${this.escapeHtml(title)}">
                <div class="sm-result-content">
                    <span class="sm-result-title">${highlightedTitle}</span>
                    ${highlightedDesc ? `<span class="sm-result-desc">${highlightedDesc}</span>` : ''}
                </div>
                ${type && !this.config.groupResults ? `<span class="sm-result-type">${this.escapeHtml(type)}</span>` : ''}
                <svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </a>
        `;
    }

    // Highlight matching text
    highlightMatches(text, query) {
        if (!query) return this.escapeHtml(text);

        const escaped = this.escapeHtml(text);

        // Check if highlighting is enabled
        if (!this.config.enableHighlighting) {
            return escaped;
        }

        const queryWords = query.toLowerCase().split(/\s+/).filter(w => w.length > 0);
        const { highlightTag, highlightClass } = this.config;
        // Always include sm-highlight for consistent widget styling, plus any custom class
        const classes = ['sm-highlight'];
        if (highlightClass) {
            classes.push(highlightClass);
        }
        const classAttr = ` class="${classes.join(' ')}"`;

        let result = escaped;
        queryWords.forEach(word => {
            // Use word boundaries to avoid matching partial words (e.g., "a" in "brand")
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
        const url = item.getAttribute('href') || item.dataset.url;
        const title = item.dataset.title || item.querySelector('.sm-result-title')?.textContent;
        const id = item.dataset.id;
        const query = item.dataset.query || this.query;

        // Save to recent searches
        this.saveRecentSearch(query, { title, url });

        // Track click for analytics
        if (id && this.config.index) {
            this.trackClick(id, query);
        }

        // Navigate if URL exists
        if (url && url !== '#') {
            // Let the link navigate naturally
            this.close();
        } else if (query) {
            // For recent items without URL, re-search
            e.preventDefault();
            this.elements.input.value = query;
            this.query = query;
            this.search(query);
        }
    }

    // Track click for analytics
    async trackClick(elementId, query) {
        try {
            const formData = new FormData();
            formData.append('elementId', elementId);
            formData.append('query', query);
            formData.append('index', this.config.index);

            fetch(this.config.analyticsEndpoint, {
                method: 'POST',
                body: formData
            }).catch(() => {
                // Silently fail analytics
            });
        } catch (e) {
            // Ignore analytics errors
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

        // Focus input after animation
        requestAnimationFrame(() => {
            this.elements.input.focus();
        });

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Dispatch event
        this.dispatchEvent(new CustomEvent('search-open'));
    }

    // Close modal
    close() {
        this.isOpen = false;
        this.elements.backdrop.hidden = true;

        // Restore body scroll
        document.body.style.overflow = '';

        // Dispatch event
        this.dispatchEvent(new CustomEvent('search-close'));
    }

    // Toggle modal
    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
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

    // Get component styles
    getStyles() {
        return `
            :host {
                --sm-backdrop-bg: rgba(0, 0, 0, 0.5);
                --sm-modal-bg: #ffffff;
                --sm-modal-border: #e5e7eb;
                --sm-modal-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                --sm-modal-radius: 12px;
                --sm-modal-width: 600px;
                --sm-modal-max-height: 80vh;

                --sm-input-bg: transparent;
                --sm-input-color: #111827;
                --sm-input-placeholder: #9ca3af;

                --sm-text-primary: #111827;
                --sm-text-secondary: #6b7280;
                --sm-text-muted: #9ca3af;

                --sm-border-color: #e5e7eb;
                --sm-hover-bg: #f3f4f6;
                --sm-selected-bg: #eff6ff;
                --sm-selected-border: #3b82f6;

                --sm-highlight-bg: #fef08a;
                --sm-highlight-color: #854d0e;

                --sm-kbd-bg: #f3f4f6;
                --sm-kbd-border: #d1d5db;
                --sm-kbd-color: #374151;

                --sm-accent: #3b82f6;
                --sm-accent-hover: #2563eb;

                display: inline-block;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            }

            :host([data-theme="dark"]) {
                --sm-backdrop-bg: rgba(0, 0, 0, 0.7);
                --sm-modal-bg: #1f2937;
                --sm-modal-border: #374151;

                --sm-input-color: #f9fafb;
                --sm-input-placeholder: #6b7280;

                --sm-text-primary: #f9fafb;
                --sm-text-secondary: #d1d5db;
                --sm-text-muted: #6b7280;

                --sm-border-color: #374151;
                --sm-hover-bg: #374151;
                --sm-selected-bg: #1e3a5f;
                --sm-selected-border: #3b82f6;

                --sm-highlight-bg: #854d0e;
                --sm-highlight-color: #fef08a;

                --sm-kbd-bg: #374151;
                --sm-kbd-border: #4b5563;
                --sm-kbd-color: #d1d5db;
            }

            *, *::before, *::after {
                box-sizing: border-box;
            }

            /* Trigger button */
            .sm-trigger {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 12px;
                background: var(--sm-modal-bg);
                border: 1px solid var(--sm-border-color);
                border-radius: 8px;
                color: var(--sm-text-secondary);
                font-size: 14px;
                cursor: pointer;
                transition: all 0.15s ease;
            }

            .sm-trigger:hover {
                border-color: var(--sm-accent);
                color: var(--sm-text-primary);
            }

            .sm-trigger-kbd {
                display: inline-flex;
                align-items: center;
                padding: 2px 6px;
                background: var(--sm-kbd-bg);
                border: 1px solid var(--sm-kbd-border);
                border-radius: 4px;
                font-size: 11px;
                font-family: inherit;
                color: var(--sm-kbd-color);
            }

            /* Backdrop */
            .sm-backdrop {
                position: fixed;
                inset: 0;
                z-index: 99999;
                display: flex;
                align-items: flex-start;
                justify-content: center;
                padding-top: 10vh;
                background: var(--sm-backdrop-bg);
                backdrop-filter: blur(4px);
                animation: sm-fade-in 0.15s ease;
            }

            .sm-backdrop[hidden] {
                display: none;
            }

            @keyframes sm-fade-in {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            /* Modal */
            .sm-modal {
                width: var(--sm-modal-width);
                max-width: calc(100vw - 32px);
                max-height: var(--sm-modal-max-height);
                background: var(--sm-modal-bg);
                border: 1px solid var(--sm-modal-border);
                border-radius: var(--sm-modal-radius);
                box-shadow: var(--sm-modal-shadow);
                display: flex;
                flex-direction: column;
                overflow: hidden;
                animation: sm-slide-up 0.2s ease;
            }

            @keyframes sm-slide-up {
                from {
                    opacity: 0;
                    transform: translateY(-10px) scale(0.98);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }

            /* Header */
            .sm-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 16px;
                border-bottom: 1px solid var(--sm-border-color);
            }

            .sm-search-icon {
                flex-shrink: 0;
                color: var(--sm-text-muted);
            }

            .sm-input {
                flex: 1;
                border: none;
                background: var(--sm-input-bg);
                color: var(--sm-input-color);
                font-size: 16px;
                outline: none;
            }

            .sm-input::placeholder {
                color: var(--sm-input-placeholder);
            }

            .sm-loading {
                flex-shrink: 0;
            }

            .sm-spinner {
                animation: sm-spin 1s linear infinite;
                color: var(--sm-accent);
            }

            @keyframes sm-spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            .sm-close {
                flex-shrink: 0;
                display: flex;
                align-items: center;
                padding: 4px 8px;
                background: transparent;
                border: none;
                cursor: pointer;
            }

            .sm-close kbd {
                padding: 2px 6px;
                background: var(--sm-kbd-bg);
                border: 1px solid var(--sm-kbd-border);
                border-radius: 4px;
                font-size: 11px;
                font-family: inherit;
                color: var(--sm-kbd-color);
            }

            /* Results */
            .sm-results {
                flex: 1;
                overflow-y: auto;
                padding: 8px;
            }

            .sm-section {
                margin-bottom: 8px;
            }

            .sm-section:last-child {
                margin-bottom: 0;
            }

            .sm-section-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 8px 12px;
                font-size: 12px;
                font-weight: 600;
                color: var(--sm-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .sm-clear-recent {
                padding: 2px 8px;
                background: transparent;
                border: none;
                border-radius: 4px;
                font-size: 11px;
                color: var(--sm-text-muted);
                cursor: pointer;
                text-transform: none;
                letter-spacing: normal;
            }

            .sm-clear-recent:hover {
                background: var(--sm-hover-bg);
                color: var(--sm-text-secondary);
            }

            /* Result item */
            .sm-result-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px;
                border-radius: 8px;
                color: var(--sm-text-primary);
                text-decoration: none;
                cursor: pointer;
                transition: background 0.1s ease;
            }

            .sm-result-item:hover,
            .sm-result-item.sm-selected {
                background: var(--sm-hover-bg);
            }

            .sm-result-item.sm-selected {
                background: var(--sm-selected-bg);
                outline: 2px solid var(--sm-selected-border);
                outline-offset: -2px;
            }

            .sm-result-icon {
                flex-shrink: 0;
                color: var(--sm-text-muted);
            }

            .sm-result-content {
                flex: 1;
                min-width: 0;
                display: flex;
                flex-direction: column;
                gap: 2px;
            }

            .sm-result-title {
                font-size: 14px;
                font-weight: 500;
                color: var(--sm-text-primary);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .sm-result-desc {
                font-size: 13px;
                color: var(--sm-text-secondary);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .sm-result-type {
                flex-shrink: 0;
                padding: 2px 8px;
                background: var(--sm-kbd-bg);
                border-radius: 4px;
                font-size: 11px;
                color: var(--sm-text-muted);
            }

            .sm-result-arrow {
                flex-shrink: 0;
                color: var(--sm-text-muted);
                opacity: 0;
                transition: opacity 0.1s ease;
            }

            .sm-result-item:hover .sm-result-arrow,
            .sm-result-item.sm-selected .sm-result-arrow {
                opacity: 1;
            }

            /* Highlight - uses .sm-highlight class to work with any tag (mark, em, span, etc.) */
            .sm-highlight {
                background: var(--sm-highlight-bg);
                color: var(--sm-highlight-color);
                border-radius: 2px;
                padding: 0 2px;
            }

            /* Empty state */
            .sm-empty {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 12px;
                padding: 48px 24px;
                color: var(--sm-text-muted);
                text-align: center;
            }

            .sm-empty p {
                margin: 0;
                font-size: 14px;
            }

            .sm-empty strong {
                color: var(--sm-text-secondary);
            }

            /* Footer */
            .sm-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                padding: 12px 16px;
                border-top: 1px solid var(--sm-border-color);
                font-size: 12px;
                color: var(--sm-text-muted);
            }

            .sm-footer-hints {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .sm-footer-hints span {
                display: flex;
                align-items: center;
                gap: 4px;
            }

            .sm-footer kbd {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 20px;
                padding: 2px 4px;
                background: var(--sm-kbd-bg);
                border: 1px solid var(--sm-kbd-border);
                border-radius: 4px;
                font-size: 10px;
                font-family: inherit;
                color: var(--sm-kbd-color);
            }

            .sm-footer-brand {
                color: var(--sm-text-muted);
            }

            .sm-footer-brand strong {
                color: var(--sm-text-secondary);
            }

            /* RTL support */
            :host([dir="rtl"]) .sm-header,
            :host([dir="rtl"]) .sm-result-item,
            :host([dir="rtl"]) .sm-footer {
                direction: rtl;
            }

            :host([dir="rtl"]) .sm-result-arrow {
                transform: scaleX(-1);
            }

            /* Mobile */
            @media (max-width: 640px) {
                .sm-backdrop {
                    padding-top: 0;
                    align-items: flex-end;
                }

                .sm-modal {
                    max-width: 100%;
                    max-height: 90vh;
                    border-radius: var(--sm-modal-radius) var(--sm-modal-radius) 0 0;
                }

                .sm-trigger-text,
                .sm-footer-hints {
                    display: none;
                }
            }
        `;
    }
}

// Register the custom element
customElements.define('search-widget', SearchWidget);

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SearchWidget;
}
