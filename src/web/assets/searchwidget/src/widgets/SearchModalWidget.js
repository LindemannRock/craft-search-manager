/**
 * SearchModalWidget - CMD+K style modal search
 *
 * A floating modal search widget with:
 * - Keyboard shortcut activation (Cmd+K / Ctrl+K)
 * - Backdrop with optional blur
 * - Trigger button
 * - Focus trapping
 * - Body scroll lock
 *
 * Extends SearchWidgetBase with modal-specific functionality.
 *
 * @extends SearchWidgetBase
 * @author Search Manager
 * @since 5.32.0
 *
 * @example
 * <search-modal
 *   indices="products,articles"
 *   placeholder="Search..."
 *   hotkey="k"
 *   show-trigger="true"
 * ></search-modal>
 *
 * @fires search-open - When modal opens
 * @fires search-close - When modal closes
 * @fires search-search - When search is executed
 * @fires search-result-click - When a result is clicked
 */

import SearchWidgetBase from '../core/SearchWidgetBase.js';
import { getObservedAttributes, parseConfig } from '../core/ConfigParser.js';
import { escapeHtml } from '../modules/Highlighter.js';
import baseStyles from '../styles/base.css';
import modalStyles from '../styles/modal.css';
import debugStyles from '../styles/debug.css';

// Combine base, modal-specific, and debug styles
const styles = baseStyles + '\n' + modalStyles + '\n' + debugStyles;

class SearchModalWidget extends SearchWidgetBase {
    /**
     * Initialize modal widget
     */
    constructor() {
        super();

        // Modal-specific state
        this.externalTrigger = null;
        this.previouslyFocused = null;

        // Bind modal-specific methods
        this.open = this.open.bind(this);
        this.close = this.close.bind(this);
        this.toggle = this.toggle.bind(this);
        this.handleGlobalKeydown = this.handleGlobalKeydown.bind(this);
        this.handleBackdropClick = this.handleBackdropClick.bind(this);
        this.handleTriggerClick = this.handleTriggerClick.bind(this);
        this.handleExternalTriggerClick = this.handleExternalTriggerClick.bind(this);
        this.handleCloseClick = this.handleCloseClick.bind(this);
    }

    /**
     * Widget type identifier
     * @returns {string} 'modal'
     */
    get widgetType() {
        return 'modal';
    }

    /**
     * Observed attributes for this widget type
     * @returns {Array<string>} Attribute names
     */
    static get observedAttributes() {
        return getObservedAttributes('modal');
    }

    // =========================================================================
    // LIFECYCLE
    // =========================================================================

    /**
     * Called when element is added to DOM
     */
    connectedCallback() {
        super.connectedCallback();
        this.render();
        this.attachEventListeners();
    }

    /**
     * Called when element is removed from DOM
     */
    disconnectedCallback() {
        if (this.state.get('isOpen')) {
            this.close({
                reason: 'disconnect',
                source: 'disconnect',
                restoreFocus: false,
            });
        }
        super.disconnectedCallback();
        this.detachEventListeners();
    }

