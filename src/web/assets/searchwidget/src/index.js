/**
 * Search Widget - Entry Point
 *
 * Main entry point for the Search Widget component library.
 * Exports all widget types and utilities for external use.
 *
 * Architecture Overview:
 * ----------------------
 * - Core: Base classes and shared functionality
 *   - SearchWidgetBase: Abstract base class for all widgets
 *   - ConfigParser: Configuration parsing from attributes
 *   - StateManager: Reactive state management
 *
 * - Widgets: Concrete implementations
 *   - SearchModalWidget: CMD+K style modal search
 *   - (Future) SearchPageWidget: Full page search
 *   - (Future) SearchInlineWidget: Inline dropdown search
 *
 * - Modules: Reusable utilities
 *   - Highlighter: Text highlighting
 *   - KeyboardNavigator: Keyboard navigation
 *   - ResultRenderer: Result rendering
 *   - SearchService: API communication
 *   - A11yUtils: Accessibility utilities
 *
 * @module SearchWidget
 * @author Search Manager
 * @since 5.x
 *
 * @example
 * // Import specific widget
 * import { SearchModalWidget } from './index.js';
 *
 * // Register custom element
 * customElements.define('search-modal', SearchModalWidget);
 *
 * @example
 * // Use in HTML
 * <search-modal
 *   indices="products,articles"
 *   placeholder="Search..."
 *   hotkey="k"
 * ></search-modal>
 */

// =============================================================================
// CORE EXPORTS
// =============================================================================

export { default as SearchWidgetBase } from './core/SearchWidgetBase.js';
export {
    parseConfig,
    getObservedAttributes,
    getDefaultsForType,
    BASE_DEFAULTS,
    MODAL_DEFAULTS,
    PAGE_DEFAULTS,
    INLINE_DEFAULTS,
} from './core/ConfigParser.js';
export {
    createStateManager,
    createDerivedState,
    DEFAULT_STATE,
} from './core/StateManager.js';

// =============================================================================
// WIDGET EXPORTS
// =============================================================================

export { default as SearchModalWidget } from './widgets/SearchModalWidget.js';

// Future widget exports (uncomment when implemented):
// export { default as SearchPageWidget } from './widgets/SearchPageWidget.js';
// export { default as SearchInlineWidget } from './widgets/SearchInlineWidget.js';

// =============================================================================
// MODULE EXPORTS (Utilities)
// =============================================================================

export {
    escapeHtml,
    escapeRegex,
    highlightMatches,
    createHighlighter,
} from './modules/Highlighter.js';

export {
    createKeyboardNavigator,
    updateSelectionState,
    attachHoverHandlers,
} from './modules/KeyboardNavigator.js';

export {
    renderResults,
    renderResultItem,
    renderPromotedBadge,
    renderRecentSearches,
    renderEmptyState,
    renderLoadingState,
    getContentToRender,
} from './modules/ResultRenderer.js';

export {
    performSearch,
    trackClick,
    groupResultsByType,
} from './modules/SearchService.js';

export {
    generateId,
    createLiveRegion,
    announce,
    getResultsAnnouncement,
    getLoadingAnnouncement,
    getRecentSearchesAnnouncement,
    updateComboboxAria,
    getOptionId,
    scrollIntoViewIfNeeded,
} from './modules/A11yUtils.js';

export {
    loadRecentSearches,
    saveRecentSearch,
    clearRecentSearches,
} from './modules/RecentSearches.js';

export {
    applyStylesToElement,
} from './modules/StyleUtils.js';

// =============================================================================
// LEGACY EXPORT (Backward Compatibility)
// =============================================================================

/**
 * Legacy SearchWidget export for backward compatibility.
 *
 * The original SearchWidget class is still available and registered
 * as 'search-widget' custom element via SearchWidget.js.
 *
 * For new implementations, prefer using SearchModalWidget which
 * provides the same functionality with improved architecture.
 *
 * @deprecated Use SearchModalWidget instead for new implementations
 */
export { default as SearchWidget } from './SearchWidget.js';

// =============================================================================
// AUTO-REGISTRATION
// =============================================================================

/**
 * Register all widget custom elements
 *
 * Registers widgets with their default element names:
 * - <search-modal> - SearchModalWidget (CMD+K style modal)
 *
 * Call this function after importing to register custom elements.
 *
 * @param {Object} options - Registration options
 * @param {boolean} [options.modal=true] - Register search-modal element
 *
 * @example
 * import { registerWidgets, SearchModalWidget } from './index.js';
 *
 * // Register all widgets
 * registerWidgets();
 *
 * // Or register selectively
 * registerWidgets({ modal: true });
 */
export function registerWidgets(options = {}) {
    const { modal = true } = options;

    // Import SearchModalWidget dynamically to avoid circular dependencies
    if (modal && !customElements.get('search-modal')) {
        import('./widgets/SearchModalWidget.js').then(module => {
            if (!customElements.get('search-modal')) {
                customElements.define('search-modal', module.default);
            }
        });
    }

    // Future widget registrations:
    // if (page && !customElements.get('search-page')) {
    //     import('./widgets/SearchPageWidget.js').then(module => {
    //         customElements.define('search-page', module.default);
    //     });
    // }
}