    /**
     * Keep live attribute updates safe after the widget has rendered.
     *
     * Full shadow-DOM rebuilds replace internal nodes, so listeners need to be
     * detached and reattached around render. Open modals stay open so callers
     * can update attributes without interrupting the search session.
     *
     * @param {string} name - Attribute name
     * @param {string|null} oldValue - Previous value
     * @param {string|null} newValue - New value
     */
    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue === newValue || this.shadowRoot.children.length === 0) {
            return;
        }

        if (name === 'theme') {
            this.config = parseConfig(this, this.widgetType);
            this.shadowRoot.host.setAttribute('data-theme', this.config.theme);
            this.applyCustomStyles();
            return;
        }

        const wasOpen = this.state.get('isOpen');
        const query = this.state.get('query') || '';

        this.detachEventListeners();
        this.config = parseConfig(this, this.widgetType);
        this.render();
        this.attachEventListeners();

        if (!wasOpen) {
            return;
        }

        this.registerOpenWidget();
        this.elements.backdrop.hidden = false;
        this.elements.trigger.setAttribute('aria-expanded', 'true');
        this.elements.input.value = query;

        this.renderResultsContent();
        this.updateLoadingVisual();
        this.updateDebugToolbar();
        this.updateSelectionVisual();

        document.body.style.overflow = this.config.preventBodyScroll ? 'hidden' : '';

        requestAnimationFrame(() => {
            if (this.isConnected) {
                this.elements.input.focus();
            }
        });
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    /**
     * Render the modal HTML structure
     */
    render() {
        const { theme, placeholder, showTrigger } = this.config;
        const hotkeyDisplay = escapeHtml(this.getHotkeyDisplay());
        const safePlaceholder = escapeHtml(placeholder || '');

        this.shadowRoot.innerHTML = `
            <style>${styles}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="Open search" aria-haspopup="dialog" aria-expanded="false" ${showTrigger ? '' : 'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${hotkeyDisplay}</kbd>
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
                            placeholder="${safePlaceholder}"
                            maxlength="256"
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
                            <svg class="sm-spinner" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
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

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

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

        // Cache DOM element references
        this.elements = {
            trigger: this.shadowRoot.querySelector('.sm-trigger'),
            backdrop: this.shadowRoot.querySelector('.sm-backdrop'),
            modal: this.shadowRoot.querySelector('.sm-modal'),
            input: this.shadowRoot.querySelector('.sm-input'),
            results: this.shadowRoot.querySelector('.sm-results'),
            loading: this.shadowRoot.querySelector('.sm-loading'),
            close: this.shadowRoot.querySelector('.sm-close'),
            debugToolbar: this.shadowRoot.querySelector('.sm-debug-toolbar'),
        };

        // Initialize live region for screen reader announcements
        this.initializeLiveRegion();

        // Set theme
        this.shadowRoot.host.setAttribute('data-theme', theme);

        // Apply custom styles
        this.applyCustomStyles();
    }

    /**
     * Get results container element
     * @returns {HTMLElement} Results container
     */
    getResultsContainer() {
        return this.elements.results;
    }

    /**
     * Get search input element
     * @returns {HTMLInputElement} Search input
     */
    getInputElement() {
        return this.elements.input;
    }

    /**
     * Get loading indicator element
     * @returns {HTMLElement} Loading element
     */
    getLoadingElement() {
        return this.elements.loading;
    }

    // =========================================================================
    // CUSTOM STYLES (MODAL-SPECIFIC)
    // =========================================================================

    /**
     * Apply custom styles including modal-specific backdrop settings
     */
    applyCustomStyles() {
        super.applyCustomStyles();

        if (!this.config) return;

        const { backdropOpacity, enableBackdropBlur } = this.config;
        const host = this.shadowRoot.host;

        // Modal-specific backdrop settings
        host.style.setProperty('--sm-backdrop-opacity', backdropOpacity / 100);
        host.style.setProperty('--sm-backdrop-blur', enableBackdropBlur ? 'blur(4px)' : 'none');
    }

    // =========================================================================
    // EVENT LISTENERS
    // =========================================================================

    /**
     * Attach modal-specific event listeners
     */
    attachEventListeners() {
        // Trigger button
        this.elements.trigger.addEventListener('click', this.handleTriggerClick);

        // Close button
        this.elements.close.addEventListener('click', this.handleCloseClick);

        // Backdrop click to close
        this.elements.backdrop.addEventListener('click', this.handleBackdropClick);

        // Input events (inherited functionality)
        this.elements.input.addEventListener('input', this.handleInput);
        this.elements.input.addEventListener('keydown', this.handleKeydown);

        // Global hotkey listener
        document.addEventListener('keydown', this.handleGlobalKeydown);

        // External trigger selector
        const { triggerSelector } = this.config;
        if (triggerSelector) {
            this.externalTrigger = document.querySelector(triggerSelector);
            if (this.externalTrigger) {
                this.externalTrigger.addEventListener('click', this.handleExternalTriggerClick);
            }
        }
    }

    /**
     * Detach modal-specific event listeners
     */
    detachEventListeners() {
        // Internal shadow-DOM listeners
        if (this.elements.trigger) {
            this.elements.trigger.removeEventListener('click', this.handleTriggerClick);
        }
        if (this.elements.close) {
            this.elements.close.removeEventListener('click', this.handleCloseClick);
        }
        if (this.elements.backdrop) {
            this.elements.backdrop.removeEventListener('click', this.handleBackdropClick);
        }
        if (this.elements.input) {
            this.elements.input.removeEventListener('input', this.handleInput);
            this.elements.input.removeEventListener('keydown', this.handleKeydown);
        }

        // Global hotkey listener
        document.removeEventListener('keydown', this.handleGlobalKeydown);

        // External trigger
        if (this.externalTrigger) {
            this.externalTrigger.removeEventListener('click', this.handleExternalTriggerClick);
            this.externalTrigger = null;
        }
    }

    // =========================================================================
    // MODAL OPEN/CLOSE
    // =========================================================================

    /**
     * Open the modal
     */
    open(options = {}) {
        const source = options.source || 'programmatic';

        if (this.state.get('isOpen')) {
            requestAnimationFrame(() => {
                this.elements.input.focus();
            });
            return;
        }

        this.previouslyFocused = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        this.registerOpenWidget();
        this.state.set({ isOpen: true });
        this.elements.backdrop.hidden = false;
        this.elements.trigger.setAttribute('aria-expanded', 'true');

        // Clear previous state
        this.elements.input.value = '';
        this.state.set({
            query: '',
            results: [],
            selectedIndex: -1,
        });

        // Render initial content (recent searches or empty state)
        this.renderResultsContent();

        // Focus input after animation frame
        requestAnimationFrame(() => {
            this.elements.input.focus();
        });

        // Prevent body scroll
        if (this.config.preventBodyScroll) {
            document.body.style.overflow = 'hidden';
        }

        // Dispatch open event
        this.dispatchWidgetEvent('open', { source });
    }

    /**
     * Close the modal
     */
    close(options = {}) {
        const wasOpen = this.state.get('isOpen');

        this.state.set({ isOpen: false });
        this.elements.backdrop.hidden = true;
        this.elements.trigger.setAttribute('aria-expanded', 'false');
        this.unregisterOpenWidget();

        // Restore body scroll
        if (this.config.preventBodyScroll) {
            document.body.style.overflow = '';
        }

        // Reset analytics tracking (allows new search session to be tracked)
        this.resetAnalyticsTracking();

        if (wasOpen && options.restoreFocus !== false && this.previouslyFocused?.isConnected) {
            this.previouslyFocused.focus();
        }
        this.previouslyFocused = null;

        // Dispatch close event
        if (wasOpen) {
            this.dispatchWidgetEvent('close', {
                reason: options.reason || 'programmatic',
                source: options.source || 'programmatic',
            });
        }
    }

    /**
     * Toggle modal open/close
     */
    toggle(options = {}) {
        if (this.state.get('isOpen')) {
            this.close({
                reason: options.reason || 'toggle',
                source: options.source || 'toggle',
            });
        } else {
            this.open({
                source: options.source || 'toggle',
            });
        }
    }

    /**
     * Handle the built-in trigger button.
     */
    handleTriggerClick() {
        this.toggle({ source: 'trigger' });
    }

    /**
     * Handle a configured external trigger.
     */
    handleExternalTriggerClick() {
        this.toggle({ source: 'external-trigger' });
    }

    /**
     * Handle the modal close button.
     */
    handleCloseClick() {
        this.close({ reason: 'close-button', source: 'close-button' });
    }

    // =========================================================================
    // KEYBOARD HANDLING
    // =========================================================================

    /**
     * Handle global keyboard shortcuts
     *
     * @param {KeyboardEvent} e - Keyboard event
     */
    handleGlobalKeydown(e) {
        const hotkey = this.config.hotkey.toLowerCase();
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const modifier = isMac ? e.metaKey : e.ctrlKey;

        // Hotkey to open (Cmd/Ctrl + K)
        if (modifier && e.key.toLowerCase() === hotkey) {
            if (!this.claimHotkeyEvent(e, hotkey)) {
                return;
            }
            e.preventDefault();
            this.toggle({ source: 'hotkey' });
        }

        // Escape to close
        if (e.key === 'Escape' && this.state.get('isOpen')) {
            e.preventDefault();
            this.close({ reason: 'escape', source: 'escape' });
        }
    }

    /**
     * Handle Escape key from keyboard navigator
     * @protected
     */
    handleEscape() {
        this.close({ reason: 'escape', source: 'keyboard' });
    }

    /**
     * Handle backdrop click
     *
     * @param {Event} e - Click event
     */
    handleBackdropClick(e) {
        // Only close if clicking the backdrop itself, not the modal
        if (e.target === this.elements.backdrop) {
            this.close({ reason: 'backdrop', source: 'backdrop' });
        }
    }

    // =========================================================================
    // RESULT SELECTION HANDLING
    // =========================================================================

    /**
     * Called when a result with URL is selected
     *
     * Closes the modal after selection.
     *
     * @protected
     * @param {string} url - Result URL
     * @param {string} title - Result title
     * @param {string|number} id - Result ID
     */
    onResultSelected(url, title, id) {
        this.close({ reason: 'result-selected', source: 'result-selected' });
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get hotkey display string
     *
     * @returns {string} Formatted hotkey (e.g., "⌘K" or "Ctrl+K")
     */
    getHotkeyDisplay() {
        const isMac = navigator.platform.toUpperCase().indexOf('MAC') >= 0;
        const key = this.config.hotkey.toUpperCase();
        return isMac ? `⌘${key}` : `Ctrl+${key}`;
    }
}

export default SearchModalWidget;
